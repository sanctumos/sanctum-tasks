import base64
import hashlib
import hmac
import json
import re
import struct
import time
import uuid
from urllib.parse import parse_qs, urlparse

import pytest
import requests


pytestmark = pytest.mark.integration


def _api_url(base_url: str, path: str) -> str:
    return f"{base_url}{path}"


def _auth_headers(api_key: str) -> dict:
    return {
        "X-API-Key": api_key,
        "Content-Type": "application/json",
    }


def _totp(secret: str, epoch: float | None = None) -> str:
    cleaned = re.sub(r"[^A-Z2-7]", "", secret.upper())
    padding = "=" * ((8 - len(cleaned) % 8) % 8)
    key = base64.b32decode(cleaned + padding, casefold=True)
    counter = int((epoch or time.time()) // 30)
    digest = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    binary = (
        ((digest[offset] & 0x7F) << 24)
        | ((digest[offset + 1] & 0xFF) << 16)
        | ((digest[offset + 2] & 0xFF) << 8)
        | (digest[offset + 3] & 0xFF)
    )
    return f"{binary % 1000000:06d}"


def _extract_mfa_secret(html: str) -> str:
    matches = re.findall(r"\b[A-Z2-7]{16,}\b", html)
    if not matches:
        raise AssertionError("Could not find MFA secret in HTML response")
    return matches[0]


def test_health_requires_auth_and_emits_rate_headers(php_server):
    base_url = php_server.base_url

    unauthorized = requests.get(_api_url(base_url, "/api/health.php"), timeout=5)
    assert unauthorized.status_code == 401
    unauthorized_json = unauthorized.json()
    assert unauthorized_json["success"] is False
    assert unauthorized_json["error_object"]["code"] == "auth.invalid_api_key"

    authorized = requests.get(
        _api_url(base_url, "/api/health.php"),
        headers=_auth_headers(php_server.api_key),
        timeout=5,
    )
    assert authorized.status_code == 200
    payload = authorized.json()
    assert payload["success"] is True
    assert payload["user"]["username"] == php_server.admin_username
    assert payload["ok"] is True
    assert "X-RateLimit-Limit" in authorized.headers
    assert "X-RateLimit-Remaining" in authorized.headers
    assert "X-RateLimit-Reset" in authorized.headers


def test_public_landing_page_labels_health_as_authenticated(php_server):
    base_url = php_server.base_url

    response = requests.get(_api_url(base_url, "/"), timeout=5)
    assert response.status_code == 200
    assert "Authenticated API Health" in response.text
    assert "requires API key authentication" in response.text
    assert 'href="/api/health.php"' in response.text


def test_task_crud_search_sort_and_collaboration_endpoints(php_server):
    base_url = php_server.base_url
    headers = _auth_headers(php_server.api_key)
    token = uuid.uuid4().hex[:8]

    create_payload = {
        "title": f"Investigate deploy error {token}",
        "body": "Check release logs",
        "status": "todo",
        "priority": "high",
        "project": f"Platform-{token}",
        "tags": ["deploy", "infra"],
        "due_at": "2026-03-01T12:30:00Z",
        "rank": 10,
        "recurrence_rule": "FREQ=WEEKLY;BYDAY=MO",
    }
    create_resp = requests.post(
        _api_url(base_url, "/api/create-task.php"),
        headers=headers,
        json=create_payload,
        timeout=5,
    )
    assert create_resp.status_code == 201
    created = create_resp.json()["task"]
    task_id = int(created["id"])
    assert created["priority"] == "high"
    assert created["project"].startswith("Platform-")
    assert created["tags"] == ["deploy", "infra"]
    assert created["recurrence_rule"] == "FREQ=WEEKLY;BYDAY=MO"
    assert created["due_at"].startswith("2026-03-01")

    comment_resp = requests.post(
        _api_url(base_url, "/api/create-comment.php"),
        headers=headers,
        json={"task_id": task_id, "comment": "Investigating now"},
        timeout=5,
    )
    assert comment_resp.status_code == 201
    assert comment_resp.json()["comment_id"] > 0

    attachment_resp = requests.post(
        _api_url(base_url, "/api/add-attachment.php"),
        headers=headers,
        json={
            "task_id": task_id,
            "file_name": "runbook.md",
            "file_url": "https://example.com/runbook.md",
            "mime_type": "text/markdown",
            "size_bytes": 1024,
        },
        timeout=5,
    )
    assert attachment_resp.status_code == 201

    watch_resp = requests.post(
        _api_url(base_url, "/api/watch-task.php"),
        headers=headers,
        json={"task_id": task_id},
        timeout=5,
    )
    assert watch_resp.status_code == 200
    assert watch_resp.json()["watching"] is True

    get_resp = requests.get(
        _api_url(base_url, "/api/get-task.php"),
        headers=headers,
        params={"id": task_id, "include_relations": 1},
        timeout=5,
    )
    assert get_resp.status_code == 200
    task = get_resp.json()["task"]
    assert task["comment_count"] >= 1
    assert task["attachment_count"] >= 1
    assert task["watcher_count"] >= 1
    assert len(task["comments"]) >= 1
    assert len(task["attachments"]) >= 1
    assert len(task["watchers"]) >= 1

    list_resp = requests.get(
        _api_url(base_url, "/api/list-tasks.php"),
        headers=headers,
        params={
            "q": "deploy",
            "project": created["project"],
            "priority": "high",
            "sort_by": "rank",
            "sort_dir": "DESC",
            "limit": 20,
            "offset": 0,
        },
        timeout=5,
    )
    assert list_resp.status_code == 200
    list_payload = list_resp.json()
    assert list_payload["count"] >= 1
    assert list_payload["pagination"]["limit"] == 20
    assert any(int(t["id"]) == task_id for t in list_payload["tasks"])

    search_resp = requests.get(
        _api_url(base_url, "/api/search-tasks.php"),
        headers=headers,
        params={"q": "deploy", "limit": 10},
        timeout=5,
    )
    assert search_resp.status_code == 200
    assert search_resp.json()["count"] >= 1

    update_resp = requests.post(
        _api_url(base_url, "/api/update-task.php"),
        headers=headers,
        json={
            "id": task_id,
            "status": "done",
            "priority": "urgent",
            "tags": ["deploy", "postmortem"],
            "rank": 77,
        },
        timeout=5,
    )
    assert update_resp.status_code == 200
    updated = update_resp.json()["task"]
    assert updated["status"] == "done"
    assert updated["priority"] == "urgent"
    assert updated["tags"] == ["deploy", "postmortem"]
    assert int(updated["rank"]) == 77

    unwatch_resp = requests.post(
        _api_url(base_url, "/api/unwatch-task.php"),
        headers=headers,
        json={"task_id": task_id},
        timeout=5,
    )
    assert unwatch_resp.status_code == 200
    assert unwatch_resp.json()["watching"] is False

    delete_resp = requests.post(
        _api_url(base_url, "/api/delete-task.php"),
        headers=headers,
        json={"id": task_id},
        timeout=5,
    )
    assert delete_resp.status_code == 200
    assert delete_resp.json()["deleted"] is True

    missing_resp = requests.get(
        _api_url(base_url, "/api/get-task.php"),
        headers=headers,
        params={"id": task_id},
        timeout=5,
    )
    assert missing_resp.status_code == 404
    assert missing_resp.json()["error_object"]["code"] == "task.not_found"


def test_list_and_search_reject_invalid_enum_filters(php_server):
    base_url = php_server.base_url
    headers = _auth_headers(php_server.api_key)

    invalid_list_status = requests.get(
        _api_url(base_url, "/api/list-tasks.php"),
        headers=headers,
        params={"status": "not-a-real-status"},
        timeout=5,
    )
    assert invalid_list_status.status_code == 400
    invalid_list_status_payload = invalid_list_status.json()
    assert invalid_list_status_payload["success"] is False
    assert invalid_list_status_payload["error_object"]["code"] == "validation.invalid_status"
    assert invalid_list_status_payload["error_object"]["details"]["field"] == "status"

    invalid_list_priority = requests.get(
        _api_url(base_url, "/api/list-tasks.php"),
        headers=headers,
        params={"priority": "super-urgent"},
        timeout=5,
    )
    assert invalid_list_priority.status_code == 400
    invalid_list_priority_payload = invalid_list_priority.json()
    assert invalid_list_priority_payload["success"] is False
    assert invalid_list_priority_payload["error_object"]["code"] == "validation.invalid_priority"
    assert invalid_list_priority_payload["error_object"]["details"]["field"] == "priority"

    invalid_search_status = requests.get(
        _api_url(base_url, "/api/search-tasks.php"),
        headers=headers,
        params={"q": "deploy", "status": "not-a-real-status"},
        timeout=5,
    )
    assert invalid_search_status.status_code == 400
    invalid_search_status_payload = invalid_search_status.json()
    assert invalid_search_status_payload["success"] is False
    assert invalid_search_status_payload["error_object"]["code"] == "validation.invalid_status"
    assert invalid_search_status_payload["error_object"]["details"]["field"] == "status"

    invalid_search_priority = requests.get(
        _api_url(base_url, "/api/search-tasks.php"),
        headers=headers,
        params={"q": "deploy", "priority": "super-urgent"},
        timeout=5,
    )
    assert invalid_search_priority.status_code == 400
    invalid_search_priority_payload = invalid_search_priority.json()
    assert invalid_search_priority_payload["success"] is False
    assert invalid_search_priority_payload["error_object"]["code"] == "validation.invalid_priority"
    assert invalid_search_priority_payload["error_object"]["details"]["field"] == "priority"


def test_task_count_fields_are_exact_with_multiple_related_rows(php_server):
    base_url = php_server.base_url
    headers = _auth_headers(php_server.api_key)
    token = uuid.uuid4().hex[:8]

    create_resp = requests.post(
        _api_url(base_url, "/api/create-task.php"),
        headers=headers,
        json={
            "title": f"Count verification task {token}",
            "body": "Verify count fields",
            "status": "todo",
            "priority": "normal",
            "project": f"Counts-{token}",
        },
        timeout=5,
    )
    assert create_resp.status_code == 201
    task_id = int(create_resp.json()["task"]["id"])

    for idx in range(2):
        comment_resp = requests.post(
            _api_url(base_url, "/api/create-comment.php"),
            headers=headers,
            json={"task_id": task_id, "comment": f"Comment {idx} {token}"},
            timeout=5,
        )
        assert comment_resp.status_code == 201

    for idx in range(2):
        attachment_resp = requests.post(
            _api_url(base_url, "/api/add-attachment.php"),
            headers=headers,
            json={
                "task_id": task_id,
                "file_name": f"evidence-{idx}.txt",
                "file_url": f"https://example.com/{token}/evidence-{idx}.txt",
                "mime_type": "text/plain",
                "size_bytes": 10 + idx,
            },
            timeout=5,
        )
        assert attachment_resp.status_code == 201

    admin_watch_resp = requests.post(
        _api_url(base_url, "/api/watch-task.php"),
        headers=headers,
        json={"task_id": task_id},
        timeout=5,
    )
    assert admin_watch_resp.status_code == 200
    assert admin_watch_resp.json()["watching"] is True

    watcher_user_resp = requests.post(
        _api_url(base_url, "/api/create-user.php"),
        headers=headers,
        json={
            "username": f"watcher_{token}",
            "password": "StrongPass123!",
            "role": "member",
            "create_api_key": True,
            "api_key_name": f"watch-{token}",
        },
        timeout=5,
    )
    assert watcher_user_resp.status_code == 201
    watcher_api_key = watcher_user_resp.json()["api_key"]

    second_watch_resp = requests.post(
        _api_url(base_url, "/api/watch-task.php"),
        headers=_auth_headers(watcher_api_key),
        json={"task_id": task_id},
        timeout=5,
    )
    assert second_watch_resp.status_code == 200
    assert second_watch_resp.json()["watching"] is True

    list_resp = requests.get(
        _api_url(base_url, "/api/list-tasks.php"),
        headers=headers,
        params={"project": f"Counts-{token}", "q": token, "limit": 20},
        timeout=5,
    )
    assert list_resp.status_code == 200
    list_payload = list_resp.json()
    listed_task = next((task for task in list_payload["tasks"] if int(task["id"]) == task_id), None)
    assert listed_task is not None
    assert int(listed_task["comment_count"]) == 2
    assert int(listed_task["attachment_count"]) == 2
    assert int(listed_task["watcher_count"]) == 2

    get_resp = requests.get(
        _api_url(base_url, "/api/get-task.php"),
        headers=headers,
        params={"id": task_id, "include_relations": 1},
        timeout=5,
    )
    assert get_resp.status_code == 200
    task_payload = get_resp.json()["task"]
    assert int(task_payload["comment_count"]) == 2
    assert int(task_payload["attachment_count"]) == 2
    assert int(task_payload["watcher_count"]) == 2
    assert len(task_payload["comments"]) == 2
    assert len(task_payload["attachments"]) == 2
    assert len(task_payload["watchers"]) == 2


def test_search_pagination_preserves_filters_and_uses_trusted_origin(php_server):
    base_url = php_server.base_url
    headers = _auth_headers(php_server.api_key)
    token = uuid.uuid4().hex[:8]

    for idx in range(3):
        create_resp = requests.post(
            _api_url(base_url, "/api/create-task.php"),
            headers=headers,
            json={
                "title": f"Pagination token {token}-{idx}",
                "status": "todo",
                "priority": "high",
                "assigned_to_user_id": 1,
            },
            timeout=5,
        )
        assert create_resp.status_code == 201

    search_resp = requests.get(
        _api_url(base_url, "/api/search-tasks.php"),
        headers={**headers, "Host": "evil.example"},
        params={
            "q": token,
            "status": "todo",
            "priority": "high",
            "assigned_to_user_id": 1,
            "sort_by": "created_at",
            "sort_dir": "ASC",
            "limit": 1,
            "offset": 0,
        },
        timeout=5,
    )
    assert search_resp.status_code == 200
    payload = search_resp.json()
    assert payload["count"] == 1

    next_url = payload["pagination"]["next_url"]
    assert isinstance(next_url, str) and next_url
    assert "evil.example" not in next_url

    parsed_next = urlparse(next_url)
    parsed_base = urlparse(base_url)
    assert parsed_next.scheme == parsed_base.scheme
    assert parsed_next.netloc == parsed_base.netloc

    next_query = parse_qs(parsed_next.query)
    assert next_query["q"] == [token]
    assert next_query["status"] == ["todo"]
    assert next_query["priority"] == ["high"]
    assert next_query["assigned_to_user_id"] == ["1"]
    assert next_query["sort_by"] == ["created_at"]
    assert next_query["sort_dir"] == ["ASC"]
    assert next_query["limit"] == ["1"]
    assert next_query["offset"] == ["1"]


def test_bulk_status_user_and_api_key_lifecycle_endpoints(php_server):
    base_url = php_server.base_url
    headers = _auth_headers(php_server.api_key)
    suffix = uuid.uuid4().hex[:8]

    status_resp = requests.post(
        _api_url(base_url, "/api/create-status.php"),
        headers=headers,
        json={"slug": f"blocked-{suffix}", "label": "Blocked", "sort_order": 40, "is_done": False},
        timeout=5,
    )
    assert status_resp.status_code == 201
    assert status_resp.json()["status"]["slug"].startswith("blocked-")

    statuses_resp = requests.get(_api_url(base_url, "/api/list-statuses.php"), headers=headers, timeout=5)
    assert statuses_resp.status_code == 200
    assert any(s["slug"].startswith("blocked-") for s in statuses_resp.json()["statuses"])

    bulk_create = requests.post(
        _api_url(base_url, "/api/bulk-create-tasks.php"),
        headers=headers,
        json={
            "tasks": [
                {"title": f"Bulk task one {suffix}", "status": "todo", "project": "Bulk"},
                {"title": f"Bulk task two {suffix}", "status": "doing", "project": "Bulk"},
            ]
        },
        timeout=5,
    )
    assert bulk_create.status_code == 200
    bulk_create_json = bulk_create.json()
    assert bulk_create_json["created"] == 2

    created_ids = [item["id"] for item in bulk_create_json["results"] if item["success"]]
    assert len(created_ids) == 2

    bulk_update = requests.post(
        _api_url(base_url, "/api/bulk-update-tasks.php"),
        headers=headers,
        json={
            "updates": [
                {"id": int(created_ids[0]), "status": "done", "priority": "high"},
                {"id": int(created_ids[1]), "status": "done", "priority": "urgent"},
            ]
        },
        timeout=5,
    )
    assert bulk_update.status_code == 200
    assert bulk_update.json()["updated"] == 2

    user_resp = requests.post(
        _api_url(base_url, "/api/create-user.php"),
        headers=headers,
        json={
            "username": f"user_{suffix}",
            "password": "StrongPass123!",
            "role": "member",
            "create_api_key": True,
            "api_key_name": f"seed-{suffix}",
        },
        timeout=5,
    )
    assert user_resp.status_code == 201
    user_payload = user_resp.json()
    created_user_id = int(user_payload["user"]["id"])
    assert isinstance(user_payload["api_key"], str) and len(user_payload["api_key"]) >= 64

    users_resp = requests.get(
        _api_url(base_url, "/api/list-users.php"),
        headers=headers,
        params={"include_disabled": 1},
        timeout=5,
    )
    assert users_resp.status_code == 200
    assert any(int(u["id"]) == created_user_id for u in users_resp.json()["users"])

    key_name = f"automation-{suffix}"
    create_key_resp = requests.post(
        _api_url(base_url, "/api/create-api-key.php"),
        headers=headers,
        json={"user_id": created_user_id, "key_name": key_name},
        timeout=5,
    )
    assert create_key_resp.status_code == 201
    created_api_key = create_key_resp.json()["api_key"]
    assert len(created_api_key) == 64

    list_keys_resp = requests.get(
        _api_url(base_url, "/api/list-api-keys.php"),
        headers=headers,
        params={"include_revoked": 1},
        timeout=5,
    )
    assert list_keys_resp.status_code == 200
    matching = [k for k in list_keys_resp.json()["api_keys"] if k["key_name"] == key_name]
    assert matching, f"Expected key named {key_name} in API key list"
    key_id = int(matching[0]["id"])

    revoke_resp = requests.post(
        _api_url(base_url, "/api/revoke-api-key.php"),
        headers=headers,
        json={"id": key_id},
        timeout=5,
    )
    assert revoke_resp.status_code == 200
    assert revoke_resp.json()["revoked"] is True

    reset_password_resp = requests.post(
        _api_url(base_url, "/api/reset-user-password.php"),
        headers=headers,
        json={"id": created_user_id, "must_change_password": True},
        timeout=5,
    )
    assert reset_password_resp.status_code == 200
    assert reset_password_resp.json()["temporary_password"]

    disable_resp = requests.post(
        _api_url(base_url, "/api/disable-user.php"),
        headers=headers,
        json={"id": created_user_id, "is_active": False},
        timeout=5,
    )
    assert disable_resp.status_code == 200
    assert int(disable_resp.json()["user"]["is_active"]) == 0

    enable_resp = requests.post(
        _api_url(base_url, "/api/disable-user.php"),
        headers=headers,
        json={"id": created_user_id, "is_active": True},
        timeout=5,
    )
    assert enable_resp.status_code == 200
    assert int(enable_resp.json()["user"]["is_active"]) == 1

    project_resp = requests.get(_api_url(base_url, "/api/list-projects.php"), headers=headers, timeout=5)
    assert project_resp.status_code == 200
    assert "projects" in project_resp.json()

    tags_resp = requests.get(_api_url(base_url, "/api/list-tags.php"), headers=headers, timeout=5)
    assert tags_resp.status_code == 200
    assert "tags" in tags_resp.json()

    audit_resp = requests.get(_api_url(base_url, "/api/list-audit-logs.php"), headers=headers, timeout=5)
    assert audit_resp.status_code == 200
    assert audit_resp.json()["count"] >= 1


def test_session_admin_csrf_password_mfa_and_logout_flows(php_server):
    base_url = php_server.base_url
    session = requests.Session()

    login_resp = session.post(
        _api_url(base_url, "/api/session-login.php"),
        json={
            "username": php_server.admin_username,
            "password": php_server.admin_password,
        },
        timeout=5,
    )
    assert login_resp.status_code == 200
    login_json = login_resp.json()
    assert login_json["success"] is True
    csrf_token = login_json["csrf_token"]
    assert isinstance(csrf_token, str) and len(csrf_token) >= 32

    # Bootstrap admin starts with must_change_password=1 and should be redirected.
    admin_index_redirect = session.get(_api_url(base_url, "/admin/"), allow_redirects=False, timeout=5)
    assert admin_index_redirect.status_code in (301, 302)
    assert "/admin/change-password.php" in admin_index_redirect.headers.get("Location", "")

    new_password = "AdminChanged123!"
    change_password_resp = session.post(
        _api_url(base_url, "/admin/change-password.php"),
        data={
            "csrf_token": csrf_token,
            "current_password": php_server.admin_password,
            "new_password": new_password,
            "confirm_password": new_password,
        },
        timeout=5,
    )
    assert change_password_resp.status_code == 200
    assert "Password changed successfully" in change_password_resp.text

    # CSRF protection should reject admin POSTs without token.
    csrf_failure = session.post(
        _api_url(base_url, "/admin/create.php"),
        data={"title": "Missing csrf", "status": "todo"},
        timeout=5,
    )
    assert csrf_failure.status_code == 403
    assert "CSRF validation failed" in csrf_failure.text

    me_resp = session.get(_api_url(base_url, "/api/session-me.php"), timeout=5)
    assert me_resp.status_code == 200
    csrf_token = me_resp.json()["csrf_token"]

    create_with_csrf = session.post(
        _api_url(base_url, "/admin/create.php"),
        data={
            "csrf_token": csrf_token,
            "title": f"Admin created task {uuid.uuid4().hex[:6]}",
            "status": "todo",
            "priority": "normal",
        },
        allow_redirects=False,
        timeout=5,
    )
    assert create_with_csrf.status_code in (301, 302)
    assert "/admin/" in create_with_csrf.headers.get("Location", "")

    users_page = session.get(_api_url(base_url, "/admin/users.php"), timeout=5)
    assert users_page.status_code == 200
    assert "Existing Users" in users_page.text

    generate_mfa = session.post(
        _api_url(base_url, "/admin/mfa.php"),
        data={"csrf_token": csrf_token, "action": "generate"},
        timeout=5,
    )
    assert generate_mfa.status_code == 200
    secret = _extract_mfa_secret(generate_mfa.text)
    code = _totp(secret)

    enable_mfa = session.post(
        _api_url(base_url, "/admin/mfa.php"),
        data={"csrf_token": csrf_token, "action": "enable", "code": code},
        timeout=5,
    )
    assert enable_mfa.status_code == 200
    assert "MFA enabled successfully." in enable_mfa.text

    logout_without_csrf = session.post(_api_url(base_url, "/api/session-logout.php"), timeout=5)
    assert logout_without_csrf.status_code == 403

    logout_with_csrf = session.post(
        _api_url(base_url, "/api/session-logout.php"),
        headers={"X-CSRF-Token": csrf_token},
        timeout=5,
    )
    assert logout_with_csrf.status_code == 200
    assert logout_with_csrf.json()["logged_out"] is True

    mfa_required_login = session.post(
        _api_url(base_url, "/api/session-login.php"),
        json={"username": php_server.admin_username, "password": new_password},
        timeout=5,
    )
    assert mfa_required_login.status_code == 401
    details = mfa_required_login.json()["error_object"]["details"]
    assert details["mfa_required"] is True

    mfa_login = session.post(
        _api_url(base_url, "/api/session-login.php"),
        json={"username": php_server.admin_username, "password": new_password, "mfa_code": _totp(secret)},
        timeout=5,
    )
    assert mfa_login.status_code == 200
    assert mfa_login.json()["success"] is True
    csrf_token = mfa_login.json()["csrf_token"]

    disable_mfa = session.post(
        _api_url(base_url, "/admin/mfa.php"),
        data={"csrf_token": csrf_token, "action": "disable", "current_password": new_password},
        timeout=5,
    )
    assert disable_mfa.status_code == 200
    assert "MFA disabled." in disable_mfa.text


def test_lockout_after_repeated_failed_logins(php_lockout_server):
    base_url = php_lockout_server.base_url

    for _ in range(2):
        bad_login = requests.post(
            _api_url(base_url, "/api/session-login.php"),
            json={"username": php_lockout_server.admin_username, "password": "WrongPass123!"},
            timeout=5,
        )
        assert bad_login.status_code == 401

    locked = requests.post(
        _api_url(base_url, "/api/session-login.php"),
        json={"username": php_lockout_server.admin_username, "password": "WrongPass123!"},
        timeout=5,
    )
    assert locked.status_code == 429
    payload = locked.json()
    assert payload["error_object"]["code"] == "auth.login_failed"
    assert payload["error_object"]["details"]["lockout_seconds"] > 0
