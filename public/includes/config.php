<?php
// Sanctum Tasks - Configuration + DB bootstrap

$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile)) {
    require_once $secretsFile;
}

function envOrDefault(string $name, $default = null) {
    $v = getenv($name);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return $v;
}

function envBool(string $name, bool $default): bool {
    $raw = envOrDefault($name, $default ? '1' : '0');
    $parsed = filter_var((string)$raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

// Database configuration
define('DB_PATH', envOrDefault('TASKS_DB_PATH', __DIR__ . '/../../db/tasks.db'));
define('DB_TIMEOUT', (int)envOrDefault('TASKS_DB_TIMEOUT', 30));

// Security settings
define('SESSION_NAME', envOrDefault('TASKS_SESSION_NAME', 'sanctum_tasks'));
define('SESSION_LIFETIME', (int)envOrDefault('TASKS_SESSION_LIFETIME', 3600));
define('PASSWORD_COST', (int)envOrDefault('TASKS_PASSWORD_COST', 12));
define('PASSWORD_MIN_LENGTH', (int)envOrDefault('TASKS_PASSWORD_MIN_LENGTH', 12));
// Default secure=true for HTTPS; set TASKS_SESSION_COOKIE_SECURE=0 for local HTTP dev only
define('SESSION_COOKIE_SECURE', envBool('TASKS_SESSION_COOKIE_SECURE', true));
define('LOGIN_LOCK_THRESHOLD', (int)envOrDefault('TASKS_LOGIN_LOCK_THRESHOLD', 5));
define('LOGIN_LOCK_WINDOW_SECONDS', (int)envOrDefault('TASKS_LOGIN_LOCK_WINDOW_SECONDS', 900));
define('LOGIN_LOCK_SECONDS', (int)envOrDefault('TASKS_LOGIN_LOCK_SECONDS', 900));
define('API_RATE_LIMIT_REQUESTS', (int)envOrDefault('TASKS_API_RATE_LIMIT_REQUESTS', 240));
define('API_RATE_LIMIT_WINDOW_SECONDS', (int)envOrDefault('TASKS_API_RATE_LIMIT_WINDOW_SECONDS', 60));
define('APP_DEBUG', envBool('TASKS_APP_DEBUG', false));

// Initialize session (admin UI uses session auth)
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

date_default_timezone_set('UTC');

function getDbConnection() {
    try {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        $db->busyTimeout(DB_TIMEOUT * 1000);
        $db->exec('PRAGMA foreign_keys = ON;');
        return $db;
    } catch (Exception $e) {
        http_response_code(500);
        $msg = APP_DEBUG ? ('Database connection failed: ' . $e->getMessage()) : 'Database connection failed';
        die($msg);
    }
}

function assertIdentifier(string $value): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
        throw new InvalidArgumentException('Unsafe identifier');
    }
    return $value;
}

function columnExists($db, $table, $column) {
    $table = assertIdentifier($table);
    $column = assertIdentifier($column);
    $result = $db->query("PRAGMA table_info($table)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $column) {
            return true;
        }
    }
    return false;
}

