"""Task CRUD and list mirroring PHP listTasks, getTaskById, createTask, updateTask, deleteTask, bulkCreateTasks, bulkUpdateTasks."""
from .. import db
from .helpers import (
    truncate_string,
    normalize_task_title,
    normalize_task_body,
    normalize_task_project,
    normalize_task_recurrence_rule,
    normalize_priority,
    normalize_tags,
    encode_tags_json,
    decode_tags_json,
    parse_datetime_or_null,
    now_utc,
)
from .statuses import sanitize_status, get_default_task_status_slug
from .users import get_user_by_id
from . import audit
from .. import auth as auth_module


def task_order_by_clause(sort_by: str, sort_dir: str) -> str:
    dir_ = "ASC" if str(sort_dir).upper() == "ASC" else "DESC"
    sort_by = str(sort_by).lower().strip()
    if sort_by == "priority":
        return f"CASE t.priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 WHEN 'low' THEN 1 ELSE 0 END {dir_}, t.id DESC"
    if sort_by == "due_at":
        return f"t.due_at {dir_}, t.id DESC"
    if sort_by == "created_at":
        return f"t.created_at {dir_}, t.id DESC"
    if sort_by == "rank":
        return f"t.rank {dir_}, t.updated_at DESC, t.id DESC"
    if sort_by == "title":
        return f"t.title {dir_}, t.id DESC"
    if sort_by == "status":
        return f"COALESCE(ts.sort_order, 9999) {dir_}, t.id DESC"
    return f"t.updated_at {dir_}, t.id DESC"


def hydrate_task_row(row: dict) -> dict:
    row = dict(row)
    row["tags"] = decode_tags_json(row.get("tags_json"))
    row["rank"] = int(row.get("rank", 0))
    row["comment_count"] = int(row.get("comment_count", 0))
    row["attachment_count"] = int(row.get("attachment_count", 0))
    row["watcher_count"] = int(row.get("watcher_count", 0))
    return row


