<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

if (isLoggedIn()) {
    requireCsrfToken();
    logout();
}

apiSuccess(['logged_out' => true]);
