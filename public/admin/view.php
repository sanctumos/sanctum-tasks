<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

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

$currentUser = getCurrentUser();
if (!$currentUser || !userCanAccessTaskForViewer($currentUser, $task)) {
    header('Location: /admin/');
    exit();
}

$statuses = listTaskStatuses();
$statusMap = [];
foreach ($statuses as $s) { $statusMap[$s['slug']] = $s; }
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

$pageTitle = '#' . (int)$task['id'] . ' ' . substr((string)$task['title'], 0, 50);
require __DIR__ . '/_layout_top.php';
?>

<?= st_back_link('/admin/', 'Tasks') ?>

<div class="page-header">
    <div class="page-header__title">
        <h1><?= htmlspecialchars($task['title']) ?></h1>
        <div class="subtitle">#<?= (int)$task['id'] ?> · opened <?= st_relative_time($task['created_at'] ?? null) ?> by <?= htmlspecialchars($task['created_by_username'] ?? '') ?> · last updated <?= st_relative_time($task['updated_at'] ?? null) ?></div>
    </div>
    <div class="page-header__actions">
        <?= st_status_pill_html($task, $statusMap) ?>
        <?= st_priority_chip_html((string)($task['priority'] ?? 'normal')) ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#editTaskDetails"><i class="bi bi-pencil me-2"></i>Edit details</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="post" action="/admin/delete.php" onsubmit="return confirm('Delete task #<?= (int)$task['id'] ?>? This cannot be undone.');" class="m-0">
                        <?= csrfInputField() ?>
                        <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete task</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">

        <div class="surface surface-pad mb-3">
            <div class="section-title"><i class="bi bi-card-text"></i> Description</div>
            <?php if (!empty($task['body'])): ?>
                <div style="white-space: pre-wrap; color: var(--st-text-primary); font-size: 0.95rem;"><?= htmlspecialchars($task['body']) ?></div>
            <?php else: ?>
                <p class="text-muted small mb-0">No description yet. Add one in <a href="#editTaskDetails">Edit details</a>.</p>
            <?php endif; ?>
        </div>

        <?php
            $watchers = $task['watchers'] ?? [];
            $attachments = $task['attachments'] ?? [];
            $comments = $task['comments'] ?? [];
            $hasCollab = !empty($watchers) || !empty($attachments) || !empty($comments);
        ?>

        <?php if ($hasCollab): ?>
            <div class="surface surface-pad mb-3">
                <div class="section-title"><i class="bi bi-chat-text"></i> Activity</div>
                <?php if (!empty($comments)): ?>
                    <ul class="activity-list">
                        <?php foreach (array_slice($comments, -10) as $comment): ?>
                            <li>
                                <i class="bi bi-chat text-muted"></i>
                                <span><strong><?= htmlspecialchars($comment['username'] ?? '') ?></strong> <?= htmlspecialchars($comment['comment'] ?? '') ?>
                                    <span class="text-muted small ms-1"><?= st_relative_time($comment['created_at'] ?? null) ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($attachments)): ?>
                    <div class="section-title mt-3"><i class="bi bi-paperclip"></i> Attachments <span class="count"><?= count($attachments) ?></span></div>
                    <ul class="activity-list">
                        <?php foreach ($attachments as $a): ?>
                            <li>
                                <i class="bi bi-file-earmark text-muted"></i>
                                <a href="<?= htmlspecialchars($a['file_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($a['file_name']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($watchers)): ?>
                    <div class="section-title mt-3"><i class="bi bi-eye"></i> Watchers <span class="count"><?= count($watchers) ?></span></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($watchers as $w): ?>
                            <span class="tag-chip"><i class="bi bi-person me-1"></i><?= htmlspecialchars($w['username'] ?? '') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="surface surface-pad mb-3">
                <div class="section-title"><i class="bi bi-chat-text"></i> Activity</div>
                <p class="text-muted small mb-0">No comments, attachments, or watchers yet.</p>
            </div>
        <?php endif; ?>

        <div class="surface surface-pad" id="editTaskDetails">
            <div class="section-title"><i class="bi bi-pencil-square"></i> Edit details</div>
            <form method="post" action="/admin/update.php">
                <?= csrfInputField() ?>
                <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                <input type="hidden" name="redirect_to" value="/admin/view.php?id=<?= (int)$task['id'] ?>">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input class="form-control" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Body</label>
                    <textarea class="form-control" name="body" rows="6" placeholder="Task description / details…"><?= htmlspecialchars($task['body'] ?? '') ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Tags <span class="fine-print">(comma-separated)</span></label>
                        <input class="form-control" name="tags" value="<?= htmlspecialchars($tagsText) ?>" placeholder="infra,api,urgent">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Recurrence rule <span class="fine-print">(RRULE)</span></label>
                        <input class="form-control" name="recurrence_rule" value="<?= htmlspecialchars((string)($task['recurrence_rule'] ?? '')) ?>" placeholder="FREQ=WEEKLY;BYDAY=MO,WE,FR">
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Save changes</button>
                    <a class="btn btn-outline-secondary" href="/admin/view.php?id=<?= (int)$task['id'] ?>">Cancel</a>
                </div>
            </form>
        </div>

    </div>

    <div class="col-12 col-lg-4">
        <aside class="metadata-rail">
            <div class="metadata-rail__row">
                <label>Status</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="status">
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $task['status'] === $s['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Priority</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="priority">
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($task['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Assignee</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <select class="form-select form-select-sm js-autosave" name="assigned_to_user_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (string)($task['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Due</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input type="datetime-local" class="form-control form-control-sm js-autosave-blur" name="due_at" value="<?= htmlspecialchars($dueAtValue) ?>">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Project</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input class="form-control form-control-sm js-autosave-blur" name="project" value="<?= htmlspecialchars((string)($task['project'] ?? '')) ?>" placeholder="(none)">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Rank</label>
                <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                    <?= csrfInputField() ?>
                    <input type="hidden" name="id" value="<?= (int)$task['id'] ?>">
                    <input type="number" class="form-control form-control-sm js-autosave-blur" name="rank" value="<?= (int)($task['rank'] ?? 0) ?>">
                </form>
            </div>
            <div class="metadata-rail__row">
                <label>Tags</label>
                <div class="metadata-rail__value">
                    <?php if (!empty($task['tags'])): ?>
                        <?php foreach ($task['tags'] as $tag): ?>
                            <span class="tag-chip"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted small">No tags</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="metadata-rail__row">
                <label>Created</label>
                <div class="metadata-rail__value text-muted"><?= htmlspecialchars($task['created_at']) ?></div>
            </div>
            <div class="metadata-rail__row">
                <label>Updated</label>
                <div class="metadata-rail__value text-muted"><?= htmlspecialchars($task['updated_at']) ?></div>
            </div>
            <div class="metadata-rail__row">
                <label>Signals</label>
                <div class="metadata-rail__value">
                    <span class="icon-stats">
                        <span title="Comments"><i class="bi bi-chat-text"></i> <?= (int)($task['comment_count'] ?? 0) ?></span>
                        <span title="Attachments"><i class="bi bi-paperclip"></i> <?= (int)($task['attachment_count'] ?? 0) ?></span>
                        <span title="Watchers"><i class="bi bi-eye"></i> <?= (int)($task['watcher_count'] ?? 0) ?></span>
                    </span>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
