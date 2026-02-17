<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

requireCsrfToken();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/');
    exit();
}

$fields = [];
if (array_key_exists('title', $_POST)) $fields['title'] = $_POST['title'];
if (array_key_exists('status', $_POST)) $fields['status'] = $_POST['status'];
if (array_key_exists('assigned_to_user_id', $_POST)) {
    $auid = $_POST['assigned_to_user_id'];
    $fields['assigned_to_user_id'] = ($auid === '' ? null : $auid);
}
if (array_key_exists('body', $_POST)) {
    $body = $_POST['body'];
    $fields['body'] = ($body === '' ? null : $body);
}
if (array_key_exists('due_at', $_POST)) {
    $dueAt = trim((string)$_POST['due_at']);
    $fields['due_at'] = ($dueAt === '' ? null : $dueAt);
}
if (array_key_exists('priority', $_POST)) {
    $fields['priority'] = $_POST['priority'];
}
if (array_key_exists('project', $_POST)) {
    $project = trim((string)$_POST['project']);
    $fields['project'] = ($project === '' ? null : $project);
}
if (array_key_exists('rank', $_POST)) {
    $fields['rank'] = (int)$_POST['rank'];
}
if (array_key_exists('recurrence_rule', $_POST)) {
    $rr = trim((string)$_POST['recurrence_rule']);
    $fields['recurrence_rule'] = ($rr === '' ? null : $rr);
}
if (array_key_exists('tags', $_POST)) {
    $tagsRaw = trim((string)$_POST['tags']);
    $fields['tags'] = ($tagsRaw === '') ? [] : preg_split('/[,]+/', $tagsRaw);
}

updateTask($id, $fields);

header('Location: /admin/');
exit();

