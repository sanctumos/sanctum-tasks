<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    apiError('validation.missing_query', 'Query parameter q is required', 400);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$filters = [
    'q' => $q,
    'status' => $_GET['status'] ?? null,
    'priority' => $_GET['priority'] ?? null,
    'assigned_to_user_id' => $_GET['assigned_to_user_id'] ?? null,
    'sort_by' => $_GET['sort_by'] ?? 'updated_at',
    'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
    'limit' => $limit,
    'offset' => $offset,
];

$result = listTasks($filters, true);
$pagination = paginationMeta('/api/search-tasks.php', ['q' => $q], (int)$result['limit'], (int)$result['offset'], (int)$result['total']);

apiSuccess([
    'tasks' => $result['tasks'],
    'count' => count($result['tasks']),
    'total' => (int)$result['total'],
    'pagination' => $pagination,
]);
