<?php
/**
 * Archived directory-project board ZIP export (async jobs + flat HTML snapshot).
 */

require_once __DIR__ . '/config.php';

function boardExportStorageRoot(): string
{
    $root = rtrim((string)TASKS_BOARD_EXPORT_DIR, '/\\');
    ensureDirExists($root);
    return $root;
}

function boardExportAbsolutePath(string $storageRelPath): ?string
{
    $rel = ltrim(str_replace('\\', '/', $storageRelPath), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    $root = boardExportStorageRoot();
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return null;
    }
    $abs = $root . '/' . $rel;
    // Parent may not exist yet for a new export file — create then re-check containment.
    ensureDirExists(dirname($abs));
    $dirReal = realpath(dirname($abs));
    if ($dirReal === false || !str_starts_with($dirReal, $rootReal)) {
        return null;
    }
    return $abs;
}

/**
 * Access + archived gate for board export features.
 *
 * @return array{ok:true}|array{ok:false,error:string,http:int}
 */
function boardExportAccessGate(array $userRow, ?array $project): array
{
    if (!$project) {
        return ['ok' => false, 'error' => 'Project not found', 'http' => 404];
    }
    if (!userCanAccessDirectoryProject($userRow, $project)) {
        return ['ok' => false, 'error' => 'Project not found', 'http' => 404];
    }
    if (($project['status'] ?? '') !== 'archived') {
        return ['ok' => false, 'error' => 'Board exports are only available after the project is archived', 'http' => 400];
    }
    return ['ok' => true];
}

