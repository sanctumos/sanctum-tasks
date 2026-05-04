<?php
/**
 * Workspace project directory (first-class projects). Uses same rules as /api/list-directory-projects.php
 * and /api/create-directory-project.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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
            $message = 'Project created.';
            $messageType = 'success';
        } else {
            $message = $result['error'] ?? 'Could not create project';
            $messageType = 'danger';
        }
    }
}

$projects = listDirectoryProjectsForUser($currentUser, 300);

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-4">
    <h1 class="h3 mb-0">Workspace projects</h1>
    <a class="btn btn-outline-secondary" href="/admin/">Back to Tasks</a>
</div>

<p class="text-muted small">
    These are <strong>project records</strong> in your organization (for access and the project directory).
    Tasks can link via <code>project_id</code> / legacy text <strong>Project</strong>; API and FastAPI expose both.
</p>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create project</h2>
        <form method="post" action="/admin/workspace-projects.php">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="create">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="name" required maxlength="200" placeholder="e.g. Website redesign">
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label">Description (optional)</label>
                    <input class="form-control" name="description" placeholder="Short summary">
                </div>
                <div class="col-12 col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="client_visible" id="cv" value="1">
                        <label class="form-check-label" for="cv">Client-visible flag</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="all_access" id="aa" value="1">
                        <label class="form-check-label" for="aa">All-access (everyone in org sees it)</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-2">Create project</button>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Your project directory</h2>
        <?php if (!$projects): ?>
            <p class="text-muted mb-0">No projects yet — create one above or use the API.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Client vis.</th>
                        <th>All-access</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projects as $p): ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= htmlspecialchars($p['status']) ?></td>
                            <td><?= !empty($p['client_visible']) ? 'Yes' : 'No' ?></td>
                            <td><?= !empty($p['all_access']) ? 'Yes' : 'No' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($p['updated_at'] ?? '') ?></td>
                            <td><a class="btn btn-sm btn-outline-primary" href="/admin/workspace-project.php?id=<?= (int)$p['id'] ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
