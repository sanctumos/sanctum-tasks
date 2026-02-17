<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
$task = getTaskById($taskId, false);
if (!$task) {
    apiError('not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$apiUser['id'], $task, (string)$apiUser['role'])) {
    apiError('not_found', 'Task not found', 404);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$comments = listTaskComments($taskId, $limit, $offset);

apiSuccess([
    'task_id' => $taskId,
    'comments' => $comments,
    'count' => count($comments),
]);
