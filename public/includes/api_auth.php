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

function buildAbsoluteUrl(string $path, array $queryParams = []): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $query = http_build_query($queryParams);
    return $scheme . '://' . $host . $path . ($query ? ('?' . $query) : '');
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
