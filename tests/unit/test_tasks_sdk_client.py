import json
from typing import Any, Dict, Optional
from unittest.mock import Mock

import pytest
import requests

from tasks_sdk import TasksClient
from tasks_sdk.exceptions import APIError, AuthenticationError, NotFoundError, ValidationError


class DummyResponse:
    def __init__(self, status_code: int, payload: Optional[Dict[str, Any]] = None, text: str = ""):
        self.status_code = status_code
        self._payload = payload
        self.text = text
        self.ok = 200 <= status_code < 300

    def json(self) -> Dict[str, Any]:
        if self._payload is None:
            raise json.JSONDecodeError("bad json", self.text, 0)
        return self._payload


@pytest.fixture()
def client() -> TasksClient:
    return TasksClient(api_key="test-api-key", base_url="https://tasks.example.test")


def test_request_maps_auth_error(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(401, {"error": "bad auth"}))
    with pytest.raises(AuthenticationError):
        client._request("GET", "health.php")


def test_request_maps_not_found_error(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(404, {"error": "missing"}))
    with pytest.raises(NotFoundError):
        client._request("GET", "get-task.php")


def test_request_maps_validation_error(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(400, {"error": "invalid payload"}))
    with pytest.raises(ValidationError):
        client._request("POST", "create-task.php", data={})


def test_request_uses_error_object_message(client: TasksClient):
    client.session.request = Mock(
        return_value=DummyResponse(
            422,
            {
                "error_object": {
                    "code": "validation.failed",
                    "message": "Validation failed from object",
                    "details": {},
                }
            },
        )
    )
    with pytest.raises(ValidationError) as exc:
        client._request("POST", "create-task.php", data={})
    assert "Validation failed from object" in str(exc.value)


def test_request_maps_rate_limit_to_api_error(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(429, {"error": "Rate limit exceeded"}))
    with pytest.raises(APIError):
        client._request("GET", "list-tasks.php")


def test_request_raises_for_invalid_json_payload(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(200, None, text="not-json"))
    with pytest.raises(APIError):
        client._request("GET", "health.php")


def test_request_success_returns_response_payload(client: TasksClient):
    payload = {"ok": True, "message": "healthy"}
    client.session.request = Mock(return_value=DummyResponse(200, payload))
    assert client._request("GET", "health.php") == payload


def test_request_maps_request_exception(client: TasksClient):
    client.session.request = Mock(side_effect=requests.exceptions.RequestException("timeout"))
    with pytest.raises(APIError) as exc:
        client._request("GET", "health.php")
    assert "Request failed" in str(exc.value)


def test_request_maps_non_ok_status_to_api_error(client: TasksClient):
    client.session.request = Mock(return_value=DummyResponse(500, {"error": "server blew up"}))
    with pytest.raises(APIError) as exc:
        client._request("GET", "health.php")
    assert "server blew up" in str(exc.value)


