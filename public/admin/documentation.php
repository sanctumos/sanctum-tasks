<?php
/**
 * Renders public/docs/user-guide.md as HTML for logged-in users (in-app help).
 * Kept under public/ so multihost deploys that mirror WEB_ROOT include the file.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_helpers.php';

requireAuth();

$pages = st_doc_pages();
$page = (string)($_GET['page'] ?? 'start');
if (!isset($pages[$page])) {
    $page = 'start';
}

$guidePath = dirname(__DIR__) . '/docs/' . $pages[$page]['file'];
if (!is_readable($guidePath)) {
    http_response_code(500);
    die('Documentation file is missing. Expected public/docs/' . htmlspecialchars($pages[$page]['file']));
}

$raw = (string)file_get_contents($guidePath);
require_once __DIR__ . '/../includes/lib/ParsedownTasks.php';
$pd = new ParsedownTasks();
$pd->setSafeMode(true);
$pd->setMarkupEscaped(true);
$pd->setBreaksEnabled(true);
$pd->setUrlsLinked(true);

$htmlBody = st_markdown_enhance_html($pd->text($raw));
$htmlBody = st_doc_inject_heading_ids($htmlBody);

$pageTitle = 'Help · ' . $pages[$page]['label'];
$adminBreadcrumbs = [
    ['href' => '/admin/', 'label' => 'Home'],
];
if ($page === 'start') {
    $adminBreadcrumbs[] = ['label' => 'Help'];
} else {
    $adminBreadcrumbs[] = ['href' => '/admin/documentation.php', 'label' => 'Help'];
    $adminBreadcrumbs[] = ['label' => $pages[$page]['label']];
}
require __DIR__ . '/_layout_top.php';
?>

<div class="page-header page-header--docs">
    <div class="page-header__title">
        <h1><i class="bi bi-journal-text me-2 text-muted"></i>Help</h1>
        <div class="subtitle"><?= htmlspecialchars($pages[$page]['deck']) ?></div>
    </div>
    <div class="page-header__actions">
        <a class="btn btn-sm btn-outline-secondary" href="https://github.com/sanctumos/tasks/tree/main/public/docs" target="_blank" rel="noopener"><i class="bi bi-folder2-open me-1"></i>User guide (repo)</a>
    </div>
</div>

<div class="row g-3 align-items-start">
    <div class="col-12 col-lg-3">
        <div class="surface surface-pad">
            <div class="section-title"><i class="bi bi-compass"></i> Help map</div>
            <div class="list-group list-group-flush">
                <?php foreach ($pages as $slug => $meta): ?>
                    <?php $href = $slug === 'start' ? '/admin/documentation.php' : '/admin/documentation.php?page=' . urlencode($slug); ?>
                    <a class="list-group-item list-group-item-action <?= $slug === $page ? 'active' : '' ?>"
                       href="<?= htmlspecialchars($href) ?>">
                        <?= htmlspecialchars($meta['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-9">
        <div class="surface surface-pad documentation-content markdown-body">
            <?= $htmlBody ?>
        </div>
    </div>
</div>

<p class="text-muted small mt-3 px-1">Tip: use the <strong>?</strong> icons in the admin UI to jump to the right section here.</p>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
