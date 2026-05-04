"""Organizations and project-directory entities (mirrors PHP listOrganizations, listDirectoryProjectsForUser, createDirectoryProject)."""
from __future__ import annotations

import sqlite3
from datetime import datetime, timezone

from .. import db
from . import audit
from .helpers import normalize_person_kind


def _now_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def user_has_unrestricted_org_directory_access(user_row: dict) -> bool:
    """Mirrors PHP userHasUnrestrictedOrgDirectoryAccess: admins always; managers unless limited_project_access."""
    if normalize_person_kind(str(user_row.get("person_kind"))) == "client":
        return False
    role = str(user_row.get("role") or "member")
    if role == "admin":
        return True
    if role == "manager" and not int(user_row.get("limited_project_access") or 0):
        return True
    return False


def list_organizations(*, user_role: str, org_id: int | None) -> list[dict]:
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            "SELECT id, name, settings_json, created_at FROM organizations ORDER BY id ASC",
        )
        rows = [dict(r) for r in cur.fetchall()]
        if user_role in ("admin", "manager"):
            return rows
        oid = int(org_id or 0)
        if oid <= 0:
            return []
        return [r for r in rows if int(r["id"]) == oid]
    finally:
        conn.close()


def list_directory_projects_for_user(user_row: dict, limit: int = 200) -> list[dict]:
    db.init_schema()
    limit = max(1, min(500, limit))
    uid = int(user_row["id"])
    org_id = int(user_row.get("org_id") or 0)
    client_only = normalize_person_kind(str(user_row.get("person_kind"))) == "client"
    if org_id <= 0:
        return []
    cv = " AND client_visible = 1" if client_only else ""
    cvp = " AND p.client_visible = 1" if client_only else ""
    conn = db.get_connection()
    try:
        if user_has_unrestricted_org_directory_access(user_row) and not client_only:
            cur = conn.execute(
                f"""
                SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
                FROM projects
                WHERE org_id = ? AND status != 'trashed'{cv}
                ORDER BY name COLLATE NOCASE ASC
                LIMIT ?
                """,
                (org_id, limit),
            )
        else:
            cur = conn.execute(
                f"""
                SELECT DISTINCT p.id, p.org_id, p.name, p.description, p.status, p.client_visible, p.all_access,
                       p.created_at, p.updated_at
                FROM projects p
                LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                WHERE p.org_id = ?
                  AND p.status != 'trashed'{cvp}
                  AND (p.all_access = 1 OR pm.user_id IS NOT NULL)
                ORDER BY p.name COLLATE NOCASE ASC
                LIMIT ?
                """,
                (uid, org_id, limit),
            )
        out = []
        for r in cur.fetchall():
            d = dict(r)
            d["id"] = int(d["id"])
            d["org_id"] = int(d["org_id"])
            d["client_visible"] = int(d["client_visible"])
            d["all_access"] = int(d["all_access"])
            out.append(d)
        return out
    finally:
        conn.close()


def create_directory_project(
    user_id: int,
    name: str,
    description: str | None = None,
    client_visible: bool = False,
    all_access: bool = False,
) -> dict:
    db.init_schema()
    name = (name or "").strip()
    if not name:
        return {"success": False, "error": "Project name is required"}
    conn = db.get_connection()
    try:
        cur = conn.execute(
            "SELECT id, role, org_id FROM users WHERE id = ? LIMIT 1",
            (int(user_id),),
        )
        row = cur.fetchone()
        if not row:
            return {"success": False, "error": "User not found"}
        uid, role, oid = int(row[0]), str(row[1]), row[2]
        org_id = int(oid) if oid is not None else 0
        if org_id <= 0:
            return {"success": False, "error": "User has no organization"}
        if role not in ("admin", "manager", "member"):
            return {"success": False, "error": "Insufficient permission to create projects"}
        now = _now_utc()
        descr = (description or "").strip() or None
        try:
            conn.execute(
                """
                INSERT INTO projects (org_id, name, description, status, client_visible, all_access, created_at, updated_at)
                VALUES (?, ?, ?, 'active', ?, ?, ?, ?)
                """,
                (
                    org_id,
                    name,
                    descr,
                    1 if client_visible else 0,
                    1 if all_access else 0,
                    now,
                    now,
                ),
            )
            pid = int(conn.execute("SELECT last_insert_rowid()").fetchone()[0])
            conn.execute(
                "INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, 'lead')",
                (pid, uid),
            )
            conn.commit()
        except sqlite3.IntegrityError:
            conn.rollback()
            return {"success": False, "error": "A project with this name already exists in your organization"}

        audit.create_audit_log(user_id, "project.create", "project", str(pid), {"name": name, "org_id": org_id})
        return {"success": True, "id": pid, "org_id": org_id, "name": name}
    finally:
        conn.close()


