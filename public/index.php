<?php
require_once __DIR__ . '/includes/config.php';
initializeDatabase();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>tasks.technonomicon.net</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h3 mb-3">tasks.technonomicon.net</h1>
            <p class="mb-4">Internal task management system (API-first) with admin UI, API key automation, and rich task metadata.</p>
            <div class="d-flex gap-2">
                <a class="btn btn-primary" href="/admin/">Admin UI</a>
                <a class="btn btn-outline-secondary" href="/api/health.php">API Health</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>

