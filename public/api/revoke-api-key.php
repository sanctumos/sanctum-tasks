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

$keyId = isset($body['id']) ? (int)$body['id'] : 0;
if ($keyId <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

if (!isAdminRole((string)$apiUser['role'])) {
    $mine = listApiKeysForUser((int)$apiUser['id'], true);
    $owned = false;
    foreach ($mine as $k) {
        if ((int)$k['id'] === $keyId) {
            $owned = true;
            break;
        }
    }
    if (!$owned) {
        apiError('auth.forbidden', 'You can only revoke your own API keys', 403);
    }
}

revokeApiKey($keyId);
createAuditLog((int)$apiUser['id'], 'api.api_key_revoke', 'api_key', (string)$keyId);

apiSuccess(['revoked' => true, 'id' => $keyId]);
