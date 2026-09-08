<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
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
    'status' => $statusFilter,
    'assigned_to_user_id' => $_GET['assigned_to_user_id'] ?? null,
    'created_by_user_id' => $_GET['created_by_user_id'] ?? null,
    'priority' => $priorityFilter,
    'project' => $_GET['project'] ?? null,
    'project_id' => $_GET['project_id'] ?? null,
    'list_id' => $_GET['list_id'] ?? null,
    'q' => $_GET['q'] ?? null,
    'due_before' => $_GET['due_before'] ?? null,
    'due_after' => $_GET['due_after'] ?? null,
    'watcher_user_id' => $_GET['watcher_user_id'] ?? null,
    'sort_by' => $_GET['sort_by'] ?? 'updated_at',
    'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
    'limit' => $limit,
    'offset' => $offset,
];
if (isset($_GET['tag']) && trim((string)$_GET['tag']) !== '') {
    $filters['tag'] = trim((string)$_GET['tag']);
}
if (isset($_GET['exclude_done']) && (string)$_GET['exclude_done'] === '1') {
    $filters['exclude_done'] = true;
}
if (isset($_GET['include_archived_projects']) && (string)$_GET['include_archived_projects'] === '1') {
    $filters['include_archived_projects'] = true;
}

$result = listTasks($filters, true, $user);
$tasks = $result['tasks'];
$total = (int)$result['total'];
$limit = (int)$result['limit'];
$offset = (int)$result['offset'];

$baseQueryParams = [];
foreach (['status', 'assigned_to_user_id', 'created_by_user_id', 'priority', 'project', 'project_id', 'list_id', 'q', 'due_before', 'due_after', 'watcher_user_id', 'tag', 'exclude_done', 'sort_by', 'sort_dir'] as $k) {
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

