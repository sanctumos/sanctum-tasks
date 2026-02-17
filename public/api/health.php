<?php
require_once __DIR__ . '/../includes/api_auth.php';

// Health endpoint: requires API key (keeps behavior consistent across /api/*)
$user = requireApiUser();

apiSuccess([
    'ok' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'is_active' => (int)$user['is_active'],
    ],
]);

