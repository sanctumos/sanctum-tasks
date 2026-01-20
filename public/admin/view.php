<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/');
    exit();
}

$task = getTaskById($id);
if (!$task) {
    header('Location: /admin/');
    exit();
}

$users = listUsers();

require __DIR__ . '/_layout_top.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0">Task #<?= (int)$task['id'] ?></h1>
    <a class="btn btn-sm btn-outline-secondary" href="/admin/">Back to Tasks</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><?= htmlspecialchars($task['title']) ?></h2>
        
        <dl class="row mb-0">
            <dt class="col-sm-3">ID</dt>
            <dd class="col-sm-9"><?= (int)$task['id'] ?></dd>
            
            <dt class="col-sm-3">Title</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($task['title']) ?></dd>
            
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <span class="badge bg-<?= $task['status'] === 'done' ? 'success' : ($task['status'] === 'doing' ? 'warning' : 'secondary') ?>">
                    <?= htmlspecialchars($task['status']) ?>
                </span>
            </dd>
            
            <dt class="col-sm-3">Created by</dt>
            <dd class="col-sm-9">
                <?= htmlspecialchars($task['created_by_username'] ?? '') ?> (ID: <?= (int)$task['created_by_user_id'] ?>)
            </dd>
            
            <dt class="col-sm-3">Assigned to</dt>
            <dd class="col-sm-9">
                <?php if ($task['assigned_to_user_id']): ?>
                    <?= htmlspecialchars($task['assigned_to_username'] ?? '') ?> (ID: <?= (int)$task['assigned_to_user_id'] ?>)
                <?php else: ?>
                    <span class="text-muted">Unassigned</span>
                <?php endif; ?>
            </dd>
            
            <dt class="col-sm-3">Created at</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($task['created_at']) ?></dd>
            
            <dt class="col-sm-3">Updated at</dt>
            <dd class="col-sm-9"><?= htmlspecialchars($task['updated_at']) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Update Task</h2>
        <form method="post" action="/admin/update.php">
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label">Title</label>
                    <input class="form-control" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="todo" <?= $task['status'] === 'todo' ? 'selected' : '' ?>>todo</option>
                        <option value="doing" <?= $task['status'] === 'doing' ? 'selected' : '' ?>>doing</option>
                        <option value="done" <?= $task['status'] === 'done' ? 'selected' : '' ?>>done</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Assign to</label>
                    <select class="form-select" name="assigned_to_user_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (string)($task['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?> (<?= (int)$u['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Update Task</button>
                    <a class="btn btn-outline-secondary" href="/admin/">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3 text-danger">Danger Zone</h2>
        <form method="post" action="/admin/delete.php" onsubmit="return confirm('Are you sure you want to delete task #<?= (int)$task['id'] ?>? This cannot be undone.');">
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <button class="btn btn-danger" type="submit">Delete Task</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
