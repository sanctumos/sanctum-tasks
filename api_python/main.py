"""
Sanctum Tasks Python API - FastAPI app with /api/*.php paths for SDK compatibility.
"""
from fastapi import FastAPI, Request, Depends
from fastapi.responses import JSONResponse
from fastapi.exceptions import HTTPException

from . import config
from . import db
from . import auth
from . import response
from . import session as session_module

app = FastAPI(title="Sanctum Tasks API", description="Python mirror of the PHP API")


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException):
    """Return error payload as JSON body (not wrapped in {"detail": ...}) when detail is our api_error dict."""
    if isinstance(exc.detail, dict) and "error_object" in exc.detail:
        return JSONResponse(content=exc.detail, status_code=exc.status_code, headers=dict(exc.headers or []))
    return JSONResponse(
        content={"detail": exc.detail} if not isinstance(exc.detail, dict) else exc.detail,
        status_code=exc.status_code,
        headers=dict(exc.headers or []),
    )


@app.on_event("startup")
def startup():
    db.init_schema()


# ----- Health -----
@app.get("/api/health.php")
async def health(request: Request, user: dict = Depends(auth.require_api_user)):
    payload = {
        "ok": True,
        "user": {
            "id": int(user["id"]),
            "username": user["username"],
            "role": user["role"],
            "is_active": int(user["is_active"]),
        },
    }
    return response.json_success(request, payload)


# ----- Tasks -----
@app.get("/api/list-tasks.php")
async def list_tasks(
    request: Request,
    user: dict = Depends(auth.require_api_user),
    status: str | None = None,
    assigned_to_user_id: str | None = None,
    created_by_user_id: str | None = None,
    priority: str | None = None,
    project: str | None = None,
    q: str | None = None,
    due_before: str | None = None,
    due_after: str | None = None,
    watcher_user_id: str | None = None,
    sort_by: str = "updated_at",
    sort_dir: str = "DESC",
    limit: int = 100,
    offset: int = 0,
):
    from . import logic

    filters = {
        "status": status,
        "assigned_to_user_id": int(assigned_to_user_id) if assigned_to_user_id not in (None, "") else None,
        "created_by_user_id": int(created_by_user_id) if created_by_user_id not in (None, "") else None,
        "priority": priority,
        "project": project,
        "q": q,
        "due_before": due_before,
        "due_after": due_after,
        "watcher_user_id": int(watcher_user_id) if watcher_user_id not in (None, "") else None,
        "sort_by": sort_by,
        "sort_dir": sort_dir,
        "limit": limit,
        "offset": offset,
    }
    result = logic.tasks.list_tasks(filters, with_pagination=True, api_user=user)
    base_params = {k: v for k, v in [("status", status), ("assigned_to_user_id", assigned_to_user_id), ("created_by_user_id", created_by_user_id), ("priority", priority), ("project", project), ("q", q), ("due_before", due_before), ("due_after", due_after), ("watcher_user_id", watcher_user_id), ("sort_by", sort_by), ("sort_dir", sort_dir)] if v is not None and str(v).strip() != ""}
    pagination = response.pagination_meta(request, "/api/list-tasks.php", base_params, result["limit"], result["offset"], result["total"])
    payload = {
        "tasks": result["tasks"],
        "count": len(result["tasks"]),
        "total": result["total"],
        "pagination": pagination,
    }
    return response.json_success(request, payload, meta={"pagination": pagination})


@app.get("/api/get-task.php")
async def get_task(
    request: Request,
    user: dict = Depends(auth.require_api_user),
    id: int = 0,
    include_relations: str = "1",
):
    from . import logic

    if id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(id, include_relations=(include_relations != "0"))
    if not task:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    return response.json_success(request, {"task": task})


async def _read_json_body(request: Request) -> dict | None:
    raw = await request.body()
    if not raw or not raw.strip():
        return {}
    try:
        import json
        data = json.loads(raw.decode("utf-8"))
        return data if isinstance(data, dict) else None
    except (ValueError, TypeError, UnicodeDecodeError):
        return None


