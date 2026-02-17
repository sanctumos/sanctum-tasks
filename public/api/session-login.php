<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}
if ($body === []) {
    $body = $_POST;
}

$username = (string)($body['username'] ?? '');
$password = (string)($body['password'] ?? '');
$mfaCode = isset($body['mfa_code']) ? (string)$body['mfa_code'] : null;

$result = login($username, $password, $mfaCode);
if (!$result['success']) {
    $statusCode = 401;
    if (isset($result['lockout_seconds']) && (int)$result['lockout_seconds'] > 0) {
        $statusCode = 429;
    }
    apiError(
        'auth.login_failed',
        $result['error'] ?? 'Login failed',
        $statusCode,
        [
            'mfa_required' => !empty($result['mfa_required']),
            'lockout_seconds' => (int)($result['lockout_seconds'] ?? 0),
        ]
    );
}

$user = getCurrentUser();
if (!$user) {
    apiError('auth.login_failed', 'Login failed', 401);
}

apiSuccess([
    'user' => $user,
    'csrf_token' => getCsrfToken(),
    'must_change_password' => !empty($result['must_change_password']),
]);
