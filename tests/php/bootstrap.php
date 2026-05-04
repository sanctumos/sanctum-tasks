<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: isolated SQLite DB + safe session defaults for CLI.
 * Integration tests that start their own PHP built-in server use a separate DB via env in the server process.
 */

$unitDb = sys_get_temp_dir() . '/sanctum_tasks_phpunit_' . uniqid('', true) . '.db';

putenv('TASKS_DB_PATH=' . $unitDb);
$_ENV['TASKS_DB_PATH'] = $unitDb;
putenv('TASKS_SESSION_COOKIE_SECURE=0');
putenv('TASKS_PASSWORD_COST=8');
putenv('TASKS_APP_DEBUG=1');
putenv('TASKS_API_RATE_LIMIT_REQUESTS=10000');
putenv('TASKS_LOGIN_LOCK_THRESHOLD=50');

// Load core (initializes schema on first include).
require_once dirname(__DIR__, 2) . '/public/includes/functions.php';

register_shutdown_function(static function () use ($unitDb): void {
    if (is_file($unitDb)) {
        @unlink($unitDb);
    }
});
