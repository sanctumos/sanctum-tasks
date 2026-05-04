<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = isset($pageTitle) ? $pageTitle : 'Sanctum Tasks';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> · Sanctum Tasks</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/admin.css?v=2" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark admin-nav">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold d-inline-flex align-items-center gap-2" href="/admin/">
            <i class="bi bi-stack"></i>
            <span>Sanctum Tasks</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <?php if (isLoggedIn()): ?>
                <div class="d-flex flex-column flex-lg-row flex-wrap gap-2 ms-lg-auto align-items-stretch align-items-lg-center py-3 py-lg-0">
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/"><i class="bi bi-list-check me-1"></i>Tasks</a>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/workspace-projects.php"><i class="bi bi-kanban me-1"></i>Projects</a>
                    <?php if (isAdminRole((string)($_SESSION['role'] ?? ''))): ?>
                        <a class="btn btn-outline-light text-center text-lg-start" href="/admin/organizations.php"><i class="bi bi-building me-1"></i>Organizations</a>
                        <a class="btn btn-outline-light text-center text-lg-start" href="/admin/users.php"><i class="bi bi-people me-1"></i>Users</a>
                    <?php endif; ?>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/settings.php"><i class="bi bi-gear me-1"></i>Settings</a>
                    <hr class="d-lg-none border-secondary opacity-50 my-1 mx-0 w-100">
                    <?php
                    $layoutUser = isLoggedIn() ? getCurrentUser() : null;
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
                    <span class="navbar-text text-white-50 small px-lg-2 py-1 text-center text-lg-start"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
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
