#!/usr/bin/env php
<?php
/**
 * Link orphaned legacy tasks (project_id NULL, legacy tasks.project text) to directory projects.
 *
 * Run on the server with TASKS_DB_PATH pointing at production SQLite (backup the DB first).
 *
 * Usage:
 *   php tools/backfill_legacy_tasks.php directory-names
 *       Runs backfillTaskProjectIdsFromLegacyNames() — case-insensitive match of legacy text to
 *       a directory project name in the task creator's org.
 *
 *   php tools/backfill_legacy_tasks.php link --project-id=N --labels=invoicing,invoice,invoices
 *       Sets project_id for tasks whose legacy label matches (case-insensitive). By default only
 *       updates tasks whose creator is in the same org as the directory project.
 *
 *   php tools/backfill_legacy_tasks.php link --project-name=Invoicing --org-id=1 --labels=invoicing,invoice
 *       Resolves directory project by name within org_id (case-insensitive).
 *
 *   php tools/backfill_legacy_tasks.php link ... --force-cross-org
 *       Allow linking even when task creator's org != project org (use only if you know the data).
 *
 * @see backfillTaskProjectIdsFromLegacyNames() and backfillLegacyTasksToDirectoryProject() in functions.php
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/public/includes/functions.php';

function usage(int $code = 2): void {
    $msg = <<<'TXT'
Usage:
  php tools/backfill_legacy_tasks.php directory-names
  php tools/backfill_legacy_tasks.php link --project-id=<id> --labels=<comma-separated>
  php tools/backfill_legacy_tasks.php link --project-name=<name> --org-id=<id> --labels=<comma-separated>
Options for link:
  --force-cross-org   Ignore creator-org vs project-org check
TXT;
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function parse_argv(array $argv): array {
    $flags = [];
    $positional = [];
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if ($a === '--help' || $a === '-h') {
            usage(0);
        }
        if (!str_starts_with($a, '--')) {
            $positional[] = $a;
            continue;
        }
        $eq = strpos($a, '=', 2);
        if ($eq !== false) {
            $flags[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
            continue;
        }
        $key = substr($a, 2);
        $boolKeys = ['force-cross-org'];
        if (in_array($key, $boolKeys, true)) {
            $flags[$key] = true;
            continue;
        }
        $next = ($i + 1 < $n && !str_starts_with((string)$argv[$i + 1], '--')) ? $argv[++$i] : null;
        if ($next === null) {
            fwrite(STDERR, "Missing value for --{$key}\n");
            usage(2);
        }
        $flags[$key] = $next;
    }
    return ['flags' => $flags, 'positional' => $positional];
}

function resolve_project_id_from_name(string $name, int $orgId): ?int {
    if ($orgId <= 0) {
        return null;
    }
    $db = getDbConnection();
    $stmt = $db->prepare('SELECT id FROM projects WHERE org_id = :o AND LOWER(name) = LOWER(:n) AND status != \'trashed\' LIMIT 1');
    $stmt->bindValue(':o', $orgId, SQLITE3_INTEGER);
    $stmt->bindValue(':n', trim($name), SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? (int)$row['id'] : null;
}

$parsed = parse_argv($_SERVER['argv'] ?? []);
$cmd = $parsed['positional'][0] ?? null;
if ($cmd === null) {
    usage(2);
}

if ($cmd === 'directory-names') {
    $out = backfillTaskProjectIdsFromLegacyNames();
    echo json_encode(['command' => 'directory-names', 'result' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($cmd !== 'link') {
    fwrite(STDERR, "Unknown command: {$cmd}\n");
    usage(2);
}

$f = $parsed['flags'];
$labelsRaw = (string)($f['labels'] ?? '');
if ($labelsRaw === '') {
    fwrite(STDERR, "link requires --labels=invoicing,invoice,...\n");
    usage(2);
}
$labels = array_values(array_filter(array_map('trim', explode(',', $labelsRaw)), static fn ($s) => $s !== ''));

$pid = isset($f['project-id']) ? (int)$f['project-id'] : 0;
if ($pid <= 0 && !empty($f['project-name'])) {
    $orgId = isset($f['org-id']) ? (int)$f['org-id'] : 0;
    if ($orgId <= 0) {
        fwrite(STDERR, "link with --project-name requires --org-id\n");
        exit(2);
    }
    $resolved = resolve_project_id_from_name((string)$f['project-name'], $orgId);
    if ($resolved === null) {
        fwrite(STDERR, "No directory project named \"{$f['project-name']}\" in org {$orgId}.\n");
        exit(1);
    }
    $pid = $resolved;
}

if ($pid <= 0) {
    fwrite(STDERR, "link requires --project-id or (--project-name and --org-id)\n");
    usage(2);
}

$force = !empty($f['force-cross-org']);
$res = backfillLegacyTasksToDirectoryProject($pid, $labels, $force);
echo json_encode(['command' => 'link', 'result' => $res], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($res['success']) ? 0 : 1);
