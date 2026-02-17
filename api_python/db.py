"""
Database connection and idempotent schema bootstrap mirroring PHP initializeDatabase().
"""
import re
import sqlite3
import hashlib
import bcrypt
from pathlib import Path
from typing import Any

from . import config

_initialized = False


def _assert_identifier(value: str) -> str:
    if not re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", value):
        raise ValueError("Unsafe identifier")
    return value


def get_connection() -> sqlite3.Connection:
    Path(config.DB_PATH).parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(config.DB_PATH, timeout=config.DB_TIMEOUT)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def _column_exists(conn: sqlite3.Connection, table: str, column: str) -> bool:
    _assert_identifier(table)
    _assert_identifier(column)
    cur = conn.execute(f"PRAGMA table_info({table})")
    for row in cur.fetchall():
        if row[1] == column:
            return True
    return False


def _ensure_column_exists(conn: sqlite3.Connection, table: str, column: str, definition: str) -> None:
    if not _column_exists(conn, table, column):
        conn.execute(f"ALTER TABLE {table} ADD COLUMN {column} {definition}")


def _index_exists(conn: sqlite3.Connection, index_name: str) -> bool:
    cur = conn.execute(
        "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
        (index_name,),
    )
    return cur.fetchone() is not None


def _ensure_index_exists(conn: sqlite3.Connection, index_name: str, sql: str) -> None:
    if not _index_exists(conn, index_name):
        conn.execute(sql)


