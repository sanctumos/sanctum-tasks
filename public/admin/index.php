<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();
$currentUser = getCurrentUser();

$status = $_GET['status'] ?? '';
$assignedToUserId = $_GET['assigned_to_user_id'] ?? '';

$users = listUsers();
$tasks = listTasks([
    'status' => $status ?: null,
    'assigned_to_user_id' => $assignedToUserId,
    'limit' => 200,
    'offset' => 0,
]);

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Tasks</h1>
    <a class="btn btn-sm btn-outline-secondary" href="/">Home</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form class="row g-2" method="get" action="/admin/">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                    <option value="todo" <?= $status === 'todo' ? 'selected' : '' ?>>todo</option>
                    <option value="doing" <?= $status === 'doing' ? 'selected' : '' ?>>doing</option>
                    <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>done</option>
                </select>
            </div>
            <div class="col-md-4">
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
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button class="btn btn-primary" type="submit">Filter</button>
                <a class="btn btn-outline-secondary" href="/admin/">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create task</h2>
        <form class="row g-2" method="post" action="/admin/create.php">
            <div class="col-md-6">
                <label class="form-label">Title</label>
                <input class="form-control" name="title" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="todo">todo</option>
                    <option value="doing">doing</option>
                    <option value="done">done</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Assign to</label>
                <select class="form-select" name="assigned_to_user_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-success" type="submit">Create</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Task list (<?= count($tasks) ?>)</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Assigned to</th>
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
                            <form method="post" action="/admin/update.php" class="d-flex gap-2">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm" name="status" style="width: 120px;">
                                    <option value="todo" <?= $t['status'] === 'todo' ? 'selected' : '' ?>>todo</option>
                                    <option value="doing" <?= $t['status'] === 'doing' ? 'selected' : '' ?>>doing</option>
                                    <option value="done" <?= $t['status'] === 'done' ? 'selected' : '' ?>>done</option>
                                </select>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?= htmlspecialchars($t['created_by_username'] ?? '') ?> (<?= (int)$t['created_by_user_id'] ?>)</td>
                        <td>
                            <form method="post" action="/admin/update.php" class="d-flex gap-2">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm" name="assigned_to_user_id" style="width: 180px;">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (string)($t['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($t['updated_at']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/admin/view.php?id=<?= (int)$t['id'] ?>">View</a>
                            <form method="post" action="/admin/delete.php" class="d-inline" onsubmit="return confirm('Delete task #<?= (int)$t['id'] ?>?');">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="7" class="text-muted">No tasks found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>

