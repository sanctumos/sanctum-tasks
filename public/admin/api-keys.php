<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();

// Handle create API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    requireCsrfToken();
    $keyName = trim($_POST['key_name'] ?? 'Unnamed Key');
    if ($keyName === '') {
        $keyName = 'Unnamed Key';
    }
    $apiKey = createApiKeyForUser((int)$currentUser['id'], $keyName, (int)$currentUser['id']);
    $message = "API key created successfully!";
    $messageType = 'success';
    $newApiKey = $apiKey; // Store for display
}

// Handle delete API key
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    requireCsrfToken();
    if (isset($_POST['id'])) {
        if (revokeApiKey((int)$_POST['id'])) {
            $message = "API key deleted successfully";
            $messageType = 'success';
        } else {
            $message = "Key not found or already revoked";
            $messageType = 'danger';
        }
    }
}

$apiKeys = getAllApiKeys();

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-4">
    <h1 class="h3 mb-0">API Keys</h1>
    <a class="btn btn-outline-secondary" href="/admin/">Back to Tasks</a>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <strong><?= htmlspecialchars($message) ?></strong>
        <?php if (isset($newApiKey)): ?>
            <div class="mt-3">
                <p class="mb-2"><strong>Your API Key (save this now - it won't be shown again!):</strong></p>
                <div class="bg-dark text-light p-3 rounded font-monospace" style="word-break: break-all;">
                    <?= htmlspecialchars($newApiKey) ?>
                </div>
            </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create New API Key</h2>
        <form method="POST">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="create">
            <div class="row g-2">
                <div class="col-md-8">
                    <label class="form-label">Key Name</label>
                    <input type="text" class="form-control" name="key_name" placeholder="e.g., AI Agent #1" required>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Create Key</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Existing API Keys</h2>
        <?php if (empty($apiKeys)): ?>
            <p class="text-muted">No API keys created yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>Key Name</th>
                        <th>User</th>
                        <th>API Key (partial)</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($apiKeys as $key): ?>
                        <tr>
                            <td><?= htmlspecialchars($key['key_name']) ?></td>
                            <td><?= htmlspecialchars($key['user_username'] ?? '') ?></td>
                            <td class="font-monospace small text-muted">
                                <?= htmlspecialchars($key['api_key_preview'] ?? '') ?>...
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($key['created_at']) ?></td>
                            <td class="small text-muted">
                                <?= $key['last_used'] ? htmlspecialchars($key['last_used']) : '<span class="text-muted">Never</span>' ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this API key? This cannot be undone.');">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$key['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
