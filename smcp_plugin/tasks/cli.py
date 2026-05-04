#!/usr/bin/env python3
"""
Tasks SMCP Plugin — CLI surface matches tasks_sdk.TasksClient (API-key routes only).

Session endpoints (cookie auth) are not exposed; use API keys.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import traceback
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, cast

sys.path.insert(0, str(Path(__file__).parent.parent.parent))
try:
    from tasks_sdk import (
        TasksClient,
        APIError,
        AuthenticationError,
        NotFoundError,
        ValidationError,
    )
except ImportError:  # pragma: no cover
    sys.path.insert(0, str(Path(__file__).parent.parent.parent / "tasks_sdk"))
    from client import TasksClient
    from exceptions import APIError, AuthenticationError, NotFoundError, ValidationError

BASE_URL = os.environ.get("TASKS_API_BASE_URL", "https://tasks.example.com").rstrip("/")
try:
    from smcp_plugin.tasks import __version__ as PLUGIN_VERSION
except ImportError:  # pragma: no cover
    PLUGIN_VERSION = "0.3.0"
DEBUG_TRACEBACKS = False


def _error_response(error: str, error_type: str, include_traceback: bool = True) -> Dict[str, Any]:
    payload: Dict[str, Any] = {
        "status": "error",
        "error": error,
        "error_type": error_type,
    }
    if include_traceback and DEBUG_TRACEBACKS:
        payload["traceback"] = traceback.format_exc()
    return payload


def _success(**data: Any) -> Dict[str, Any]:
    out: Dict[str, Any] = {"status": "success"}
    out.update(data)
    return out


def _ok_from_api(body: Dict[str, Any]) -> Dict[str, Any]:
    """Merge API JSON into a success envelope; rename key `status` if present to avoid clobbering CLI status."""
    out: Dict[str, Any] = {"status": "success"}
    for k, v in body.items():
        if k == "status":
            out["response_status"] = v
        else:
            out[k] = v
    return out


def _arg_type_name(action: argparse.Action) -> str:
    if isinstance(action, argparse._StoreTrueAction):
        return "boolean"
    if action.type is int:
        return "integer"
    if action.type is float:
        return "number"
    return "string"


def _canonical_option_name(action: argparse.Action) -> str:
    for option in action.option_strings:
        if option.startswith("--") and "_" not in option:
            return option[2:]
    for option in action.option_strings:
        if option.startswith("--"):
            return option[2:].replace("_", "-")
    return action.dest.replace("_", "-")


def _describe_action(action: argparse.Action) -> Optional[Dict[str, Any]]:
    if action.dest == "help" or action.help == argparse.SUPPRESS:
        return None
    description = action.help or ""
    if action.choices:
        choices_text = ", ".join(str(c) for c in action.choices)
        description = f"{description} Choices: {choices_text}".strip()
    default_value = None if action.default is argparse.SUPPRESS else action.default
    return {
        "name": _canonical_option_name(action),
        "type": _arg_type_name(action),
        "description": description,
        "required": bool(getattr(action, "required", False)),
        "default": default_value,
    }


def _get_subparsers_action(parser: argparse.ArgumentParser) -> Optional[argparse._SubParsersAction]:
    for action in parser._actions:
        if isinstance(action, argparse._SubParsersAction):
            return action
    return None


def get_client(api_key: str) -> TasksClient:
    if not api_key:
        raise ValueError("API key is required")
    return TasksClient(api_key=api_key, base_url=BASE_URL)


def _wrap(fn: Callable[[], Dict[str, Any]]) -> Dict[str, Any]:
    try:
        return fn()
    except NotFoundError as e:
        return _error_response(str(e), "not_found")
    except ValidationError as e:
        return _error_response(str(e), "validation_error")
    except AuthenticationError as e:
        return _error_response(str(e), "authentication_error")
    except APIError as e:
        return _error_response(str(e), "api_error")
    except Exception as e:
        return _error_response(str(e), "unknown_error")


def _parse_json_inline(raw: str) -> Any:
    return json.loads(raw)


def create_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        tags = args.get("tags")
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",") if t.strip()]
        task = client.create_task(
            title=cast(str, args.get("title")),
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            body=args.get("body"),
            due_at=args.get("due-at"),
            priority=args.get("priority"),
            project=args.get("project"),
            tags=tags,
            rank=args.get("rank"),
            recurrence_rule=args.get("recurrence-rule"),
        )
        return _success(message=f"Task '{task['title']}' created successfully", task=task)

    return _wrap(run)


def update_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        tags = args.get("tags")
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",") if t.strip()]
        task = client.update_task(
            task_id=args.get("task-id"),
            title=args.get("title"),
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            body=args.get("body"),
            due_at=args.get("due-at"),
            priority=args.get("priority"),
            project=args.get("project"),
            tags=tags,
            rank=args.get("rank"),
            recurrence_rule=args.get("recurrence-rule"),
            unassign=bool(args.get("unassign", False)),
            clear_body=bool(args.get("clear-body", False)),
        )
        return _success(message=f"Task '{task['title']}' updated successfully", task=task)

    return _wrap(run)


def list_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        # Only pass filters the caller supplied (matches SDK param-building; keeps tests stable).
        call_kw: Dict[str, Any] = {"offset": int(args.get("offset", 0))}
        mapping = (
            ("status", "status"),
            ("assigned-to-user-id", "assigned_to_user_id"),
            ("created-by-user-id", "created_by_user_id"),
            ("priority", "priority"),
            ("project", "project"),
            ("q", "q"),
            ("due-before", "due_before"),
            ("due-after", "due_after"),
            ("watcher-user-id", "watcher_user_id"),
            ("sort-by", "sort_by"),
            ("sort-dir", "sort_dir"),
            ("limit", "limit"),
        )
        for cli_key, sdk_key in mapping:
            if cli_key in args:
                call_kw[sdk_key] = args[cli_key]
        result = client.list_tasks(**call_kw)
        return _success(
            count=result["count"],
            total=result.get("total", result["count"]),
            pagination=result.get("pagination"),
            tasks=result["tasks"],
        )

    return _wrap(run)


def get_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        inc = args.get("include-relations", True)
        if isinstance(inc, str):
            inc = inc.lower() not in ("0", "false", "no")
        task = client.get_task(task_id=args.get("task-id"), include_relations=bool(inc))
        return _success(task=task)

    return _wrap(run)


def delete_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        deleted = client.delete_task(task_id=args.get("task-id"))
        return _success(message="Task deleted successfully", deleted=deleted)

    return _wrap(run)


def health(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        return _ok_from_api(client.health())

    return _wrap(run)


def search_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        q = args.get("q") or ""
        kwargs: Dict[str, Any] = {}
        if args.get("status"):
            kwargs["status"] = args["status"]
        if args.get("priority"):
            kwargs["priority"] = args["priority"]
        if args.get("assigned-to-user-id") is not None:
            kwargs["assigned_to_user_id"] = args["assigned-to-user-id"]
        if args.get("sort-by"):
            kwargs["sort_by"] = args["sort-by"]
        if args.get("sort-dir"):
            kwargs["sort_dir"] = args["sort-dir"]
        if args.get("limit") is not None:
            kwargs["limit"] = args["limit"]
        if args.get("offset") is not None:
            kwargs["offset"] = args["offset"]
        result = client.search_tasks(q, **kwargs)
        return _success(
            tasks=result["tasks"],
            count=result.get("count", 0),
            total=result.get("total", 0),
            pagination=result.get("pagination"),
        )

    return _wrap(run)


def bulk_create_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        tasks = _parse_json_inline(cast(str, args["json"]))
        if not isinstance(tasks, list):
            raise ValidationError("json must be an array of task objects", 400, None)
        data = client.bulk_create_tasks(tasks)
        return _ok_from_api(data)

    return _wrap(run)


def bulk_update_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        updates = _parse_json_inline(cast(str, args["json"]))
        if not isinstance(updates, list):
            raise ValidationError("json must be an array of update objects", 400, None)
        data = client.bulk_update_tasks(updates)
        return _ok_from_api(data)

    return _wrap(run)


def list_statuses(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        statuses = client.list_statuses()
        return _success(statuses=statuses, count=len(statuses))

    return _wrap(run)


def create_status(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        st = client.create_status(
            slug=args.get("slug"),
            label=args.get("label"),
            sort_order=int(args.get("sort-order", 100)),
            is_done=bool(args.get("is-done", False)),
            is_default=bool(args.get("is-default", False)),
        )
        return _success(status=st)

    return _wrap(run)


def list_projects(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        projects = client.list_projects(limit=int(args.get("limit", 200)))
        return _success(projects=projects, count=len(projects))

    return _wrap(run)


def list_organizations(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        orgs = client.list_organizations()
        return _success(organizations=orgs, count=len(orgs))

    return _wrap(run)


def list_directory_projects(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        projects = client.list_directory_projects(limit=int(args.get("limit", 200)))
        return _success(projects=projects, count=len(projects))

    return _wrap(run)


def create_directory_project(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        body = client.create_directory_project(
            name=str(args["name"]),
            description=args.get("description"),
            client_visible=bool(args.get("client-visible", False)),
            all_access=bool(args.get("all-access", False)),
        )
        return _success(project=body)

    return _wrap(run)


def list_tags(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        tags = client.list_tags(limit=int(args.get("limit", 200)))
        return _success(tags=tags, count=len(tags))

    return _wrap(run)


def list_comments(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        comments = client.list_comments(
            task_id=int(args["task-id"]),
            limit=int(args.get("limit", 100)),
            offset=int(args.get("offset", 0)),
        )
        return _success(comments=comments, count=len(comments))

    return _wrap(run)


def create_comment(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        c = client.create_comment(task_id=int(args["task-id"]), comment=str(args["comment"]))
        return _success(comment=c)

    return _wrap(run)


def list_attachments(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        attachments = client.list_attachments(task_id=int(args["task-id"]))
        return _success(attachments=attachments, count=len(attachments))

    return _wrap(run)


def add_attachment(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        body = client.add_attachment(
            task_id=int(args["task-id"]),
            file_name=str(args["file-name"]),
            file_url=str(args["file-url"]),
            mime_type=args.get("mime-type"),
            size_bytes=args.get("size-bytes"),
        )
        return _ok_from_api(body)

    return _wrap(run)


def list_watchers(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        watchers = client.list_watchers(task_id=int(args["task-id"]))
        return _success(watchers=watchers, count=len(watchers))

    return _wrap(run)


def watch_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        uid = args.get("user-id")
        data = client.watch_task(task_id=int(args["task-id"]), user_id=uid)
        return _ok_from_api(data)

    return _wrap(run)


def unwatch_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        uid = args.get("user-id")
        data = client.unwatch_task(task_id=int(args["task-id"]), user_id=uid)
        return _ok_from_api(data)

    return _wrap(run)


def list_users(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        users = client.list_users(include_disabled=bool(args.get("include-disabled", False)))
        return _success(users=users, count=len(users))

    return _wrap(run)


def create_user(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        data = client.create_user(
            username=str(args["username"]),
            password=str(args["password"]),
            role=str(args.get("role", "member")),
            must_change_password=bool(args.get("must-change-password", True)),
            create_api_key=bool(args.get("create-api-key", False)),
            api_key_name=str(args.get("api-key-name", "default")),
        )
        return _ok_from_api(data)

    return _wrap(run)


def disable_user(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        data = client.disable_user(
            user_id=int(args["user-id"]),
            is_active=bool(args.get("activate", False)),
        )
        return _ok_from_api(data)

    return _wrap(run)


def reset_user_password(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        np = args.get("new-password")
        data = client.reset_user_password(
            user_id=int(args["user-id"]),
            new_password=np,
            must_change_password=bool(args.get("must-change-password", True)),
        )
        return _ok_from_api(data)

    return _wrap(run)


def list_api_keys(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        keys = client.list_api_keys(
            include_revoked=bool(args.get("include-revoked", False)),
            mine=bool(args.get("mine", False)),
        )
        return _success(api_keys=keys, count=len(keys))

    return _wrap(run)


def create_api_key(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        data = client.create_api_key(
            key_name=str(args["key-name"]),
            user_id=args.get("user-id"),
        )
        return _ok_from_api(data)

    return _wrap(run)


def revoke_api_key(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        data = client.revoke_api_key(key_id=int(args["key-id"]))
        return _ok_from_api(data)

    return _wrap(run)


def list_audit_logs(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    def run() -> Dict[str, Any]:
        client = get_client(api_key)
        logs = client.list_audit_logs(
            limit=int(args.get("limit", 100)),
            offset=int(args.get("offset", 0)),
        )
        return _success(logs=logs, count=len(logs))

    return _wrap(run)


def command_handlers() -> Dict[str, Callable[[Dict[str, Any], str], Dict[str, Any]]]:
    """Resolve handlers at call time so tests can monkeypatch module functions."""
    return {
        "health": health,
        "create-task": create_task,
        "update-task": update_task,
        "list-tasks": list_tasks,
        "get-task": get_task,
        "delete-task": delete_task,
        "search-tasks": search_tasks,
        "bulk-create-tasks": bulk_create_tasks,
        "bulk-update-tasks": bulk_update_tasks,
        "list-statuses": list_statuses,
        "create-status": create_status,
        "list-projects": list_projects,
        "list-organizations": list_organizations,
        "list-directory-projects": list_directory_projects,
        "create-directory-project": create_directory_project,
        "list-tags": list_tags,
        "list-comments": list_comments,
        "create-comment": create_comment,
        "list-attachments": list_attachments,
        "add-attachment": add_attachment,
        "list-watchers": list_watchers,
        "watch-task": watch_task,
        "unwatch-task": unwatch_task,
        "list-users": list_users,
        "create-user": create_user,
        "disable-user": disable_user,
        "reset-user-password": reset_user_password,
        "list-api-keys": list_api_keys,
        "create-api-key": create_api_key,
        "revoke-api-key": revoke_api_key,
        "list-audit-logs": list_audit_logs,
    }


def get_plugin_description(parser: argparse.ArgumentParser) -> Dict[str, Any]:
    commands: List[Dict[str, Any]] = []
    subparsers_action = _get_subparsers_action(parser)
    if subparsers_action:
        command_help = {a.dest: a.help for a in subparsers_action._get_subactions()}
        for command_name, command_parser in sorted(subparsers_action.choices.items()):
            parameters: List[Dict[str, Any]] = []
            for action in command_parser._actions:
                described = _describe_action(action)
                if described is not None:
                    parameters.append(described)
            commands.append(
                {
                    "name": command_name,
                    "description": command_help.get(command_name, ""),
                    "parameters": parameters,
                }
            )
    return {
        "plugin": {
            "name": "tasks",
            "version": PLUGIN_VERSION,
            "description": "Sanctum Tasks API — SMCP CLI mirrors tasks_sdk (API-key routes)",
        },
        "commands": commands,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Sanctum Tasks API — SMCP plugin (full parity with Python SDK / API-key HTTP API)",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--describe", action="store_true", help="JSON plugin + command schema for MCP")
    parser.add_argument("--debug", action="store_true", help="Include tracebacks in error JSON")
    subparsers = parser.add_subparsers(dest="command", help="Command")

    def add_api_key(sp: argparse.ArgumentParser) -> None:
        sp.add_argument("--api-key", "--api_key", dest="api_key", required=True, help="X-API-Key value")

    def priority_arg(sp: argparse.ArgumentParser) -> None:
        sp.add_argument("--priority", choices=["low", "normal", "high", "urgent"], help="Priority")

    # health
    p = subparsers.add_parser("health", help="GET /api/health.php")
    add_api_key(p)

    p = subparsers.add_parser("create-task", help="POST /api/create-task.php")
    add_api_key(p)
    p.add_argument("--title", required=True)
    p.add_argument("--status", default="todo")
    p.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id")
    p.add_argument("--body")
    p.add_argument("--due-at", dest="due_at")
    priority_arg(p)
    p.add_argument("--project")
    p.add_argument("--tags", help="Comma-separated tags")
    p.add_argument("--rank", type=int)
    p.add_argument("--recurrence-rule", dest="recurrence_rule")

    p = subparsers.add_parser("update-task", help="POST /api/update-task.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--title")
    p.add_argument("--status")
    p.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id")
    p.add_argument("--body")
    p.add_argument("--clear-body", action="store_true", dest="clear_body")
    p.add_argument("--unassign", action="store_true")
    p.add_argument("--due-at", dest="due_at")
    priority_arg(p)
    p.add_argument("--project")
    p.add_argument("--tags")
    p.add_argument("--rank", type=int)
    p.add_argument("--recurrence-rule", dest="recurrence_rule")

    p = subparsers.add_parser("list-tasks", help="GET /api/list-tasks.php")
    add_api_key(p)
    p.add_argument("--status")
    p.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id")
    p.add_argument("--created-by-user-id", type=int, dest="created_by_user_id")
    priority_arg(p)
    p.add_argument("--project")
    p.add_argument("--q")
    p.add_argument("--due-before", dest="due_before")
    p.add_argument("--due-after", dest="due_after")
    p.add_argument("--watcher-user-id", type=int, dest="watcher_user_id")
    p.add_argument("--sort-by", dest="sort_by")
    p.add_argument("--sort-dir", dest="sort_dir", choices=["ASC", "DESC", "asc", "desc"])
    p.add_argument("--limit", type=int)
    p.add_argument("--offset", type=int, default=0)

    p = subparsers.add_parser("get-task", help="GET /api/get-task.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument(
        "--no-include-relations",
        action="store_false",
        dest="include_relations",
        help="Omit related entities (comments, etc.)",
    )
    p.set_defaults(include_relations=True)

    p = subparsers.add_parser("delete-task", help="POST /api/delete-task.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")

    p = subparsers.add_parser("search-tasks", help="GET /api/search-tasks.php")
    add_api_key(p)
    p.add_argument("--q", required=True)
    p.add_argument("--status")
    priority_arg(p)
    p.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id")
    p.add_argument("--sort-by", dest="sort_by", default="updated_at")
    p.add_argument("--sort-dir", dest="sort_dir", default="DESC")
    p.add_argument("--limit", type=int, default=50)
    p.add_argument("--offset", type=int, default=0)

    p = subparsers.add_parser("bulk-create-tasks", help="POST /api/bulk-create-tasks.php")
    add_api_key(p)
    p.add_argument("--json", required=True, help='JSON array of tasks, e.g. [{"title":"a"}]')

    p = subparsers.add_parser("bulk-update-tasks", help="POST /api/bulk-update-tasks.php")
    add_api_key(p)
    p.add_argument("--json", required=True, help="JSON array of update objects")

    p = subparsers.add_parser("list-statuses", help="GET /api/list-statuses.php")
    add_api_key(p)

    p = subparsers.add_parser("create-status", help="POST /api/create-status.php (admin)")
    add_api_key(p)
    p.add_argument("--slug", required=True)
    p.add_argument("--label", required=True)
    p.add_argument("--sort-order", type=int, default=100, dest="sort_order")
    p.add_argument("--is-done", action="store_true", dest="is_done")
    p.add_argument("--is-default", action="store_true", dest="is_default")

    p = subparsers.add_parser("list-projects", help="GET /api/list-projects.php")
    add_api_key(p)
    p.add_argument("--limit", type=int, default=200)

    p = subparsers.add_parser("list-organizations", help="GET /api/list-organizations.php")
    add_api_key(p)

    p = subparsers.add_parser("list-directory-projects", help="GET /api/list-directory-projects.php (project entities)")
    add_api_key(p)
    p.add_argument("--limit", type=int, default=200)

    p = subparsers.add_parser("create-directory-project", help="POST /api/create-directory-project.php")
    add_api_key(p)
    p.add_argument("--name", required=True, help="Project name")
    p.add_argument("--description", dest="description", default=None)
    p.add_argument("--client-visible", action="store_true", dest="client_visible")
    p.add_argument("--all-access", action="store_true", dest="all_access")

    p = subparsers.add_parser("list-tags", help="GET /api/list-tags.php")
    add_api_key(p)
    p.add_argument("--limit", type=int, default=200)

    p = subparsers.add_parser("list-comments", help="GET /api/list-comments.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--limit", type=int, default=100)
    p.add_argument("--offset", type=int, default=0)

    p = subparsers.add_parser("create-comment", help="POST /api/create-comment.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--comment", required=True)

    p = subparsers.add_parser("list-attachments", help="GET /api/list-attachments.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")

    p = subparsers.add_parser("add-attachment", help="POST /api/add-attachment.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--file-name", required=True, dest="file_name")
    p.add_argument("--file-url", required=True, dest="file_url")
    p.add_argument("--mime-type", dest="mime_type")
    p.add_argument("--size-bytes", type=int, dest="size_bytes")

    p = subparsers.add_parser("list-watchers", help="GET /api/list-watchers.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")

    p = subparsers.add_parser("watch-task", help="POST /api/watch-task.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--user-id", type=int, dest="user_id")

    p = subparsers.add_parser("unwatch-task", help="POST /api/unwatch-task.php")
    add_api_key(p)
    p.add_argument("--task-id", type=int, required=True, dest="task_id")
    p.add_argument("--user-id", type=int, dest="user_id")

    p = subparsers.add_parser("list-users", help="GET /api/list-users.php")
    add_api_key(p)
    p.add_argument("--include-disabled", action="store_true", dest="include_disabled")

    p = subparsers.add_parser("create-user", help="POST /api/create-user.php (admin)")
    add_api_key(p)
    p.set_defaults(must_change_password=True)
    p.add_argument("--username", required=True)
    p.add_argument("--password", required=True)
    p.add_argument("--role", default="member")
    p.add_argument(
        "--no-must-change-password",
        action="store_false",
        dest="must_change_password",
        help="Allow user to keep password without forced change",
    )
    p.add_argument("--create-api-key", action="store_true", dest="create_api_key")
    p.add_argument("--api-key-name", default="default", dest="api_key_name")

    p = subparsers.add_parser("disable-user", help="POST /api/disable-user.php (admin)")
    add_api_key(p)
    p.add_argument("--user-id", type=int, required=True, dest="user_id")
    p.add_argument("--activate", action="store_true", help="Set is_active true (re-enable)")

    p = subparsers.add_parser("reset-user-password", help="POST /api/reset-user-password.php (admin)")
    add_api_key(p)
    p.set_defaults(must_change_password=True)
    p.add_argument("--user-id", type=int, required=True, dest="user_id")
    p.add_argument("--new-password", dest="new_password")
    p.add_argument(
        "--no-must-change-password",
        action="store_false",
        dest="must_change_password",
    )

    p = subparsers.add_parser("list-api-keys", help="GET /api/list-api-keys.php")
    add_api_key(p)
    p.add_argument("--include-revoked", action="store_true", dest="include_revoked")
    p.add_argument("--mine", action="store_true")

    p = subparsers.add_parser("create-api-key", help="POST /api/create-api-key.php (admin)")
    add_api_key(p)
    p.add_argument("--key-name", required=True, dest="key_name")
    p.add_argument("--user-id", type=int, dest="user_id")

    p = subparsers.add_parser("revoke-api-key", help="POST /api/revoke-api-key.php (admin)")
    add_api_key(p)
    p.add_argument("--key-id", type=int, required=True, dest="key_id")

    p = subparsers.add_parser("list-audit-logs", help="GET /api/list-audit-logs.php (admin)")
    add_api_key(p)
    p.add_argument("--limit", type=int, default=100)
    p.add_argument("--offset", type=int, default=0)

    return parser


def main() -> None:
    global DEBUG_TRACEBACKS
    parser = build_parser()
    try:
        args = parser.parse_args()
    except SystemExit as e:
        if e.code == 0:
            raise
        err = _error_response("Invalid arguments. Check command syntax.", "argument_error", include_traceback=False)
        print(json.dumps(err, indent=2), file=sys.stderr)
        print(json.dumps(err, indent=2))
        sys.exit(e.code if isinstance(e.code, int) else 2)
    except Exception as e:
        err = _error_response(f"Failed to parse arguments: {str(e)}", "argument_error")
        print(json.dumps(err, indent=2), file=sys.stderr)
        print(json.dumps(err, indent=2))
        sys.exit(2)

    DEBUG_TRACEBACKS = bool(getattr(args, "debug", False))

    if args.describe:
        print(json.dumps(get_plugin_description(parser), indent=2))
        sys.exit(0)

    if not args.command:
        parser.print_help()
        sys.exit(1)

    api_key = getattr(args, "api_key", None)
    if not api_key:
        err = _error_response("API key is required (--api-key).", "validation_error", include_traceback=False)
        print(json.dumps(err, indent=2), file=sys.stderr)
        print(json.dumps(err, indent=2))
        sys.exit(1)

    args_dict: Dict[str, Any] = {}
    for key, value in vars(args).items():
        if key in ("command", "describe", "api_key", "debug"):
            continue
        if value is None:
            continue
        args_dict[key.replace("_", "-")] = value

    handler = command_handlers().get(args.command)
    if not handler:
        err = _error_response(f"Unknown command: {args.command}", "argument_error", include_traceback=False)
        print(json.dumps(err, indent=2))
        sys.exit(1)

    try:
        result = handler(args_dict, api_key)
        print(json.dumps(result, indent=2))
        sys.exit(0 if result.get("status") == "success" else 1)
    except Exception as e:
        err = _error_response(str(e), "unknown_error")
        print(json.dumps(err, indent=2), file=sys.stderr)
        print(json.dumps(err, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
