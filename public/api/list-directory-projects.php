<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$full = getUserById((int)$user['id'], false);
if (!$full) {
    apiError('auth.invalid_user', 'User not found', 401);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$projects = listDirectoryProjectsForUser($full, $limit);

apiSuccess([
    'projects' => $projects,
    'count' => count($projects),
]);