function ensureColumnExists($db, $table, $column, $definition) {
    $table = assertIdentifier($table);
    $column = assertIdentifier($column);
    if (!columnExists($db, $table, $column)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function ensureIndexExists($db, $indexName, $sql) {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name");
    $stmt->bindValue(':name', $indexName, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $db->exec($sql);
    }
}

function ensureDirExists(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

function loadOrGenerateSecretFile(string $filePath, int $bytes = 24): string {
    if (is_file($filePath)) {
        $existing = trim((string)@file_get_contents($filePath));
        if ($existing !== '') {
            return $existing;
        }
    }

    $secret = bin2hex(random_bytes($bytes));
    @file_put_contents($filePath, $secret);
    @chmod($filePath, 0600);
    return $secret;
}

function getBootstrapAdminUsername(): string {
    return (string)envOrDefault('TASKS_BOOTSTRAP_ADMIN_USERNAME', 'admin');
}

function getBootstrapAdminPassword(): string {
    $configured = trim((string)envOrDefault('TASKS_BOOTSTRAP_ADMIN_PASSWORD', ''));
    if ($configured !== '') {
        return $configured;
    }

    $dir = dirname(DB_PATH);
    ensureDirExists($dir);
    return loadOrGenerateSecretFile($dir . '/bootstrap_admin_password.txt');
}

function getBootstrapApiKey(): string {
    $configured = trim((string)envOrDefault('TASKS_BOOTSTRAP_API_KEY', ''));
    if ($configured !== '') {
        return $configured;
    }

    $dir = dirname(DB_PATH);
    ensureDirExists($dir);
    return loadOrGenerateSecretFile($dir . '/api_key.txt', 32);
}

/** Returns 32-byte key for MFA secret encryption (C-02). */
function getMfaEncryptionKey(): string {
    $raw = trim((string)envOrDefault('TASKS_MFA_ENCRYPTION_KEY', ''));
    if ($raw !== '' && strlen($raw) === 64 && ctype_xdigit($raw)) {
        return hex2bin($raw);
    }
    $dir = dirname(DB_PATH);
    ensureDirExists($dir);
    $hex = loadOrGenerateSecretFile($dir . '/mfa_encryption_key.txt', 32);
    return hex2bin($hex);
}

function initializeDatabase() {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $db = getDbConnection();

    // Users
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'member',
            is_active INTEGER NOT NULL DEFAULT 1,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            mfa_secret TEXT DEFAULT NULL,
            mfa_enabled INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    ensureColumnExists($db, 'users', 'role', "TEXT NOT NULL DEFAULT 'member'");
    ensureColumnExists($db, 'users', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumnExists($db, 'users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($db, 'users', 'mfa_secret', 'TEXT DEFAULT NULL');
    ensureColumnExists($db, 'users', 'mfa_enabled', 'INTEGER NOT NULL DEFAULT 0');

    // API Keys (each key maps to a user)
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            key_name TEXT NOT NULL,
            api_key TEXT UNIQUE NOT NULL,
            created_by_user_id INTEGER DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used DATETIME,
            revoked_at DATETIME DEFAULT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id),
            FOREIGN KEY(created_by_user_id) REFERENCES users(id)
        )
    ");
    ensureColumnExists($db, 'api_keys', 'created_by_user_id', 'INTEGER DEFAULT NULL');
    ensureColumnExists($db, 'api_keys', 'revoked_at', 'DATETIME DEFAULT NULL');
    ensureColumnExists($db, 'api_keys', 'api_key_hash', 'TEXT DEFAULT NULL');
    ensureColumnExists($db, 'api_keys', 'key_preview', 'TEXT DEFAULT NULL');
    // Backfill: store only hashes; existing rows get api_key_hash and key_preview from api_key
    $backfill = $db->query("SELECT id, api_key FROM api_keys WHERE (api_key_hash IS NULL OR api_key_hash = '') AND api_key IS NOT NULL AND api_key != ''");
    while ($backfill && ($row = $backfill->fetchArray(SQLITE3_ASSOC))) {
        $id = (int)$row['id'];
        $key = (string)$row['api_key'];
        $h = hash('sha256', $key);
        $preview = substr($key, 0, 12);
        $upd = $db->prepare("UPDATE api_keys SET api_key_hash = :h, key_preview = :p WHERE id = :id");
        $upd->bindValue(':h', $h, SQLITE3_TEXT);
        $upd->bindValue(':p', $preview, SQLITE3_TEXT);
        $upd->bindValue(':id', $id, SQLITE3_INTEGER);
        $upd->execute();
    }

    // Task statuses (customizable workflow states)
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_statuses (
            slug TEXT PRIMARY KEY,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_done INTEGER NOT NULL DEFAULT 0,
            is_default INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Tasks
    $db->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'todo',
            due_at DATETIME DEFAULT NULL,
            priority TEXT NOT NULL DEFAULT 'normal',
            project TEXT DEFAULT NULL,
            tags_json TEXT DEFAULT NULL,
            rank INTEGER NOT NULL DEFAULT 0,
            recurrence_rule TEXT DEFAULT NULL,
            created_by_user_id INTEGER NOT NULL,
            assigned_to_user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id),
            FOREIGN KEY(assigned_to_user_id) REFERENCES users(id)
        )
    ");
    ensureColumnExists($db, 'tasks', 'body', 'TEXT DEFAULT NULL');
    ensureColumnExists($db, 'tasks', 'due_at', 'DATETIME DEFAULT NULL');
    ensureColumnExists($db, 'tasks', 'priority', "TEXT NOT NULL DEFAULT 'normal'");
    ensureColumnExists($db, 'tasks', 'project', 'TEXT DEFAULT NULL');
    ensureColumnExists($db, 'tasks', 'tags_json', 'TEXT DEFAULT NULL');
    ensureColumnExists($db, 'tasks', 'rank', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumnExists($db, 'tasks', 'recurrence_rule', 'TEXT DEFAULT NULL');

    // Collaboration entities
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            comment TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS task_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            uploaded_by_user_id INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            file_url TEXT NOT NULL,
            mime_type TEXT DEFAULT NULL,
            size_bytes INTEGER DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(uploaded_by_user_id) REFERENCES users(id)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS task_watchers (
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(task_id, user_id),
            FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )
    ");

    // Security + operations
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip_address TEXT DEFAULT NULL,
            success INTEGER NOT NULL DEFAULT 0,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            api_key_hash TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            last_request_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(api_key_hash, window_start)
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER DEFAULT NULL,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id TEXT DEFAULT NULL,
            ip_address TEXT DEFAULT NULL,
            metadata_json TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(actor_user_id) REFERENCES users(id)
        )
    ");

    // Helpful indexes
    ensureIndexExists($db, 'idx_tasks_status', 'CREATE INDEX idx_tasks_status ON tasks(status)');
    ensureIndexExists($db, 'idx_tasks_assigned_to', 'CREATE INDEX idx_tasks_assigned_to ON tasks(assigned_to_user_id)');
    ensureIndexExists($db, 'idx_tasks_due_at', 'CREATE INDEX idx_tasks_due_at ON tasks(due_at)');
    ensureIndexExists($db, 'idx_tasks_priority', 'CREATE INDEX idx_tasks_priority ON tasks(priority)');
    ensureIndexExists($db, 'idx_tasks_rank', 'CREATE INDEX idx_tasks_rank ON tasks(rank)');
    ensureIndexExists($db, 'idx_api_keys_user', 'CREATE INDEX idx_api_keys_user ON api_keys(user_id)');
    ensureIndexExists($db, 'idx_api_keys_revoked_at', 'CREATE INDEX idx_api_keys_revoked_at ON api_keys(revoked_at)');
    ensureIndexExists($db, 'idx_login_attempts_username_time', 'CREATE INDEX idx_login_attempts_username_time ON login_attempts(username, attempted_at)');
    ensureIndexExists($db, 'idx_login_attempts_ip_time', 'CREATE INDEX idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at)');
    ensureIndexExists($db, 'idx_comments_task', 'CREATE INDEX idx_comments_task ON task_comments(task_id)');
    ensureIndexExists($db, 'idx_watchers_user', 'CREATE INDEX idx_watchers_user ON task_watchers(user_id)');
    ensureIndexExists($db, 'idx_attachments_task', 'CREATE INDEX idx_attachments_task ON task_attachments(task_id)');
    ensureIndexExists($db, 'idx_audit_logs_action_time', 'CREATE INDEX idx_audit_logs_action_time ON audit_logs(action, created_at)');

    // Seed status workflow if empty.
    $db->exec("INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('todo', 'To Do', 10, 0, 1)");
    $db->exec("INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('doing', 'In Progress', 20, 0, 0)");
    $db->exec("INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('done', 'Done', 30, 1, 0)");

    // Bootstrap admin user
    $bootstrapUsername = getBootstrapAdminUsername();
    $adminStmt = $db->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $adminStmt->bindValue(':username', $bootstrapUsername, SQLITE3_TEXT);
    $adminRes = $adminStmt->execute();
    $adminRow = $adminRes->fetchArray(SQLITE3_ASSOC);
    if (!$adminRow) {
        $bootstrapPassword = getBootstrapAdminPassword();
        $defaultHash = password_hash($bootstrapPassword, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
        $stmt = $db->prepare("
            INSERT INTO users (username, password_hash, role, is_active, must_change_password)
            VALUES (:username, :hash, 'admin', 1, 1)
        ");
        $stmt->bindValue(':username', $bootstrapUsername, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $defaultHash, SQLITE3_TEXT);
        $stmt->execute();
        $adminId = (int)$db->lastInsertRowID();
    } else {
        $adminId = (int)$adminRow['id'];
        $fix = $db->prepare("
            UPDATE users
            SET role = CASE WHEN role = '' THEN 'admin' ELSE role END,
                is_active = 1
            WHERE id = :id
        ");
        $fix->bindValue(':id', $adminId, SQLITE3_INTEGER);
        $fix->execute();
    }

    // Ensure bootstrap API key exists for admin (store hash only, C-01).
    $apiKey = getBootstrapApiKey();
    $keyHash = hash('sha256', $apiKey);
    $keyPreview = substr($apiKey, 0, 12);
    $stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key_hash = :hash LIMIT 1");
    $stmt->bindValue(':hash', $keyHash, SQLITE3_TEXT);
    $res = $stmt->execute();
    $existingKey = $res->fetchArray(SQLITE3_ASSOC);
    if (!$existingKey) {
        $ins = $db->prepare("
            INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id)
            VALUES (:uid, :name, :key, :hash, :preview, :created_by)
        ");
        $ins->bindValue(':uid', $adminId, SQLITE3_INTEGER);
        $ins->bindValue(':name', 'bootstrap', SQLITE3_TEXT);
        $ins->bindValue(':key', $keyHash, SQLITE3_TEXT);
        $ins->bindValue(':hash', $keyHash, SQLITE3_TEXT);
        $ins->bindValue(':preview', $keyPreview, SQLITE3_TEXT);
        $ins->bindValue(':created_by', $adminId, SQLITE3_INTEGER);
        $ins->execute();
    }

    $initialized = true;
}

