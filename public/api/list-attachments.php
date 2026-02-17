<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    apiError('validation.invalid_task_id', 'Missing or invalid task_id', 400);
}

$attachments = listTaskAttachments($taskId);
apiSuccess([
    'task_id' => $taskId,
    'attachments' => $attachments,
    'count' => count($attachments),
]);
