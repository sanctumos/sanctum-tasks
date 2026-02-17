<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireAdminApiUser();

$includeDisabled = isset($_GET['include_disabled']) && $_GET['include_disabled'] === '1';
$users = listUsers($includeDisabled);

apiSuccess([
    'users' => $users,
    'count' => count($users),
]);
