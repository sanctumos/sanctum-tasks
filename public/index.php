<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
initializeDatabase();

$appName = getAppName();

if (isLoggedIn()) {
    header('Location: /admin/');
    exit();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/admin.css?v=21" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7 col-xl-6">
            <div class="surface surface-pad text-center" style="padding: 2.5rem 2rem;">
                <div class="mb-3" style="font-size: 2.5rem; color: var(--st-accent);">
                    <i class="bi bi-stack"></i>
                </div>
                <h1 class="h3 mb-2"><?= htmlspecialchars($appName) ?></h1>
                <p class="text-muted mb-4">Internal task management with rich metadata, project workspaces, and an API-first core.</p>
                <div class="d-flex justify-content-center gap-2 flex-wrap">
                    <a class="btn btn-primary" href="/admin/login.php"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</a>
                    <a class="btn btn-outline-secondary" href="/api/health.php"><i class="bi bi-heart-pulse me-1"></i>API health</a>
                </div>
                <p class="fine-print mt-4 mb-0">The API health endpoint requires API key authentication.</p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
