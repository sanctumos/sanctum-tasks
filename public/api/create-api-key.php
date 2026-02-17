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

$isAdmin = isAdminRole((string)$apiUser['role']);
$targetUserId = isset($body['user_id']) ? (int)$body['user_id'] : (int)$apiUser['id'];
if (!$isAdmin && $targetUserId !== (int)$apiUser['id']) {
    apiError('auth.forbidden', 'Only admins can create keys for other users', 403);
}

$targetUser = getUserById($targetUserId, false);
if (!$targetUser) {
    apiError('user.not_found', 'Target user not found', 404);
}

$keyName = trim((string)($body['key_name'] ?? 'Unnamed Key'));
if ($keyName === '') {
    $keyName = 'Unnamed Key';
}

$apiKey = createApiKeyForUser($targetUserId, $keyName, (int)$apiUser['id']);
createAuditLog((int)$apiUser['id'], 'api.api_key_create', 'api_key', null, ['user_id' => $targetUserId, 'key_name' => $keyName]);

apiSuccess([
    'api_key' => $apiKey,
    'user_id' => $targetUserId,
    'key_name' => $keyName,
], [], 201);
