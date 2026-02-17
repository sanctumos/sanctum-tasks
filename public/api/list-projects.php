<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
$projects = listProjects($limit);

apiSuccess([
    'projects' => $projects,
    'count' => count($projects),
]);
