<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireAdminApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$logs = listAuditLogs($limit, $offset);

apiSuccess([
    'logs' => $logs,
    'count' => count($logs),
]);
