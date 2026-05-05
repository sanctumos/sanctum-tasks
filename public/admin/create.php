<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

requireCsrfToken();
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$title = $_POST['title'] ?? '';
$status = $_POST['status'] ?? 'todo';
$assignedToUserId = $_POST['assigned_to_user_id'] ?? null;
if ($assignedToUserId === '') $assignedToUserId = null;
$taskBody = $_POST['body'] ?? null;
if ($taskBody === '') $taskBody = null;
$dueAt = $_POST['due_at'] ?? null;
$priority = $_POST['priority'] ?? 'normal';
$project = $_POST['project'] ?? null;
$projectIdPost = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$rank = $_POST['rank'] ?? 0;
$recurrenceRule = $_POST['recurrence_rule'] ?? null;
$tagsRaw = $_POST['tags'] ?? '';
$tags = $tagsRaw === '' ? [] : preg_split('/[,]+/', (string)$tagsRaw);

$opts = [
    'due_at' => $dueAt,
    'priority' => $priority,
    'project' => $project,
    'rank' => $rank,
    'recurrence_rule' => $recurrenceRule,
    'tags' => $tags,
];
if ($projectIdPost > 0) {
    $opts['project_id'] = $projectIdPost;
}

$res = createTask(
    $title,
    $status,
    (int)$currentUser['id'],
    $assignedToUserId,
    $taskBody,
    $opts
);

if (!empty($res['success'])) {
    $_SESSION['admin_flash_success'] = 'Task created.';
} else {
    $_SESSION['admin_flash_error'] = $res['error'] ?? 'Create failed.';
}

if (!empty($res['success']) && $projectIdPost > 0) {
    header('Location: /admin/project.php?id=' . $projectIdPost . '&tab=tasks');
    exit();
}

header('Location: /admin/');
exit();

