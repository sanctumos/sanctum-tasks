"""Login lockout mirroring PHP recordLoginAttempt, getLoginLockState, resetLoginAttempts."""
import time
from .. import db
from .. import config
from .helpers import normalize_username
from .audit import request_ip_address


def record_login_attempt(username: str, success: bool, request=None) -> None:
    db.init_schema()
    u = normalize_username(username)
    if not u:
        return
    ip = request_ip_address(request) if request else "unknown"
    conn = db.get_connection()
    conn.execute("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)", (u, ip, 1 if success else 0))
    conn.commit()
    conn.close()


def get_login_lock_state(username: str, request=None) -> dict:
    db.init_schema()
    u = normalize_username(username)
    if not u:
        return {"locked": False, "remaining_seconds": 0}
    ip = request_ip_address(request) if request else "unknown"
    conn = db.get_connection()
    window_sec = config.LOGIN_LOCK_WINDOW_SECONDS
    cur = conn.execute(
        """SELECT COUNT(*) AS failed_count, MAX(CAST(strftime('%s', attempted_at) AS INTEGER)) AS last_failed_epoch
           FROM login_attempts
           WHERE success = 0 AND attempted_at >= datetime('now', ?)
             AND (username = ? OR ip_address = ?)""",
        (f"-{window_sec} seconds", u, ip),
    )
    row = cur.fetchone()
    conn.close()
    failed_count = row[0] or 0
    last_epoch = row[1]
    if failed_count < config.LOGIN_LOCK_THRESHOLD or not last_epoch:
        return {"locked": False, "remaining_seconds": 0}
    locked_until = last_epoch + config.LOGIN_LOCK_SECONDS
    remaining = locked_until - int(time.time())
    if remaining <= 0:
        return {"locked": False, "remaining_seconds": 0}
    return {"locked": True, "remaining_seconds": remaining}


def reset_login_attempts(username: str, request=None) -> None:
    db.init_schema()
    u = normalize_username(username)
    if not u:
        return
    ip = request_ip_address(request) if request else "unknown"
    conn = db.get_connection()
    conn.execute("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?", (u, ip))
    conn.commit()
    conn.close()
