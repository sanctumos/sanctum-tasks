<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();
$currentUser = getCurrentUser();

$status = $_GET['status'] ?? '';
$tag = isset($_GET['tag']) ? trim((string)$_GET['tag']) : '';
$mineFilter = st_mine_filter_active();
$assignedToUserId = $_GET['assigned_to_user_id'] ?? '';
if ($mineFilter) {
    $assignedToUserId = (string)$currentUser['id'];
}
$priority = $_GET['priority'] ?? '';
$project = $_GET['project'] ?? '';
$projectIdFilter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$q = $_GET['q'] ?? '';
$view = $_GET['view'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'updated_at';
$sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$excludeDone = isset($_GET['exclude_done']) && (string)$_GET['exclude_done'] === '1';

$homeWidgets = getHomeWidgetsForUser($currentUser);
// Filter / board deep-links opt into the heavy cross-project board for this request only.
if (
    isset($_GET['board'])
    || $status !== ''
    || $tag !== ''
    || $q !== ''
    || $priority !== ''
    || $project !== ''
    || $projectIdFilter > 0
    || $excludeDone
) {
    $homeWidgets['cross_project_board'] = true;
}

$statuses = listTaskStatuses();
$statusMap = [];
foreach ($statuses as $s) { $statusMap[$s['slug']] = $s; }
$users = !empty($homeWidgets['cross_project_board']) ? listUsers(false) : [];
$projects = !empty($homeWidgets['cross_project_board']) ? listProjects(200) : [];

// directory_projects (workspace projects), used to render Project as a link
$directoryProjects = !empty($homeWidgets['projects_hub']) || !empty($homeWidgets['cross_project_board']) || !empty($homeWidgets['my_work'])
    ? listDirectoryProjectsForUser($currentUser, 300)
    : [];
$directoryProjectByName = [];
foreach ($directoryProjects as $dp) {
    $directoryProjectByName[strtolower($dp['name'])] = $dp;
}

$todoListsByProject = [];
if (!empty($homeWidgets['cross_project_board'])) {
    foreach ($directoryProjects as $dp) {
        $todoListsByProject[(int)$dp['id']] = listTodoListsForProject($currentUser, (int)$dp['id']);
    }
}

$filters = [
    'status' => $status ?: null,
    'assigned_to_user_id' => $assignedToUserId,
    'priority' => $priority ?: null,
    'project' => $project ?: null,
    'q' => $q ?: null,
    'sort_by' => $sortBy,
    'sort_dir' => $sortDir,
];
if ($tag !== '') {
    $filters['tag'] = $tag;
}
if ($excludeDone) {
    $filters['exclude_done'] = true;
}
if ($projectIdFilter > 0) {
    $filters['project_id'] = $projectIdFilter;
}

$tasks = [];
$total = 0;
$grouped = [];
foreach ($statuses as $s) {
    $grouped[$s['slug']] = [];
}
if (!empty($homeWidgets['cross_project_board'])) {
    $tasksResult = listAllTasks($filters, null, $currentUser);
    $tasks = $tasksResult['tasks'];
    $total = (int)$tasksResult['total'];
    foreach ($tasks as $t) {
        $slug = (string)$t['status'];
        if (!isset($grouped[$slug])) {
            $grouped[$slug] = [];
        }
        $grouped[$slug][] = $t;
    }
}

$pulseKpis = !empty($homeWidgets['pulse_kpis']) ? computeHomePulseKpis($currentUser) : null;

$myWorkTasks = [];
$myWorkTotal = 0;
if (!empty($homeWidgets['my_work'])) {
    $myWorkResult = listTasks([
        'assigned_to_user_id' => (int)$currentUser['id'],
        'exclude_done' => true,
        'limit' => 12,
        'sort_by' => 'priority',
        'sort_dir' => 'DESC',
    ], true, null, $currentUser);
    $myWorkTasks = $myWorkResult['tasks'];
    $myWorkTotal = (int)$myWorkResult['total'];
}

$inboxPeek = [];
if (!empty($homeWidgets['inbox_peek'])) {
    $inboxResult = listNotificationsForUser((int)$currentUser['id'], 8, null, false);
    $inboxPeek = array_slice($inboxResult['notifications'] ?? [], 0, 5);
}

$flashError = $_SESSION['admin_flash_error'] ?? null;
$flashSuccess = $_SESSION['admin_flash_success'] ?? null;
unset($_SESSION['admin_flash_error'], $_SESSION['admin_flash_success']);

$homeActivityFeed = !empty($homeWidgets['recent_activity'])
    ? listAccessibleProjectsActivityForViewer($currentUser, 10, null)
    : [];

$homePins = [];
$homePinnedIds = [];
if (!empty($homeWidgets['pinned_boards']) || !empty($homeWidgets['projects_hub'])) {
    $homePins = listUserProjectPinsForUser($currentUser, 80);
    foreach ($homePins as $hp) {
        $homePinnedIds[(int)$hp['project_id']] = true;
    }
}

$pageTitle = 'Home';
$adminBreadcrumbs = [['label' => 'Home']];
require __DIR__ . '/_layout_top.php';

$initialView = ($view === 'list' || $view === 'board') ? $view : 'board';

function st_render_task_assignee_html(array $t): string {
    if (!empty($t['assigned_to_user_id'])) {
        return '<i class="bi bi-person-fill"></i> ' . htmlspecialchars($t['assigned_to_username'] ?? '');
    }
    return '<i class="bi bi-person"></i> <span class="text-muted">Unassigned</span>';
}
?>

<div class="page-header mb-4">
    <div class="page-header__title">
        <h1><i class="bi bi-house-door me-2 text-muted"></i>Home</h1>
        <div class="subtitle">Jump boards fast — pin the ones you live in. Pulse and My Work stay light.</div>
    </div>
    <div class="page-header__actions d-flex align-items-center flex-wrap gap-2">
        <span class="d-inline-flex align-items-center" title="Documentation"><?= st_doc_help('home') ?></span>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/settings.php?tab=appearance"><i class="bi bi-sliders me-1"></i>Home widgets</a>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/workspace-projects.php"><i class="bi bi-grid-3x3-gap me-1"></i>All projects page</a>
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

<?php if (!empty($homeWidgets['pinned_boards'])): ?>
<section class="st-home-pins mb-4" aria-labelledby="st-home-pins-heading">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h2 id="st-home-pins-heading" class="h5 mb-0"><i class="bi bi-pin-angle me-2 text-muted"></i>Pinned boards</h2>
        <span class="text-muted small"><?= count($homePins) ?> pinned · also in the Boards menu</span>
    </div>
    <?php if ($homePins === []): ?>
        <div class="surface surface-pad text-muted small">
            No pins yet. Open a board you use a lot and hit <strong>Pin</strong> — or pin from the grid below.
            That shortlist also appears under <strong>Boards</strong> in the top nav.
        </div>
    <?php else: ?>
        <div class="st-pin-nav d-flex flex-wrap gap-2">
            <?php foreach ($homePins as $pin): ?>
                <a class="st-pin-chip" href="/admin/project.php?id=<?= (int)$pin['project_id'] ?>">
                    <i class="bi bi-pin-fill"></i>
                    <span><?= htmlspecialchars((string)$pin['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pulseKpis !== null): ?>
<section class="st-home-kpis mb-4" aria-label="Pulse">
    <div class="st-kpi-strip">
        <a class="st-kpi" href="#st-home-mywork-heading">
            <div class="st-kpi__n"><?= (int)$pulseKpis['assigned_open'] ?></div>
            <div class="st-kpi__l">Assigned to me</div>
        </a>
        <a class="st-kpi <?= ((int)$pulseKpis['blocked'] > 0) ? 'st-kpi--warn' : '' ?>" href="/admin/?tag=blocked&amp;exclude_done=1&amp;board=1">
            <div class="st-kpi__n"><?= (int)$pulseKpis['blocked'] ?></div>
            <div class="st-kpi__l">Blocked</div>
        </a>
        <a class="st-kpi" href="/admin/?board=1">
            <div class="st-kpi__n"><?= (int)$pulseKpis['unassigned_open'] ?></div>
            <div class="st-kpi__l">Unassigned (open)</div>
        </a>
        <a class="st-kpi" href="/admin/activity.php">
            <div class="st-kpi__n"><?= (int)$pulseKpis['updated_today'] ?></div>
            <div class="st-kpi__l">Updated today</div>
        </a>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($homeWidgets['my_work']) || !empty($homeWidgets['inbox_peek'])): ?>
<div class="row g-3 mb-5">
    <?php if (!empty($homeWidgets['my_work'])): ?>
    <div class="<?= !empty($homeWidgets['inbox_peek']) ? 'col-lg-8' : 'col-12' ?>">
        <section class="st-home-mywork" aria-labelledby="st-home-mywork-heading">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h2 id="st-home-mywork-heading" class="h5 mb-0"><i class="bi bi-person-check me-2 text-muted"></i>My Work</h2>
                <span class="text-muted small"><?= (int)$myWorkTotal ?> open assigned to you</span>
            </div>
            <?php if ($myWorkTasks === []): ?>
                <div class="surface surface-pad text-muted small">Nothing assigned to you right now.</div>
            <?php else: ?>
                <div class="st-mywork surface">
                    <?php foreach ($myWorkTasks as $t): ?>
                        <?php
                        $pri = (string)($t['priority'] ?? 'normal');
                        $priClass = $pri === 'urgent' ? 'st-pri--urgent' : ($pri === 'high' ? 'st-pri--high' : 'st-pri--normal');
                        $projName = (string)($t['directory_project_name'] ?? $t['project'] ?? '');
                        ?>
                        <a class="st-mywork__row text-decoration-none text-reset" href="/admin/view.php?id=<?= (int)$t['id'] ?>">
                            <span class="st-pri <?= $priClass ?>"><?= htmlspecialchars($pri) ?></span>
                            <div>
                                <div class="st-mywork__title"><?= htmlspecialchars((string)$t['title']) ?></div>
                                <div class="st-mywork__meta"><?= htmlspecialchars($projName !== '' ? $projName : 'No project') ?> · <?= htmlspecialchars((string)($t['status'] ?? '')) ?></div>
                            </div>
                            <span class="st-mywork__meta">#<?= (int)$t['id'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
    <?php if (!empty($homeWidgets['inbox_peek'])): ?>
    <div class="<?= !empty($homeWidgets['my_work']) ? 'col-lg-4' : 'col-12' ?>">
        <section class="st-peek surface surface-pad" aria-labelledby="st-home-inbox-heading">
            <h2 id="st-home-inbox-heading" class="h6 mb-3 d-flex align-items-center gap-2">
                <i class="bi bi-bell"></i> Inbox
                <?php $unreadN = countUnreadNotifications((int)$currentUser['id']); ?>
                <?php if ($unreadN > 0): ?><span class="badge text-bg-danger"><?= $unreadN > 99 ? '99+' : (int)$unreadN ?></span><?php endif; ?>
                <a class="ms-auto small" href="/admin/notifications.php">All</a>
            </h2>
            <?php if ($inboxPeek === []): ?>
                <p class="text-muted small mb-0">No recent notifications.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($inboxPeek as $n): ?>
                        <?php
                        $nLabel = trim((string)($n['label'] ?? ''));
                        if ($nLabel === '') {
                            $nLabel = trim((string)($n['title'] ?? ''));
                        }
                        if ($nLabel === '') {
                            $nLabel = 'Notification';
                        }
                        $nHref = (string)($n['href'] ?? '');
                        if ($nHref === '' && !empty($n['task_id'])) {
                            $nHref = '/admin/view.php?id=' . (int)$n['task_id'];
                        }
                        if ($nHref === '') {
                            $nHref = '/admin/notifications.php';
                        }
                        ?>
                        <li class="st-peek__li d-flex justify-content-between gap-2 py-1 border-top">
                            <a class="small text-decoration-none" href="<?= htmlspecialchars($nHref) ?>"><?= htmlspecialchars($nLabel) ?></a>
                            <span class="text-muted small text-nowrap"><?= htmlspecialchars(st_relative_time($n['created_at'] ?? null)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* -------- Projects hub (accessible directory projects first) ------- */ ?>
<?php if (!empty($homeWidgets['projects_hub'])): ?>
<section class="st-home-projects mb-5" aria-labelledby="st-home-projects-heading">
    <div class="st-home-projects__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h2 id="st-home-projects-heading" class="h5 mb-0 d-flex align-items-center gap-2 flex-wrap"><i class="bi bi-kanban me-2 text-muted"></i>Your projects <?= st_doc_help('projects') ?></h2>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small"><?= count($directoryProjects) ?> you can access</span>
            <a class="btn btn-outline-primary btn-sm" href="/admin/workspace-projects.php"><i class="bi bi-plus-lg me-1"></i>New project</a>
        </div>
    </div>
    <?php if (empty($directoryProjects)): ?>
        <div class="surface surface-pad text-center st-home-projects__empty">
            <div class="mb-3" style="font-size: 2rem; color: var(--st-text-muted);"><i class="bi bi-kanban"></i></div>
            <h3 class="h6 mb-1">No projects yet</h3>
            <p class="text-muted small mb-3 mx-auto" style="max-width: 32rem;">Create a workspace project &mdash; most work starts there. You will still see any matching tasks below once they exist.</p>
            <a class="btn btn-primary btn-sm" href="/admin/workspace-projects.php"><i class="bi bi-plus-lg me-1"></i>Create a project</a>
        </div>
    <?php else: ?>
        <div class="st-home-project-grid board" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
            <?php foreach ($directoryProjects as $dp): ?>
                <?php $dpId = (int)$dp['id']; $dpPinned = !empty($homePinnedIds[$dpId]); ?>
                <div class="task-card st-home-project-card position-relative">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <a class="task-card__title mb-0 text-truncate text-decoration-none stretched-link" href="/admin/project.php?id=<?= $dpId ?>" title="<?= htmlspecialchars($dp['name']) ?>"><?= htmlspecialchars($dp['name']) ?></a>
                        <div class="d-flex align-items-center gap-1 position-relative" style="z-index:3;">
                            <?= st_project_pin_form_html($dpId, $dpPinned, '/admin/', 'btn btn-sm ' . ($dpPinned ? 'btn-primary' : 'btn-outline-secondary')) ?>
                            <span class="status-pill status-pill--<?= $dp['status'] === 'active' ? 'doing' : ($dp['status'] === 'archived' ? 'todo' : 'blocked') ?>"><?= htmlspecialchars($dp['status']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($dp['description'])): ?>
                        <div class="text-muted small st-home-project-card__desc"><?= htmlspecialchars($dp['description']) ?></div>
                    <?php endif; ?>
                    <div class="task-card__meta mb-0">
                        <?php if ($dpPinned): ?>
                            <span><i class="bi bi-pin-fill"></i> pinned</span>
                        <?php endif; ?>
                        <?php if (!empty($dp['all_access'])): ?>
                            <span><i class="bi bi-globe"></i> all-access</span>
                        <?php endif; ?>
                        <?php if (!empty($dp['client_visible'])): ?>
                            <span><i class="bi bi-eye"></i> client-visible</span>
                        <?php endif; ?>
                    </div>
                    <div class="task-card__footer mt-auto pt-2">
                        <span class="text-muted small">Updated <?= st_relative_time($dp['updated_at'] ?? null) ?></span>
                        <span class="small" style="color: var(--st-accent);">Open <i class="bi bi-arrow-right-short"></i></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($homeActivityFeed)): ?>
<section class="st-home-activity mb-5" aria-labelledby="st-home-activity-heading">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h2 id="st-home-activity-heading" class="h5 mb-0 d-flex align-items-center gap-2"><i class="bi bi-activity me-2 text-muted"></i>Recent activity</h2>
        <a class="btn btn-outline-secondary btn-sm" href="/admin/activity.php">Full timeline</a>
    </div>
    <ul class="activity-feed activity-feed--compact list-unstyled mb-0">
        <?php foreach ($homeActivityFeed as $ev): ?>
            <li class="activity-feed__item surface surface-pad mb-2">
                <div class="activity-feed__icon"><i class="bi <?= htmlspecialchars((string)($ev['icon'] ?? 'bi-activity')) ?>"></i></div>
                <div class="activity-feed__body">
                    <a class="activity-feed__summary stretched-link text-decoration-none" href="<?= htmlspecialchars((string)($ev['href'] ?? '/admin/')) ?>"><?= htmlspecialchars((string)($ev['summary'] ?? '')) ?></a>
                    <div class="activity-feed__meta text-muted small"><?= htmlspecialchars(st_relative_time($ev['created_at'] ?? null)) ?></div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<hr class="st-home-rule text-muted opacity-50 my-5" aria-hidden="true">

<?php if (empty($homeWidgets['cross_project_board'])): ?>
<section class="st-home-master-optin mb-4">
    <div class="surface surface-pad d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="fw-semibold mb-1">Cross-project board</div>
            <p class="fine-print mb-0 text-muted">Off by default — it loads every reachable task. Turn it on under Appearance → Home widgets when you need the full board.</p>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="/admin/settings.php?tab=appearance"><i class="bi bi-sliders me-1"></i>Enable in settings</a>
    </div>
</section>
<?php else: ?>
<section class="st-home-master" aria-labelledby="st-home-master-heading">
    <div class="page-header">
        <div class="page-header__title">
            <h2 id="st-home-master-heading" class="h4 mb-1">All tasks <span class="text-muted fw-normal">across projects</span></h2>
            <div class="subtitle"><?= $total ?> task<?= $total === 1 ? '' : 's' ?> across every project you can reach.</div>
        </div>
        <div class="page-header__actions d-flex align-items-center flex-wrap gap-2">
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

<form class="filter-bar" method="get" action="/admin/" role="search">
    <?php if ($mineFilter): ?>
        <input type="hidden" name="mine" value="1">
    <?php endif; ?>
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
        <input class="form-control" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="Tag" aria-label="Tag" list="tags-filter-list">
        <datalist id="tags-filter-list">
            <option value="blocked"></option>
            <option value="deferred"></option>
            <option value="review"></option>
            <option value="halted"></option>
        </datalist>
    </div>
    <?php if ($excludeDone): ?>
        <input type="hidden" name="exclude_done" value="1">
    <?php endif; ?>
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
    <div class="filter-bar__actions d-flex align-items-center flex-wrap gap-2">
        <?= st_assigned_to_me_button('/admin/', st_request_query(['mine', 'assigned_to_user_id']), $mineFilter) ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
        <a class="btn btn-outline-secondary" href="/admin/"><i class="bi bi-x-lg"></i></a>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advFilters" aria-expanded="false"><i class="bi bi-sliders"></i> More</button>
        <?= st_doc_help('filters', 'How filters and sorting work') ?>
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
                        <input class="form-control form-control-lg" name="title" required autofocus placeholder="What needs to happen?" data-mention="1">
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
                            <select class="form-select" name="project_id" id="newTaskProjectId" required>
                                <option value="" selected disabled>Select a project…</option>
                                <?php foreach ($directoryProjects as $dp): ?>
                                    <option value="<?= (int)$dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Tasks must belong to a workspace project.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">To-do list</label>
                            <select class="form-select" name="list_id" id="newTaskListId" required disabled>
                                <option value="">Select a project first…</option>
                            </select>
                            <div class="form-text">Pick the list this task lives on (each project has at least a “General” list).</div>
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
                            <textarea class="form-control" name="body" rows="3" data-mention="1" placeholder="Optional notes… Tag teammates with @username."></textarea>
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
(function () {
    var byProject = <?= json_encode($todoListsByProject, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var projSel = document.getElementById('newTaskProjectId');
    var listSel = document.getElementById('newTaskListId');
    if (!projSel || !listSel) return;
    function refillLists() {
        var pid = parseInt(projSel.value, 10);
        listSel.innerHTML = '';
        if (!pid || isNaN(pid)) {
            listSel.disabled = true;
            listSel.required = false;
            var o0 = document.createElement('option');
            o0.value = '';
            o0.textContent = 'Select a project first…';
            listSel.appendChild(o0);
            return;
        }
        var lists = byProject[String(pid)] || byProject[pid] || [];
        if (!lists.length) {
            listSel.disabled = true;
            listSel.required = false;
            var o1 = document.createElement('option');
            o1.value = '';
            o1.textContent = 'No lists — open the project’s Lists tab and add one';
            listSel.appendChild(o1);
            return;
        }
        lists.forEach(function (L) {
            var o = document.createElement('option');
            o.value = L.id;
            o.textContent = L.name || ('List #' + L.id);
            listSel.appendChild(o);
        });
        listSel.disabled = false;
        listSel.required = true;
    }
    projSel.addEventListener('change', refillLists);
    var modal = document.getElementById('newTaskModal');
    if (modal) {
        modal.addEventListener('shown.bs.modal', refillLists);
    }
})();
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

</section>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
