<?php
require_once __DIR__ . '/../includes/auth.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tasks Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Admin shell: touch-friendly targets, full-width buttons in collapsed nav */
        .admin-nav .btn-outline-light {
            min-height: 2.5rem;
            align-items: center;
        }
        .admin-nav .admin-nav-cta { width: 100%; }
        @media (min-width: 992px) {
            .admin-nav .admin-nav-cta { width: auto; }
        }
        .admin-shell { max-width: 1400px; }
        .task-inline-edit { min-width: 0; }
        .task-inline-edit .form-select { min-width: 0; }
        @media (max-width: 1199.98px) {
            .task-inline-edit { width: 100%; }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark admin-nav">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold" href="/admin/">Tasks Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <?php if (isLoggedIn()): ?>
                <div class="d-flex flex-column flex-lg-row flex-wrap gap-2 ms-lg-auto align-items-stretch align-items-lg-center py-3 py-lg-0">
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/workspace-projects.php">Workspace projects</a>
                    <?php if (isAdminRole((string)($_SESSION['role'] ?? ''))): ?>
                        <a class="btn btn-outline-light text-center text-lg-start" href="/admin/users.php">Users</a>
                        <a class="btn btn-outline-light text-center text-lg-start" href="/admin/api-keys.php">API keys</a>
                    <?php endif; ?>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/mfa.php">MFA</a>
                    <a class="btn btn-outline-light text-center text-lg-start" href="/admin/change-password.php">Password</a>
                    <hr class="d-lg-none border-secondary opacity-50 my-1 mx-0 w-100">
                    <span class="navbar-text text-white-50 small px-lg-2 py-1 text-center text-lg-start"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
                    <form method="post" action="/admin/logout.php" class="m-0">
                        <?= csrfInputField() ?>
                        <button class="btn btn-outline-light admin-nav-cta" type="submit">Logout</button>
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

