<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();
$statuses = listTaskStatuses();

apiSuccess([
    'statuses' => $statuses,
    'count' => count($statuses),
]);
