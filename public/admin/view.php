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

$statuses = listTaskStatuses();
$users = listUsers();
$tagsText = !empty($task['tags']) ? implode(', ', $task['tags']) : '';
$dueAtValue = '';
if (!empty($task['due_at'])) {
    try {
        $dueAtValue = (new DateTime((string)$task['due_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        $dueAtValue = '';
    }
}

require __DIR__ . '/_layout_top.php';
?>

<?php
$statusBadgeClass = ((int)($task['status_is_done'] ?? 0) === 1)
    ? 'success'
    : (($task['status'] ?? '') === 'doing' ? 'warning' : 'secondary');
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
                <span class="badge bg-<?= $statusBadgeClass ?>">
                    <?= htmlspecialchars($task['status_label'] ?? $task['status']) ?>
                </span>
            </dd>

            <dt class="col-sm-3">Priority</dt>
            <dd class="col-sm-9"><?= htmlspecialchars((string)($task['priority'] ?? 'normal')) ?></dd>

            <dt class="col-sm-3">Due at</dt>
            <dd class="col-sm-9"><?= htmlspecialchars((string)($task['due_at'] ?? '')) ?></dd>

            <dt class="col-sm-3">Project</dt>
            <dd class="col-sm-9"><?= htmlspecialchars((string)($task['project'] ?? '')) ?></dd>

            <dt class="col-sm-3">Rank</dt>
            <dd class="col-sm-9"><?= (int)($task['rank'] ?? 0) ?></dd>

            <dt class="col-sm-3">Tags</dt>
            <dd class="col-sm-9">
                <?php if (!empty($task['tags'])): ?>
                    <?php foreach ($task['tags'] as $tag): ?>
                        <span class="badge text-bg-light border me-1"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">No tags</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Recurrence</dt>
            <dd class="col-sm-9"><?= htmlspecialchars((string)($task['recurrence_rule'] ?? '')) ?></dd>
            
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
            
            <dt class="col-sm-3">Body</dt>
            <dd class="col-sm-9">
                <?php if ($task['body']): ?>
                    <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;"><?= htmlspecialchars($task['body']) ?></div>
                <?php else: ?>
                    <span class="text-muted">No body content</span>
                <?php endif; ?>
            </dd>

            <dt class="col-sm-3">Comments</dt>
            <dd class="col-sm-9"><?= (int)($task['comment_count'] ?? 0) ?></dd>

            <dt class="col-sm-3">Attachments</dt>
            <dd class="col-sm-9"><?= (int)($task['attachment_count'] ?? 0) ?></dd>

            <dt class="col-sm-3">Watchers</dt>
            <dd class="col-sm-9"><?= (int)($task['watcher_count'] ?? 0) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Update Task</h2>
        <form method="post" action="/admin/update.php">
            <?= csrfInputField() ?>
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Title</label>
                    <input class="form-control" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $task['status'] === $s['slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['slug']) ?>
                            </option>
                        <?php endforeach; ?>
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
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select class="form-select" name="priority">
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($task['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Due at (UTC)</label>
                    <input class="form-control" type="datetime-local" name="due_at" value="<?= htmlspecialchars($dueAtValue) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Project</label>
                    <input class="form-control" name="project" value="<?= htmlspecialchars((string)($task['project'] ?? '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rank</label>
                    <input class="form-control" type="number" name="rank" value="<?= (int)($task['rank'] ?? 0) ?>">
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="form-label">Tags (comma-separated)</label>
                    <input class="form-control" name="tags" value="<?= htmlspecialchars($tagsText) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Recurrence rule</label>
                    <input class="form-control" name="recurrence_rule" value="<?= htmlspecialchars((string)($task['recurrence_rule'] ?? '')) ?>">
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label">Body</label>
                <textarea class="form-control" name="body" rows="6" placeholder="Task description/details..."><?= htmlspecialchars($task['body'] ?? '') ?></textarea>
            </div>
            <div>
                <button class="btn btn-primary" type="submit">Update Task</button>
                <a class="btn btn-outline-secondary" href="/admin/">Cancel</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3 text-danger">Danger Zone</h2>
        <form method="post" action="/admin/delete.php" onsubmit="return confirm('Are you sure you want to delete task #<?= (int)$task['id'] ?>? This cannot be undone.');">
            <?= csrfInputField() ?>
            <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
            <button class="btn btn-danger" type="submit">Delete Task</button>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Collaboration</h2>
        <div class="row">
            <div class="col-md-4">
                <h3 class="h6">Watchers (<?= count($task['watchers'] ?? []) ?>)</h3>
                <?php if (!empty($task['watchers'])): ?>
                    <ul class="small">
                        <?php foreach ($task['watchers'] as $watcher): ?>
                            <li><?= htmlspecialchars($watcher['username']) ?> (#<?= (int)$watcher['user_id'] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted small">No watchers</p>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <h3 class="h6">Attachments (<?= count($task['attachments'] ?? []) ?>)</h3>
                <?php if (!empty($task['attachments'])): ?>
                    <ul class="small">
                        <?php foreach ($task['attachments'] as $attachment): ?>
                            <li><a href="<?= htmlspecialchars($attachment['file_url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($attachment['file_name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted small">No attachments</p>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <h3 class="h6">Recent comments (<?= count($task['comments'] ?? []) ?>)</h3>
                <?php if (!empty($task['comments'])): ?>
                    <ul class="small">
                        <?php foreach (array_slice($task['comments'], -5) as $comment): ?>
                            <li><strong><?= htmlspecialchars($comment['username']) ?>:</strong> <?= htmlspecialchars($comment['comment']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted small">No comments</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
