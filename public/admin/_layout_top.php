<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/skin-lab-env.php';
require_once __DIR__ . '/_helpers.php';
$stAppName = getAppName();
$pageTitle = isset($pageTitle) ? $pageTitle : $stAppName;
$stSkinSlug = skinLabEffectiveSlug(isLoggedIn() ? getCurrentUser() : null);
$stShowSkinCompBar = skinLabShouldShowCompBar();
$stSkinSlugs = skinLabAvailableSlugs();
?>
<!doctype html>
<html lang="en" data-skin-comp="<?= htmlspecialchars($stSkinSlug) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars($stAppName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/admin.css?v=21" rel="stylesheet">
    <?php if ($stShowSkinCompBar): ?>
        <?php /* Comp bar toggles data-skin-comp client-side — load every skin sheet so switches paint. */ ?>
        <?php foreach ($stSkinSlugs as $slug): ?>
    <link href="/assets/skins/<?= htmlspecialchars($slug) ?>.css?v=31" rel="stylesheet">
        <?php endforeach; ?>
    <?php else: ?>
    <link href="/assets/skins/<?= htmlspecialchars($stSkinSlug) ?>.css?v=31" rel="stylesheet">
    <?php endif; ?>
    <?php if ($stShowSkinCompBar): ?>
    <script src="/js/st-skin-comp-switcher.js?v=1"></script>
    <?php endif; ?>
</head>
<body class="bg-light<?= $stShowSkinCompBar ? ' st-skin-lab-active' : '' ?>">
<?php if ($stShowSkinCompBar): ?>
<div class="st-skin-comp-bar" id="st-skin-comp-bar" role="region" aria-label="Skin comp switcher (dev)">
    <span class="st-skin-comp-bar__label">SKIN</span>
    <?php foreach ($stSkinSlugs as $slug): ?>
        <button type="button" class="st-skin-comp-bar__btn" data-skin-set="<?= htmlspecialchars($slug) ?>" aria-pressed="false"><?= htmlspecialchars($slug) ?></button>
    <?php endforeach; ?>
    <span class="st-skin-comp-bar__spacer"></span>
    <span class="st-skin-comp-bar__note">UI-Dev · dev.tasks</span>
