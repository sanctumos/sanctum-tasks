<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
if (!getTaskById($taskId, false)) {
    apiError('not_found', 'Task not found', 404);
}

$watchers = listTaskWatchers($taskId);
apiSuccess([
    'task_id' => $taskId,
    'watchers' => $watchers,
    'count' => count($watchers),
]);
