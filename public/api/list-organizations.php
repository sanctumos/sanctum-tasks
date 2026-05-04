<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();
$full = getUserById((int)$user['id'], false);
if (!$full) {
    apiError('auth.invalid_user', 'User not found', 401);
}

$role = (string)($full['role'] ?? 'member');
if (in_array($role, ['admin', 'manager'], true)) {
    $organizations = listOrganizations();
} else {
    $oid = (int)($full['org_id'] ?? 0);
    $organizations = [];
    if ($oid > 0) {
        foreach (listOrganizations() as $org) {
            if ((int)$org['id'] === $oid) {
                $organizations[] = $org;
                break;
            }
        }
    }
}

apiSuccess([
    'organizations' => $organizations,
    'count' => count($organizations),
]);
