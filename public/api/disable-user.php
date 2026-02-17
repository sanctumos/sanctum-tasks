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

$targetUserId = isset($body['id']) ? (int)$body['id'] : 0;
if ($targetUserId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$isActive = isset($body['is_active']) ? (bool)$body['is_active'] : false;
if ($targetUserId === (int)$apiUser['id'] && !$isActive) {
    apiError('validation.self_disable_not_allowed', 'You cannot disable your own user via API', 400);
}

$result = setUserActive($targetUserId, $isActive);
if (!$result['success']) {
    apiError('user.update_failed', $result['error'] ?? 'Failed to update user', 400);
}

$updatedUser = getUserById($targetUserId, false);
if (!$updatedUser) {
    apiError('user.not_found', 'User not found', 404);
}

createAuditLog((int)$apiUser['id'], $isActive ? 'api.user_enable' : 'api.user_disable', 'user', (string)$targetUserId);
apiSuccess(['user' => $updatedUser]);
