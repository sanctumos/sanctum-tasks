<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit();
}

requireCsrfToken();
logout();
header('Location: /admin/login.php');
exit();

