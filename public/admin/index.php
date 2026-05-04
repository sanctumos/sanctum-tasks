<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
$currentUser = getCurrentUser();

$status = $_GET['status'] ?? '';
$assignedToUserId = $_GET['assigned_to_user_id'] ?? '';
$priority = $_GET['priority'] ?? '';
$project = $_GET['project'] ?? '';
$q = $_GET['q'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'updated_at';
$sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$statuses = listTaskStatuses();
$users = listUsers(false);
$tasksResult = listTasks([
    'status' => $status ?: null,
    'assigned_to_user_id' => $assignedToUserId,
    'priority' => $priority ?: null,
    'project' => $project ?: null,
    'q' => $q ?: null,
    'sort_by' => $sortBy,
    'sort_dir' => $sortDir,
    'limit' => 250,
    'offset' => 0,
], true);
$tasks = $tasksResult['tasks'];
$projects = listProjects(200);

require __DIR__ . '/_layout_top.php';
?>

<?php
$flashError = $_SESSION['admin_flash_error'] ?? null;
$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
if (isset($_SESSION['admin_flash_error'])) unset($_SESSION['admin_flash_error']);
if (isset($_SESSION['admin_flash_success'])) unset($_SESSION['admin_flash_success']);
?>
<div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-2 mb-4">
    <h1 class="h3 mb-0">Tasks</h1>
    <a class="btn btn-outline-secondary" href="/">Home</a>
</div>
<?php if ($flashError): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($flashError) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($flashSuccess): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($flashSuccess) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form class="row g-3" method="get" action="/admin/">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $status === $s['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['label']) ?> (<?= htmlspecialchars($s['slug']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label">Assigned to</label>
                <select class="form-select" name="assigned_to_user_id">
                    <option value="">Anyone</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (string)$assignedToUserId === (string)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-2">
                <label class="form-label">Priority</label>
                <select class="form-select" name="priority">
                    <option value="" <?= $priority === '' ? 'selected' : '' ?>>Any</option>
                    <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                        <option value="<?= $p ?>" <?= $priority === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label">Project</label>
                <input class="form-control" name="project" value="<?= htmlspecialchars($project) ?>" list="projects-list" placeholder="Project name">
                <datalist id="projects-list">
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?= htmlspecialchars($proj['name']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-12 col-md-6 col-xl-4">
                <label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Title or body...">
            </div>
            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                <label class="form-label">Sort by</label>
                <select class="form-select" name="sort_by">
                    <?php foreach (['updated_at', 'created_at', 'due_at', 'priority', 'rank', 'status', 'title'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $sortBy === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-md-2 col-xl-1">
                <label class="form-label">Dir</label>
                <select class="form-select" name="sort_dir">
                    <option value="DESC" <?= $sortDir === 'DESC' ? 'selected' : '' ?>>DESC</option>
                    <option value="ASC" <?= $sortDir === 'ASC' ? 'selected' : '' ?>>ASC</option>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-4 col-xl-3 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-end gap-2">
                <button class="btn btn-primary flex-grow-1 flex-sm-grow-0" type="submit">Filter</button>
                <a class="btn btn-outline-secondary flex-grow-1 flex-sm-grow-0" href="/admin/">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create task</h2>
        <form method="post" action="/admin/create.php">
            <?= csrfInputField() ?>
            <div class="row g-3 mb-2">
                <div class="col-12 col-lg-5">
                    <label class="form-label">Title</label>
                    <input class="form-control" name="title" required>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['slug']) ?>" <?= ((int)$s['is_default'] === 1) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['slug']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label">Assign to</label>
                    <select class="form-select" name="assigned_to_user_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label">Priority</label>
                    <select class="form-select" name="priority">
                        <option value="low">low</option>
                        <option value="normal" selected>normal</option>
                        <option value="high">high</option>
                        <option value="urgent">urgent</option>
                    </select>
                </div>
            </div>
            <div class="row g-3 mb-2">
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label">Due at (UTC)</label>
                    <input class="form-control" name="due_at" type="datetime-local">
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label">Project</label>
                    <input class="form-control" name="project" placeholder="e.g. Platform">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label">Rank</label>
                    <input class="form-control" name="rank" type="number" value="0">
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label">Tags (comma-separated)</label>
                    <input class="form-control" name="tags" placeholder="backend,urgent,infra">
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label">Recurrence rule (optional)</label>
                <input class="form-control" name="recurrence_rule" placeholder="FREQ=WEEKLY;BYDAY=MO,WE,FR">
            </div>
            <div class="mb-2">
                <label class="form-label">Body</label>
                <textarea class="form-control" name="body" rows="4" placeholder="Task description/details..."></textarea>
            </div>
            <div>
                <button class="btn btn-success" type="submit">Create</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Task list (<?= count($tasks) ?> / total <?= (int)$tasksResult['total'] ?>)</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Due</th>
                    <th>Project</th>
                    <th>Rank</th>
                    <th>Created by</th>
                    <th>Assigned to</th>
                    <th>Signals</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td><?= (int)$t['id'] ?></td>
                        <td><?= htmlspecialchars($t['title']) ?></td>
                        <td>
                            <form method="post" action="/admin/update.php" class="task-inline-edit d-flex flex-column flex-xl-row gap-2 align-items-stretch">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm flex-grow-1" name="status">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $t['status'] === $s['slug'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['slug']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary flex-shrink-0" type="submit">Save</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="task-inline-edit d-flex flex-column flex-xl-row gap-2 align-items-stretch">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm flex-grow-1" name="priority">
                                    <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                        <option value="<?= $p ?>" <?= ($t['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary flex-shrink-0" type="submit">Save</button>
                            </form>
                        </td>
                        <td class="small"><?= htmlspecialchars((string)($t['due_at'] ?? '')) ?></td>
                        <td class="small"><?= htmlspecialchars((string)($t['project'] ?? '')) ?></td>
                        <td class="small"><?= (int)($t['rank'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($t['created_by_username'] ?? '') ?> (<?= (int)$t['created_by_user_id'] ?>)</td>
                        <td>
                            <form method="post" action="/admin/update.php" class="task-inline-edit d-flex flex-column flex-xl-row gap-2 align-items-stretch">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm flex-grow-1" name="assigned_to_user_id">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (string)($t['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary flex-shrink-0" type="submit">Save</button>
                            </form>
                        </td>
                        <td class="small text-muted">
                            C <?= (int)($t['comment_count'] ?? 0) ?> /
                            A <?= (int)($t['attachment_count'] ?? 0) ?> /
                            W <?= (int)($t['watcher_count'] ?? 0) ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($t['updated_at']) ?></td>
                        <td class="text-start text-md-end">
                            <div class="d-flex flex-column flex-md-row gap-1 gap-md-2 justify-content-md-end">
                                <a class="btn btn-sm btn-outline-primary" href="/admin/view.php?id=<?= (int)$t['id'] ?>">View</a>
                                <form method="post" action="/admin/delete.php" class="m-0" onsubmit="return confirm('Delete task #<?= (int)$t['id'] ?>?');">
                                    <?= csrfInputField() ?>
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger w-100" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="12" class="text-muted">No tasks found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>