def get_directory_project_by_id(project_id: int) -> dict | None:
    if project_id <= 0:
        return None
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            """
            SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
            FROM projects WHERE id = ? LIMIT 1
            """,
            (project_id,),
        )
        row = cur.fetchone()
        if not row:
            return None
        d = dict(row)
        d["id"] = int(d["id"])
        d["org_id"] = int(d["org_id"])
        d["client_visible"] = int(d["client_visible"])
        d["all_access"] = int(d["all_access"])
        return d
    finally:
        conn.close()


def normalize_directory_project_status(status: str | None) -> str | None:
    s = str(status or "").lower().strip()
    if s in ("active", "archived", "trashed"):
        return s
    return None


def normalize_project_member_role(role: str | None) -> str | None:
    r = str(role or "").lower().strip()
    if r in ("lead", "member", "client"):
        return r
    return None


def get_project_member_role(user_id: int, project_id: int) -> str | None:
    if user_id <= 0 or project_id <= 0:
        return None
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            "SELECT role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1",
            (project_id, user_id),
        )
        row = cur.fetchone()
        if not row:
            return None
        return normalize_project_member_role(str(row[0])) or "member"
    finally:
        conn.close()


def user_can_access_directory_project(user_row: dict, project: dict | None) -> bool:
    if not project:
        return False
    org_id = int(user_row.get("org_id") or 0)
    if org_id <= 0 or int(project.get("org_id") or 0) != org_id:
        return False
    if str(project.get("status") or "") == "trashed":
        return False
    if normalize_person_kind(str(user_row.get("person_kind"))) == "client" and not int(project.get("client_visible") or 0):
        return False
    if user_has_unrestricted_org_directory_access(user_row):
        return True
    if int(project.get("all_access") or 0):
        return True
    uid = int(user_row["id"])
    pid = int(project["id"])
    conn = db.get_connection()
    try:
        cur = conn.execute(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1",
            (pid, uid),
        )
        return cur.fetchone() is not None
    finally:
        conn.close()


def user_can_manage_directory_project(user_row: dict, project: dict) -> bool:
    if not user_can_access_directory_project(user_row, project):
        return False
    if normalize_person_kind(str(user_row.get("person_kind"))) == "client":
        return False
    role = str(user_row.get("role") or "member")
    if role == "admin":
        return True
    if role == "manager" and user_has_unrestricted_org_directory_access(user_row):
        return True
    return get_project_member_role(int(user_row["id"]), int(project["id"])) == "lead"


def resolve_task_directory_project_id(user_row: dict, project_id_raw, allow_null: bool = True) -> dict:
    if project_id_raw is None or project_id_raw == "":
        if not allow_null:
            return {"success": False, "error": "project_id is required"}
        return {"success": True, "project_id": None, "project": None}
    pid = int(project_id_raw)
    if pid <= 0:
        return {"success": False, "error": "Invalid project_id"}
    proj = get_directory_project_by_id(pid)
    if not proj or not user_can_access_directory_project(user_row, proj):
        return {"success": False, "error": "Project not found or not accessible"}
    return {"success": True, "project_id": pid, "project": str(proj["name"])}


