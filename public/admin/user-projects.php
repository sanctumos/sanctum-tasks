<?php
/**
 * Admin: bulk edit which directory projects a user can access (project_members rows).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAdmin();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$target = $userId > 0 ? getUserById($userId, false) : null;
if (!$target) {
    header('Location: /admin/users.php');
    exit();
}

$orgId = isset($target['org_id']) ? (int)$target['org_id'] : 0;
$orgName = '—';
if ($orgId > 0 && ($ogr = getOrganizationById($orgId))) {
    $orgName = (string)$ogr['name'];
}

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'sync_projects') {
        $postedIds = isset($_POST['project_id']) && is_array($_POST['project_id']) ? $_POST['project_id'] : [];
        $want = [];
        foreach ($postedIds as $raw) {
            $pid = (int)$raw;
            if ($pid > 0) {
                $want[$pid] = true;
            }
        }
        $allInOrg = listAllDirectoryProjectsInOrganization($orgId, 500);
        $hadError = false;
        foreach ($allInOrg as $proj) {
            $pid = (int)$proj['id'];
            $isMember = getProjectMemberRole($userId, $pid) !== null;
            $should = isset($want[$pid]);
            if ($should && !$isMember) {
                $res = addProjectMember((int)$currentUser['id'], $pid, $userId, 'member');
                if (empty($res['success'])) {
                    $hadError = true;
                }
            } elseif (!$should && $isMember) {
                $res = removeProjectMember((int)$currentUser['id'], $pid, $userId);
                if (empty($res['success'])) {
                    $hadError = true;
                }
            }
        }
        createAuditLog((int)$currentUser['id'], 'admin.user_projects_sync', 'user', (string)$userId, ['project_ids' => array_keys($want)]);
        if ($hadError) {
            $message = 'Saved with some conflicts (e.g. cannot remove sole project lead). Review project membership tabs.';
            $messageType = 'warning';
        } else {
            $message = 'Project access updated.';
        }
        $target = getUserById($userId, false);
    }
}

$projects = $orgId > 0 ? listAllDirectoryProjectsInOrganization($orgId, 500) : [];
$checked = [];
foreach ($projects as $p) {
    if (getProjectMemberRole($userId, (int)$p['id']) !== null) {
        $checked[(int)$p['id']] = true;
    }
}

$pageTitle = 'Projects · ' . $target['username'];
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/users.php', 'Users') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Project access</h1>
        <div class="subtitle">
            <code><?= htmlspecialchars($target['username']) ?></code> · org <?= htmlspecialchars($orgName) ?>
            <?= $orgId > 0 ? '· #' . $orgId : '' ?>
            <?php if (!empty((int)($target['limited_project_access'] ?? 0))): ?>
                · <span class="status-pill status-pill--doing">limited directory</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($orgId <= 0): ?>
    <div class="alert alert-warning">This user has no organization. Assign one on the Users page first.</div>
<?php elseif (!$projects): ?>
    <div class="surface surface-pad"><p class="text-muted mb-0">No projects in this organization yet.</p></div>
<?php else: ?>
    <form method="post" action="/admin/user-projects.php?id=<?= $userId ?>" class="surface surface-pad">
        <?= csrfInputField() ?>
        <input type="hidden" name="action" value="sync_projects">
        <div class="section-title mb-3"><i class="bi bi-kanban"></i> Checked projects <?= $target['username'] ?> is on</div>
        <p class="small text-muted mb-3">Uncheck to remove membership (respects minimum project-lead rules). <strong>All-access</strong> projects remain visible directory-wide regardless of checkbox.</p>
        <div class="row row-cols-1 row-cols-md-2 g-2">
            <?php foreach ($projects as $p): ?>
                <div class="col">
                    <label class="d-flex align-items-start gap-2 border rounded p-3 h-100 bg-white">
                        <input class="form-check-input mt-1" type="checkbox" name="project_id[]" value="<?= (int)$p['id'] ?>" <?= !empty($checked[(int)$p['id']]) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= htmlspecialchars($p['name']) ?></strong>
                            <span class="text-muted small">#<?= (int)$p['id'] ?></span>
                            <?php if (!empty((int)$p['all_access'])): ?>
                                <span class="badge text-bg-secondary ms-1">all-access</span>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save access</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
