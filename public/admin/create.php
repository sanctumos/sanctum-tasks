<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit();
}

$title = $_POST['title'] ?? '';
$status = $_POST['status'] ?? 'todo';
$assignedToUserId = $_POST['assigned_to_user_id'] ?? null;
if ($assignedToUserId === '') $assignedToUserId = null;

$res = createTask($title, $status, (int)$currentUser['id'], $assignedToUserId);

header('Location: /admin/');
exit();

