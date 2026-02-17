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

$providedPassword = isset($body['new_password']) ? trim((string)$body['new_password']) : '';
$newPassword = $providedPassword !== '' ? $providedPassword : generateTemporaryPassword(16);
$mustChangePassword = !isset($body['must_change_password']) || (bool)$body['must_change_password'];

$result = resetUserPassword($targetUserId, $newPassword, $mustChangePassword);
if (!$result['success']) {
    apiError('user.password_reset_failed', $result['error'] ?? 'Failed to reset password', 400);
}

createAuditLog((int)$apiUser['id'], 'api.user_password_reset', 'user', (string)$targetUserId, [
    'must_change_password' => $mustChangePassword ? 1 : 0,
]);

apiSuccess([
    'id' => $targetUserId,
    'temporary_password' => $newPassword,
    'must_change_password' => $mustChangePassword ? 1 : 0,
]);
