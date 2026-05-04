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

$name = (string)($body['name'] ?? '');
$description = isset($body['description']) ? (string)$body['description'] : null;
$clientVisible = isset($body['client_visible']) && (bool)$body['client_visible'];
$allAccess = isset($body['all_access']) && (bool)$body['all_access'];

$res = createDirectoryProject((int)$user['id'], $name, $description, $clientVisible, $allAccess);
if (empty($res['success'])) {
    apiError('project.create_failed', $res['error'] ?? 'Create failed', 400);
}

$p = getDirectoryProjectById((int)$res['id']);
apiSuccess(['project' => $p], [], 201);
