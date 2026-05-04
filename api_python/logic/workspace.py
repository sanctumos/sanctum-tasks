"""Organizations and project-directory entities (mirrors PHP listOrganizations, listDirectoryProjectsForUser, createDirectoryProject)."""
from __future__ import annotations

import sqlite3
from datetime import datetime, timezone

from .. import db
from . import audit


def _now_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


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
    role = str(user_row.get("role") or "member")
    if org_id <= 0:
        return []
    conn = db.get_connection()
    try:
        if role in ("admin", "manager"):
            cur = conn.execute(
                """
                SELECT id, org_id, name, description, status, client_visible, all_access, created_at, updated_at
                FROM projects
                WHERE org_id = ? AND status != 'trashed'
                ORDER BY name COLLATE NOCASE ASC
                LIMIT ?
                """,
                (org_id, limit),
            )
        else:
            cur = conn.execute(
                """
                SELECT DISTINCT p.id, p.org_id, p.name, p.description, p.status, p.client_visible, p.all_access,
                       p.created_at, p.updated_at
                FROM projects p
                LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
                WHERE p.org_id = ?
                  AND p.status != 'trashed'
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
