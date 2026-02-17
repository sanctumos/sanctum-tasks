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

$tasks = $body['tasks'] ?? null;
if (!is_array($tasks)) {
    apiError('validation.invalid_tasks', 'tasks must be an array', 400);
}
if (count($tasks) > 100) {
    apiError('validation.batch_too_large', 'Maximum 100 tasks per request', 400);
}

$result = bulkCreateTasks($tasks, (int)$apiUser['id']);
createAuditLog((int)$apiUser['id'], 'api.task_bulk_create', 'task', null, ['count' => count($tasks), 'created' => (int)$result['created']]);

apiSuccess($result);
