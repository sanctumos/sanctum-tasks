<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: /admin/');
    exit();
}

$fields = [];
if (array_key_exists('status', $_POST)) $fields['status'] = $_POST['status'];
if (array_key_exists('assigned_to_user_id', $_POST)) {
    $auid = $_POST['assigned_to_user_id'];
    $fields['assigned_to_user_id'] = ($auid === '' ? null : $auid);
}

updateTask($id, $fields);

header('Location: /admin/');
exit();

