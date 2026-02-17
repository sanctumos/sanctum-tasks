"""API key lifecycle mirroring PHP createApiKeyForUser, listApiKeysForUser, getAllApiKeys, revokeApiKey."""
import secrets
import hashlib
from .. import db
from .helpers import truncate_string
from . import audit


def create_api_key_for_user(user_id: int, key_name: str, created_by_user_id: int | None = None) -> str:
    conn = db.get_connection()
    api_key = secrets.token_hex(32)
    key_hash = hashlib.sha256(api_key.encode()).hexdigest()
    key_preview = api_key[:12]
    name = truncate_string((key_name or "Unnamed Key").strip(), 80)
    conn.execute(
        """INSERT INTO api_keys (user_id, key_name, api_key, api_key_hash, key_preview, created_by_user_id)
           VALUES (?, ?, ?, ?, ?, ?)""",
        (user_id, name, key_hash, key_hash, key_preview, created_by_user_id),
    )
    kid = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
    conn.commit()
    conn.close()
    audit.create_audit_log(created_by_user_id, "api_key.create", "api_key", str(kid), {"user_id": user_id, "key_name": name})
    return api_key


def list_api_keys_for_user(user_id: int, include_revoked: bool = False) -> list[dict]:
    conn = db.get_connection()
    sql = """SELECT id, user_id, key_name, COALESCE(key_preview, substr(api_key, 1, 12)) AS api_key_preview, created_at, last_used, revoked_at
             FROM api_keys WHERE user_id = ?"""
    if not include_revoked:
        sql += " AND revoked_at IS NULL"
    sql += " ORDER BY created_at DESC"
    cur = conn.execute(sql, (user_id,))
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def get_all_api_keys(include_revoked: bool = False) -> list[dict]:
    conn = db.get_connection()
    sql = """SELECT ak.id, ak.user_id, ak.key_name,
             COALESCE(ak.key_preview, substr(ak.api_key, 1, 12)) AS api_key_preview,
             ak.created_at, ak.last_used, ak.revoked_at, u.username AS user_username
             FROM api_keys ak JOIN users u ON u.id = ak.user_id"""
    if not include_revoked:
        sql += " WHERE ak.revoked_at IS NULL"
    sql += " ORDER BY ak.created_at DESC"
    cur = conn.execute(sql)
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def revoke_api_key(key_id: int) -> bool:
    conn = db.get_connection()
    cur = conn.execute("UPDATE api_keys SET revoked_at = datetime('now') WHERE id = ?", (key_id,))
    conn.commit()
    changed = cur.rowcount
    conn.close()
    if changed:
        audit.create_audit_log(None, "api_key.revoke", "api_key", str(key_id))
    return changed > 0
