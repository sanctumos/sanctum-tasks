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

$username = (string)($body['username'] ?? '');
$password = (string)($body['password'] ?? '');
$role = (string)($body['role'] ?? 'member');
$mustChangePassword = !isset($body['must_change_password']) || (bool)$body['must_change_password'];
$createApiKey = isset($body['create_api_key']) && (bool)$body['create_api_key'];
$apiKeyName = (string)($body['api_key_name'] ?? 'default');
$orgId = isset($body['org_id']) ? (int)$body['org_id'] : null;
$personKind = (string)($body['person_kind'] ?? 'team_member');

$createResult = createUser($username, $password, $role, $mustChangePassword, $orgId, $personKind);
if (!$createResult['success']) {
    apiError('user.create_failed', $createResult['error'] ?? 'Create user failed', 400);
}

$newUserId = (int)$createResult['id'];
$newUser = getUserById($newUserId, false);
if (!$newUser) {
    apiError('user.create_failed', 'Failed to load created user', 500);
}

$response = ['user' => $newUser];
if ($createApiKey) {
    $response['api_key'] = createApiKeyForUser($newUserId, $apiKeyName, (int)$apiUser['id']);
}

createAuditLog((int)$apiUser['id'], 'api.user_create', 'user', (string)$newUserId, ['username' => $newUser['username']]);
apiSuccess($response, [], 201);
