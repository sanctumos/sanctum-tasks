<?php
/**
 * Organizations — admin/manager; create workspace boundaries and rename orgs.
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

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = (string)($_POST['name'] ?? '');
        $result = createOrganization($name, (int)$currentUser['id']);
        if ($result['success']) {
            $message = 'Organization created.';
        } else {
            $message = $result['error'] ?? 'Create failed';
            $messageType = 'danger';
        }
    } elseif ($action === 'rename') {
        $orgId = (int)($_POST['org_id'] ?? 0);
        $name = (string)($_POST['name'] ?? '');
        $result = updateOrganizationName($orgId, $name, (int)$currentUser['id']);
        if ($result['success']) {
            $message = 'Organization renamed.';
        } else {
            $message = $result['error'] ?? 'Rename failed';
            $messageType = 'danger';
        }
    }
}

$orgs = listOrganizationsWithStats();

$pageTitle = 'Organizations';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Organizations</h1>
        <div class="subtitle">Separate workspaces — users and projects belong to exactly one organization</div>
    </div>
    <div class="page-header__actions">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newOrgModal"><i class="bi bi-plus-lg me-1"></i>New organization</button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show"><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="surface mb-4">
    <table class="task-table">
        <thead>
            <tr>
                <th>Name</th>
                <th style="width: 110px;">Users</th>
                <th style="width: 110px;">Projects</th>
                <th style="width: 200px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orgs as $o): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($o['name']) ?></strong><div class="text-muted small">ID <?= (int)$o['id'] ?></div></td>
                    <td><?= (int)($o['user_count'] ?? 0) ?></td>
                    <td><?= (int)($o['project_count'] ?? 0) ?></td>
                    <td class="task-actions">
                        <form method="post" action="/admin/organizations.php" class="d-flex flex-wrap gap-2 align-items-center m-0">
                            <?= csrfInputField() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="org_id" value="<?= (int)$o['id'] ?>">
                            <input class="form-control form-control-sm" name="name" value="<?= htmlspecialchars($o['name']) ?>" style="max-width: 220px;" maxlength="200">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save name</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="surface surface-pad fine-print mb-5">
    <p class="mb-2"><strong>How tenancy works:</strong></p>
    <ul class="mb-0 small">
        <li>Each user has an <strong>organization</strong> — they only appear in membership pickers inside that org’s projects.</li>
        <li>Changing a user’s org removes their memberships on projects in other organizations.</li>
        <li><strong>Limit to assigned projects</strong> on the Users screen forces managers (and applies to everyone except admins) to only see directory projects where they’re a member or the project has <strong>all-access</strong>.</li>
    </ul>
</div>

<div class="modal fade" id="newOrgModal" tabindex="-1" aria-labelledby="newOrgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/organizations.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="newOrgModalLabel">New organization</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="create">
                    <label class="form-label">Name</label>
                    <input class="form-control form-control-lg" name="name" required maxlength="200" placeholder="e.g. Acme Corp" autofocus>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
