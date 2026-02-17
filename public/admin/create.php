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
$rank = $_POST['rank'] ?? 0;
$recurrenceRule = $_POST['recurrence_rule'] ?? null;
$tagsRaw = $_POST['tags'] ?? '';
$tags = $tagsRaw === '' ? [] : preg_split('/[,]+/', (string)$tagsRaw);

$res = createTask(
    $title,
    $status,
    (int)$currentUser['id'],
    $assignedToUserId,
    $taskBody,
    [
        'due_at' => $dueAt,
        'priority' => $priority,
        'project' => $project,
        'rank' => $rank,
        'recurrence_rule' => $recurrenceRule,
        'tags' => $tags,
    ]
);

header('Location: /admin/');
exit();

