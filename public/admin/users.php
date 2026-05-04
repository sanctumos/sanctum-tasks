<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

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
        $personKind = normalizePersonKind((string)($_POST['person_kind'] ?? 'team_member'));
        $orgIdCreate = isset($_POST['org_id']) ? (int)$_POST['org_id'] : 0;
        $orgIdCreate = $orgIdCreate > 0 ? $orgIdCreate : null;
        $limitedCreate = isset($_POST['limited_project_access']);
        $result = createUser($username, $password, $role, $mustChange, $orgIdCreate, $personKind, $limitedCreate);
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
            $message = 'Password reset successfully. Communicate the new password to the user via a secure channel.';
            $messageType = 'success';
            createAuditLog((int)$currentUser['id'], 'admin.user_password_reset', 'user', (string)$userId);
        } else {
            $message = $result['error'] ?? 'Failed to reset password';
            $messageType = 'danger';
        }
    } elseif ($action === 'update_workspace') {
        $userId = (int)($_POST['id'] ?? 0);
        $orgId = (int)($_POST['org_id'] ?? 0);
        if ($userId <= 0 || $orgId <= 0) {
            $message = 'Invalid user or organization';
            $messageType = 'danger';
        } else {
            $resOrg = setUserOrganization((int)$currentUser['id'], $userId, $orgId);
            if (!$resOrg['success']) {
                $message = $resOrg['error'] ?? 'Failed to set organization';
                $messageType = 'danger';
            } else {
                $tgt = getUserById($userId, false);
                $wantLimited = isset($_POST['limited_project_access']) && $tgt && (string)($tgt['role'] ?? '') !== 'admin';
                setUserLimitedProjectAccess((int)$currentUser['id'], $userId, $wantLimited);
                $message = 'Workspace access updated.';
            }
        }
    }
}

$users = listUsers(true);
$orgDirectory = listOrganizations();

$pageTitle = 'Users';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Users</h1>
        <div class="subtitle"><?= count($users) ?> in this workspace</div>
    </div>
    <div class="page-header__actions">
        <a class="btn btn-sm btn-outline-secondary" href="/admin/organizations.php"><i class="bi bi-building me-1"></i>Organizations</a>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/settings.php?tab=audit"><i class="bi bi-shield-check me-1"></i>Audit log</a>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newUserModal"><i class="bi bi-person-plus me-1"></i>New user</button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="surface">
    <table class="task-table">
        <thead>
            <tr>
                <th>Username</th>
                <th style="min-width: 148px;">Organization</th>
                <th style="min-width: 140px;">Project scope</th>
                <th style="width: 96px;">Role</th>
                <th style="width: 110px;">Person kind</th>
                <th style="width: 72px;">Active</th>
                <th style="width: 80px;">MFA</th>
                <th style="width: 96px;">Must change</th>
                <th style="width: 120px;">Created</th>
                <th style="min-width: 260px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                        <div class="text-muted small">User #<?= (int)$u['id'] ?></div>
                    </td>
                    <td class="small">
                        <?php if ($orgDirectory !== []): ?>
                            <form method="post" action="/admin/users.php" class="d-flex flex-column gap-1 align-items-start">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="update_workspace">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <select class="form-select form-select-sm" name="org_id" aria-label="Organization for <?= htmlspecialchars($u['username']) ?>">
                                    <?php foreach ($orgDirectory as $o): ?>
                                        <option value="<?= (int)$o['id'] ?>" <?= isset($u['org_id']) && (int)$u['org_id'] === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ((string)($u['role'] ?? '') !== 'admin'): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="limited_project_access" value="1" id="lim_<?= (int)$u['id'] ?>" <?= !empty((int)($u['limited_project_access'] ?? 0)) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="lim_<?= (int)$u['id'] ?>">Limit projects</label>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Admin sees all org projects</span>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">No orgs</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ((string)($u['role'] ?? '') === 'admin'): ?>
                            <span class="status-pill status-pill--done">All projects</span>
                        <?php elseif (!empty((int)($u['limited_project_access'] ?? 0))): ?>
                            <span class="status-pill status-pill--doing">Assigned only</span>
                        <?php elseif (in_array((string)($u['role'] ?? ''), ['manager'], true)): ?>
                            <span class="status-pill status-pill--todo">Org-wide</span>
                        <?php else: ?>
                            <span class="status-pill status-pill--doing">Member routing</span>
                        <?php endif; ?>
                        <div class="mt-1"><a class="small" href="/admin/user-projects.php?id=<?= (int)$u['id'] ?>"><i class="bi bi-kanban me-1"></i>Edit projects</a></div>
                    </td>
                    <td><span class="tag-chip"><?= htmlspecialchars($u['role']) ?></span></td>
                    <td class="small"><?= htmlspecialchars($u['person_kind'] ?? 'team_member') ?></td>
                    <td>
                        <?php if ((int)$u['is_active'] === 1): ?>
                            <span class="status-pill status-pill--done">Yes</span>
                        <?php else: ?>
                            <span class="status-pill status-pill--blocked">No</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= (int)$u['mfa_enabled'] === 1 ? 'Enabled' : 'Disabled' ?></td>
                    <td class="small"><?= (int)$u['must_change_password'] === 1 ? 'Yes' : 'No' ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($u['created_at']) ?></td>
                    <td class="task-actions">
                        <form method="post" action="/admin/users.php" class="d-inline m-0">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= (int)$u['is_active'] === 1 ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-<?= (int)$u['is_active'] === 1 ? 'warning' : 'success' ?>" type="submit">
                                <?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <form method="post" action="/admin/users.php" class="d-inline m-0" onsubmit="return confirm('Reset password for <?= htmlspecialchars($u['username']) ?>?');">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="new_password" value="">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-key me-1"></i>Reset</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php /* New user modal */ ?>
<div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/users.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="newUserModalLabel">New user</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Temporary password</label>
                        <input class="form-control" type="password" name="password" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role">
                                <?php foreach (['member', 'manager', 'admin', 'api'] as $role): ?>
                                    <option value="<?= $role ?>" <?= $role === 'member' ? 'selected' : '' ?>><?= $role ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Person kind</label>
                            <select class="form-select" name="person_kind">
                                <option value="team_member" selected>team_member</option>
                                <option value="client">client</option>
                            </select>
                        </div>
                    </div>
                    <?php if ($orgDirectory !== []): ?>
                        <div class="mb-3">
                            <label class="form-label">Organization</label>
                            <select class="form-select" name="org_id" required>
                                <?php foreach ($orgDirectory as $o): ?>
                                    <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="limitedNew" name="limited_project_access" value="1">
                        <label class="form-check-label" for="limitedNew">Limit to assigned projects (applies managers &amp; members)</label>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="mustChangePassword" name="must_change_password" value="1" checked>
                        <label class="form-check-label" for="mustChangePassword">Require password change on first login</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
