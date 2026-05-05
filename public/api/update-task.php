<?php
require_once __DIR__ . '/../includes/api_auth.php';

$user = requireApiUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('method.not_allowed', 'Use POST for this endpoint', 405);
}

$body = readJsonBody();
if ($body === null) {
    apiError('validation.invalid_json', 'Invalid JSON body', 400);
}

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    apiError('validation.invalid_id', 'Missing or invalid id', 400);
}

$fields = [];
if (array_key_exists('title', $body)) $fields['title'] = $body['title'];
if (array_key_exists('status', $body)) $fields['status'] = $body['status'];
if (array_key_exists('assigned_to_user_id', $body)) $fields['assigned_to_user_id'] = $body['assigned_to_user_id'];
if (array_key_exists('body', $body)) $fields['body'] = $body['body'];
if (array_key_exists('due_at', $body)) $fields['due_at'] = $body['due_at'];
if (array_key_exists('priority', $body)) $fields['priority'] = $body['priority'];
if (array_key_exists('project', $body)) {
    $fields['project'] = $body['project'];
}
if (array_key_exists('project_id', $body)) {
    $rawPid = $body['project_id'];
    if ($rawPid === null || $rawPid === '' || (is_string($rawPid) && trim($rawPid) === '')) {
        apiError('validation.project_id', 'project_id cannot be removed; every task must belong to a directory project.', 400);
    }
    $resP = resolveTaskDirectoryProjectId($user, $rawPid, false);
    if (!$resP['success']) {
        apiError('validation.project_id', $resP['error'] ?? 'Invalid project_id', 400);
    }
    $fields['project_id'] = $resP['project_id'];
    $fields['project'] = $resP['project'];
}
if (array_key_exists('list_id', $body)) {
    $lid = $body['list_id'];
    if ($lid === null || $lid === '') {
        $fields['list_id'] = null;
    } else {
        $listId = (int)$lid;
        if ($listId <= 0) {
            apiError('validation.list_id', 'Invalid list_id', 400);
        }
        $db = getDbConnection();
        $ls = $db->prepare('SELECT project_id FROM todo_lists WHERE id = :i LIMIT 1');
        $ls->bindValue(':i', $listId, SQLITE3_INTEGER);
        $lr = $ls->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$lr) {
            apiError('validation.list_id', 'Invalid list_id', 400);
        }
        $lpid = (int)$lr['project_id'];
        $pRow = getDirectoryProjectById($lpid);
        if (!$pRow || !userCanAccessDirectoryProject($user, $pRow)) {
            apiError('validation.list_id', 'Invalid list_id', 400);
        }
        $task = getTaskById($id, false);
        if ($task) {
            $tPid = isset($task['project_id']) ? (int)$task['project_id'] : 0;
            if ($tPid > 0 && $tPid !== $lpid) {
                apiError('validation.list_id', 'list_id does not match task project', 400);
            }
        }
        $fields['list_id'] = $listId;
    }
}
if (array_key_exists('tags', $body)) $fields['tags'] = $body['tags'];
if (array_key_exists('rank', $body)) $fields['rank'] = $body['rank'];
if (array_key_exists('recurrence_rule', $body)) $fields['recurrence_rule'] = $body['recurrence_rule'];

$existing = getTaskById($id, false);
if (!$existing) {
    apiError('task.not_found', 'Task not found', 404);
}
if (!userCanAccessTask((int)$user['id'], $existing, (string)$user['role'])) {
    apiError('task.not_found', 'Task not found', 404);
}

$result = updateTask($id, $fields);
if (!$result['success']) {
    $statusCode = ($result['error'] ?? '') === 'Task not found' ? 404 : 400;
    apiError('task.update_failed', $result['error'] ?? 'Update failed', $statusCode);
}

$task = getTaskById($id);
if (!$task) {
    apiError('task.not_found', 'Task not found', 404);
}

apiSuccess(['task' => $task]);

