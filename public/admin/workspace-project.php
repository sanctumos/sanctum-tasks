<?php
/**
 * Single workspace (directory) project: update, members, to-do lists, pin.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$project = $id > 0 ? getDirectoryProjectById($id) : null;
if (!$project || !userCanAccessDirectoryProject($currentUser, $project)) {
    header('Location: /admin/workspace-projects.php');
    exit;
}

$canManage = userCanManageDirectoryProject($currentUser, $project);
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    requireCsrfToken();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update') {
        $fields = [
            'name' => (string)($_POST['name'] ?? ''),
            'description' => isset($_POST['description']) ? (string)$_POST['description'] : null,
            'status' => (string)($_POST['status'] ?? 'active'),
            'client_visible' => isset($_POST['client_visible']),
            'all_access' => isset($_POST['all_access']),
        ];
        $result = updateDirectoryProject((int)$currentUser['id'], $id, $fields);
        if ($result['success']) {
            $message = 'Project updated.';
            $messageType = 'success';
            $project = getDirectoryProjectById($id);
        } else {
            $message = $result['error'] ?? 'Update failed';
            $messageType = 'danger';
        }
    } elseif ($action === 'add_member') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $role = (string)($_POST['member_role'] ?? 'member');
        $result = addProjectMember((int)$currentUser['id'], $id, $uid, $role);
        if ($result['success']) {
            $message = 'Member added or updated.';
        } else {
            $message = $result['error'] ?? 'Could not add member';
            $messageType = 'danger';
        }
    } elseif ($action === 'remove_member') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $result = removeProjectMember((int)$currentUser['id'], $id, $uid);
        if ($result['success']) {
            $message = 'Member removed.';
        } else {
            $message = $result['error'] ?? 'Could not remove';
            $messageType = 'danger';
        }
    } elseif ($action === 'create_list') {
        $name = trim((string)($_POST['list_name'] ?? ''));
        $result = createTodoList((int)$currentUser['id'], $id, $name);
        if ($result['success']) {
            $message = 'To-do list created.';
        } else {
            $message = $result['error'] ?? 'Could not create list';
            $messageType = 'danger';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $message = 'You do not have permission to change this project.';
    $messageType = 'danger';
}

$members = listProjectMembers($id);
$lists = listTodoListsForProject($currentUser, $id);
$orgUsers = [];
foreach (listUsers(false) as $u) {
    if ((int)($u['org_id'] ?? 0) === (int)$project['org_id']) {
        $orgUsers[] = $u;
    }
}

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-4">
    <h1 class="h3 mb-0 text-break"><?= htmlspecialchars($project['name']) ?></h1>
    <a class="btn btn-outline-secondary" href="/admin/workspace-projects.php">All projects</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Details</h2>
        <?php if ($canManage): ?>
            <form method="post" action="/admin/workspace-project.php?id=<?= (int)$id ?>">
                <?= csrfInputField() ?>
                <input type="hidden" name="action" value="update">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name</label>
                        <input class="form-control" name="name" required maxlength="200" value="<?= htmlspecialchars($project['name']) ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach (['active', 'archived', 'trashed'] as $st): ?>
                                <option value="<?= htmlspecialchars($st) ?>" <?= ($project['status'] ?? '') === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <input class="form-control" name="description" value="<?= htmlspecialchars((string)($project['description'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="client_visible" id="cv" value="1" <?= !empty($project['client_visible']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cv">Client-visible</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="all_access" id="aa" value="1" <?= !empty($project['all_access']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="aa">All-access</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Save</button>
            </form>
        <?php else: ?>
            <p class="mb-1"><strong>Status:</strong> <?= htmlspecialchars((string)$project['status']) ?></p>
            <?php if (!empty($project['description'])): ?>
                <p class="text-muted"><?= htmlspecialchars((string)$project['description']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Members</h2>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>User</th><th>Role (project)</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['username']) ?> (<?= htmlspecialchars($m['person_kind']) ?>)</td>
                        <td><?= htmlspecialchars($m['role']) ?></td>
                        <td>
                            <form method="post" action="/admin/workspace-project.php?id=<?= (int)$id ?>" class="d-inline" onsubmit="return confirm('Remove this member?');">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="/admin/workspace-project.php?id=<?= (int)$id ?>" class="row g-2 align-items-end">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="add_member">
            <div class="col-md-5">
                <label class="form-label">Add user</label>
                <select class="form-select" name="user_id" required>
                    <option value="">—</option>
                    <?php foreach ($orgUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['person_kind'] ?? 'team_member') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Role</label>
                <select class="form-select" name="member_role">
                    <option value="member">member</option>
                    <option value="lead">lead</option>
                    <option value="client">client</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-secondary">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">To-do lists</h2>
        <?php if (!$lists): ?>
            <p class="text-muted small">No lists yet.</p>
        <?php else: ?>
            <ul class="mb-3">
                <?php foreach ($lists as $tl): ?>
                    <li><?= htmlspecialchars($tl['name']) ?> <span class="text-muted small">(id <?= (int)$tl['id'] ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/admin/workspace-project.php?id=<?= (int)$id ?>" class="row g-2">
            <?= csrfInputField() ?>
            <input type="hidden" name="action" value="create_list">
            <div class="col-md-6">
                <input class="form-control" name="list_name" placeholder="New list name" required maxlength="200">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary">Create list</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
