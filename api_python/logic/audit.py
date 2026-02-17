"""Audit logging mirroring PHP createAuditLog."""
import json
from .. import db
from .helpers import truncate_string


def request_ip_address(request) -> str:
    """Get client IP from request (mirror PHP requestIpAddress)."""
    for key in ("cf-connecting-ip", "x-forwarded-for", "x-real-ip"):
        v = request.headers.get(key) if hasattr(request, "headers") else None
        if v:
            v = str(v).strip()
            if key == "x-forwarded-for" and "," in v:
                v = v.split(",")[0].strip()
            if v:
                return truncate_string(v, 128)
    return getattr(request, "client", None) and str(getattr(request.client, "host", "unknown")) or "unknown"


def create_audit_log(
    actor_user_id: int | None,
    action: str,
    entity_type: str,
    entity_id: str | None = None,
    metadata: dict | None = None,
    ip_address: str = "unknown",
) -> None:
    try:
        conn = db.get_connection()
        conn.execute(
            """INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, metadata_json)
               VALUES (?, ?, ?, ?, ?, ?)""",
            (
                actor_user_id,
                truncate_string(action, 80),
                truncate_string(entity_type, 80),
                truncate_string(entity_id, 64) if entity_id else None,
                truncate_string(ip_address, 128),
                json.dumps(metadata) if metadata else None,
            ),
        )
        conn.commit()
        conn.close()
    except Exception:
        pass


def list_audit_logs(limit: int = 100, offset: int = 0) -> list[dict]:
    db.init_schema()
    limit = max(1, min(500, limit))
    offset = max(0, offset)
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT a.*, u.username AS actor_username FROM audit_logs a
           LEFT JOIN users u ON u.id = a.actor_user_id
           ORDER BY a.id DESC LIMIT ? OFFSET ?""",
        (limit, offset),
    )
    rows = []
    for r in cur.fetchall():
        row = dict(r)
        raw = row.get("metadata_json")
        row["metadata"] = json.loads(raw) if raw else []
        rows.append(row)
    conn.close()
    return rows
