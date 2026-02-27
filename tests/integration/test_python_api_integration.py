"""
Integration tests for the Python API mirror.
Runs the same contract as PHP: health, task CRUD, list, search, auth.
Uses in-process TestClient (python_api_app fixture); optional python_server for live uvicorn.
"""
import pytest


pytestmark = pytest.mark.integration


def _auth_headers(api_key: str) -> dict:
    return {"X-API-Key": api_key, "Content-Type": "application/json"}


def test_python_health_requires_auth_and_emits_rate_headers(python_api_app):
    client = python_api_app.client
    api_key = python_api_app.api_key
    unauthorized = client.get("/api/health.php")
    assert unauthorized.status_code == 401
    unauthorized_json = unauthorized.json()
    assert unauthorized_json["success"] is False
    assert unauthorized_json["error_object"]["code"] == "auth.invalid_api_key"

    authorized = client.get("/api/health.php", headers=_auth_headers(api_key))
    assert authorized.status_code == 200
    payload = authorized.json()
    assert payload["success"] is True
    assert payload["user"]["username"] == python_api_app.admin_username
    assert payload["ok"] is True
    assert "x-ratelimit-limit" in [h.lower() for h in authorized.headers.keys()]
    assert "x-ratelimit-remaining" in [h.lower() for h in authorized.headers.keys()]


def test_python_task_crud_and_list(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)

    create_resp = client.post(
        "/api/create-task.php",
        headers=headers,
        json={"title": "Python API test task", "status": "todo", "priority": "normal"},
    )
    assert create_resp.status_code == 201
    created = create_resp.json().get("data") or create_resp.json()
    task = created.get("task") or created
    task_id = int(task["id"])
    assert task["title"] == "Python API test task"
    assert task["status"] == "todo"
    assert task.get("priority") == "normal"

    get_resp = client.get("/api/get-task.php", headers=headers, params={"id": task_id})
    assert get_resp.status_code == 200
    get_data = get_resp.json().get("data") or get_resp.json()
    assert get_data["task"]["id"] == task_id

    list_resp = client.get("/api/list-tasks.php", headers=headers, params={"limit": 10})
    assert list_resp.status_code == 200
    list_data = list_resp.json().get("data") or list_resp.json()
    tasks = list_data.get("tasks") or []
    ids = [t["id"] for t in tasks]
    assert task_id in ids

    delete_resp = client.post("/api/delete-task.php", headers=headers, json={"id": task_id})
    assert delete_resp.status_code == 200