def get_task_by_id(task_id: int, include_relations: bool = True) -> dict | None:
    db.init_schema()
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT t.*, ts.label AS status_label, ts.sort_order AS status_sort_order, ts.is_done AS status_is_done,
           cu.username AS created_by_username, au.username AS assigned_to_username,
           (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comment_count,
           (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachment_count,
           (SELECT COUNT(*) FROM task_watchers tw WHERE tw.task_id = t.id) AS watcher_count
           FROM tasks t
           JOIN users cu ON cu.id = t.created_by_user_id
           LEFT JOIN users au ON au.id = t.assigned_to_user_id
           LEFT JOIN task_statuses ts ON ts.slug = t.status
           WHERE t.id = ? LIMIT 1""",
        (int(task_id),),
    )
    row = cur.fetchone()
    conn.close()
    if not row:
        return None
    task = hydrate_task_row(dict(row))
    if include_relations:
        task["comments"] = list_task_comments(int(task_id), 200, 0)
        task["attachments"] = list_task_attachments(int(task_id))
        task["watchers"] = list_task_watchers(int(task_id))
    return task


def list_task_comments(task_id: int, limit: int = 100, offset: int = 0) -> list[dict]:
    limit = max(1, min(500, limit))
    offset = max(0, offset)
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT c.id, c.task_id, c.user_id, c.comment, c.created_at, u.username
           FROM task_comments c JOIN users u ON u.id = c.user_id
           WHERE c.task_id = ? ORDER BY c.id ASC LIMIT ? OFFSET ?""",
        (task_id, limit, offset),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def list_task_attachments(task_id: int) -> list[dict]:
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT a.id, a.task_id, a.uploaded_by_user_id, u.username AS uploaded_by_username,
           a.file_name, a.file_url, a.mime_type, a.size_bytes, a.created_at
           FROM task_attachments a JOIN users u ON u.id = a.uploaded_by_user_id
           WHERE a.task_id = ? ORDER BY a.id ASC""",
        (task_id,),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def list_task_watchers(task_id: int) -> list[dict]:
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT w.task_id, w.user_id, w.created_at, u.username
           FROM task_watchers w JOIN users u ON u.id = w.user_id WHERE w.task_id = ? ORDER BY u.username ASC""",
        (task_id,),
    )
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows


def user_can_access_task(user_id: int, task: dict, role: str) -> bool:
    if auth_module.is_admin_role(role):
        return True
    created_by = int(task.get("created_by_user_id", 0))
    assigned_to = int(task.get("assigned_to_user_id", 0))
    return created_by == user_id or assigned_to == user_id


def list_tasks(
    filters: dict,
    with_pagination: bool = False,
    api_user: dict | None = None,
) -> list[dict] | dict:
    db.init_schema()
    conn = db.get_connection()

    where_clauses: list[str] = []
    params: list = []
    joins = [
        "JOIN users cu ON cu.id = t.created_by_user_id",
        "LEFT JOIN users au ON au.id = t.assigned_to_user_id",
        "LEFT JOIN task_statuses ts ON ts.slug = t.status",
    ]

    if filters.get("status"):
        s = sanitize_status(str(filters["status"]))
        if s:
            where_clauses.append("t.status = ?")
            params.append(s)

    if filters.get("priority"):
        p = normalize_priority(str(filters["priority"]))
        if p:
            where_clauses.append("t.priority = ?")
            params.append(p)

    if filters.get("project") and str(filters["project"]).strip():
        where_clauses.append("t.project = ?")
        params.append(str(filters["project"]).strip())

    if filters.get("assigned_to_user_id") is not None and filters["assigned_to_user_id"] != "":
        where_clauses.append("t.assigned_to_user_id = ?")
        params.append(int(filters["assigned_to_user_id"]))

    if filters.get("created_by_user_id") is not None and filters["created_by_user_id"] != "":
        where_clauses.append("t.created_by_user_id = ?")
        params.append(int(filters["created_by_user_id"]))

    if filters.get("watcher_user_id") is not None and filters["watcher_user_id"] != "":
        joins.append("JOIN task_watchers tw_filter ON tw_filter.task_id = t.id")
        where_clauses.append("tw_filter.user_id = ?")
        params.append(int(filters["watcher_user_id"]))

    if filters.get("q") and str(filters["q"]).strip():
        q = "%" + str(filters["q"]).strip() + "%"
        where_clauses.append("(t.title LIKE ? OR IFNULL(t.body, '') LIKE ?)")
        params.extend([q, q])

    if filters.get("due_before"):
        due_before = parse_datetime_or_null(filters["due_before"])
        if due_before:
            where_clauses.append("t.due_at IS NOT NULL AND t.due_at <= ?")
            params.append(due_before)

    if filters.get("due_after"):
        due_after = parse_datetime_or_null(filters["due_after"])
        if due_after:
            where_clauses.append("t.due_at IS NOT NULL AND t.due_at >= ?")
            params.append(due_after)

    if api_user and not auth_module.is_admin_role(str(api_user.get("role", ""))):
        uid = int(api_user["id"])
        where_clauses.append("(t.created_by_user_id = ? OR t.assigned_to_user_id = ?)")
        params.extend([uid, uid])

    limit = int(filters.get("limit", 100))
    offset = int(filters.get("offset", 0))
    if limit <= 0:
        limit = 100
    if limit > 500:
        limit = 500
    if offset < 0:
        offset = 0

    sort_by = str(filters.get("sort_by", "updated_at"))
    sort_dir = str(filters.get("sort_dir", "DESC"))
    order_by = task_order_by_clause(sort_by, sort_dir)

    joins_sql = " ".join(joins)
    where_sql = " AND ".join(where_clauses) if where_clauses else "1=1"

    select_sql = f"""
        SELECT t.*, ts.label AS status_label, ts.sort_order AS status_sort_order, ts.is_done AS status_is_done,
        cu.username AS created_by_username, au.username AS assigned_to_username,
        (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comment_count,
        (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachment_count,
        (SELECT COUNT(*) FROM task_watchers tw WHERE tw.task_id = t.id) AS watcher_count
        FROM tasks t {joins_sql}
        WHERE {where_sql}
        ORDER BY {order_by}
        LIMIT ? OFFSET ?
    """
    cur = conn.execute(select_sql, params + [limit, offset])
    tasks = [hydrate_task_row(dict(r)) for r in cur.fetchall()]

    if not with_pagination:
        conn.close()
        return tasks

    count_sql = f"SELECT COUNT(*) AS total_count FROM tasks t {joins_sql} WHERE {where_sql}"
    cur = conn.execute(count_sql, params)
    total = int(cur.fetchone()[0])
    conn.close()

    return {"tasks": tasks, "total": total, "limit": limit, "offset": offset}


def create_task(
    title: str,
    status: str | None,
    created_by_user_id: int,
    assigned_to_user_id: int | None = None,
    body: str | None = None,
    options: dict | None = None,
) -> dict:
    options = options or {}
    title = normalize_task_title(title)
    if title is None:
        return {"success": False, "error": "Title is required"}

    if created_by_user_id <= 0 or not get_user_by_id(created_by_user_id):
        return {"success": False, "error": "Invalid creator user"}

    if status and str(status).strip():
        status_slug = sanitize_status(status)
        if status_slug is None:
            return {"success": False, "error": "Invalid status"}
    else:
        status_slug = get_default_task_status_slug()

    body = normalize_task_body(body)
    project = normalize_task_project(options.get("project"))
    due_at = parse_datetime_or_null(options.get("due_at"))
    if options.get("due_at") is not None and due_at is None:
        return {"success": False, "error": "Invalid due_at datetime"}
    priority = normalize_priority(str(options.get("priority", "normal")))
    if priority is None:
        return {"success": False, "error": "Invalid priority"}
    tags = normalize_tags(options.get("tags", []))
    tags_json = encode_tags_json(tags)
    rank = int(options.get("rank", 0))
    rrule = normalize_task_recurrence_rule(options.get("recurrence_rule"))

    if assigned_to_user_id is not None and assigned_to_user_id != "":
        auid = int(assigned_to_user_id)
        u = get_user_by_id(auid)
        if not u or int(u.get("is_active", 0)) != 1:
            return {"success": False, "error": "Assigned user is invalid or disabled"}
    else:
        auid = None

    conn = db.get_connection()
    conn.execute(
        """INSERT INTO tasks (title, body, status, due_at, priority, project, tags_json, rank, recurrence_rule, created_by_user_id, assigned_to_user_id, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))""",
        (title, body, status_slug, due_at, priority, project, tags_json, rank, rrule, created_by_user_id, auid),
    )
    task_id = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
    conn.commit()
    conn.close()

    audit.create_audit_log(created_by_user_id, "task.create", "task", str(task_id), {"status": status_slug, "priority": priority, "assigned_to_user_id": auid})
    return {"success": True, "id": task_id}


def update_task(task_id: int, fields: dict) -> dict:
    task_id = int(task_id)
    if task_id <= 0:
        return {"success": False, "error": "Invalid id"}
    if not get_task_by_id(task_id, False):
        return {"success": False, "error": "Task not found"}

    sets: list[str] = []
    params: list = []

    if "title" in fields:
        title = normalize_task_title(fields["title"])
        if title is None:
            return {"success": False, "error": "Title cannot be empty"}
        sets.append("title = ?")
        params.append(title)

    if "status" in fields:
        status = sanitize_status(str(fields["status"]))
        if status is None:
            return {"success": False, "error": "Invalid status"}
        sets.append("status = ?")
        params.append(status)

    if "assigned_to_user_id" in fields:
        v = fields["assigned_to_user_id"]
        if v is None or v == "":
            sets.append("assigned_to_user_id = ?")
            params.append(None)
        else:
            auid = int(v)
            u = get_user_by_id(auid)
            if not u or int(u.get("is_active", 0)) != 1:
                return {"success": False, "error": "Assigned user is invalid or disabled"}
            sets.append("assigned_to_user_id = ?")
            params.append(auid)

    if "body" in fields:
        body = normalize_task_body(fields["body"])
        sets.append("body = ?")
        params.append(body)

    if "due_at" in fields:
        due_at = parse_datetime_or_null(fields["due_at"])
        if fields["due_at"] is not None and fields["due_at"] != "" and due_at is None:
            return {"success": False, "error": "Invalid due_at datetime"}
        sets.append("due_at = ?")
        params.append(due_at)

    if "priority" in fields:
        priority = normalize_priority(str(fields["priority"]))
        if priority is None:
            return {"success": False, "error": "Invalid priority"}
        sets.append("priority = ?")
        params.append(priority)

    if "project" in fields:
        project = normalize_task_project(fields["project"])
        sets.append("project = ?")
        params.append(project)

    if "tags" in fields:
        tags_json = encode_tags_json(normalize_tags(fields["tags"]))
        sets.append("tags_json = ?")
        params.append(tags_json)

    if "rank" in fields:
        sets.append("rank = ?")
        params.append(int(fields["rank"]))

    if "recurrence_rule" in fields:
        rrule = normalize_task_recurrence_rule(fields["recurrence_rule"])
        sets.append("recurrence_rule = ?")
        params.append(rrule)

    if not sets:
        return {"success": False, "error": "No fields to update"}

    sets.append("updated_at = datetime('now')")
    params.append(task_id)
    conn = db.get_connection()
    conn.execute("UPDATE tasks SET " + ", ".join(sets) + " WHERE id = ?", params)
    conn.commit()
    conn.close()

    audit.create_audit_log(None, "task.update", "task", str(task_id), {"updated_fields": list(fields.keys())})
    return {"success": True}


def delete_task(task_id: int) -> dict:
    task_id = int(task_id)
    if task_id <= 0:
        return {"success": False, "error": "Invalid id"}
    conn = db.get_connection()
    conn.execute("DELETE FROM tasks WHERE id = ?", (task_id,))
    conn.commit()
    conn.close()
    audit.create_audit_log(None, "task.delete", "task", str(task_id))
    return {"success": True}


def bulk_create_tasks(items: list[dict], created_by_user_id: int) -> dict:
    results = []
    success_count = 0
    failure_count = 0
    for idx, item in enumerate(items):
        if not isinstance(item, dict):
            results.append({"index": idx, "success": False, "error": "Item must be an object"})
            failure_count += 1
            continue
        res = create_task(
            item.get("title", ""),
            item.get("status"),
            created_by_user_id,
            item.get("assigned_to_user_id"),
            item.get("body"),
            {
                "due_at": item.get("due_at"),
                "priority": item.get("priority", "normal"),
                "project": item.get("project"),
                "tags": item.get("tags", []),
                "rank": item.get("rank", 0),
                "recurrence_rule": item.get("recurrence_rule"),
            },
        )
        results.append({"index": idx, **res})
        if res.get("success"):
            success_count += 1
        else:
            failure_count += 1
    return {
        "success": failure_count == 0,
        "created": success_count,
        "failed": failure_count,
        "results": results,
    }


def list_projects(limit: int = 100) -> list[dict]:
    limit = max(1, min(1000, limit))
    conn = db.get_connection()
    cur = conn.execute(
        """SELECT project AS name, COUNT(*) AS task_count FROM tasks
           WHERE project IS NOT NULL AND TRIM(project) != '' GROUP BY project ORDER BY project ASC LIMIT ?""",
        (limit,),
    )
    rows = [{"name": r[0], "task_count": int(r[1])} for r in cur.fetchall()]
    conn.close()
    return rows


def list_tags(limit: int = 200) -> list[dict]:
    limit = max(1, min(2000, limit))
    conn = db.get_connection()
    cur = conn.execute("SELECT tags_json FROM tasks WHERE tags_json IS NOT NULL AND TRIM(tags_json) != ''")
    counts: dict[str, dict] = {}
    for row in cur.fetchall():
        for tag in decode_tags_json(row[0]):
            k = tag.lower()
            if k not in counts:
                counts[k] = {"name": tag, "task_count": 0}
            counts[k]["task_count"] += 1
    conn.close()
    sorted_tags = sorted(counts.values(), key=lambda x: -x["task_count"])
    return sorted_tags[:limit]


def add_task_comment(task_id: int, user_id: int, comment: str) -> dict:
    comment = comment.strip()
    if not comment:
        return {"success": False, "error": "Comment is required"}
    comment = truncate_string(comment, 2000)
    if not get_task_by_id(task_id, False):
        return {"success": False, "error": "Task not found"}
    if not get_user_by_id(user_id):
        return {"success": False, "error": "User not found"}
    conn = db.get_connection()
    conn.execute(
        "INSERT INTO task_comments (task_id, user_id, comment, created_at) VALUES (?, ?, ?, datetime('now'))",
        (task_id, user_id, comment),
    )
    cid = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
    conn.execute("UPDATE tasks SET updated_at = datetime('now') WHERE id = ?", (task_id,))
    cur = conn.execute("SELECT created_at FROM task_comments WHERE id = ? LIMIT 1", (cid,))
    row = cur.fetchone()
    created_at = row[0] if row else now_utc()
    conn.commit()
    conn.close()
    audit.create_audit_log(user_id, "task.comment_add", "task_comment", str(cid), {"task_id": task_id})
    return {"success": True, "id": cid, "created_at": created_at}


def add_task_attachment(
    task_id: int,
    uploaded_by_user_id: int,
    file_name: str,
    file_url: str,
    mime_type: str | None = None,
    size_bytes: int | None = None,
) -> dict:
    if not get_task_by_id(task_id, False):
        return {"success": False, "error": "Task not found"}
    user = get_user_by_id(uploaded_by_user_id)
    if not user or int(user.get("is_active", 0)) != 1:
        return {"success": False, "error": "User not found or inactive"}
    file_name = truncate_string(file_name.strip(), 255)
    file_url = truncate_string(file_url.strip(), 2048)
    if not file_name:
        return {"success": False, "error": "file_name is required"}
    if not file_url:
        return {"success": False, "error": "file_url is required"}
    conn = db.get_connection()
    conn.execute(
        """INSERT INTO task_attachments (task_id, uploaded_by_user_id, file_name, file_url, mime_type, size_bytes, created_at)
           VALUES (?, ?, ?, ?, ?, ?, datetime('now'))""",
        (task_id, uploaded_by_user_id, file_name, file_url, mime_type, size_bytes),
    )
    aid = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
    conn.commit()
    conn.close()
    audit.create_audit_log(uploaded_by_user_id, "task.attachment_add", "task_attachment", str(aid), {"task_id": task_id})
    return {"success": True, "id": aid}


def add_task_watcher(task_id: int, user_id: int) -> dict:
    if not get_task_by_id(task_id, False):
        return {"success": False, "error": "Task not found"}
    user = get_user_by_id(user_id)
    if not user or int(user.get("is_active", 0)) != 1:
        return {"success": False, "error": "User not found or inactive"}
    conn = db.get_connection()
    conn.execute("INSERT OR IGNORE INTO task_watchers (task_id, user_id) VALUES (?, ?)", (task_id, user_id))
    conn.commit()
    conn.close()
    audit.create_audit_log(user_id, "task.watch_add", "task", str(task_id), {"watcher_user_id": user_id})
    return {"success": True}


def remove_task_watcher(task_id: int, user_id: int) -> dict:
    conn = db.get_connection()
    conn.execute("DELETE FROM task_watchers WHERE task_id = ? AND user_id = ?", (task_id, user_id))
    conn.commit()
    conn.close()
    audit.create_audit_log(user_id, "task.watch_remove", "task", str(task_id), {"watcher_user_id": user_id})
    return {"success": True}


def bulk_update_tasks(items: list[dict]) -> dict:
    results = []
    success_count = 0
    failure_count = 0
    for idx, item in enumerate(items):
        if not isinstance(item, dict):
            results.append({"index": idx, "success": False, "error": "Item must be an object"})
            failure_count += 1
            continue
        task_id = int(item.get("id", 0))
        if task_id <= 0:
            results.append({"index": idx, "success": False, "error": "Missing id"})
            failure_count += 1
            continue
        fields = {}
        for key in ["title", "status", "assigned_to_user_id", "body", "due_at", "priority", "project", "tags", "rank", "recurrence_rule"]:
            if key in item:
                fields[key] = item[key]
        res = update_task(task_id, fields)
        results.append({"index": idx, "id": task_id, **res})
        if res.get("success"):
            success_count += 1
        else:
            failure_count += 1
    return {
        "success": failure_count == 0,
        "updated": success_count,
        "failed": failure_count,
        "results": results,
    }
