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

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$existing = getTaskById($id, false);
if (!$existing) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$user['id'], $existing, (string)$user['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

deleteTask((int)$id);

apiSuccess(['deleted' => true]);

