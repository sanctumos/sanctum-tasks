<?php
/**
 * Set active organization for admin/manager (new directory projects, labels).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/', true, 303);
    exit();
}

requireCsrfToken();

$currentUser = getCurrentUser();
$orgId = (int)($_POST['org_id'] ?? 0);

if ($currentUser && userQualifiesForMultiOrganizationMemberships($currentUser) && $orgId > 0 && userMayAccessOrganization($currentUser, $orgId)) {
    $_SESSION['active_org_id'] = $orgId;
}

$dest = '/admin/';
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '') {
    $parts = parse_url($ref);
    $path = isset($parts['path']) ? (string)$parts['path'] : '';
    if ($path !== '' && str_starts_with($path, '/')) {
        $dest = $path;
        if (!empty($parts['query'])) {
            $dest .= '?' . $parts['query'];
        }
    }
}

header('Location: ' . $dest, true, 303);
exit();
