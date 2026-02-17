"""Task status helpers mirroring PHP listTaskStatuses, getDefaultTaskStatusSlug, sanitizeStatus."""
from .. import db
from .helpers import normalize_slug


def list_task_statuses() -> list[dict]:
    db.init_schema()
    conn = db.get_connection()
    cur = conn.execute(
        "SELECT slug, label, sort_order, is_done, is_default, created_at FROM task_statuses ORDER BY sort_order ASC, slug ASC"
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    for r in rows:
        r["is_done"] = int(r.get("is_done", 0))
        r["is_default"] = int(r.get("is_default", 0))
    return rows


def get_task_status_map() -> dict:
    return {s["slug"]: s for s in list_task_statuses()}


def get_task_status_by_slug(slug: str) -> dict | None:
    slug = normalize_slug(slug)
    if not slug:
        return None
    return get_task_status_map().get(slug)


def get_default_task_status_slug() -> str:
    for s in list_task_statuses():
        if int(s.get("is_default", 0)) == 1:
            return s["slug"]
    return "todo"


def sanitize_status(status: str) -> str | None:
    value = normalize_slug(str(status))
    if not value:
        return None
    return value if get_task_status_by_slug(value) else None


def create_task_status(
    slug: str,
    label: str,
    sort_order: int = 100,
    is_done: bool = False,
    is_default: bool = False,
) -> dict:
    from .helpers import truncate_string
    from . import audit

    slug = normalize_slug(slug)
    label = str(label).strip()
    if not slug:
        return {"success": False, "error": "Status slug is required"}
    if not label:
        return {"success": False, "error": "Status label is required"}
    conn = db.get_connection()
    try:
        if is_default:
            conn.execute("UPDATE task_statuses SET is_default = 0")
        conn.execute(
            """INSERT INTO task_statuses (slug, label, sort_order, is_done, is_default)
               VALUES (?, ?, ?, ?, ?)""",
            (slug, truncate_string(label, 60), sort_order, 1 if is_done else 0, 1 if is_default else 0),
        )
        conn.commit()
        conn.close()
        audit.create_audit_log(None, "task_status.create", "task_status", slug, {"label": label})
        return {"success": True, "slug": slug}
    except Exception:
        conn.close()
        return {"success": False, "error": "Status slug already exists"}
