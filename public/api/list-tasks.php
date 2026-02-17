<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$filters = [
    'status' => $_GET['status'] ?? null,
    'assigned_to_user_id' => $_GET['assigned_to_user_id'] ?? null,
    'created_by_user_id' => $_GET['created_by_user_id'] ?? null,
    'priority' => $_GET['priority'] ?? null,
    'project' => $_GET['project'] ?? null,
    'q' => $_GET['q'] ?? null,
    'due_before' => $_GET['due_before'] ?? null,
    'due_after' => $_GET['due_after'] ?? null,
    'watcher_user_id' => $_GET['watcher_user_id'] ?? null,
    'sort_by' => $_GET['sort_by'] ?? 'updated_at',
    'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
    'limit' => $limit,
    'offset' => $offset,
];

$result = listTasks($filters, true);
$tasks = $result['tasks'];
$total = (int)$result['total'];
$limit = (int)$result['limit'];
$offset = (int)$result['offset'];

$baseQueryParams = [];
foreach (['status', 'assigned_to_user_id', 'created_by_user_id', 'priority', 'project', 'q', 'due_before', 'due_after', 'watcher_user_id', 'sort_by', 'sort_dir'] as $k) {
    if (isset($_GET[$k]) && trim((string)$_GET[$k]) !== '') {
        $baseQueryParams[$k] = (string)$_GET[$k];
    }
}
$pagination = paginationMeta('/api/list-tasks.php', $baseQueryParams, $limit, $offset, $total);

apiSuccess(
    [
        'tasks' => $tasks,
        'count' => count($tasks),
        'total' => $total,
        'pagination' => $pagination,
    ],
    ['pagination' => $pagination]
);

