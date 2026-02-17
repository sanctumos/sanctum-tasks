<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$includeRelations = !isset($_GET['include_relations']) || $_GET['include_relations'] !== '0';
$task = getTaskById($id, $includeRelations);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$user['id'], $task, (string)$user['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

apiSuccess(['task' => $task]);

