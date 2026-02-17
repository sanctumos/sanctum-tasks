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

$updates = $body['updates'] ?? null;
if (!is_array($updates)) {
    apiError('validation.invalid_updates', 'updates must be an array', 400);
}
if (count($updates) > 100) {
    apiError('validation.batch_too_large', 'Maximum 100 updates per request', 400);
}

$result = bulkUpdateTasks($updates);
createAuditLog((int)$apiUser['id'], 'api.task_bulk_update', 'task', null, ['count' => count($updates), 'updated' => (int)$result['updated']]);

apiSuccess($result);