def init_schema() -> None:
    """Idempotent schema bootstrap matching PHP initializeDatabase()."""
    global _initialized
    if _initialized:
        return

    conn = get_connection()
    try:
        # Users
        conn.executescript("""
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
        """)
        _ensure_column_exists(conn, "users", "role", "TEXT NOT NULL DEFAULT 'member'")
        _ensure_column_exists(conn, "users", "is_active", "INTEGER NOT NULL DEFAULT 1")
        _ensure_column_exists(conn, "users", "must_change_password", "INTEGER NOT NULL DEFAULT 0")
        _ensure_column_exists(conn, "users", "mfa_secret", "TEXT DEFAULT NULL")
        _ensure_column_exists(conn, "users", "mfa_enabled", "INTEGER NOT NULL DEFAULT 0")

        # API Keys
        conn.executescript("""
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
        """)
        _ensure_column_exists(conn, "api_keys", "created_by_user_id", "INTEGER DEFAULT NULL")
        _ensure_column_exists(conn, "api_keys", "revoked_at", "DATETIME DEFAULT NULL")
        _ensure_column_exists(conn, "api_keys", "api_key_hash", "TEXT DEFAULT NULL")
        _ensure_column_exists(conn, "api_keys", "key_preview", "TEXT DEFAULT NULL")

        # Backfill api_key_hash/key_preview from api_key where missing
        cur = conn.execute(
            "SELECT id, api_key FROM api_keys WHERE (api_key_hash IS NULL OR api_key_hash = '') AND api_key IS NOT NULL AND api_key != ''"
        )
        for row in cur.fetchall():
            kid, key = row[0], row[1]
            h = hashlib.sha256(key.encode() if isinstance(key, str) else key).hexdigest()
            preview = (key[:12] if isinstance(key, str) else key.decode()[:12]) if key else ""
            conn.execute(
                "UPDATE api_keys SET api_key_hash = ?, key_preview = ? WHERE id = ?",
                (h, preview, kid),
            )

        # Task statuses
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS task_statuses (
                slug TEXT PRIMARY KEY,
                label TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_done INTEGER NOT NULL DEFAULT 0,
                is_default INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """)

        # Tasks
        conn.executescript("""
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
        """)
        _ensure_column_exists(conn, "tasks", "body", "TEXT DEFAULT NULL")
        _ensure_column_exists(conn, "tasks", "due_at", "DATETIME DEFAULT NULL")
        _ensure_column_exists(conn, "tasks", "priority", "TEXT NOT NULL DEFAULT 'normal'")
        _ensure_column_exists(conn, "tasks", "project", "TEXT DEFAULT NULL")
        _ensure_column_exists(conn, "tasks", "tags_json", "TEXT DEFAULT NULL")
        _ensure_column_exists(conn, "tasks", "rank", "INTEGER NOT NULL DEFAULT 0")
        _ensure_column_exists(conn, "tasks", "recurrence_rule", "TEXT DEFAULT NULL")

        # Collaboration
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS task_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                comment TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
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
            );
            CREATE TABLE IF NOT EXISTS task_watchers (
                task_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(task_id, user_id),
                FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
        """)

        # Security + operations
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                ip_address TEXT DEFAULT NULL,
                success INTEGER NOT NULL DEFAULT 0,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS api_rate_limits (
                api_key_hash TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                request_count INTEGER NOT NULL DEFAULT 0,
                last_request_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(api_key_hash, window_start)
            );
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
            );
        """)

        # Indexes
        _ensure_index_exists(conn, "idx_tasks_status", "CREATE INDEX idx_tasks_status ON tasks(status)")
        _ensure_index_exists(conn, "idx_tasks_assigned_to", "CREATE INDEX idx_tasks_assigned_to ON tasks(assigned_to_user_id)")
        _ensure_index_exists(conn, "idx_tasks_due_at", "CREATE INDEX idx_tasks_due_at ON tasks(due_at)")
        _ensure_index_exists(conn, "idx_tasks_priority", "CREATE INDEX idx_tasks_priority ON tasks(priority)")
        _ensure_index_exists(conn, "idx_tasks_rank", "CREATE INDEX idx_tasks_rank ON tasks(rank)")
        _ensure_index_exists(conn, "idx_api_keys_user", "CREATE INDEX idx_api_keys_user ON api_keys(user_id)")
        _ensure_index_exists(conn, "idx_api_keys_revoked_at", "CREATE INDEX idx_api_keys_revoked_at ON api_keys(revoked_at)")
        _ensure_index_exists(conn, "idx_login_attempts_username_time", "CREATE INDEX idx_login_attempts_username_time ON login_attempts(username, attempted_at)")
        _ensure_index_exists(conn, "idx_login_attempts_ip_time", "CREATE INDEX idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at)")
        _ensure_index_exists(conn, "idx_comments_task", "CREATE INDEX idx_comments_task ON task_comments(task_id)")
        _ensure_index_exists(conn, "idx_watchers_user", "CREATE INDEX idx_watchers_user ON task_watchers(user_id)")
        _ensure_index_exists(conn, "idx_attachments_task", "CREATE INDEX idx_attachments_task ON task_attachments(task_id)")
        _ensure_index_exists(conn, "idx_audit_logs_action_time", "CREATE INDEX idx_audit_logs_action_time ON audit_logs(action, created_at)")

        # Seed task statuses
        conn.executescript("""
            INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('todo', 'To Do', 10, 0, 1);
            INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('doing', 'In Progress', 20, 0, 0);
            INSERT OR IGNORE INTO task_statuses (slug, label, sort_order, is_done, is_default) VALUES ('done', 'Done', 30, 1, 0);
        """)

        # Bootstrap admin user
        username = config.get_bootstrap_admin_username()
        cur = conn.execute("SELECT id FROM users WHERE username = ? LIMIT 1", (username,))
        row = cur.fetchone()
        if not row:
            password = config.get_bootstrap_admin_password()
            pw_hash = bcrypt.hashpw(
                password.encode(),
                bcrypt.gensalt(rounds=config.PASSWORD_COST),
            ).decode()
            conn.execute(
                "INSERT INTO users (username, password_hash, role, is_active, must_change_password) VALUES (?, ?, 'admin', 1, 1)",
                (username, pw_hash),
            )
            admin_id = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
        else:
            admin_id = row[0]
            conn.execute(
                "UPDATE users SET role = CASE WHEN role = '' THEN 'admin' ELSE role END, is_active = 1 WHERE id = ?",
                (admin_id,),
            )

        # Bootstrap API key (store hash only)
        api_key = config.get_bootstrap_api_key()
        key_hash = hashlib.sha256(api_key.encode()).hexdigest()
        key_preview = api_key[:12]
        cur = conn.execute("SELECT id FROM api_keys WHERE api_key_hash = ? LIMIT 1", (key_hash,))
        if cur.fetchone() is None:
            conn.execute(
                """INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id)
                   VALUES (?, 'bootstrap', ?, ?, ?, ?)""",
                (admin_id, key_hash, key_hash, key_preview, admin_id),
            )

        # Python-only: api_sessions for session endpoints
        conn.executescript("""
            CREATE TABLE IF NOT EXISTS api_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            );
            CREATE INDEX IF NOT EXISTS idx_api_sessions_token ON api_sessions(token);
            CREATE INDEX IF NOT EXISTS idx_api_sessions_expires ON api_sessions(expires_at);
        """)
        _ensure_column_exists(conn, "api_sessions", "csrf_token", "TEXT DEFAULT NULL")

        conn.commit()
        _initialized = True
    finally:
        conn.close()
