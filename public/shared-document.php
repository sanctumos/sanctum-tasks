<?php
/**
 * Unsigned read-only render of an active document when public sharing is on.
 *
 * Requires `documents.public_link_enabled` + `public_link_token` (256-bit hex).
 * Discussion/comments are intentionally excluded.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/admin/_helpers.php';

$token = normalizeDocumentPublicLinkHexToken((string)($_GET['token'] ?? ''));

if ($token === null) {
    http_response_code(404);
    $pageTitle = 'Document not found';
    $bodyBlock = '<h1 class="h3 mb-3">Not found</h1><p class="text-muted">This link is missing or malformed.</p>';
} else {
    $doc = getDocumentByPublicLinkToken($token);
    if (!$doc) {
        http_response_code(404);
        $pageTitle = 'Document unavailable';
        $bodyBlock = '<h1 class="h3 mb-3">Unavailable</h1><p class="text-muted">This document link is inactive, revoked, or no longer exists.</p>';
    } else {
        $pageTitle = (string)$doc['title'];
        $htmlTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
        $upd = htmlspecialchars((string)($doc['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $rawBody = isset($doc['body']) ? (string)$doc['body'] : '';
        $mdInner = trim($rawBody) === ''
            ? '<p class="text-muted mb-0">This document has no published body.</p>'
            : '<div class="markdown-body">' . rewriteSharedDocumentAssetUrls(st_markdown($rawBody), $token) . '</div>';
        $bodyBlock = '<h1 class="mb-4">' . $htmlTitle . '</h1>'
            . '<p class="text-muted small mb-4"><i class="bi bi-clock"></i> Last updated&nbsp;UTC: ' . $upd . '</p>'
            . $mdInner;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/admin.css?v=21" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4 py-lg-5" style="max-width: 900px;">
    <div class="surface surface-pad">
        <?= $bodyBlock ?>
        <hr class="my-4">
        <p class="fine-print mb-0 text-muted">Shared from <?= htmlspecialchars(getAppName(), ENT_QUOTES, 'UTF-8') ?>. Signing in is not required to view this page. Internal discussions stay inside your workspace.</p>
    </div>
</div>
<?php echo st_mermaid_assets_html(); ?>
</body>
</html>