def update_directory_project(user_id: int, project_id: int, fields: dict) -> dict:
    proj = get_directory_project_by_id(project_id)
    from .users import get_user_by_id

    actor = get_user_by_id(user_id, False)
    if not proj or not actor:
        return {"success": False, "error": "Project not found"}
    if not user_can_manage_directory_project(actor, proj):
        return {"success": False, "error": "Insufficient permission to update this project"}

    sets: list[str] = []
    params: list = []
    if "name" in fields:
        name = str(fields["name"] or "").strip()
        if not name:
            return {"success": False, "error": "Project name cannot be empty"}
        sets.append("name = ?")
        params.append(name)
    if "description" in fields:
        d = fields["description"]
        descr = None if d is None or str(d).strip() == "" else str(d).strip()
        sets.append("description = ?")
        params.append(descr)
    if "status" in fields:
        st = normalize_directory_project_status(str(fields["status"]))
        if st is None:
            return {"success": False, "error": "Invalid status (use active, archived, or trashed)"}
        sets.append("status = ?")
        params.append(st)
    if "client_visible" in fields:
        sets.append("client_visible = ?")
        params.append(1 if fields["client_visible"] else 0)
    if "all_access" in fields:
        sets.append("all_access = ?")
        params.append(1 if fields["all_access"] else 0)

    if not sets:
        return {"success": False, "error": "No fields to update"}

    sets.append("updated_at = ?")
    params.append(_now_utc())
    params.append(project_id)

    conn = db.get_connection()
    try:
        conn.execute(f"UPDATE projects SET {', '.join(sets)} WHERE id = ?", params)
        conn.commit()
    except sqlite3.IntegrityError:
        conn.rollback()
        return {"success": False, "error": "A project with this name already exists in your organization"}
    finally:
        conn.close()

    audit.create_audit_log(user_id, "project.update", "project", str(project_id), {"fields": list(fields.keys())})
    return {"success": True}


def list_project_members(project_id: int) -> list[dict]:
    if project_id <= 0:
        return []
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            """
            SELECT pm.project_id, pm.user_id, pm.role, pm.created_at,
                   u.username, u.role AS user_role, u.person_kind
            FROM project_members pm
            JOIN users u ON u.id = pm.user_id
            WHERE pm.project_id = ?
            ORDER BY u.username COLLATE NOCASE ASC
            """,
            (project_id,),
        )
        out = []
        for r in cur.fetchall():
            d = dict(r)
            d["project_id"] = int(d["project_id"])
            d["user_id"] = int(d["user_id"])
            d["person_kind"] = normalize_person_kind(str(d.get("person_kind")))
            out.append(d)
        return out
    finally:
        conn.close()


def add_project_member(actor_user_id: int, project_id: int, target_user_id: int, member_role: str = "member") -> dict:
    from .users import get_user_by_id

    proj = get_directory_project_by_id(project_id)
    actor = get_user_by_id(actor_user_id, False)
    target = get_user_by_id(target_user_id, False)
    if not proj or not actor or not target:
        return {"success": False, "error": "User or project not found"}
    if not user_can_manage_directory_project(actor, proj):
        return {"success": False, "error": "Insufficient permission to manage members"}
    org_id = int(proj["org_id"])
    if int(target.get("org_id") or 0) != org_id:
        return {"success": False, "error": "User is not in this organization"}
    mr = normalize_project_member_role(member_role)
    if mr is None:
        return {"success": False, "error": "Invalid member role"}

    conn = db.get_connection()
    try:
        if mr == "lead":
            conn.execute("UPDATE project_members SET role = 'member' WHERE project_id = ? AND role = 'lead'", (project_id,))
        try:
            conn.execute(
                "INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)",
                (project_id, target_user_id, mr),
            )
        except sqlite3.IntegrityError:
            conn.execute(
                "UPDATE project_members SET role = ? WHERE project_id = ? AND user_id = ?",
                (mr, project_id, target_user_id),
            )
        conn.commit()
    finally:
        conn.close()

    audit.create_audit_log(actor_user_id, "project.member_add", "project", str(project_id), {"user_id": target_user_id, "role": mr})
    return {"success": True}


def remove_project_member(actor_user_id: int, project_id: int, target_user_id: int) -> dict:
    from .users import get_user_by_id

    proj = get_directory_project_by_id(project_id)
    actor = get_user_by_id(actor_user_id, False)
    if not proj or not actor:
        return {"success": False, "error": "Not found"}
    if not user_can_manage_directory_project(actor, proj):
        return {"success": False, "error": "Insufficient permission"}
    current = get_project_member_role(target_user_id, project_id)
    if current is None:
        return {"success": False, "error": "User is not on this project"}
    conn = db.get_connection()
    try:
        if current == "lead":
            cur = conn.execute("SELECT COUNT(*) FROM project_members WHERE project_id = ?", (project_id,))
            n = int(cur.fetchone()[0])
            if n <= 1:
                return {"success": False, "error": "Cannot remove the last member; trash the project or add another lead first"}
        conn.execute("DELETE FROM project_members WHERE project_id = ? AND user_id = ?", (project_id, target_user_id))
        conn.commit()
    finally:
        conn.close()

    audit.create_audit_log(actor_user_id, "project.member_remove", "project", str(project_id), {"user_id": target_user_id})
    return {"success": True}


