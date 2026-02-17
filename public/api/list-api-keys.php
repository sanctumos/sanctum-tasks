<?php
require_once __DIR__ . '/../includes/api_auth.php';

$apiUser = requireApiUser();

$includeRevoked = isset($_GET['include_revoked']) && $_GET['include_revoked'] === '1';
$mineOnly = isset($_GET['mine']) && $_GET['mine'] === '1';

if ($mineOnly || !isAdminRole((string)$apiUser['role'])) {
    $keys = listApiKeysForUser((int)$apiUser['id'], $includeRevoked);
} else {
    $keys = getAllApiKeys($includeRevoked);
}

foreach ($keys as &$k) {
    $k['api_key_preview'] = substr((string)$k['api_key'], 0, 12) . '...';
    unset($k['api_key']);
}
unset($k);

apiSuccess([
    'api_keys' => $keys,
    'count' => count($keys),
]);