@app.post("/api/create-task.php")
async def create_task(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    title = body.get("title", "")
    status = body.get("status")
    assigned_to_user_id = body.get("assigned_to_user_id")
    task_body = body.get("body")
    options = {
        "due_at": body.get("due_at"),
        "priority": body.get("priority", "normal"),
        "project": body.get("project"),
        "tags": body.get("tags", []),
        "rank": body.get("rank", 0),
        "recurrence_rule": body.get("recurrence_rule"),
    }
    result = logic.tasks.create_task(title, status, int(user["id"]), assigned_to_user_id, task_body, options)
    if not result.get("success"):
        payload, sc = response.api_error("task.create_failed", result.get("error", "Create failed"), 400)
        raise HTTPException(status_code=sc, detail=payload)
    task = logic.tasks.get_task_by_id(result["id"])
    return response.json_success(request, {"task": task}, status_code=201)


@app.post("/api/update-task.php")
async def update_task(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("id", 0))
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    existing = logic.tasks.get_task_by_id(task_id, False)
    if not existing:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), existing, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    fields = {}
    for key in ["title", "status", "assigned_to_user_id", "body", "due_at", "priority", "project", "tags", "rank", "recurrence_rule"]:
        if key in body:
            fields[key] = body[key]
    result = logic.tasks.update_task(task_id, fields)
    if not result.get("success"):
        sc = 404 if (result.get("error") == "Task not found") else 400
        payload, _ = response.api_error("task.update_failed", result.get("error", "Update failed"), sc)
        raise HTTPException(status_code=sc, detail=payload)
    task = logic.tasks.get_task_by_id(task_id)
    return response.json_success(request, {"task": task})


@app.post("/api/delete-task.php")
async def delete_task(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("id", 0))
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    existing = logic.tasks.get_task_by_id(task_id, False)
    if not existing:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), existing, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    logic.tasks.delete_task(task_id)
    return response.json_success(request, {"deleted": True})


@app.get("/api/search-tasks.php")
async def search_tasks(
    request: Request,
    user: dict = Depends(auth.require_api_user),
    q: str = "",
    status: str | None = None,
    priority: str | None = None,
    assigned_to_user_id: str | None = None,
    sort_by: str = "updated_at",
    sort_dir: str = "DESC",
    limit: int = 50,
    offset: int = 0,
):
    from . import logic

    if not str(q).strip():
        payload, _ = response.api_error("validation.missing_query", "Query parameter q is required", 400)
        raise HTTPException(status_code=400, detail=payload)
    filters = {
        "q": q.strip(),
        "status": status,
        "priority": priority,
        "assigned_to_user_id": int(assigned_to_user_id) if assigned_to_user_id not in (None, "") else None,
        "sort_by": sort_by,
        "sort_dir": sort_dir,
        "limit": limit,
        "offset": offset,
    }
    result = logic.tasks.list_tasks(filters, with_pagination=True, api_user=user)
    base_params = {k: v for k, v in [("q", q), ("status", status), ("priority", priority), ("assigned_to_user_id", assigned_to_user_id), ("sort_by", sort_by), ("sort_dir", sort_dir)] if v is not None and str(v).strip() != ""}
    pagination = response.pagination_meta(request, "/api/search-tasks.php", base_params, result["limit"], result["offset"], result["total"])
    payload = {
        "tasks": result["tasks"],
        "count": len(result["tasks"]),
        "total": result["total"],
        "pagination": pagination,
    }
    return response.json_success(request, payload, meta={"pagination": pagination})


