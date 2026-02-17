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
$comment = trim((string)($body['comment'] ?? ''));
if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
if ($comment === '') {
    apiError('validation.missing_comment', 'comment is required', 400);
}
$task = getTaskById($taskId, false);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$apiUser['id'], $task, (string)$apiUser['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

$result = addTaskComment($taskId, (int)$apiUser['id'], $comment);
if (!$result['success']) {
    $statusCode = ($result['error'] ?? '') === 'Task not found' ? 404 : 400;
    apiError('task.comment_create_failed', $result['error'] ?? 'Failed to add comment', $statusCode);
}

apiSuccess([
    'task_id' => $taskId,
    'comment_id' => (int)$result['id'],
    'comment' => [
        'id' => (int)$result['id'],
        'task_id' => $taskId,
        'user_id' => (int)$apiUser['id'],
        'username' => $apiUser['username'],
        'comment' => $comment,
        'created_at' => $result['created_at'] ?? nowUtc(),
    ],
], [], 201);
