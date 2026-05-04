<?php
/**
 * Settings tab: API keys (admin only).
 * Expects: $currentUser loaded; caller already enforced admin role.
 */

$apikey_message = null;
$apikey_messageType = 'success';
$apikey_revealed = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'api_key_create') {
    requireCsrfToken();
    $keyName = trim((string)($_POST['key_name'] ?? 'Unnamed Key'));
    if ($keyName === '') $keyName = 'Unnamed Key';
    $apikey_revealed = createApiKeyForUser((int)$currentUser['id'], $keyName, (int)$currentUser['id']);
    $apikey_message = 'API key created.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['settings_action'] ?? '') === 'api_key_delete') {
    requireCsrfToken();
    if (isset($_POST['id'])) {
        if (revokeApiKey((int)$_POST['id'])) {
            $apikey_message = 'API key revoked.';
        } else {
            $apikey_message = 'Key not found or already revoked.';
            $apikey_messageType = 'danger';
        }
    }
}

$apiKeys = getAllApiKeys();
?>

<?php if ($apikey_revealed): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <strong><i class="bi bi-check-circle-fill me-1"></i>API key created.</strong>
        <div class="mt-2 small">Save this now — it won't be shown again.</div>
        <pre class="bg-dark text-light p-3 rounded font-monospace small mt-2 mb-0" style="white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($apikey_revealed) ?></pre>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php elseif ($apikey_message): ?>
    <div class="alert alert-<?= htmlspecialchars($apikey_messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($apikey_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <span class="fine-print"><?= count($apiKeys) ?> issued</span>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newKeyModal"><i class="bi bi-key me-1"></i>New key</button>
</div>

<div class="surface">
    <table class="task-table">
        <thead>
            <tr>
                <th>Key name</th>
                <th>User</th>
                <th>Preview</th>
                <th>Created</th>
                <th>Last used</th>
                <th style="text-align: right; width: 100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($apiKeys as $key): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($key['key_name']) ?></strong></td>
                    <td class="small"><?= htmlspecialchars($key['user_username'] ?? '') ?></td>
                    <td class="font-monospace small text-muted"><?= htmlspecialchars($key['api_key_preview'] ?? '') ?>…</td>
                    <td class="small text-muted"><?= htmlspecialchars($key['created_at']) ?></td>
                    <td class="small text-muted"><?= $key['last_used'] ? htmlspecialchars($key['last_used']) : '<span class="text-muted">Never</span>' ?></td>
                    <td class="task-actions">
                        <form method="post" action="/admin/settings.php?tab=api-keys" class="d-inline m-0" onsubmit="return confirm('Revoke this API key? This cannot be undone.');">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="settings_action" value="api_key_delete">
                            <input type="hidden" name="id" value="<?= (int)$key['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($apiKeys)): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">No API keys yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="newKeyModal" tabindex="-1" aria-labelledby="newKeyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/settings.php?tab=api-keys">
                <div class="modal-header">
                    <h5 class="modal-title" id="newKeyModalLabel">New API key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="settings_action" value="api_key_create">
                    <label class="form-label">Key name</label>
                    <input type="text" class="form-control form-control-lg" name="key_name" placeholder="e.g. Otto agent" required autofocus>
                    <p class="fine-print mt-2 mb-0">The full key will only be shown once after creation. Treat it like a password.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Create key</button>
                </div>
            </form>
        </div>
    </div>
</div>
