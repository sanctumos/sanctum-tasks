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
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="/admin/">Tasks Admin</a>
        <div class="d-flex gap-2">
            <?php if (isLoggedIn()): ?>
                <a class="btn btn-sm btn-outline-light" href="/admin/workspace-projects.php">Workspace projects</a>
                <?php if (isAdminRole((string)($_SESSION['role'] ?? ''))): ?>
                    <a class="btn btn-sm btn-outline-light" href="/admin/users.php">Users</a>
                    <a class="btn btn-sm btn-outline-light" href="/admin/api-keys.php">API Keys</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-light" href="/admin/mfa.php">MFA</a>
                <a class="btn btn-sm btn-outline-light" href="/admin/change-password.php">Change Password</a>
                <span class="navbar-text text-white-50">Signed in as <?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
                <form method="post" action="/admin/logout.php" class="m-0">
                    <?= csrfInputField() ?>
                    <button class="btn btn-sm btn-outline-light" type="submit">Logout</button>
                </form>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-light" href="/admin/login.php">Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div class="container py-4">

