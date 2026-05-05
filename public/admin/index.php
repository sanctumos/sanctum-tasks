<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();

$status = $_GET['status'] ?? '';
$assignedToUserId = $_GET['assigned_to_user_id'] ?? '';
$priority = $_GET['priority'] ?? '';
$project = $_GET['project'] ?? '';
$projectIdFilter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$q = $_GET['q'] ?? '';
$view = $_GET['view'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'updated_at';
$sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$statuses = listTaskStatuses();
$statusMap = [];
foreach ($statuses as $s) { $statusMap[$s['slug']] = $s; }
$users = listUsers(false);
$projects = listProjects(200);

// directory_projects (workspace projects), used to render Project as a link
$directoryProjects = listDirectoryProjectsForUser($currentUser, 300);
$directoryProjectByName = [];
foreach ($directoryProjects as $dp) {
    $directoryProjectByName[strtolower($dp['name'])] = $dp;
}

$filters = [
    'status' => $status ?: null,
    'assigned_to_user_id' => $assignedToUserId,
    'priority' => $priority ?: null,
    'project' => $project ?: null,
    'q' => $q ?: null,
    'sort_by' => $sortBy,
    'sort_dir' => $sortDir,
    'limit' => 250,
    'offset' => 0,
];
if ($projectIdFilter > 0) {
    $filters['project_id'] = $projectIdFilter;
}
$tasksResult = listTasks($filters, true, null, $currentUser);
$tasks = $tasksResult['tasks'];
$total = (int)$tasksResult['total'];

// Group tasks by status for the board view
$grouped = [];
foreach ($statuses as $s) {
    $grouped[$s['slug']] = [];
}
foreach ($tasks as $t) {
    $slug = (string)$t['status'];
    if (!isset($grouped[$slug])) $grouped[$slug] = [];
    $grouped[$slug][] = $t;
}

$flashError = $_SESSION['admin_flash_error'] ?? null;
$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
unset($_SESSION['admin_flash_error'], $_SESSION['admin_flash_success']);

$pageTitle = 'Tasks';
$adminBreadcrumbs = [['label' => 'Tasks']];
require __DIR__ . '/_layout_top.php';

$initialView = ($view === 'list' || $view === 'board') ? $view : 'board';

function st_render_task_assignee_html(array $t): string {
    if (!empty($t['assigned_to_user_id'])) {
        return '<i class="bi bi-person-fill"></i> ' . htmlspecialchars($t['assigned_to_username'] ?? '');
    }
    return '<i class="bi bi-person"></i> <span class="text-muted">Unassigned</span>';
}
?>

<div class="page-header">
    <div class="page-header__title">
        <h1>Tasks</h1>
        <div class="subtitle"><?= count($tasks) ?> shown · <?= $total ?> total</div>
    </div>
    <div class="page-header__actions">
        <div class="btn-group" role="group" aria-label="View">
            <button type="button" class="btn btn-sm btn-outline-secondary <?= $initialView === 'board' ? 'active' : '' ?>" data-view-switch="board"><i class="bi bi-kanban me-1"></i>Board</button>
            <button type="button" class="btn btn-sm btn-outline-secondary <?= $initialView === 'list' ? 'active' : '' ?>" data-view-switch="list"><i class="bi bi-list-ul me-1"></i>List</button>
        </div>
        <?php if (!empty($directoryProjects)): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTaskModal">
                <i class="bi bi-plus-lg"></i> New task
            </button>
        <?php else: ?>
            <a class="btn btn-outline-primary btn-sm" href="/admin/workspace-projects.php"><i class="bi bi-kanban me-1"></i>Create a project first</a>
        <?php endif; ?>
    </div>
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

<form class="filter-bar" method="get" action="/admin/" role="search">
    <div class="filter-bar__search">
        <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input class="form-control border-start-0" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search title or body…" aria-label="Search">
        </div>
    </div>
    <div class="filter-bar__field">
        <select class="form-select" name="status" aria-label="Status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $status === $s['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar__field">
        <select class="form-select" name="priority" aria-label="Priority">
            <option value="">Any priority</option>
            <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                <option value="<?= $p ?>" <?= $priority === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar__field">
        <select class="form-select" name="assigned_to_user_id" aria-label="Assignee">
            <option value="">Anyone</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)$assignedToUserId === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-bar__field">
        <input class="form-control" name="project" value="<?= htmlspecialchars($project) ?>" list="projects-list" placeholder="Project name">
        <datalist id="projects-list">
            <?php foreach ($projects as $proj): ?>
                <option value="<?= htmlspecialchars($proj['name']) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
    <div class="filter-bar__actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
        <a class="btn btn-outline-secondary" href="/admin/"><i class="bi bi-x-lg"></i></a>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advFilters" aria-expanded="false"><i class="bi bi-sliders"></i> More</button>
    </div>
    <div class="collapse w-100" id="advFilters">
        <div class="d-flex flex-wrap gap-2 pt-2 border-top mt-2">
            <div class="filter-bar__field">
                <label class="form-label small text-muted mb-1">Sort by</label>
                <select class="form-select form-select-sm" name="sort_by">
                    <?php foreach (['updated_at', 'created_at', 'due_at', 'priority', 'rank', 'status', 'title'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $sortBy === $opt ? 'selected' : '' ?>><?= str_replace('_', ' ', $opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-bar__field">
                <label class="form-label small text-muted mb-1">Direction</label>
                <select class="form-select form-select-sm" name="sort_dir">
                    <option value="DESC" <?= $sortDir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                    <option value="ASC" <?= $sortDir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                </select>
            </div>
        </div>
    </div>
</form>

<div data-view-root data-view="<?= htmlspecialchars($initialView) ?>" style="position: relative;">

    <?php /* ------- BOARD VIEW ------- */ ?>
    <div class="board" data-when-view="board" style="<?= $initialView === 'board' ? '' : 'display:none;' ?>">
        <?php foreach ($statuses as $s):
            $kind = st_status_kind(['slug' => $s['slug'], 'is_done' => $s['is_done']]);
            $count = count($grouped[$s['slug']] ?? []);
        ?>
            <div class="swimlane">
                <div class="swimlane__header">
                    <span class="status-pill status-pill--<?= $kind ?>"><?= htmlspecialchars($s['label']) ?></span>
                    <span class="swimlane__count"><?= $count ?></span>
                </div>
                <div class="swimlane__body">
                    <?php if ($count === 0): ?>
                        <div class="swimlane__empty">No tasks here.</div>
                    <?php endif; ?>
                    <?php foreach (($grouped[$s['slug']] ?? []) as $t):
                        $projectLink = null;
                        if (!empty($t['project'])) {
                            $key = strtolower((string)$t['project']);
                            if (isset($directoryProjectByName[$key])) {
                                $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                            }
                        }
                    ?>
                        <div class="task-card task-card--interactive">
                            <a class="task-card__title text-decoration-none stretched-link" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                            <div class="task-card__meta">
                                <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                                <?php if (!empty($t['project'])): ?>
                                    <?php if ($projectLink): ?>
                                        <a href="<?= $projectLink ?>" class="position-relative" style="z-index:2;"><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></a>
                                    <?php else: ?>
                                        <span><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($t['due_at'])): ?>
                                    <span title="Due <?= htmlspecialchars($t['due_at']) ?>"><i class="bi bi-calendar-event"></i> <?= htmlspecialchars(substr((string)$t['due_at'], 0, 10)) ?></span>
                                <?php endif; ?>
                                <?= st_signal_icons_html($t) ?>
                            </div>
                            <div class="task-card__footer">
                                <span class="task-card__assignee"><?= st_render_task_assignee_html($t) ?></span>
                                <span class="text-muted small"><?= st_relative_time($t['updated_at'] ?? null) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* ------- LIST VIEW (desktop table + mobile cards) ------- */ ?>
    <div data-when-view="list" style="<?= $initialView === 'list' ? '' : 'display:none;' ?>">
        <div class="surface task-list-table">
            <table class="task-table">
                <thead>
                <tr>
                    <th>Title</th>
                    <th style="width: 130px;">Status</th>
                    <th style="width: 110px;">Priority</th>
                    <th style="width: 160px;">Project</th>
                    <th style="width: 160px;">Assignee</th>
                    <th style="width: 110px;">Due</th>
                    <th style="width: 110px;">Updated</th>
                    <th style="width: 110px;">Signals</th>
                    <th style="width: 90px; text-align: right;">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t):
                    $projectLink = null;
                    if (!empty($t['project'])) {
                        $key = strtolower((string)$t['project']);
                        if (isset($directoryProjectByName[$key])) {
                            $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                        }
                    }
                ?>
                    <tr>
                        <td class="task-title-cell">
                            <a href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                            <div class="text-muted small">#<?= (int)$t['id'] ?> · by <?= htmlspecialchars($t['created_by_username'] ?? '') ?></div>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="status" aria-label="Status">
                                    <?php foreach ($statuses as $s): ?>
                                        <option value="<?= htmlspecialchars($s['slug']) ?>" <?= $t['status'] === $s['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="priority" aria-label="Priority">
                                    <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                        <option value="<?= $p ?>" <?= ($t['priority'] ?? 'normal') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="small">
                            <?php if (!empty($t['project'])): ?>
                                <?php if ($projectLink): ?>
                                    <a class="text-decoration-none" href="<?= $projectLink ?>"><i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($t['project']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($t['project']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/update.php" class="js-autosave-form m-0">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <select class="form-select form-select-sm js-autosave" name="assigned_to_user_id" aria-label="Assignee">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= (int)$u['id'] ?>" <?= (string)($t['assigned_to_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="small text-muted"><?= !empty($t['due_at']) ? htmlspecialchars(substr((string)$t['due_at'], 0, 10)) : '—' ?></td>
                        <td class="small text-muted"><?= st_relative_time($t['updated_at'] ?? null) ?></td>
                        <td class="small"><?= st_signal_icons_html($t) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="task-actions">
                            <a class="btn btn-sm btn-outline-secondary" title="Open" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><i class="bi bi-arrow-right-short"></i></a>
                            <form method="post" action="/admin/delete.php" class="d-inline m-0" onsubmit="return confirm('Delete task #<?= (int)$t['id'] ?>?');">
                                <?= csrfInputField() ?>
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tasks)): ?>
                    <tr><td colspan="9" class="text-muted text-center py-4">No tasks match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="task-list-cards">
            <?php foreach ($tasks as $t):
                $projectLink = null;
                if (!empty($t['project'])) {
                    $key = strtolower((string)$t['project']);
                    if (isset($directoryProjectByName[$key])) {
                        $projectLink = '/admin/project.php?id=' . (int)$directoryProjectByName[$key]['id'];
                    }
                }
            ?>
                <div class="task-card task-card--interactive">
                    <a class="task-card__title stretched-link text-decoration-none" href="/admin/view.php?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['title']) ?></a>
                    <div class="task-card__meta">
                        <?= st_status_pill_html($t, $statusMap) ?>
                        <?= st_priority_chip_html((string)($t['priority'] ?? 'normal')) ?>
                        <?php if (!empty($t['project'])): ?>
                            <?php if ($projectLink): ?>
                                <a href="<?= $projectLink ?>" class="position-relative" style="z-index:2;"><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></a>
                            <?php else: ?>
                                <span><i class="bi bi-folder2"></i> <?= htmlspecialchars($t['project']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?= st_signal_icons_html($t) ?>
                    </div>
                    <div class="task-card__footer">
                        <span class="task-card__assignee"><?= st_render_task_assignee_html($t) ?></span>
                        <span class="text-muted small"><?= st_relative_time($t['updated_at'] ?? null) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <div class="empty-hint">No tasks match these filters.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php /* ------- New task modal (requires a directory project) ------- */ ?>
<?php if (!empty($directoryProjects)): ?>
<div class="modal fade" id="newTaskModal" tabindex="-1" aria-labelledby="newTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="/admin/create.php">
                <div class="modal-header">
                    <h5 class="modal-title" id="newTaskModalLabel">New task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrfInputField() ?>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input class="form-control form-control-lg" name="title" required autofocus placeholder="What needs to happen?">
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s['slug']) ?>" <?= ((int)$s['is_default'] === 1) ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?>
                                    <option value="<?= $p ?>" <?= $p === 'normal' ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Assign to</label>
                            <select class="form-select" name="assigned_to_user_id">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Project</label>
                            <select class="form-select" name="project_id" required>
                                <option value="" selected disabled>Select a project…</option>
                                <?php foreach ($directoryProjects as $dp): ?>
                                    <option value="<?= (int)$dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Tasks must belong to a workspace project.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Due (UTC)</label>
                            <input class="form-control" type="datetime-local" name="due_at">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tags</label>
                            <input class="form-control" name="tags" placeholder="comma,separated,tags">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Body</label>
                            <textarea class="form-control" name="body" rows="3" placeholder="Optional notes…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Create task</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// View switcher: hide/show board vs list based on data-view-root
(function () {
    function applyView(name) {
        document.querySelectorAll('[data-when-view]').forEach(function (el) {
            el.style.display = (el.getAttribute('data-when-view') === name) ? '' : 'none';
        });
    }
    var root = document.querySelector('[data-view-root]');
    if (!root) return;
    applyView(root.dataset.view || 'board');
    var observer = new MutationObserver(function () { applyView(root.dataset.view || 'board'); });
    observer.observe(root, { attributes: true, attributeFilter: ['data-view'] });
})();
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