function getBoardExportJobById(int $jobId): ?array
{
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT * FROM project_board_exports WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $jobId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function listBoardExportJobsForProject(int $projectId, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT e.*, u.username AS requested_by_username
        FROM project_board_exports e
        JOIN users u ON u.id = e.requested_by_user_id
        WHERE e.project_id = :pid
        ORDER BY e.id DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Fingerprint of board content that would land in a ZIP snapshot.
 * Same hash ⇒ no need to build another archive file.
 */
function boardExportContentFingerprint(int $projectId): string
{
    $db = getDbConnection();
    $parts = [];

    $proj = $db->prepare('SELECT id, name, description, status, updated_at FROM projects WHERE id = :id LIMIT 1');
    $proj->bindValue(':id', $projectId, SQLITE3_INTEGER);
    $prow = $proj->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$prow) {
        return hash('sha256', 'missing-project:' . $projectId);
    }
    $parts[] = 'project|' . implode('|', [
        (string)$prow['id'],
        (string)$prow['name'],
        (string)($prow['description'] ?? ''),
        (string)$prow['status'],
        (string)($prow['updated_at'] ?? ''),
    ]);

    $lists = $db->prepare('SELECT id, name, sort_order, created_at FROM todo_lists WHERE project_id = :p ORDER BY id ASC');
    $lists->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $lr = $lists->execute();
    while ($row = $lr->fetchArray(SQLITE3_ASSOC)) {
        $parts[] = 'list|' . implode('|', [
            (string)$row['id'],
            (string)$row['name'],
            (string)$row['sort_order'],
            (string)($row['created_at'] ?? ''),
        ]);
    }

    $tasks = $db->prepare("
        SELECT id, title, body, status, priority, due_at, list_id, assigned_to_user_id,
               tags_json, rank, recurrence_rule, updated_at
        FROM tasks
        WHERE project_id = :p
        ORDER BY id ASC
    ");
    $tasks->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $tr = $tasks->execute();
    $taskIds = [];
    while ($row = $tr->fetchArray(SQLITE3_ASSOC)) {
        $taskIds[] = (int)$row['id'];
        $parts[] = 'task|' . implode('|', [
            (string)$row['id'],
            (string)$row['title'],
            (string)($row['body'] ?? ''),
            (string)$row['status'],
            (string)$row['priority'],
            (string)($row['due_at'] ?? ''),
            (string)($row['list_id'] ?? ''),
            (string)($row['assigned_to_user_id'] ?? ''),
            (string)($row['tags_json'] ?? ''),
            (string)$row['rank'],
            (string)($row['recurrence_rule'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
    }

    if ($taskIds !== []) {
        $in = implode(',', array_map('intval', $taskIds));
        $cr = $db->query("
            SELECT id, task_id, comment, created_at
            FROM task_comments
            WHERE task_id IN ({$in})
            ORDER BY id ASC
        ");
        if ($cr) {
            while ($row = $cr->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'tcomment|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['task_id'],
                    (string)$row['comment'],
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
        $ar = $db->query("
            SELECT id, task_id, file_name, file_url, mime_type, size_bytes, storage_kind, storage_rel_path, created_at
            FROM task_attachments
            WHERE task_id IN ({$in})
            ORDER BY id ASC
        ");
        if ($ar) {
            while ($row = $ar->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'att|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['task_id'],
                    (string)$row['file_name'],
                    (string)($row['file_url'] ?? ''),
                    (string)($row['mime_type'] ?? ''),
                    (string)($row['size_bytes'] ?? ''),
                    (string)($row['storage_kind'] ?? ''),
                    (string)($row['storage_rel_path'] ?? ''),
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
    }

    $docs = $db->prepare("
        SELECT id, title, body, status, directory_path, updated_at
        FROM documents
        WHERE project_id = :p AND status != 'trashed'
        ORDER BY id ASC
    ");
    $docs->bindValue(':p', $projectId, SQLITE3_INTEGER);
    $dr = $docs->execute();
    $docIds = [];
    while ($row = $dr->fetchArray(SQLITE3_ASSOC)) {
        $docIds[] = (int)$row['id'];
        $parts[] = 'doc|' . implode('|', [
            (string)$row['id'],
            (string)$row['title'],
            (string)($row['body'] ?? ''),
            (string)$row['status'],
            (string)($row['directory_path'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
    }
    if ($docIds !== []) {
        $din = implode(',', array_map('intval', $docIds));
        $dcr = $db->query("
            SELECT id, document_id, comment, created_at
            FROM document_comments
            WHERE document_id IN ({$din})
            ORDER BY id ASC
        ");
        if ($dcr) {
            while ($row = $dcr->fetchArray(SQLITE3_ASSOC)) {
                $parts[] = 'dcomment|' . implode('|', [
                    (string)$row['id'],
                    (string)$row['document_id'],
                    (string)$row['comment'],
                    (string)($row['created_at'] ?? ''),
                ]);
            }
        }
    }

    return hash('sha256', implode("\n", $parts));
}

/**
 * Latest ready export whose ZIP file still exists on disk.
 */
function getLatestReadyBoardExportWithFile(int $projectId): ?array
{
    $jobs = listBoardExportJobsForProject($projectId, 50);
    foreach ($jobs as $job) {
        if (($job['status'] ?? '') !== 'ready') {
            continue;
        }
        $rel = (string)($job['storage_rel_path'] ?? '');
        if ($rel === '') {
            continue;
        }
        $abs = boardExportAbsolutePath($rel);
        if ($abs !== null && is_file($abs)) {
            return $job;
        }
    }
    return null;
}

/**
 * @return array{success:bool,id?:int,error?:string,reused?:bool,unchanged?:bool,status?:string}
 */
function requestBoardExportJob(int $actorUserId, int $projectId): array
{
    $user = getUserById($actorUserId, false);
    $project = getDirectoryProjectById($projectId);
    if (!$user) {
        return ['success' => false, 'error' => 'User not found'];
    }
    $gate = boardExportAccessGate($user, $project);
    if (empty($gate['ok'])) {
        return ['success' => false, 'error' => (string)($gate['error'] ?? 'Denied')];
    }

    // Avoid stacking identical pending/running jobs for the same requester.
    $db = getDbConnection();
    $chk = $db->prepare("
        SELECT id, status FROM project_board_exports
        WHERE project_id = :pid AND requested_by_user_id = :uid
          AND status IN ('pending', 'running')
        ORDER BY id DESC LIMIT 1
    ");
    $chk->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $chk->bindValue(':uid', $actorUserId, SQLITE3_INTEGER);
    $existing = $chk->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing) {
        return [
            'success' => true,
            'id' => (int)$existing['id'],
            'reused' => true,
            'status' => (string)$existing['status'],
        ];
    }

    $fingerprint = boardExportContentFingerprint($projectId);
    $latestReady = getLatestReadyBoardExportWithFile($projectId);
    if ($latestReady !== null) {
        $prevHash = (string)($latestReady['content_hash'] ?? '');
        if ($prevHash !== '' && hash_equals($prevHash, $fingerprint)) {
            createAuditLog($actorUserId, 'project.board_export_reuse_unchanged', 'project_board_export', (string)$latestReady['id'], [
                'project_id' => $projectId,
                'content_hash' => $fingerprint,
            ]);
            return [
                'success' => true,
                'id' => (int)$latestReady['id'],
                'reused' => true,
                'unchanged' => true,
                'status' => 'ready',
            ];
        }
    }

    $ins = $db->prepare("
        INSERT INTO project_board_exports (project_id, requested_by_user_id, status, content_hash, created_at)
        VALUES (:pid, :uid, 'pending', :hash, CURRENT_TIMESTAMP)
    ");
    $ins->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $ins->bindValue(':uid', $actorUserId, SQLITE3_INTEGER);
    $ins->bindValue(':hash', $fingerprint, SQLITE3_TEXT);
    $ins->execute();
    $jobId = (int)$db->lastInsertRowID();
    createAuditLog($actorUserId, 'project.board_export_request', 'project_board_export', (string)$jobId, [
        'project_id' => $projectId,
        'content_hash' => $fingerprint,
    ]);

    boardExportSpawnWorker($jobId);

    return ['success' => true, 'id' => $jobId, 'status' => 'pending'];
}

function boardExportPhpCliBinary(): string
{
    $candidates = [];
    $bin = (string)(PHP_BINARY ?: '');
    // Under php-fpm, PHP_BINARY is the FPM binary and cannot run CLI scripts.
    if ($bin !== '' && stripos($bin, 'fpm') === false && stripos($bin, 'cgi') === false) {
        $candidates[] = $bin;
    }
    foreach ([
        '/usr/bin/php8.3',
        '/usr/bin/php8.2',
        '/usr/bin/php8.1',
        '/usr/bin/php',
        'php',
    ] as $c) {
        $candidates[] = $c;
    }
    foreach ($candidates as $c) {
        if ($c === 'php') {
            return 'php';
        }
        if (is_executable($c)) {
            return $c;
        }
    }
    return 'php';
}

function boardExportSpawnWorker(int $jobId): void
{
    $candidates = [];
    $repoEnv = getenv('TASKS_REPO_ROOT');
    if (is_string($repoEnv) && $repoEnv !== '') {
        $candidates[] = rtrim($repoEnv, '/\\') . '/tools/board-export-worker.php';
        $candidates[] = rtrim($repoEnv, '/\\') . '/public/cli/board-export-worker.php';
    }
    // Repo checkout layout: public/includes → ../../tools
    $candidates[] = dirname(__DIR__, 2) . '/tools/board-export-worker.php';
    // Multihost WEB_ROOT is the public/ tree only — worker ships under public/cli/
    $candidates[] = dirname(__DIR__) . '/cli/board-export-worker.php';

    $worker = null;
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $worker = $path;
            break;
        }
    }
    if ($worker === null) {
        error_log('boardExportSpawnWorker: worker script missing; tried ' . implode(', ', $candidates));
        return;
    }
    $php = boardExportPhpCliBinary();
    $cmd = sprintf(
        'nohup %s %s %d >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($worker),
        $jobId,
        escapeshellarg(boardExportStorageRoot() . '/worker.log')
    );
    // Pass DB / export env into the worker process.
    $envPrefix = '';
    foreach (['TASKS_DB_PATH', 'TASKS_BOARD_EXPORT_DIR', 'TASKS_ASSET_STORAGE_DIR', 'TASKS_REPO_ROOT'] as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
        }
    }
    exec($envPrefix . $cmd);
}

/**
 * Claim job and build ZIP. Intended for CLI worker.
 *
 * @return array{success:bool,error?:string}
 */
function processBoardExportJob(int $jobId): array
{
    $db = getDbConnection();
    $claim = $db->prepare("
        UPDATE project_board_exports
        SET status = 'running', started_at = CURRENT_TIMESTAMP, error_message = NULL
        WHERE id = :id AND status = 'pending'
    ");
    $claim->bindValue(':id', $jobId, SQLITE3_INTEGER);
    $claim->execute();
    if ($db->changes() < 1) {
        $row = getBoardExportJobById($jobId);
        if (!$row) {
            return ['success' => false, 'error' => 'Job not found'];
        }
        if (($row['status'] ?? '') === 'ready') {
            return ['success' => true];
        }
        if (($row['status'] ?? '') === 'running') {
            // Allow reclaim if stuck — for simplicity continue only if we just claimed.
            return ['success' => false, 'error' => 'Job already running or finished'];
        }
        return ['success' => false, 'error' => 'Job not claimable (status=' . ($row['status'] ?? '') . ')'];
    }

    try {
        $job = getBoardExportJobById($jobId);
        if (!$job) {
            throw new RuntimeException('Job vanished after claim');
        }
        $projectId = (int)$job['project_id'];
        $project = getDirectoryProjectById($projectId);
        if (!$project || ($project['status'] ?? '') !== 'archived') {
            throw new RuntimeException('Project must be archived to export');
        }

        $built = boardExportBuildZipArchive($project, $jobId);
        $hash = (string)($job['content_hash'] ?? '');
        if ($hash === '') {
            $hash = boardExportContentFingerprint($projectId);
        }
        $upd = $db->prepare("
            UPDATE project_board_exports
            SET status = 'ready',
                storage_rel_path = :rel,
                byte_size = :sz,
                content_hash = :hash,
                completed_at = CURRENT_TIMESTAMP,
                error_message = NULL
            WHERE id = :id
        ");
        $upd->bindValue(':rel', $built['rel'], SQLITE3_TEXT);
        $upd->bindValue(':sz', $built['bytes'], SQLITE3_INTEGER);
        $upd->bindValue(':hash', $hash, SQLITE3_TEXT);
        $upd->bindValue(':id', $jobId, SQLITE3_INTEGER);
        $upd->execute();
        return ['success' => true];
    } catch (Throwable $e) {
        $fail = $db->prepare("
            UPDATE project_board_exports
            SET status = 'failed',
                error_message = :err,
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $fail->bindValue(':err', truncateString($e->getMessage(), 1900), SQLITE3_TEXT);
        $fail->bindValue(':id', $jobId, SQLITE3_INTEGER);
        $fail->execute();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @param array<string,mixed> $project
 * @return array{rel:string,bytes:int}
 */
function boardExportBuildZipArchive(array $project, int $jobId): array
{
    $projectId = (int)$project['id'];
    $slug = boardExportSafeSlug((string)$project['name']);
    $rel = sprintf('project-%d/export-%d-%s.zip', $projectId, $jobId, date('Ymd-His'));
    $abs = boardExportAbsolutePath($rel);
    if ($abs === null) {
        throw new RuntimeException('Invalid export path');
    }
    ensureDirExists(dirname($abs));

    $staging = boardExportStorageRoot() . '/staging-' . $jobId . '-' . bin2hex(random_bytes(4));
    ensureDirExists($staging);
    ensureDirExists($staging . '/assets');

    try {
        $assetMap = boardExportCollectAndCopyAssets($projectId, $staging . '/assets');
        boardExportWriteHtmlPages($project, $staging, $assetMap);

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $local = str_replace('\\', '/', substr($full, strlen($staging) + 1));
            $files[$full] = $local;
        }
        boardExportWriteZipFile($abs, $files);

        clearstatcache(true, $abs);
        $bytes = (int)filesize($abs);
        if ($bytes <= 0) {
            throw new RuntimeException('ZIP empty after build');
        }
        if ($bytes > (int)TASKS_BOARD_EXPORT_MAX_BYTES) {
            @unlink($abs);
            throw new RuntimeException('ZIP exceeds TASKS_BOARD_EXPORT_MAX_BYTES');
        }
        return ['rel' => $rel, 'bytes' => $bytes];
    } finally {
        boardExportRmTree($staging);
    }
}

/**
 * Create a ZIP at $absPath from map of absolute path => archive-local name.
 * Prefers ZipArchive; falls back to store-only pure PHP ZIP (no compression).
 *
 * @param array<string,string> $files
 */
function boardExportWriteZipFile(string $absPath, array $files): void
{
    if ($files === []) {
        throw new RuntimeException('No files to zip');
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $full => $local) {
                $zip->addFile($full, $local);
            }
            $zip->close();
            return;
        }
    }

    // Pure-PHP ZIP (stored / method 0) — works without ext-zip.
    $out = fopen($absPath, 'wb');
    if ($out === false) {
        throw new RuntimeException('Could not open ZIP for writing');
    }
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $full => $local) {
        $data = file_get_contents($full);
        if ($data === false) {
            fclose($out);
            throw new RuntimeException('Could not read file for ZIP: ' . $local);
        }
        $name = str_replace('\\', '/', $local);
        $nameLen = strlen($name);
        $size = strlen($data);
        $crc = crc32($data);
        $modTime = boardExportDosTime(filemtime($full) ?: time());

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $modTime[0], $modTime[1], $crc, $size, $size, $nameLen, 0);
        fwrite($out, $localHeader);
        fwrite($out, $name);
        fwrite($out, $data);

        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $modTime[0], $modTime[1], $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset);
        $central .= $name;
        $offset += 30 + $nameLen + $size;
        $count++;
    }
    $centralOffset = $offset;
    fwrite($out, $central);
    $centralSize = strlen($central);
    fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
    fclose($out);
}

/**
 * @return array{0:int,1:int} [dosTime, dosDate]
 */
function boardExportDosTime(int $timestamp): array
{
    $d = getdate($timestamp);
    $time = (($d['hours'] & 0x1f) << 11) | (($d['minutes'] & 0x3f) << 5) | (((int)floor($d['seconds'] / 2)) & 0x1f);
    $date = ((($d['year'] - 1980) & 0x7f) << 9) | (($d['mon'] & 0xf) << 5) | ($d['mday'] & 0x1f);
    return [$time, $date];
}

/**
 * @return array<int,string> attachment id => relative path inside ZIP (assets/...)
 */
function boardExportCollectAndCopyAssets(int $projectId, string $assetsDir): array
{
    $db = getDbConnection();
    $ids = [];

    $stmt = $db->prepare("
        SELECT a.id, a.file_name, a.file_url, a.mime_type, a.storage_kind, a.storage_rel_path, a.task_id
        FROM task_attachments a
        INNER JOIN tasks t ON t.id = a.task_id
        WHERE t.project_id = :pid
        ORDER BY a.id ASC
    ");
    $stmt->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[(int)$row['id']] = $row;
        $ids[(int)$row['id']] = true;
    }

    // Sweep markdown embeds in tasks, comments, documents.
    $bodies = [];
    $tq = $db->prepare('SELECT id, title, body FROM tasks WHERE project_id = :pid');
    $tq->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $tr = $tq->execute();
    while ($t = $tr->fetchArray(SQLITE3_ASSOC)) {
        $bodies[] = (string)($t['body'] ?? '');
        $cq = $db->prepare('SELECT comment FROM task_comments WHERE task_id = :tid');
        $cq->bindValue(':tid', (int)$t['id'], SQLITE3_INTEGER);
        $cr = $cq->execute();
        while ($c = $cr->fetchArray(SQLITE3_ASSOC)) {
            $bodies[] = (string)($c['comment'] ?? '');
        }
    }
    $dq = $db->prepare("SELECT id, body FROM documents WHERE project_id = :pid AND status != 'trashed'");
    $dq->bindValue(':pid', $projectId, SQLITE3_INTEGER);
    $dr = $dq->execute();
    while ($d = $dr->fetchArray(SQLITE3_ASSOC)) {
        $bodies[] = (string)($d['body'] ?? '');
        $dc = $db->prepare('SELECT comment FROM document_comments WHERE document_id = :did');
        $dc->bindValue(':did', (int)$d['id'], SQLITE3_INTEGER);
        $dcr = $dc->execute();
        while ($c = $dcr->fetchArray(SQLITE3_ASSOC)) {
            $bodies[] = (string)($c['comment'] ?? '');
        }
    }

    foreach ($bodies as $body) {
        if (preg_match_all('/get-asset\\.php\\?[^\\s"\'<>]*\\bid=(\\d+)/i', $body, $m)) {
            foreach ($m[1] as $rawId) {
                $aid = (int)$rawId;
                if ($aid > 0) {
                    $ids[$aid] = true;
                }
            }
        }
    }

    // Load any embed-only attachment rows not already selected via project join.
    foreach (array_keys($ids) as $aid) {
        if (isset($rows[$aid])) {
            continue;
        }
        $one = $db->prepare('SELECT id, file_name, file_url, mime_type, storage_kind, storage_rel_path, task_id FROM task_attachments WHERE id = :id');
        $one->bindValue(':id', $aid, SQLITE3_INTEGER);
        $got = $one->execute()->fetchArray(SQLITE3_ASSOC);
        if ($got) {
            $rows[$aid] = $got;
        }
    }

    $map = [];
    foreach ($rows as $aid => $att) {
        $safeName = boardExportSafeFileName((string)$att['file_name']);
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        $base = pathinfo($safeName, PATHINFO_FILENAME);
        $outName = $aid . '-' . $base . ($ext !== '' ? '.' . $ext : '');
        $dest = $assetsDir . '/' . $outName;
        $ok = boardExportMaterializeAttachment($att, $dest);
        if ($ok) {
            $map[$aid] = 'assets/' . $outName;
        } else {
            $note = $dest . '.MISSING.txt';
            file_put_contents(
                $note,
                "Could not include attachment #{$aid} ({$att['file_name']}).\n"
                . 'kind=' . ($att['storage_kind'] ?? '') . "\n"
                . 'url=' . ($att['file_url'] ?? '') . "\n"
            );
            $map[$aid] = 'assets/' . $outName . '.MISSING.txt';
        }
    }
    return $map;
}

/**
 * @param array<string,mixed> $att
 */
function boardExportMaterializeAttachment(array $att, string $destPath): bool
{
    $kind = (string)($att['storage_kind'] ?? 'remote');
    if ($kind === 'local') {
        $rel = (string)($att['storage_rel_path'] ?? '');
        $src = taskAttachmentAbsolutePath($rel);
        if ($src === null || !is_file($src)) {
            return false;
        }
        return @copy($src, $destPath);
    }

    $url = trim((string)($att['file_url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'SanctumTasksBoardExport/1.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || $data === '') {
        return false;
    }
    return file_put_contents($destPath, $data) !== false;
}

/**
 * @param array<string,mixed> $project
 * @param array<int,string> $assetMap
 */
function boardExportWriteHtmlPages(array $project, string $staging, array $assetMap): void
{
    $projectId = (int)$project['id'];
    $userStub = ['id' => 0, 'role' => 'admin', 'person_kind' => 'team_member', 'org_id' => (int)$project['org_id'], 'limited_project_access' => 0];
    // Prefer a real admin row if present for list helpers that expect a user.
    $admin = null;
    $db = getDbConnection();
    $ar = $db->query("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1");
    if ($ar) {
        $admin = $ar->fetchArray(SQLITE3_ASSOC) ?: null;
    }
    $viewer = $admin ?: $userStub;

    $lists = listTodoListsForProject($viewer, $projectId);
    $taskResult = listTasks(['project_id' => $projectId], false, null, is_array($admin) ? $admin : null);
    $tasks = is_array($taskResult) ? $taskResult : [];
    // listTasks without pagination returns list of tasks
    if (isset($tasks['tasks']) && is_array($tasks['tasks'])) {
        $tasks = $tasks['tasks'];
    }

    $docs = listDocumentsForUser(is_array($admin) ? $admin : ['id' => 1, 'role' => 'admin', 'person_kind' => 'team_member', 'org_id' => (int)$project['org_id'], 'limited_project_access' => 0], 500, $projectId);

    $css = <<<'CSS'
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:1.5rem;line-height:1.45;color:#111}
a{color:#0645ad} h1,h2,h3{margin-top:1.4rem}
.meta{color:#555;font-size:.9rem} .card{border:1px solid #ccc;padding:1rem;margin:.75rem 0;border-radius:6px}
pre,code{background:#f5f5f5} img{max-width:100%}
nav a{margin-right:1rem}
CSS;

    $indexItems = [];
    $indexItems[] = '<h1>' . htmlspecialchars((string)$project['name']) . '</h1>';
    $indexItems[] = '<p class="meta">Archived board snapshot · exported '
        . htmlspecialchars(gmdate('Y-m-d H:i') . ' UTC')
        . ' · project_id ' . $projectId . '</p>';
    if (!empty($project['description'])) {
        $indexItems[] = '<p>' . nl2br(htmlspecialchars((string)$project['description'])) . '</p>';
    }
    $indexItems[] = '<h2>Lists</h2><ul>';
    foreach ($lists as $list) {
        $indexItems[] = '<li>' . htmlspecialchars((string)$list['name']) . ' (#' . (int)$list['id'] . ')</li>';
    }
    $indexItems[] = '</ul><h2>Tasks</h2><ul>';
    foreach ($tasks as $t) {
        $tid = (int)$t['id'];
        $indexItems[] = '<li><a href="task-' . $tid . '.html">#' . $tid . ' '
            . htmlspecialchars((string)$t['title']) . '</a>'
            . ' <span class="meta">[' . htmlspecialchars((string)($t['status'] ?? '')) . ']</span></li>';
        boardExportWriteTaskHtml($t, $staging, $assetMap, $css);
    }
    $indexItems[] = '</ul><h2>Documents</h2><ul>';
    foreach ($docs as $d) {
        if (($d['status'] ?? '') === 'trashed') {
            continue;
        }
        $did = (int)$d['id'];
        $full = getDocumentById($did, true);
        if (!$full) {
            continue;
        }
        $indexItems[] = '<li><a href="doc-' . $did . '.html">'
            . htmlspecialchars((string)$full['title']) . '</a></li>';
        boardExportWriteDocHtml($full, $staging, $assetMap, $css);
    }
    $indexItems[] = '</ul>';

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars((string)$project['name'])
        . '</title><style>' . $css . '</style></head><body>'
        . implode("\n", $indexItems)
        . '</body></html>';
    file_put_contents($staging . '/index.html', $html);
}

/**
 * @param array<string,mixed> $task
 * @param array<int,string> $assetMap
 */
function boardExportWriteTaskHtml(array $task, string $staging, array $assetMap, string $css): void
{
    $tid = (int)$task['id'];
    $body = boardExportRewriteAssetUrls((string)($task['body'] ?? ''), $assetMap);
    $comments = listTaskComments($tid, 500, 0);
    $atts = listTaskAttachments($tid);

    $parts = [];
    $parts[] = '<nav><a href="index.html">← Board index</a></nav>';
    $parts[] = '<h1>#' . $tid . ' ' . htmlspecialchars((string)$task['title']) . '</h1>';
    $parts[] = '<p class="meta">status=' . htmlspecialchars((string)($task['status'] ?? ''))
        . ' · priority=' . htmlspecialchars((string)($task['priority'] ?? ''))
        . ' · assignee=' . htmlspecialchars((string)($task['assigned_to_username'] ?? '—'))
        . '</p>';
    $parts[] = '<div class="card">' . boardExportMarkdownToHtml($body) . '</div>';

    $parts[] = '<h2>Comments</h2>';
    if ($comments === []) {
        $parts[] = '<p class="meta">No comments.</p>';
    }
    foreach ($comments as $c) {
        $ct = boardExportRewriteAssetUrls((string)$c['comment'], $assetMap);
        $parts[] = '<div class="card"><div class="meta">'
            . htmlspecialchars((string)$c['username']) . ' · '
            . htmlspecialchars((string)$c['created_at']) . '</div>'
            . boardExportMarkdownToHtml($ct) . '</div>';
    }

    $parts[] = '<h2>Attachments</h2><ul>';
    foreach ($atts as $a) {
        $aid = (int)$a['id'];
        $href = $assetMap[$aid] ?? null;
        if ($href) {
            $parts[] = '<li><a href="' . htmlspecialchars($href) . '">'
                . htmlspecialchars((string)$a['file_name']) . '</a></li>';
        } else {
            $parts[] = '<li>' . htmlspecialchars((string)$a['file_name']) . ' (missing)</li>';
        }
    }
    $parts[] = '</ul>';

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>Task #'
        . $tid . '</title><style>' . $css . '</style></head><body>'
        . implode("\n", $parts) . '</body></html>';
    file_put_contents($staging . '/task-' . $tid . '.html', $html);
}

/**
 * @param array<string,mixed> $doc
 * @param array<int,string> $assetMap
 */
function boardExportWriteDocHtml(array $doc, string $staging, array $assetMap, string $css): void
{
    $did = (int)$doc['id'];
    $body = boardExportRewriteAssetUrls((string)($doc['body'] ?? ''), $assetMap);
    $parts = [];
    $parts[] = '<nav><a href="index.html">← Board index</a></nav>';
    $parts[] = '<h1>' . htmlspecialchars((string)$doc['title']) . '</h1>';
    $parts[] = '<p class="meta">document #' . $did . ' · '
        . htmlspecialchars((string)($doc['status'] ?? '')) . '</p>';
    $parts[] = '<div class="card">' . boardExportMarkdownToHtml($body) . '</div>';

    $html = '<!doctype html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars((string)$doc['title'])
        . '</title><style>' . $css . '</style></head><body>'
        . implode("\n", $parts) . '</body></html>';
    file_put_contents($staging . '/doc-' . $did . '.html', $html);
}

/**
 * @param array<int,string> $assetMap
 */
function boardExportRewriteAssetUrls(string $text, array $assetMap): string
{
    return (string)preg_replace_callback(
        '/(?:\\/api\\/)?get-asset\\.php\\?([^)\\s"\'<>]*)/i',
        static function (array $m) use ($assetMap): string {
            $qs = $m[1] ?? '';
            parse_str($qs, $params);
            $id = isset($params['id']) ? (int)$params['id'] : 0;
            if ($id > 0 && isset($assetMap[$id])) {
                return $assetMap[$id];
            }
            return $m[0];
        },
        $text
    );
}

function boardExportMarkdownToHtml(string $md): string
{
    $md = trim($md);
    if ($md === '') {
        return '<p class="meta">(empty)</p>';
    }
    $parsedown = __DIR__ . '/lib/ParsedownTasks.php';
    if (is_file($parsedown)) {
        require_once $parsedown;
        if (class_exists('ParsedownTasks')) {
            $pd = new ParsedownTasks();
            $pd->setSafeMode(true);
            return $pd->text($md);
        }
    }
    return '<pre>' . htmlspecialchars($md) . '</pre>';
}

function boardExportSafeSlug(string $name): string
{
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? 'board');
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 60) : 'board';
}

function boardExportSafeFileName(string $name): string
{
    $base = basename(str_replace(["\0", '\\'], '', $name));
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?? 'file';
    return $base !== '' ? $base : 'file';
}

function boardExportRmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Stream a ready ZIP to the client (caller already authenticated + gated).
 */
function emitBoardExportDownload(array $job): void
{
    if (($job['status'] ?? '') !== 'ready') {
        http_response_code(404);
        echo 'Export not ready';
        exit;
    }
    $rel = (string)($job['storage_rel_path'] ?? '');
    $abs = boardExportAbsolutePath($rel);
    if ($abs === null || !is_file($abs)) {
        http_response_code(404);
        echo 'Export file missing';
        exit;
    }
    $name = 'board-export-' . (int)$job['project_id'] . '-' . (int)$job['id'] . '.zip';
    header('Content-Type: application/zip');
    header('Content-Length: ' . (string)filesize($abs));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}
