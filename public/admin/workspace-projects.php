<?php
/**
 * Workspace project directory (first-class projects). Uses same rules as /api/list-directory-projects.php
 * and /api/create-directory-project.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
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
        $name = trim((string)($_POST['name'] ?? ''));
        $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $description = $description === '' ? null : $description;
        $clientVisible = isset($_POST['client_visible']);
        $allAccess = isset($_POST['all_access']);
        $result = createDirectoryProject((int)$currentUser['id'], $name, $description, $clientVisible, $allAccess);
        if ($result['success']) {
            $newProjectId = (int)($result['id'] ?? 0);
            if ($newProjectId > 0) {
                header('Location: /admin/project.php?id=' . $newProjectId);
                exit();
            }
            $message = 'Project created.';
        } else {
            $message = $result['error'] ?? 'Could not create project';
            $messageType = 'danger';
        }
    }
}

$projects = listDirectoryProjectsForUser($currentUser, 300);

$orgLabel = '';
$oid = isset($currentUser['org_id']) ? (int)$currentUser['org_id'] : 0;
if ($oid > 0 && ($og = getOrganizationById($oid))) {
    $orgLabel = (string)$og['name'];
}

$pageTitle = 'Projects';
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Projects</h1>
        <div class="subtitle">
            <?php if ($orgLabel !== ''): ?><span class="text-body"><?= htmlspecialchars($orgLabel) ?></span> · <?php endif; ?>
            <?= count($projects) ?> <?= $orgLabel !== '' ? 'you can access' : 'in your directory' ?>
        </div>
    </div>
    <div class="page-header__actions">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newProjectModal"><i class="bi bi-plus-lg me-1"></i>New project</button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if (!$projects): ?>
    <div class="surface surface-pad text-center">
        <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-kanban"></i></div>
        <h2 class="h5 mb-1">No projects yet</h2>
        <p class="text-muted small mb-3">Group related tasks under a project to give collaborators a workspace.</p>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newProjectModal"><i class="bi bi-plus-lg me-1"></i>Create your first project</button>
    </div>
<?php else: ?>
    <div class="board" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
        <?php foreach ($projects as $p): ?>
            <a class="task-card" href="/admin/project.php?id=<?= (int)$p['id'] ?>" style="display: flex; flex-direction: column; gap: 0.4rem;">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="task-card__title mb-0"><?= htmlspecialchars($p['name']) ?></span>
                    <span class="status-pill status-pill--<?= $p['status'] === 'active' ? 'doing' : ($p['status'] === 'archived' ? 'todo' : 'blocked') ?>"><?= htmlspecialchars($p['status']) ?></span>
                </div>
                <?php if (!empty($p['description'])): ?>
                    <div class="text-muted small" style="line-height: 1.35;"><?= htmlspecialchars($p['description']) ?></div>
                <?php endif; ?>
                <div class="task-card__meta">
                    <?php if (!empty($p['all_access'])): ?>
                        <span><i class="bi bi-globe"></i> all-access</span>
                    <?php endif; ?>
                    <?php if (!empty($p['client_visible'])): ?>
                        <span><i class="bi bi-eye"></i> client-visible</span>
                    <?php endif; ?>
                </div>
                <div class="task-card__footer mt-auto">
                    <span class="text-muted small">Updated <?= st_relative_time($p['updated_at'] ?? null) ?></span>
                    <span class="small" style="color: var(--st-accent);">Open <i class="bi bi-arrow-right-short"></i></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php /* New project modal */ ?>
<div class="modal fade" id="newProjectModal" tabindex="-1" aria-labelledby="newProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/workspace-projects.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="newProjectModalLabel">New project</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input class="form-control form-control-lg" name="name" required maxlength="200" placeholder="e.g. Website redesign" autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description <span class="fine-print">(optional)</span></label>
                        <input class="form-control" name="description" placeholder="Short summary so the team knows what this is">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="client_visible" id="cvm" value="1">
                        <label class="form-check-label" for="cvm">Client-visible</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="all_access" id="aam" value="1">
                        <label class="form-check-label" for="aam">All-access (everyone in the org sees it)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Create project</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