def list_todo_lists_for_project(user_row: dict, project_id: int) -> list[dict]:
    proj = get_directory_project_by_id(project_id)
    if not proj or not user_can_access_directory_project(user_row, proj):
        return []
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            """
            SELECT id, project_id, name, sort_order, created_at
            FROM todo_lists WHERE project_id = ?
            ORDER BY sort_order ASC, name COLLATE NOCASE ASC
            """,
            (project_id,),
        )
        out = []
        for r in cur.fetchall():
            d = dict(r)
            d["id"] = int(d["id"])
            d["project_id"] = int(d["project_id"])
            d["sort_order"] = int(d["sort_order"])
            out.append(d)
        return out
    finally:
        conn.close()


def create_todo_list(user_id: int, project_id: int, name: str) -> dict:
    from .users import get_user_by_id

    proj = get_directory_project_by_id(project_id)
    actor = get_user_by_id(user_id, False)
    if not proj or not actor or not user_can_manage_directory_project(actor, proj):
        return {"success": False, "error": "Insufficient permission"}
    name = (name or "").strip()
    if not name:
        return {"success": False, "error": "List name is required"}
    conn = db.get_connection()
    try:
        cur = conn.execute(
            "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM todo_lists WHERE project_id = ?",
            (project_id,),
        )
        row = cur.fetchone()
        next_sort = int(row[0]) if row and row[0] is not None else 0
        conn.execute(
            "INSERT INTO todo_lists (project_id, name, sort_order) VALUES (?, ?, ?)",
            (project_id, name, next_sort),
        )
        tid = int(conn.execute("SELECT last_insert_rowid()").fetchone()[0])
        conn.commit()
    finally:
        conn.close()

    audit.create_audit_log(user_id, "todo_list.create", "todo_list", str(tid), {"project_id": project_id, "name": name})
    return {"success": True, "id": tid}


def list_user_project_pins_for_user(user_row: dict, limit: int = 200) -> list[dict]:
    uid = int(user_row["id"])
    org_id = int(user_row.get("org_id") or 0)
    if uid <= 0 or org_id <= 0:
        return []
    limit = max(1, min(500, limit))
    db.init_schema()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            """
            SELECT upp.project_id, upp.sort_order, p.name, p.status, p.client_visible
            FROM user_project_pins upp
            JOIN projects p ON p.id = upp.project_id
            WHERE upp.user_id = ? AND p.org_id = ? AND p.status != 'trashed'
            ORDER BY upp.sort_order ASC, p.name COLLATE NOCASE ASC
            LIMIT ?
            """,
            (uid, org_id, limit),
        )
        out = []
        for r in cur.fetchall():
            d = dict(r)
            pid = int(d["project_id"])
            pd = get_directory_project_by_id(pid)
            if not pd or not user_can_access_directory_project(user_row, pd):
                continue
            d["project_id"] = pid
            d["sort_order"] = int(d["sort_order"])
            d["client_visible"] = int(d["client_visible"])
            out.append(d)
        return out
    finally:
        conn.close()


def set_user_project_pin(user_id: int, project_id: int, sort_order: int = 0) -> dict:
    from .users import get_user_by_id

    u = get_user_by_id(user_id, False)
    proj = get_directory_project_by_id(project_id)
    if not u or not proj or not user_can_access_directory_project(u, proj):
        return {"success": False, "error": "Project not accessible"}
    conn = db.get_connection()
    try:
        conn.execute(
            """
            INSERT INTO user_project_pins (user_id, project_id, sort_order) VALUES (?, ?, ?)
            ON CONFLICT(user_id, project_id) DO UPDATE SET sort_order = excluded.sort_order
            """,
            (user_id, project_id, sort_order),
        )
        conn.commit()
    finally:
        conn.close()
    return {"success": True}


def remove_user_project_pin(user_id: int, project_id: int) -> dict:
    conn = db.get_connection()
    try:
        conn.execute("DELETE FROM user_project_pins WHERE user_id = ? AND project_id = ?", (user_id, project_id))
        conn.commit()
    finally:
        conn.close()
    return {"success": True}