def test_health_wrapper_calls_health_endpoint(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        return {"ok": True}

    monkeypatch.setattr(client, "_request", fake_request)
    assert client.health()["ok"] is True
    assert captured["method"] == "GET"
    assert captured["endpoint"] == "health.php"


def test_create_task_includes_new_metadata_fields(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["params"] = params
        captured["data"] = data
        return {"task": {"id": 1, "title": data["title"]}}

    monkeypatch.setattr(client, "_request", fake_request)

    task = client.create_task(
        title="Investigate incident",
        status="todo",
        assigned_to_user_id=2,
        body="Inspect logs",
        due_at="2026-03-05T10:00:00Z",
        priority="high",
        project="Platform",
        project_id=7,
        tags=["incident", "platform"],
        rank=10,
        recurrence_rule="FREQ=WEEKLY;BYDAY=MO",
    )

    assert task["id"] == 1
    assert captured["method"] == "POST"
    assert captured["endpoint"] == "create-task.php"
    assert captured["data"]["priority"] == "high"
    assert captured["data"]["project"] == "Platform"
    assert captured["data"]["project_id"] == 7
    assert captured["data"]["tags"] == ["incident", "platform"]
    assert captured["data"]["rank"] == 10
    assert captured["data"]["recurrence_rule"] == "FREQ=WEEKLY;BYDAY=MO"


def test_update_task_supports_unassign_and_clear_body(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["data"] = data
        return {"task": {"id": data["id"], "title": "x"}}

    monkeypatch.setattr(client, "_request", fake_request)
    client.update_task(
        task_id=123,
        unassign=True,
        clear_body=True,
        priority="urgent",
        rank=50,
    )

    assert captured["method"] == "POST"
    assert captured["endpoint"] == "update-task.php"
    assert captured["data"]["assigned_to_user_id"] is None
    assert captured["data"]["body"] is None
    assert captured["data"]["priority"] == "urgent"
    assert captured["data"]["rank"] == 50


def test_update_task_includes_standard_optional_fields(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["data"] = data
        return {"task": {"id": data["id"], "title": data.get("title", "x")}}

    monkeypatch.setattr(client, "_request", fake_request)
    client.update_task(
        task_id=321,
        title="New title",
        status="doing",
        assigned_to_user_id=7,
        body="Updated body",
        due_at="2026-03-11T12:00:00Z",
        project="Platform",
        tags=["x", "y"],
        recurrence_rule="FREQ=DAILY",
    )

    assert captured["method"] == "POST"
    assert captured["endpoint"] == "update-task.php"
    assert captured["data"]["title"] == "New title"
    assert captured["data"]["status"] == "doing"
    assert captured["data"]["assigned_to_user_id"] == 7
    assert captured["data"]["body"] == "Updated body"
    assert captured["data"]["due_at"] == "2026-03-11T12:00:00Z"
    assert captured["data"]["project"] == "Platform"
    assert captured["data"]["tags"] == ["x", "y"]
    assert captured["data"]["recurrence_rule"] == "FREQ=DAILY"


def test_get_task_wrapper_honors_include_relations_flag(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["params"] = params
        return {"task": {"id": 55, "title": "x"}}

    monkeypatch.setattr(client, "_request", fake_request)
    task = client.get_task(55, include_relations=False)
    assert task["id"] == 55
    assert captured["method"] == "GET"
    assert captured["endpoint"] == "get-task.php"
    assert captured["params"] == {"id": 55, "include_relations": 0}


def test_list_tasks_returns_total_and_pagination(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    def fake_request(method: str, endpoint: str, params=None, data=None):
        assert method == "GET"
        assert endpoint == "list-tasks.php"
        assert params["q"] == "deploy"
        return {
            "tasks": [{"id": 1}],
            "count": 1,
            "total": 10,
            "pagination": {"limit": 5, "offset": 0},
        }

    monkeypatch.setattr(client, "_request", fake_request)
    result = client.list_tasks(q="deploy", limit=5, offset=0, sort_by="updated_at", sort_dir="DESC")
    assert result["count"] == 1
    assert result["total"] == 10
    assert result["pagination"]["limit"] == 5


def test_list_tasks_includes_extended_filters(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["params"] = params
        return {"tasks": [], "count": 0, "total": 0, "pagination": {}}

    monkeypatch.setattr(client, "_request", fake_request)
    client.list_tasks(
        status="todo",
        assigned_to_user_id=2,
        created_by_user_id=3,
        priority="high",
        project="Core",
        due_before="2026-04-01T00:00:00Z",
        due_after="2026-03-01T00:00:00Z",
        watcher_user_id=5,
        offset=0,
    )

    assert captured["method"] == "GET"
    assert captured["endpoint"] == "list-tasks.php"
    assert captured["params"]["status"] == "todo"
    assert captured["params"]["assigned_to_user_id"] == 2
    assert captured["params"]["created_by_user_id"] == 3
    assert captured["params"]["priority"] == "high"
    assert captured["params"]["project"] == "Core"
    assert captured["params"]["due_before"] == "2026-04-01T00:00:00Z"
    assert captured["params"]["due_after"] == "2026-03-01T00:00:00Z"
    assert captured["params"]["watcher_user_id"] == 5


def test_delete_task_wrapper_calls_endpoint(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    captured = {}

    def fake_request(method: str, endpoint: str, params=None, data=None):
        captured["method"] = method
        captured["endpoint"] = endpoint
        captured["data"] = data
        return {"success": True}

    monkeypatch.setattr(client, "_request", fake_request)
    assert client.delete_task(123) is True
    assert captured["method"] == "POST"
    assert captured["endpoint"] == "delete-task.php"
    assert captured["data"] == {"id": 123}


def test_optional_payload_fields_in_wrappers(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    calls = []

    def fake_request(method: str, endpoint: str, params=None, data=None):
        calls.append((method, endpoint, params, data))
        if endpoint == "reset-user-password.php":
            return {"success": True}
        if endpoint == "create-api-key.php":
            return {"success": True}
        return {"success": True}

    monkeypatch.setattr(client, "_request", fake_request)
    client.reset_user_password(9, new_password="Temp123!", must_change_password=False)
    client.create_api_key("name", user_id=3)
    client.add_attachment(7, "log.txt", "https://example.com/log.txt", mime_type="text/plain", size_bytes=42)
    client.watch_task(7, user_id=3)
    client.unwatch_task(7, user_id=3)

    endpoint_to_payload = {endpoint: payload for _, endpoint, _, payload in calls}
    assert endpoint_to_payload["reset-user-password.php"]["new_password"] == "Temp123!"
    assert endpoint_to_payload["create-api-key.php"]["user_id"] == 3
    assert endpoint_to_payload["add-attachment.php"]["mime_type"] == "text/plain"
    assert endpoint_to_payload["add-attachment.php"]["size_bytes"] == 42
    assert endpoint_to_payload["watch-task.php"]["user_id"] == 3
    assert endpoint_to_payload["unwatch-task.php"]["user_id"] == 3


def test_extended_endpoint_wrappers(client: TasksClient, monkeypatch: pytest.MonkeyPatch):
    calls = []

    def fake_request(method: str, endpoint: str, params=None, data=None):
        calls.append((method, endpoint, params, data))
        if endpoint == "list-statuses.php":
            return {"statuses": [{"slug": "todo"}]}
        if endpoint == "list-users.php":
            return {"users": [{"id": 1}]}
        if endpoint == "list-api-keys.php":
            return {"api_keys": [{"id": 1}]}
        if endpoint == "list-comments.php":
            return {"comments": [{"id": 1}]}
        if endpoint == "list-attachments.php":
            return {"attachments": [{"id": 1}]}
        if endpoint == "list-watchers.php":
            return {"watchers": [{"user_id": 1}]}
        if endpoint == "list-projects.php":
            return {"projects": [{"name": "Platform"}]}
        if endpoint == "list-tags.php":
            return {"tags": [{"name": "infra"}]}
        if endpoint == "list-audit-logs.php":
            return {"logs": [{"id": 1}]}
        if endpoint == "search-tasks.php":
            return {"tasks": [{"id": 2}], "count": 1, "total": 1, "pagination": {}}
        if endpoint == "create-status.php":
            return {"status": {"slug": "blocked", "label": "Blocked"}}
        if endpoint == "create-comment.php":
            return {"comment": {"id": 99, "comment": "x"}}
        return {"success": True}

    monkeypatch.setattr(client, "_request", fake_request)

    assert client.bulk_create_tasks([{"title": "a"}])["success"] is True
    assert client.bulk_update_tasks([{"id": 1, "status": "done"}])["success"] is True
    assert client.list_statuses() == [{"slug": "todo"}]
    assert client.create_status("blocked", "Blocked")["slug"] == "blocked"
    assert client.list_users() == [{"id": 1}]
    assert client.create_user("u", "Password123!")["success"] is True
    assert client.disable_user(1)["success"] is True
    assert client.reset_user_password(1)["success"] is True
    assert client.list_api_keys() == [{"id": 1}]
    assert client.create_api_key("agent")["success"] is True
    assert client.revoke_api_key(1)["success"] is True
    assert client.list_comments(1) == [{"id": 1}]
    assert client.create_comment(1, "x")["id"] == 99
    assert client.list_attachments(1) == [{"id": 1}]
    assert client.add_attachment(1, "f", "https://example.com/f")["success"] is True
    assert client.list_watchers(1) == [{"user_id": 1}]
    assert client.watch_task(1)["success"] is True
    assert client.unwatch_task(1)["success"] is True
    assert client.list_projects() == [{"name": "Platform"}]
    assert client.list_tags() == [{"name": "infra"}]
    assert client.list_audit_logs() == [{"id": 1}]
    assert client.search_tasks("deploy")["total"] == 1

    endpoints = [c[1] for c in calls]
    assert "bulk-create-tasks.php" in endpoints
    assert "bulk-update-tasks.php" in endpoints
    assert "create-status.php" in endpoints
    assert "create-user.php" in endpoints
    assert "create-api-key.php" in endpoints
    assert "add-attachment.php" in endpoints
