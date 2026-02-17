"""User lookup mirroring PHP getUserById, getUserByUsername."""
from .. import db
from .helpers import normalize_username


def get_user_by_id(user_id: int, include_sensitive: bool = False) -> dict | None:
    db.init_schema()
    conn = db.get_connection()
    if include_sensitive:
        cur = conn.execute(
            "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, created_at FROM users WHERE id = ? LIMIT 1",
            (int(user_id),),
        )
    else:
        cur = conn.execute(
            "SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at FROM users WHERE id = ? LIMIT 1",
            (int(user_id),),
        )
    row = cur.fetchone()
    conn.close()
    if not row:
        return None
    r = dict(row)
    r["is_active"] = int(r.get("is_active", 0))
    r["must_change_password"] = int(r.get("must_change_password", 0))
    r["mfa_enabled"] = int(r.get("mfa_enabled", 0))
    return r


def get_user_by_username(username: str, include_sensitive: bool = False) -> dict | None:
    db.init_schema()
    u = normalize_username(username)
    if not u:
        return None
    conn = db.get_connection()
    if include_sensitive:
        cur = conn.execute(
            "SELECT id, username, role, is_active, must_change_password, mfa_enabled, mfa_secret, password_hash, created_at FROM users WHERE username = ? LIMIT 1",
            (u,),
        )
    else:
        cur = conn.execute(
            "SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at FROM users WHERE username = ? LIMIT 1",
            (u,),
        )
    row = cur.fetchone()
    conn.close()
    if not row:
        return None
    r = dict(row)
    r["is_active"] = int(r.get("is_active", 0))
    r["must_change_password"] = int(r.get("must_change_password", 0))
    r["mfa_enabled"] = int(r.get("mfa_enabled", 0))
    return r
