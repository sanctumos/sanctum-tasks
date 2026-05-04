<?php
/**
 * Settings — single page for account + workspace administration.
 * Tabs: Password · MFA · API keys · Audit
 *  (API keys / Audit are admin-only.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$isAdmin = isAdminRole((string)($currentUser['role'] ?? ''));

$availableTabs = [
    'password' => ['label' => 'Password', 'icon' => 'bi-asterisk', 'admin' => false],
    'mfa' => ['label' => 'MFA', 'icon' => 'bi-shield-lock', 'admin' => false],
    'api-keys' => ['label' => 'API keys', 'icon' => 'bi-key', 'admin' => true],
    'audit' => ['label' => 'Audit log', 'icon' => 'bi-shield-check', 'admin' => true],
];

$tab = (string)($_GET['tab'] ?? 'password');
if (!isset($availableTabs[$tab])) {
    $tab = 'password';
}
if ($availableTabs[$tab]['admin'] && !$isAdmin) {
    $tab = 'password';
}

$pageTitle = 'Settings · ' . $availableTabs[$tab]['label'];
require __DIR__ . '/_layout_top.php';

function st_settings_tab_link(string $tab, string $active, array $availableTabs, bool $isAdmin): string {
    if ($availableTabs[$tab]['admin'] && !$isAdmin) return '';
    $cls = $active === $tab ? 'active' : '';
    $href = '/admin/settings.php?tab=' . urlencode($tab);
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">'
        . '<i class="bi ' . htmlspecialchars($availableTabs[$tab]['icon']) . '"></i>'
        . '<span>' . htmlspecialchars($availableTabs[$tab]['label']) . '</span>'
        . '</a>';
}
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Settings</h1>
        <div class="subtitle">Account &amp; workspace administration for <code><?= htmlspecialchars($currentUser['username']) ?></code></div>
    </div>
</div>

<nav class="tabbar" aria-label="Settings sections">
    <?php foreach ($availableTabs as $slug => $meta): ?>
        <?= st_settings_tab_link($slug, $tab, $availableTabs, $isAdmin) ?>
    <?php endforeach; ?>
</nav>

<?php
switch ($tab) {
    case 'password':
        require __DIR__ . '/_settings/password.php';
        break;
    case 'mfa':
        require __DIR__ . '/_settings/mfa.php';
        break;
    case 'api-keys':
        if ($isAdmin) {
            require __DIR__ . '/_settings/api_keys.php';
        }
        break;
    case 'audit':
        if ($isAdmin) {
            require __DIR__ . '/_settings/audit.php';
        }
        break;
}
?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
