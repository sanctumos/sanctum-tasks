<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$message = null;
$messageType = 'success';
$temporaryPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'member');
        $mustChange = isset($_POST['must_change_password']) ? (bool)$_POST['must_change_password'] : true;
        $result = createUser($username, $password, $role, $mustChange);
        if ($result['success']) {
            $message = 'User created successfully';
            $messageType = 'success';
            createAuditLog((int)$currentUser['id'], 'admin.user_create', 'user', (string)$result['id'], ['username' => $username, 'role' => $role]);
        } else {
            $message = $result['error'] ?? 'Failed to create user';
            $messageType = 'danger';
        }
    } elseif ($action === 'toggle_active') {
        $userId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $newState = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : false;
        if ($userId === (int)$currentUser['id'] && !$newState) {
            $message = 'You cannot disable your own account';
            $messageType = 'danger';
        } else {
            $result = setUserActive($userId, $newState);
            if ($result['success']) {
                $message = $newState ? 'User enabled' : 'User disabled';
                $messageType = 'success';
                createAuditLog((int)$currentUser['id'], $newState ? 'admin.user_enable' : 'admin.user_disable', 'user', (string)$userId);
            } else {
                $message = $result['error'] ?? 'Failed to update user';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $newPassword = trim((string)($_POST['new_password'] ?? ''));
        if ($newPassword === '') {
            $newPassword = bin2hex(random_bytes(8));
        }
        $result = resetUserPassword($userId, $newPassword, true);
        if ($result['success']) {
            $message = 'Password reset successfully';
            $messageType = 'success';
            $temporaryPassword = $newPassword;
            createAuditLog((int)$currentUser['id'], 'admin.user_password_reset', 'user', (string)$userId);
        } else {
            $message = $result['error'] ?? 'Failed to reset password';
            $messageType = 'danger';
        }
    }
}

$users = listUsers(true);
$auditLogs = listAuditLogs(50, 0);

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Users</h1>
    <a class="btn btn-sm btn-outline-secondary" href="/admin/">Back to Tasks</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
        <?= htmlspecialchars($message) ?>
        <?php if ($temporaryPassword): ?>
            <div class="mt-2">
                Temporary password:
                <code><?= htmlspecialchars($temporaryPassword) ?></code>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create User</h2>
        <form method="post" action="/admin/users.php">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="create">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Username</label>
                    <input class="form-control" name="username" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Temporary Password</label>
                    <input class="form-control" type="password" name="password" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        <?php foreach (['member', 'manager', 'admin', 'api'] as $role): ?>
                            <option value="<?= $role ?>" <?= $role === 'member' ? 'selected' : '' ?>><?= $role ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Create</button>
                </div>
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="mustChangePassword" name="must_change_password" value="1" checked>
                <label class="form-check-label" for="mustChangePassword">Require password change on first login</label>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Existing Users</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Active</th>
                    <th>MFA</th>
                    <th>Must Change Password</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= (int)$u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td><?= (int)$u['is_active'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= (int)$u['mfa_enabled'] === 1 ? 'Enabled' : 'Disabled' ?></td>
                        <td><?= (int)$u['must_change_password'] === 1 ? 'Yes' : 'No' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($u['created_at']) ?></td>
                        <td class="text-end">
                            <form method="post" action="/admin/users.php" class="d-inline">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= (int)$u['is_active'] === 1 ? '0' : '1' ?>">
                                <button class="btn btn-sm btn-outline-<?= (int)$u['is_active'] === 1 ? 'warning' : 'success' ?>" type="submit">
                                    <?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/users.php" class="d-inline">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="new_password" value="">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Reset Password</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Recent Audit Logs</h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                <tr>
                    <th>When (UTC)</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>IP</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($auditLogs as $log): ?>
                    <tr>
                        <td class="small text-muted"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td><?= htmlspecialchars((string)($log['actor_username'] ?? 'system')) ?></td>
                        <td><code><?= htmlspecialchars($log['action']) ?></code></td>
                        <td><?= htmlspecialchars($log['entity_type']) ?> <?= htmlspecialchars((string)($log['entity_id'] ?? '')) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars((string)($log['ip_address'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$auditLogs): ?>
                    <tr><td colspan="5" class="text-muted">No audit log entries.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
