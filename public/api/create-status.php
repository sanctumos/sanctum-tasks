<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireAdminApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$slug = (string)($body['slug'] ?? '');
$label = (string)($body['label'] ?? '');
$sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 100;
$isDone = isset($body['is_done']) ? (bool)$body['is_done'] : false;
$isDefault = isset($body['is_default']) ? (bool)$body['is_default'] : false;

$result = createTaskStatus($slug, $label, $sortOrder, $isDone, $isDefault);
if (!$result['success']) {
    apiError('status.create_failed', $result['error'] ?? 'Failed to create status', 400);
}

createAuditLog((int)$apiUser['id'], 'api.status_create', 'task_status', $result['slug'], ['label' => $label]);
apiSuccess([
    'status' => getTaskStatusBySlug((string)$result['slug']),
], [], 201);
