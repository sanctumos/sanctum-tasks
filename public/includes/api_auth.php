<?php
require_once __DIR__ . '/functions.php';

function jsonResponse($data, $statusCode = 200, array $headers = []): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data);
    exit();
}

function apiError(string $code, string $message, int $statusCode = 400, array $details = [], array $extra = []): void {
    $payload = array_merge([
        'success' => false,
        // Keep legacy key for older SDK clients.
        'error' => $message,
        'error_object' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
    ], $extra);

    jsonResponse($payload, $statusCode);
}

function apiSuccess(array $payload = [], array $meta = [], int $statusCode = 200): void {
    $response = array_merge(['success' => true], $payload);
    $response['data'] = $payload;
    if (!empty($meta)) {
        $response['meta'] = $meta;
    }
    jsonResponse($response, $statusCode);
}

function readJsonBody(): ?array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function getApiKeyFromRequest(): ?string {
    // Primary: X-API-Key header.
    if (isset($_SERVER['HTTP_X_API_KEY']) && trim($_SERVER['HTTP_X_API_KEY']) !== '') {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    // Authorization: Bearer <token>
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
        return trim($m[1]);
    }
    // Fallback (debug/local tooling): querystring.
    if (isset($_GET['api_key']) && trim($_GET['api_key']) !== '') {
        return trim($_GET['api_key']);
    }
    return null;
}

function setRateLimitHeaders(array $rateState): void {
    header('X-RateLimit-Limit: ' . (int)($rateState['limit'] ?? 0));
    header('X-RateLimit-Remaining: ' . (int)($rateState['remaining'] ?? 0));
    header('X-RateLimit-Reset: ' . (int)($rateState['reset_epoch'] ?? time()));
}

function requestScheme(): string {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off') ? 'https' : 'http';
}

function sanitizeUrlHost(string $host): ?string {
    $host = trim($host);
    if ($host === '') {
        return null;
    }

    if (substr($host, 0, 1) === '[') {
        $closingBracket = strpos($host, ']');
        if ($closingBracket === false) {
            return null;
        }
        $host = substr($host, 1, $closingBracket - 1);
    } elseif (substr_count($host, ':') === 1) {
        [$rawHost, $rawPort] = explode(':', $host, 2);
        if ($rawPort !== '' && !ctype_digit($rawPort)) {
            return null;
        }
        $host = $rawHost;
    }

    $host = strtolower(trim($host));
    if ($host === '') {
        return null;
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $host;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return '[' . $host . ']';
    }
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
        return $host;
    }
    if ($host === 'localhost') {
        return $host;
    }
    return null;
}

function normalizedPortFromServer(string $scheme): ?int {
    if (!isset($_SERVER['SERVER_PORT'])) {
        return null;
    }

    $port = (int)$_SERVER['SERVER_PORT'];
    if ($port <= 0 || $port > 65535) {
        return null;
    }
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        return null;
    }
    return $port;
}

function configuredAppOrigin(): ?string {
    $configured = trim((string)envOrDefault('TASKS_APP_BASE_URL', ''));
    if ($configured === '') {
        return null;
    }

    $parts = parse_url($configured);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = sanitizeUrlHost((string)($parts['host'] ?? ''));
    if ($host === null) {
        return null;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && ($port <= 0 || $port > 65535)) {
        return null;
    }

    $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    $portSuffix = ($port !== null && !$isDefaultPort) ? (':' . $port) : '';
    return $scheme . '://' . $host . $portSuffix;
}

function requestOrigin(): string {
    $configured = configuredAppOrigin();
    if ($configured !== null) {
        return $configured;
    }

    $scheme = requestScheme();
    $host = 'localhost';
    foreach ([(string)($_SERVER['SERVER_ADDR'] ?? ''), (string)($_SERVER['SERVER_NAME'] ?? '')] as $candidate) {
        $sanitized = sanitizeUrlHost($candidate);
        if ($sanitized !== null) {
            $host = $sanitized;
            break;
        }
    }
    $port = normalizedPortFromServer($scheme);
    $portSuffix = $port !== null ? (':' . $port) : '';
    return $scheme . '://' . $host . $portSuffix;
}

function buildAbsoluteUrl(string $path, array $queryParams = []): string {
    $query = http_build_query($queryParams);
    return requestOrigin() . $path . ($query ? ('?' . $query) : '');
}

function paginationMeta(string $path, array $baseQueryParams, int $limit, int $offset, int $total): array {
    $nextOffset = ($offset + $limit < $total) ? ($offset + $limit) : null;
    $prevOffset = ($offset - $limit >= 0) ? ($offset - $limit) : null;

    $nextUrl = null;
    if ($nextOffset !== null) {
        $nextUrl = buildAbsoluteUrl($path, array_merge($baseQueryParams, ['limit' => $limit, 'offset' => $nextOffset]));
    }

    $prevUrl = null;
    if ($prevOffset !== null) {
        $prevUrl = buildAbsoluteUrl($path, array_merge($baseQueryParams, ['limit' => $limit, 'offset' => $prevOffset]));
    }

    return [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'next_offset' => $nextOffset,
        'prev_offset' => $prevOffset,
        'next_url' => $nextUrl,
        'prev_url' => $prevUrl,
    ];
}

function requireApiUser(bool $requireAdmin = false): array {
    initializeDatabase();
    $apiKey = getApiKeyFromRequest();
    if (!$apiKey) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }

    $rateState = checkApiRateLimit($apiKey);
    setRateLimitHeaders($rateState);
    if (empty($rateState['allowed'])) {
        header('Retry-After: ' . (int)($rateState['retry_after'] ?? 1));
        apiError(
            'rate_limited',
            'Rate limit exceeded. Slow down and retry later.',
            429,
            ['retry_after' => (int)($rateState['retry_after'] ?? 1)],
            ['rate_limit' => $rateState]
        );
    }

    $user = validateApiKeyAndGetUser($apiKey);
    if (!$user) {
        apiError('auth.invalid_api_key', 'Invalid or missing API key', 401);
    }

    if ($requireAdmin && !isAdminRole((string)$user['role'])) {
        apiError('auth.forbidden', 'Admin role required', 403);
    }

    return $user;
}

function requireAdminApiUser(): array {
    return requireApiUser(true);
}
