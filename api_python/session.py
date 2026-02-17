"""
Session store for Python API (api_sessions table).
Create/validate/destroy session by token; set cookie for TUI.
"""
import secrets
import time
from datetime import datetime, timezone, timedelta

from . import db
from . import config
from .logic import users
from .logic import audit


def _now_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def create_session(user_id: int) -> tuple[str, str]:
    """Create a session for user_id. Returns (token, csrf_token)."""
    db.init_schema()
    token = secrets.token_urlsafe(48)
    csrf_token = secrets.token_hex(32)
    expires_at = (datetime.now(timezone.utc) + timedelta(seconds=config.SESSION_LIFETIME_SECONDS)).strftime("%Y-%m-%d %H:%M:%S")
    conn = db.get_connection()
    conn.execute(
        "INSERT INTO api_sessions (token, user_id, expires_at, csrf_token) VALUES (?, ?, ?, ?)",
        (token, user_id, expires_at, csrf_token),
    )
    conn.commit()
    conn.close()
    return token, csrf_token


def validate_session(token: str | None) -> tuple[dict, str] | None:
    """
    Validate session token. Returns (user_dict, csrf_token) or None if invalid/expired.
    """
    if not token or not token.strip():
        return None
    db.init_schema()
    conn = db.get_connection()
    cur = conn.execute(
        "SELECT user_id, expires_at, csrf_token FROM api_sessions WHERE token = ? LIMIT 1",
        (token.strip(),),
    )
    row = cur.fetchone()
    conn.close()
    if not row:
        return None
    user_id, expires_at, csrf_token = row[0], row[1], row[2] or ""
    try:
        exp_dt = datetime.strptime(expires_at, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except Exception:
        return None
    if datetime.now(timezone.utc) >= exp_dt:
        return None
    user = users.get_user_by_id(user_id, False)
    if not user or int(user.get("is_active", 0)) != 1:
        return None
    return (user, csrf_token)


def destroy_session(token: str | None) -> bool:
    """Remove session by token. Returns True if a row was deleted."""
    if not token or not token.strip():
        return False
    conn = db.get_connection()
    cur = conn.execute("DELETE FROM api_sessions WHERE token = ?", (token.strip(),))
    conn.commit()
    deleted = cur.rowcount
    conn.close()
    return deleted > 0


def verify_csrf(session_csrf: str | None, provided: str | None) -> bool:
    if not session_csrf or not provided:
        return False
    return secrets.compare_digest(session_csrf, provided)


# Cookie name for API session token (distinct from PHP session)
SESSION_COOKIE_NAME = "sanctum_tasks_py_session"


def get_session_token_from_request(request) -> str | None:
    """Get session token from cookie (for session-me / session-logout)."""
    if not hasattr(request, "cookies"):
        return None
    return request.cookies.get(SESSION_COOKIE_NAME)
