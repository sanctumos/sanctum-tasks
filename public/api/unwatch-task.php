<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$taskId = isset($body['task_id']) ? (int)$body['task_id'] : 0;
$userId = isset($body['user_id']) ? (int)$body['user_id'] : (int)$apiUser['id'];

if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
if ($userId !== (int)$apiUser['id'] && !isAdminRole((string)$apiUser['role'])) {
    apiError('auth.forbidden', 'Only admins can remove watchers for other users', 403);
}
$task = getTaskById($taskId, false);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$apiUser['id'], $task, (string)$apiUser['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

$result = removeTaskWatcher($taskId, $userId);
if (!$result['success']) {
    apiError('task.unwatch_failed', $result['error'] ?? 'Failed to remove watcher', 400);
}

apiSuccess([
    'task_id' => $taskId,
    'user_id' => $userId,
    'watching' => false,
]);
