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
$fileName = trim((string)($body['file_name'] ?? ''));
$fileUrl = trim((string)($body['file_url'] ?? ''));
$mimeType = isset($body['mime_type']) ? (string)$body['mime_type'] : null;
$sizeBytes = isset($body['size_bytes']) ? (int)$body['size_bytes'] : null;

if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}
$task = getTaskById($taskId, false);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$apiUser['id'], $task, (string)$apiUser['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

$result = addTaskAttachment($taskId, (int)$apiUser['id'], $fileName, $fileUrl, $mimeType, $sizeBytes);
if (!$result['success']) {
    $statusCode = ($result['error'] ?? '') === 'Task not found' ? 404 : 400;
    apiError('task.attachment_add_failed', $result['error'] ?? 'Failed to add attachment', $statusCode);
}

apiSuccess([
    'task_id' => $taskId,
    'attachment_id' => (int)$result['id'],
], [], 201);