@app.post("/api/bulk-create-tasks.php")
async def bulk_create_tasks(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    tasks = body.get("tasks")
    if not isinstance(tasks, list):
        payload, _ = response.api_error("validation.invalid_tasks", "tasks must be an array", 400)
        raise HTTPException(status_code=400, detail=payload)
    if len(tasks) > 100:
        payload, _ = response.api_error("validation.batch_too_large", "Maximum 100 tasks per request", 400)
        raise HTTPException(status_code=400, detail=payload)
    result = logic.tasks.bulk_create_tasks(tasks, int(user["id"]))
    logic.audit.create_audit_log(int(user["id"]), "api.task_bulk_create", "task", None, {"count": len(tasks), "created": result["created"]})
    return response.json_success(request, result)


@app.post("/api/bulk-update-tasks.php")
async def bulk_update_tasks(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    updates = body.get("updates")
    if not isinstance(updates, list):
        payload, _ = response.api_error("validation.invalid_updates", "updates must be an array", 400)
        raise HTTPException(status_code=400, detail=payload)
    if len(updates) > 100:
        payload, _ = response.api_error("validation.batch_too_large", "Maximum 100 updates per request", 400)
        raise HTTPException(status_code=400, detail=payload)
    result = logic.tasks.bulk_update_tasks(updates)
    logic.audit.create_audit_log(int(user["id"]), "api.task_bulk_update", "task", None, {"count": len(updates), "updated": result["updated"]})
    return response.json_success(request, result)


# ----- Status -----
@app.get("/api/list-statuses.php")
async def list_statuses(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    statuses = logic.statuses.list_task_statuses()
    return response.json_success(request, {"statuses": statuses, "count": len(statuses)})


@app.post("/api/create-status.php")
async def create_status(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    slug = str(body.get("slug", "")).strip()
    label = str(body.get("label", "")).strip()
    sort_order = int(body.get("sort_order", 100))
    is_done = bool(body.get("is_done", False))
    is_default = bool(body.get("is_default", False))
    result = logic.statuses.create_task_status(slug, label, sort_order, is_done, is_default)
    if not result.get("success"):
        payload, _ = response.api_error("status.create_failed", result.get("error", "Failed to create status"), 400)
        raise HTTPException(status_code=400, detail=payload)
    logic.audit.create_audit_log(int(user["id"]), "api.status_create", "task_status", result["slug"], {"label": label})
    status_obj = logic.statuses.get_task_status_by_slug(result["slug"])
    return response.json_success(request, {"status": status_obj}, status_code=201)


# ----- Taxonomy -----
@app.get("/api/list-projects.php")
async def list_projects(request: Request, user: dict = Depends(auth.require_api_user), limit: int = 200):
    from . import logic

    projects = logic.tasks.list_projects(limit)
    return response.json_success(request, {"projects": projects, "count": len(projects)})


@app.get("/api/list-tags.php")
async def list_tags(request: Request, user: dict = Depends(auth.require_api_user), limit: int = 200):
    from . import logic

    tags = logic.tasks.list_tags(limit)
    return response.json_success(request, {"tags": tags, "count": len(tags)})


# ----- Collaboration: comments -----
@app.get("/api/list-comments.php")
async def list_comments(
    request: Request,
    user: dict = Depends(auth.require_api_user),
    task_id: int = 0,
    limit: int = 100,
    offset: int = 0,
):
    from . import logic

    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    comments = logic.tasks.list_task_comments(task_id, limit, offset)
    return response.json_success(request, {"task_id": task_id, "comments": comments, "count": len(comments)})


@app.post("/api/create-comment.php")
async def create_comment(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("task_id", 0))
    comment = str(body.get("comment", "")).strip()
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    if not comment:
        payload, _ = response.api_error("validation.missing_comment", "comment is required", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    result = logic.tasks.add_task_comment(task_id, int(user["id"]), comment)
    if not result.get("success"):
        sc = 404 if result.get("error") == "Task not found" else 400
        payload, _ = response.api_error("task.comment_create_failed", result.get("error", "Failed to add comment"), sc)
        raise HTTPException(status_code=sc, detail=payload)
    from .logic.helpers import now_utc
    return response.json_success(request, {
        "task_id": task_id,
        "comment_id": result["id"],
        "comment": {
            "id": result["id"],
            "task_id": task_id,
            "user_id": int(user["id"]),
            "username": user["username"],
            "comment": comment,
            "created_at": result.get("created_at", now_utc()),
        },
    }, status_code=201)


# ----- Collaboration: attachments -----
@app.get("/api/list-attachments.php")
async def list_attachments(request: Request, user: dict = Depends(auth.require_api_user), task_id: int = 0):
    from . import logic

    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    attachments = logic.tasks.list_task_attachments(task_id)
    return response.json_success(request, {"task_id": task_id, "attachments": attachments, "count": len(attachments)})


@app.post("/api/add-attachment.php")
async def add_attachment(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("task_id", 0))
    file_name = str(body.get("file_name", "")).strip()
    file_url = str(body.get("file_url", "")).strip()
    mime_type = body.get("mime_type")
    size_bytes = body.get("size_bytes")
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    result = logic.tasks.add_task_attachment(task_id, int(user["id"]), file_name, file_url, mime_type, size_bytes)
    if not result.get("success"):
        sc = 404 if result.get("error") == "Task not found" else 400
        payload, _ = response.api_error("task.attachment_add_failed", result.get("error", "Failed to add attachment"), sc)
        raise HTTPException(status_code=sc, detail=payload)
    return response.json_success(request, {"task_id": task_id, "attachment_id": result["id"]}, status_code=201)


# ----- Collaboration: watchers -----
@app.get("/api/list-watchers.php")
async def list_watchers(request: Request, user: dict = Depends(auth.require_api_user), task_id: int = 0):
    from . import logic

    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    watchers = logic.tasks.list_task_watchers(task_id)
    return response.json_success(request, {"task_id": task_id, "watchers": watchers, "count": len(watchers)})


@app.post("/api/watch-task.php")
async def watch_task(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("task_id", 0))
    user_id = int(body.get("user_id", user["id"]))
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    if user_id != int(user["id"]) and not auth.is_admin_role(str(user.get("role", ""))):
        payload, _ = response.api_error("auth.forbidden", "Only admins can set watchers for other users", 403)
        raise HTTPException(status_code=403, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    result = logic.tasks.add_task_watcher(task_id, user_id)
    if not result.get("success"):
        sc = 404 if result.get("error") == "Task not found" else 400
        payload, _ = response.api_error("task.watch_failed", result.get("error", "Failed to add watcher"), sc)
        raise HTTPException(status_code=sc, detail=payload)
    return response.json_success(request, {"task_id": task_id, "user_id": user_id, "watching": True})


@app.post("/api/unwatch-task.php")
async def unwatch_task(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    task_id = int(body.get("task_id", 0))
    user_id = int(body.get("user_id", user["id"]))
    if task_id <= 0:
        payload, _ = response.api_error("validation.invalid_task_id", "Missing or invalid task_id", 400)
        raise HTTPException(status_code=400, detail=payload)
    if user_id != int(user["id"]) and not auth.is_admin_role(str(user.get("role", ""))):
        payload, _ = response.api_error("auth.forbidden", "Only admins can remove watchers for other users", 403)
        raise HTTPException(status_code=403, detail=payload)
    task = logic.tasks.get_task_by_id(task_id, False)
    if not task:
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    if not logic.tasks.user_can_access_task(int(user["id"]), task, str(user.get("role", ""))):
        payload, _ = response.api_error("task.not_found", "Task not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    result = logic.tasks.remove_task_watcher(task_id, user_id)
    return response.json_success(request, {"task_id": task_id, "user_id": user_id, "watching": False})


# ----- Users (admin) -----
@app.get("/api/list-users.php")
async def list_users_route(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic
    include_disabled = request.query_params.get("include_disabled") == "1"
    users_list = logic.users_logic.list_users(include_disabled)
    return response.json_success(request, {"users": users_list, "count": len(users_list)})


@app.post("/api/create-user.php")
async def create_user_route(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic
    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    username = str(body.get("username", "")).strip()
    password = str(body.get("password", "")).strip()
    role = str(body.get("role", "member")).strip()
    must_change_password = body.get("must_change_password", True)
    if must_change_password is not None and not bool(must_change_password):
        must_change_password = False
    create_api_key = bool(body.get("create_api_key", False))
    api_key_name = str(body.get("api_key_name", "default")).strip()
    result = logic.users_logic.create_user(username, password, role, must_change_password)
    if not result.get("success"):
        payload, _ = response.api_error("user.create_failed", result.get("error", "Create user failed"), 400)
        raise HTTPException(status_code=400, detail=payload)
    new_user_id = int(result["id"])
    new_user = logic.users.get_user_by_id(new_user_id, False)
    if not new_user:
        payload, _ = response.api_error("user.create_failed", "Failed to load created user", 500)
        raise HTTPException(status_code=500, detail=payload)
    payload_data = {"user": new_user}
    if create_api_key:
        raw_key = logic.api_keys_logic.create_api_key_for_user(new_user_id, api_key_name, int(user["id"]))
        payload_data["api_key"] = raw_key
    logic.audit.create_audit_log(int(user["id"]), "api.user_create", "user", str(new_user_id), {"username": new_user["username"]})
    return response.json_success(request, payload_data, status_code=201)


@app.post("/api/disable-user.php")
async def disable_user_route(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic
    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    target_user_id = int(body.get("id", 0))
    is_active = bool(body.get("is_active", False))
    if target_user_id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    if target_user_id == int(user["id"]) and not is_active:
        payload, _ = response.api_error("validation.self_disable_not_allowed", "You cannot disable your own user via API", 400)
        raise HTTPException(status_code=400, detail=payload)
    result = logic.users_logic.set_user_active(target_user_id, is_active)
    if not result.get("success"):
        payload, _ = response.api_error("user.update_failed", result.get("error", "Failed to update user"), 400)
        raise HTTPException(status_code=400, detail=payload)
    updated = logic.users.get_user_by_id(target_user_id, False)
    if not updated:
        payload, _ = response.api_error("user.not_found", "User not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    logic.audit.create_audit_log(int(user["id"]), "api.user_enable" if is_active else "api.user_disable", "user", str(target_user_id))
    return response.json_success(request, {"user": updated})


@app.post("/api/reset-user-password.php")
async def reset_user_password_route(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic
    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    target_user_id = int(body.get("id", 0))
    if target_user_id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    provided = str(body.get("new_password", "")).strip()
    new_password = provided if provided else logic.users_logic.generate_temp_password(16)
    must_change_password = body.get("must_change_password", True)
    if must_change_password is not None and not bool(must_change_password):
        must_change_password = False
    result = logic.users_logic.reset_user_password(target_user_id, new_password, must_change_password)
    if not result.get("success"):
        payload, _ = response.api_error("user.password_reset_failed", result.get("error", "Failed to reset password"), 400)
        raise HTTPException(status_code=400, detail=payload)
    logic.audit.create_audit_log(int(user["id"]), "api.user_password_reset", "user", str(target_user_id), {"must_change_password": 1 if must_change_password else 0})
    return response.json_success(request, {
        "id": target_user_id,
        "temporary_password": new_password,
        "must_change_password": 1 if must_change_password else 0,
    })


# ----- API keys -----
@app.get("/api/list-api-keys.php")
async def list_api_keys_route(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic
    include_revoked = request.query_params.get("include_revoked") == "1"
    mine_only = request.query_params.get("mine") == "1"
    if mine_only or not auth.is_admin_role(str(user.get("role", ""))):
        keys = logic.api_keys_logic.list_api_keys_for_user(int(user["id"]), include_revoked)
    else:
        keys = logic.api_keys_logic.get_all_api_keys(include_revoked)
    for k in keys:
        k["api_key_preview"] = (k.get("api_key_preview") or "") + "..."
    return response.json_success(request, {"api_keys": keys, "count": len(keys)})


@app.post("/api/create-api-key.php")
async def create_api_key_route(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic
    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    is_admin = auth.is_admin_role(str(user.get("role", "")))
    target_user_id = int(body.get("user_id", user["id"]))
    if not is_admin and target_user_id != int(user["id"]):
        payload, _ = response.api_error("auth.forbidden", "Only admins can create keys for other users", 403)
        raise HTTPException(status_code=403, detail=payload)
    target_user = logic.users.get_user_by_id(target_user_id, False)
    if not target_user:
        payload, _ = response.api_error("user.not_found", "Target user not found", 404)
        raise HTTPException(status_code=404, detail=payload)
    key_name = str(body.get("key_name", "Unnamed Key")).strip() or "Unnamed Key"
    raw_key = logic.api_keys_logic.create_api_key_for_user(target_user_id, key_name, int(user["id"]))
    logic.audit.create_audit_log(int(user["id"]), "api.api_key_create", "api_key", None, {"user_id": target_user_id, "key_name": key_name})
    return response.json_success(request, {"api_key": raw_key, "user_id": target_user_id, "key_name": key_name}, status_code=201)


@app.post("/api/revoke-api-key.php")
async def revoke_api_key_route(request: Request, user: dict = Depends(auth.require_api_user)):
    from . import logic
    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    key_id = int(body.get("id", 0))
    if key_id <= 0:
        payload, _ = response.api_error("validation.invalid_id", "Missing or invalid id", 400)
        raise HTTPException(status_code=400, detail=payload)
    if not auth.is_admin_role(str(user.get("role", ""))):
        mine = logic.api_keys_logic.list_api_keys_for_user(int(user["id"]), True)
        if not any(int(k["id"]) == key_id for k in mine):
            payload, _ = response.api_error("auth.forbidden", "You can only revoke your own API keys", 403)
            raise HTTPException(status_code=403, detail=payload)
    if not logic.api_keys_logic.revoke_api_key(key_id):
        payload, _ = response.api_error("not_found", "API key not found or already revoked", 404)
        raise HTTPException(status_code=404, detail=payload)
    logic.audit.create_audit_log(int(user["id"]), "api.api_key_revoke", "api_key", str(key_id))
    return response.json_success(request, {"revoked": True, "id": key_id})


# ----- Audit (admin) -----
@app.get("/api/list-audit-logs.php")
async def list_audit_logs_route(request: Request, user: dict = Depends(auth.require_admin_user)):
    from . import logic
    limit = int(request.query_params.get("limit", 100))
    offset = int(request.query_params.get("offset", 0))
    logs = logic.audit.list_audit_logs(limit, offset)
    return response.json_success(request, {"logs": logs, "count": len(logs)})


# ----- Session (no API key; cookie-based for TUI) -----
def _require_session(request: Request):
    """Dependency: validate session cookie and return (user, csrf_token). Raises 401 if invalid."""
    token = session_module.get_session_token_from_request(request)
    result = session_module.validate_session(token)
    if not result:
        payload, _ = response.api_error("auth.not_logged_in", "Not logged in", 401)
        raise HTTPException(status_code=401, detail=payload)
    return result


@app.post("/api/session-login.php")
async def session_login(request: Request):
    from . import logic
    import bcrypt

    body = await _read_json_body(request)
    if body is None:
        payload, _ = response.api_error("validation.invalid_json", "Invalid JSON body", 400)
        raise HTTPException(status_code=400, detail=payload)
    body = body or {}
    if not body and hasattr(request, "form"):
        try:
            form = await request.form()
            body = dict(form) if form else {}
        except Exception:
            pass
    username = str(body.get("username", "")).strip()
    password = str(body.get("password", "")).strip()
    mfa_code = body.get("mfa_code")
    mfa_code = str(mfa_code).strip() if mfa_code is not None else ""

    lock = logic.login_attempts.get_login_lock_state(username, request)
    if lock.get("locked"):
        payload, _ = response.api_error(
            "auth.login_failed",
            "Too many failed login attempts. Try again later.",
            429,
            details={"mfa_required": False, "lockout_seconds": lock.get("remaining_seconds", 0)},
        )
        raise HTTPException(status_code=429, detail=payload)

    user = logic.users.get_user_by_username(username, True)
    if not user or int(user.get("is_active", 0)) != 1:
        logic.login_attempts.record_login_attempt(username, False, request)
        payload, _ = response.api_error("auth.login_failed", "Invalid username or password", 401, details={"mfa_required": False, "lockout_seconds": 0})
        raise HTTPException(status_code=401, detail=payload)
    pw_hash = user.get("password_hash") or ""
    if not pw_hash or not bcrypt.checkpw(password.encode(), pw_hash.encode() if isinstance(pw_hash, str) else pw_hash):
        logic.login_attempts.record_login_attempt(username, False, request)
        payload, _ = response.api_error("auth.login_failed", "Invalid username or password", 401, details={"mfa_required": False, "lockout_seconds": 0})
        raise HTTPException(status_code=401, detail=payload)
    if int(user.get("mfa_enabled", 0)) == 1:
        if not mfa_code:
            logic.login_attempts.record_login_attempt(username, False, request)
            payload, _ = response.api_error("auth.login_failed", "MFA code is required or invalid", 401, details={"mfa_required": True, "lockout_seconds": 0})
            raise HTTPException(status_code=401, detail=payload)
        # TOTP verification not implemented; accept any non-empty code for parity placeholder
    logic.login_attempts.reset_login_attempts(username, request)
    logic.login_attempts.record_login_attempt(username, True, request)

    token, csrf_token = session_module.create_session(int(user["id"]))
    logic.audit.create_audit_log(int(user["id"]), "auth.login", "session", token[:16], {"mfa_enabled": int(user.get("mfa_enabled", 0))})

    user_out = {k: v for k, v in user.items() if k != "password_hash" and k != "mfa_secret"}
    user_out["is_active"] = int(user_out.get("is_active", 0))
    user_out["must_change_password"] = int(user_out.get("must_change_password", 0))
    user_out["mfa_enabled"] = int(user_out.get("mfa_enabled", 0))
    resp = response.json_success(request, {"user": user_out, "csrf_token": csrf_token, "must_change_password": bool(user.get("must_change_password"))})
    resp.set_cookie(
        key=session_module.SESSION_COOKIE_NAME,
        value=token,
        max_age=config.SESSION_LIFETIME_SECONDS,
        path="/",
        httponly=True,
        samesite="lax",
        secure=config.SESSION_COOKIE_SECURE,
    )
    return resp


@app.get("/api/session-me.php")
async def session_me(request: Request, session_result: tuple = Depends(_require_session)):
    user, csrf_token = session_result
    user_out = {k: v for k, v in user.items() if k != "password_hash" and k != "mfa_secret"}
    return response.json_success(request, {"user": user_out, "csrf_token": csrf_token})


@app.post("/api/session-logout.php")
async def session_logout(request: Request, session_result: tuple = Depends(_require_session)):
    from . import logic
    body = await _read_json_body(request)
    body = body or {}
    user, csrf_token = session_result
    provided_csrf = body.get("csrf_token") or (request.headers.get("X-CSRF-Token") if hasattr(request, "headers") else None)
    if not session_module.verify_csrf(csrf_token, provided_csrf):
        payload, _ = response.api_error("auth.forbidden", "CSRF validation failed", 403)
        raise HTTPException(status_code=403, detail=payload)
    token = session_module.get_session_token_from_request(request)
    session_module.destroy_session(token)
    logic.audit.create_audit_log(int(user["id"]), "auth.logout", "session", None)
    resp = response.json_success(request, {"logged_out": True})
    resp.delete_cookie(key=session_module.SESSION_COOKIE_NAME, path="/")
    return resp


def create_app() -> FastAPI:
    """Return the app (for testing or alternate ASGI servers)."""
    return app