</div>
<script>
window.__ST_SKIN_LAB__ = {
    slugs: <?= json_encode($stSkinSlugs, JSON_UNESCAPED_UNICODE) ?>,
    defaultSlug: <?= json_encode($stSkinSlug, JSON_UNESCAPED_UNICODE) ?>,
    saveUrl: '/admin/save-skin.php',
    csrfToken: <?= json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<?php endif; ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark admin-nav">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold d-inline-flex align-items-center gap-2" href="/admin/" title="<?= htmlspecialchars($stAppName) ?>">
            <i class="bi bi-stack"></i>
            <span><?= htmlspecialchars($stAppName) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <?php if (isLoggedIn()): ?>
                <?php
                $layoutUser = getCurrentUser();
                $stNotifUnread = $layoutUser !== null ? countUnreadNotifications((int)$layoutUser['id']) : 0;
                $stNavPins = ($layoutUser !== null) ? listUserProjectPinsForUser($layoutUser, 40) : [];
                ?>
                <div class="d-flex flex-column flex-lg-row flex-wrap gap-2 ms-lg-auto align-items-stretch align-items-lg-center py-3 py-lg-0">
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/" title="Your projects &amp; all tasks"><i class="bi bi-house-door me-1"></i>Home</a>
                    <div class="dropdown">
                        <button class="btn btn-outline-light text-center text-lg-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Pinned boards and all projects">
                            <i class="bi bi-pin-angle me-1"></i>Boards<?php if ($stNavPins !== []): ?><span class="badge text-bg-light text-dark ms-1"><?= count($stNavPins) ?></span><?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="max-height: 22rem; overflow-y: auto;">
                            <?php if ($stNavPins === []): ?>
                                <li><span class="dropdown-item-text text-muted small">No pinned boards yet. Open a board and pin it.</span></li>
                            <?php else: ?>
                                <li><h6 class="dropdown-header">Pinned</h6></li>
                                <?php foreach ($stNavPins as $pin): ?>
                                    <li>
                                        <a class="dropdown-item" href="/admin/project.php?id=<?= (int)$pin['project_id'] ?>">
                                            <i class="bi bi-pin-fill me-2 text-muted"></i><?= htmlspecialchars((string)$pin['name']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="/admin/workspace-projects.php"><i class="bi bi-grid-3x3-gap me-2"></i>All projects</a></li>
                            <li><a class="dropdown-item" href="/admin/"><i class="bi bi-house-door me-2"></i>Home</a></li>
                        </ul>
                    </div>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/docs.php"><i class="bi bi-journals me-1"></i>Docs</a>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/schedule.php"><i class="bi bi-calendar3 me-1"></i>Schedule</a>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/activity.php"><i class="bi bi-activity me-1"></i>Activity</a>
                    <div class="dropdown">
                        <button class="btn btn-outline-light text-center text-lg-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear me-1"></i>Settings
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/admin/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <li><a class="dropdown-item" href="/admin/documentation.php"><i class="bi bi-question-circle me-2"></i>Help</a></li>
                            <?php if (isAdminRole((string)($_SESSION['role'] ?? ''))): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="/admin/organizations.php"><i class="bi bi-building me-2"></i>Organizations</a></li>
                                <li><a class="dropdown-item" href="/admin/users.php"><i class="bi bi-people me-2"></i>Users</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <hr class="d-lg-none border-secondary opacity-50 my-1 mx-0 w-100">
                    <?php
                    $layoutOrgIds = ($layoutUser && userQualifiesForMultiOrganizationMemberships($layoutUser))
                        ? listOrganizationIdsForUserAccess($layoutUser)
                        : [];
                    ?>
                    <?php if ($layoutUser !== null && count($layoutOrgIds) > 1): ?>
                        <form method="post" action="/admin/switch-org.php" class="m-0 px-lg-2 py-1">
                            <?= csrfInputField() ?>
                            <label class="visually-hidden" for="active_org_select">Active organization</label>
                            <select id="active_org_select" name="org_id" class="form-select form-select-sm bg-dark text-white border-secondary" style="max-width: 14rem;" title="New projects are created in this organization" onchange="this.form.submit()">
                                <?php
                                $activePick = (int)($_SESSION['active_org_id'] ?? $layoutUser['org_id'] ?? 0);
                                foreach ($layoutOrgIds as $oid):
                                    $ogr = getOrganizationById($oid);
                                    if (!$ogr) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?= (int)$oid ?>" <?= $activePick === $oid ? 'selected' : '' ?>><?= htmlspecialchars($ogr['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <span class="navbar-text text-white-50 small px-lg-2 py-1 text-center text-lg-start d-inline-flex align-items-center gap-2">
                        <i class="bi bi-person-circle"></i><?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                        <a class="btn btn-outline-light btn-sm st-nav-notif-btn position-relative" href="/admin/notifications.php" title="Assignments, mentions, and activity" aria-label="Notifications">
                            <i class="bi bi-bell"></i>
                            <?php if ($stNotifUnread > 0): ?><span class="badge rounded-pill bg-danger st-nav-notif-badge"><?= $stNotifUnread > 99 ? '99+' : (int)$stNotifUnread ?></span><?php endif; ?>
                        </a>
                    </span>
                    <form method="post" action="/admin/logout.php" class="m-0">
                        <?= csrfInputField() ?>
                        <button class="btn btn-outline-light admin-nav-cta" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Logout</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="ms-lg-auto py-2 py-lg-0">
                    <a class="btn btn-outline-light px-3 admin-nav-cta" href="/admin/login.php">Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="admin-shell container-fluid px-3 px-lg-4 py-4">
<?php
if (!empty($adminBreadcrumbs) && is_array($adminBreadcrumbs)) {
    echo st_admin_breadcrumbs($adminBreadcrumbs);
}
?>
