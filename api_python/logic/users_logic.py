"""User management mirroring PHP listUsers, createUser, setUserActive, resetUserPassword."""
import bcrypt
from .. import db
from .. import config
from .helpers import (
    normalize_username,
    validate_username,
    validate_password,
    normalize_role,
    generate_temporary_password,
)
from . import audit


def list_users(include_disabled: bool = False) -> list[dict]:
    db.init_schema()
    conn = db.get_connection()
    sql = """SELECT id, username, role, is_active, must_change_password, mfa_enabled, created_at FROM users"""
    if not include_disabled:
        sql += " WHERE is_active = 1"
    sql += " ORDER BY username ASC"
    cur = conn.execute(sql)
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    for r in rows:
        r["is_active"] = int(r.get("is_active", 0))
        r["must_change_password"] = int(r.get("must_change_password", 0))
        r["mfa_enabled"] = int(r.get("mfa_enabled", 0))
    return rows


def create_user(
    username: str,
    password: str,
    role: str = "member",
    must_change_password: bool = True,
) -> dict:
    err = validate_username(username)
    if err:
        return {"success": False, "error": err}
    err = validate_password(password, config.PASSWORD_MIN_LENGTH)
    if err:
        return {"success": False, "error": err}
    normalized_role = normalize_role(role)
    if normalized_role is None:
        return {"success": False, "error": "Invalid role"}
    conn = db.get_connection()
    try:
        pw_hash = bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=config.PASSWORD_COST)).decode()
        conn.execute(
            """INSERT INTO users (username, password_hash, role, is_active, must_change_password)
               VALUES (?, ?, ?, 1, ?)""",
            (normalize_username(username), pw_hash, normalized_role, 1 if must_change_password else 0),
        )
        uid = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
        conn.commit()
        conn.close()
        audit.create_audit_log(None, "user.create", "user", str(uid), {"username": normalize_username(username), "role": normalized_role})
        return {"success": True, "id": uid}
    except Exception:
        conn.close()
        return {"success": False, "error": "Username already exists"}


def set_user_active(user_id: int, is_active: bool) -> dict:
    if user_id <= 0:
        return {"success": False, "error": "Invalid user id"}
    conn = db.get_connection()
    conn.execute("UPDATE users SET is_active = ? WHERE id = ?", (1 if is_active else 0, user_id))
    conn.commit()
    conn.close()
    audit.create_audit_log(None, "user.enable" if is_active else "user.disable", "user", str(user_id))
    return {"success": True}


def reset_user_password(user_id: int, new_password: str, must_change_password: bool = True) -> dict:
    err = validate_password(new_password, config.PASSWORD_MIN_LENGTH)
    if err:
        return {"success": False, "error": err}
    conn = db.get_connection()
    pw_hash = bcrypt.hashpw(new_password.encode(), bcrypt.gensalt(rounds=config.PASSWORD_COST)).decode()
    conn.execute(
        "UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?",
        (pw_hash, 1 if must_change_password else 0, user_id),
    )
    conn.commit()
    conn.close()
    audit.create_audit_log(None, "user.password_reset", "user", str(user_id), {"must_change_password": 1 if must_change_password else 0})
    return {"success": True}


def generate_temp_password(length: int = 16) -> str:
    return generate_temporary_password(length, config.PASSWORD_MIN_LENGTH)
