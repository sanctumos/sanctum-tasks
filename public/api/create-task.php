<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$title = $body['title'] ?? '';
$status = $body['status'] ?? null;
$assignedToUserId = $body['assigned_to_user_id'] ?? null;
$taskBody = $body['body'] ?? null;
$options = [
    'due_at' => $body['due_at'] ?? null,
    'priority' => $body['priority'] ?? 'normal',
    'project' => $body['project'] ?? null,
    'tags' => $body['tags'] ?? [],
    'rank' => $body['rank'] ?? 0,
    'recurrence_rule' => $body['recurrence_rule'] ?? null,
];

$result = createTask($title, $status, (int)$user['id'], $assignedToUserId, $taskBody, $options);
if (!$result['success']) {
    apiError('task.create_failed', $result['error'] ?? 'Create failed', 400);
}

$task = getTaskById((int)$result['id']);
apiSuccess(['task' => $task], [], 201);