def test_python_list_statuses_and_projects(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    status_resp = client.get("/api/list-statuses.php", headers=headers)
    assert status_resp.status_code == 200
    status_data = status_resp.json().get("data") or status_resp.json()
    statuses = status_data.get("statuses") or []
    assert len(statuses) >= 1
    slugs = [s["slug"] for s in statuses]
    assert "todo" in slugs or "doing" in slugs or "done" in slugs

    proj_resp = client.get("/api/list-projects.php", headers=headers)
    assert proj_resp.status_code == 200


def test_python_admin_users_and_audit(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    users_resp = client.get("/api/list-users.php", headers=headers)
    assert users_resp.status_code == 200
    users_data = users_resp.json().get("data") or users_resp.json()
    assert "users" in users_data
    assert len(users_data["users"]) >= 1

    audit_resp = client.get("/api/list-audit-logs.php", headers=headers, params={"limit": 5})
    assert audit_resp.status_code == 200
    audit_data = audit_resp.json().get("data") or audit_resp.json()
    assert "logs" in audit_data


def test_python_session_login_and_me(python_api_app):
    client = python_api_app.client
    login_resp = client.post(
        "/api/session-login.php",
        json={"username": python_api_app.admin_username, "password": python_api_app.admin_password},
    )
    assert login_resp.status_code == 200
    login_data = login_resp.json().get("data") or login_resp.json()
    assert "user" in login_data
    assert login_data["user"]["username"] == python_api_app.admin_username
    assert "csrf_token" in login_data
    session_cookie = login_resp.cookies.get("sanctum_tasks_py_session")
    assert session_cookie

    # TestClient persists cookies; send session-me with same cookie if needed
    me_resp = client.get("/api/session-me.php", cookies=dict(sanctum_tasks_py_session=session_cookie))
    assert me_resp.status_code == 200, me_resp.text
    me_data = me_resp.json().get("data") or me_resp.json()
    assert me_data["user"]["username"] == python_api_app.admin_username


def test_python_search_tasks_bulk_create_update(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    # search
    r = client.get("/api/search-tasks.php", headers=headers, params={"q": "test", "limit": 5})
    assert r.status_code == 200
    # bulk create
    r = client.post(
        "/api/bulk-create-tasks.php",
        headers=headers,
        json={"tasks": [{"title": "B1", "status": "todo"}, {"title": "B2", "status": "todo"}]},
    )
    assert r.status_code in (200, 201)
    data = r.json().get("data") or r.json()
    tasks = data.get("tasks") or []
    ids = [t["id"] for t in tasks]
    if len(ids) >= 2:
        r = client.post(
            "/api/bulk-update-tasks.php",
            headers=headers,
            json={"task_ids": ids[:2], "updates": {"status": "doing"}},
        )
        assert r.status_code == 200


def test_python_create_status(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.post(
        "/api/create-status.php",
        headers=headers,
        json={"slug": "review", "label": "In Review", "is_done": 0},
    )
    assert r.status_code == 201
    data = r.json().get("data") or r.json()
    assert data.get("status", {}).get("slug") == "review"


def test_python_comments_attachments_watchers(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    # create task
    cr = client.post("/api/create-task.php", headers=headers, json={"title": "CW", "status": "todo"})
    assert cr.status_code == 201
    task_id = (cr.json().get("data") or cr.json()).get("task", {}).get("id")
    if not task_id:
        task_id = (cr.json().get("data") or cr.json()).get("id")
    task_id = int(task_id)
    # comment
    r = client.post("/api/create-comment.php", headers=headers, json={"task_id": task_id, "comment": "Hello"})
    assert r.status_code == 201
    r = client.get("/api/list-comments.php", headers=headers, params={"task_id": task_id})
    assert r.status_code == 200
    # attachment
    r = client.post(
        "/api/add-attachment.php",
        headers=headers,
        json={"task_id": task_id, "file_name": "f.txt", "file_url": "https://example.com/f.txt"},
    )
    assert r.status_code == 201
    r = client.get("/api/list-attachments.php", headers=headers, params={"task_id": task_id})
    assert r.status_code == 200
    # watch
    r = client.post("/api/watch-task.php", headers=headers, json={"task_id": task_id})
    assert r.status_code == 200
    r = client.get("/api/list-watchers.php", headers=headers, params={"task_id": task_id})
    assert r.status_code == 200
    r = client.post("/api/unwatch-task.php", headers=headers, json={"task_id": task_id})
    assert r.status_code == 200
    client.post("/api/delete-task.php", headers=headers, json={"id": task_id})


def test_python_list_tags(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.get("/api/list-tags.php", headers=headers)
    assert r.status_code == 200


def test_python_create_user_disable_reset_password(python_api_app):
    import uuid
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    username = f"newuser_{uuid.uuid4().hex[:8]}"
    r = client.post(
        "/api/create-user.php",
        headers=headers,
        json={"username": username, "password": "NewUser123!Ab", "role": "member", "must_change_password": True},
    )
    assert r.status_code == 201, (r.status_code, r.json())
    data = r.json().get("data") or r.json()
    uid = data.get("user", {}).get("id")
    assert uid
    r = client.post("/api/disable-user.php", headers=headers, json={"id": uid, "is_active": False})
    assert r.status_code == 200
    r = client.post("/api/disable-user.php", headers=headers, json={"id": uid, "is_active": True})
    assert r.status_code == 200
    r = client.post("/api/reset-user-password.php", headers=headers, json={"id": uid, "must_change_password": False})
    assert r.status_code == 200
    j = r.json()
    assert j.get("success") is True
    assert "id" in j
    assert "temporary_password" not in j


def test_python_api_keys(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.get("/api/list-api-keys.php", headers=headers)
    assert r.status_code == 200
    r = client.post("/api/create-api-key.php", headers=headers, json={"key_name": "test-key"})
    assert r.status_code == 201
    list_data = client.get("/api/list-api-keys.php", headers=headers).json().get("data") or {}
    keys = list_data.get("api_keys") or []
    key_id = next((k["id"] for k in keys if k.get("key_name") == "test-key"), keys[0]["id"] if keys else None)
    if key_id:
        r = client.post("/api/revoke-api-key.php", headers=headers, json={"id": key_id})
        assert r.status_code == 200


def test_python_admin_only_returns_403_for_member(python_api_app):
    """Member user cannot access admin-only endpoints."""
    import uuid
    client = python_api_app.client
    admin_headers = _auth_headers(python_api_app.api_key)
    username = f"member_{uuid.uuid4().hex[:8]}"
    r = client.post(
        "/api/create-user.php",
        headers=admin_headers,
        json={"username": username, "password": "Member123!Ab", "role": "member", "create_api_key": True, "api_key_name": "m2"},
    )
    assert r.status_code == 201, (r.status_code, r.json())
    data = r.json().get("data") or r.json()
    member_key = data.get("api_key")
    assert member_key, "create_api_key=True should return api_key"
    member_headers = _auth_headers(member_key)
    r = client.get("/api/list-users.php", headers=member_headers)
    assert r.status_code == 403


def test_python_invalid_json_returns_400(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.post("/api/create-task.php", headers=headers, data="not json")
    assert r.status_code == 400
    r = client.post("/api/create-user.php", headers=headers, data="invalid")
    assert r.status_code == 400


def test_python_get_task_404(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.get("/api/get-task.php", headers=headers, params={"id": 99999})
    assert r.status_code == 404


def test_python_update_task_validation(python_api_app):
    client = python_api_app.client
    headers = _auth_headers(python_api_app.api_key)
    r = client.post("/api/update-task.php", headers=headers, json={"id": 99999, "title": "x"})
    assert r.status_code == 404


def test_python_session_logout(python_api_app):
    client = python_api_app.client
    login_resp = client.post(
        "/api/session-login.php",
        json={"username": python_api_app.admin_username, "password": python_api_app.admin_password},
    )
    assert login_resp.status_code == 200
    session_cookie = login_resp.cookies.get("sanctum_tasks_py_session")
    csrf = (login_resp.json().get("data") or login_resp.json()).get("csrf_token")
    r = client.post(
        "/api/session-logout.php",
        cookies=dict(sanctum_tasks_py_session=session_cookie),
        json={"csrf_token": csrf},
    )
    assert r.status_code == 200
