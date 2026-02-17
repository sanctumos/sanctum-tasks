<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

if (!isLoggedIn()) {
    apiError('auth.not_logged_in', 'Not logged in', 401);
}

$user = getCurrentUser();
if (!$user) {
    apiError('auth.not_logged_in', 'Not logged in', 401);
}

apiSuccess([
    'user' => $user,
    'csrf_token' => getCsrfToken(),
]);
