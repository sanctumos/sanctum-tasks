<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    apiError('validation.missing_query', 'Query parameter q is required', 400);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$statusFilter = $_GET['status'] ?? null;
if ($statusFilter !== null && trim((string)$statusFilter) !== '') {
    $statusFilter = sanitizeStatus((string)$statusFilter);
    if ($statusFilter === null) {
        apiError('validation.invalid_status', 'Invalid status filter', 400, ['field' => 'status']);
    }
}

$priorityFilter = $_GET['priority'] ?? null;
if ($priorityFilter !== null && trim((string)$priorityFilter) !== '') {
    $priorityFilter = normalizePriority((string)$priorityFilter);
    if ($priorityFilter === null) {
        apiError('validation.invalid_priority', 'Invalid priority filter', 400, ['field' => 'priority']);
    }
}

$filters = [
    'q' => $q,
    'status' => $statusFilter,
    'priority' => $priorityFilter,
    'assigned_to_user_id' => $_GET['assigned_to_user_id'] ?? null,
    'sort_by' => $_GET['sort_by'] ?? 'updated_at',
    'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
    'limit' => $limit,
    'offset' => $offset,
];

$result = listTasks($filters, true);
$baseQueryParams = [];
foreach (['q', 'status', 'priority', 'assigned_to_user_id', 'sort_by', 'sort_dir'] as $k) {
    if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') {
        $baseQueryParams[$k] = (string)$_GET[$k];
    }
}
$pagination = paginationMeta('/api/search-tasks.php', $baseQueryParams, (int)$result['limit'], (int)$result['offset'], (int)$result['total']);

apiSuccess([
    'tasks' => $result['tasks'],
    'count' => count($result['tasks']),
    'total' => (int)$result['total'],
    'pagination' => $pagination,
]);
