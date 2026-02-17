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
    assert "sanctum_tasks_py_session" in login_resp.cookies

    me_resp = client.get("/api/session-me.php")
    assert me_resp.status_code == 200
    me_data = me_resp.json().get("data") or me_resp.json()
    assert me_data["user"]["username"] == python_api_app.admin_username
