#!/usr/bin/env python3
"""
Tasks SMCP Plugin

Provides MCP tools for interacting with tasks.technonomicon.net API.
Allows AI agents to create, update, list, and manage tasks.

Copyright (c) 2025
"""

import argparse
import json
import sys
import traceback
from pathlib import Path
from typing import Dict, Any, Optional, List

# Add parent directory to path to import SDK
sys.path.insert(0, str(Path(__file__).parent.parent.parent))
try:
    from tasks_sdk import TasksClient, APIError, NotFoundError, ValidationError
except ImportError:  # pragma: no cover - used in standalone plugin layouts
    # Fallback if SDK not in path
    sys.path.insert(0, str(Path(__file__).parent.parent.parent / "tasks_sdk"))
    from client import TasksClient
    from exceptions import APIError, NotFoundError, ValidationError


# Hard-coded base URL for the live site
BASE_URL = "https://tasks.technonomicon.net"
try:
    from smcp_plugin.tasks import __version__ as PLUGIN_VERSION
except ImportError:  # pragma: no cover - fallback when executed outside package context
    PLUGIN_VERSION = "0.2.1"
DEBUG_TRACEBACKS = False


def _error_response(error: str, error_type: str, include_traceback: bool = True) -> Dict[str, Any]:
    """Create a structured error payload with optional traceback details."""
    payload: Dict[str, Any] = {
        "status": "error",
        "error": error,
        "error_type": error_type,
    }
    if include_traceback and DEBUG_TRACEBACKS:
        payload["traceback"] = traceback.format_exc()
    return payload


def _arg_type_name(action: argparse.Action) -> str:
    """Convert argparse action type metadata to schema type names."""
    if isinstance(action, argparse._StoreTrueAction):
        return "boolean"
    if action.type is int:
        return "integer"
    if action.type is float:
        return "number"
    return "string"


def _canonical_option_name(action: argparse.Action) -> str:
    """Pick a stable parameter name from argparse option strings."""
    for option in action.option_strings:
        if option.startswith("--") and "_" not in option:
            return option[2:]
    for option in action.option_strings:
        if option.startswith("--"):
            return option[2:].replace("_", "-")
    return action.dest.replace("_", "-")


def _describe_action(action: argparse.Action) -> Optional[Dict[str, Any]]:
    """Return command parameter metadata for an argparse action."""
    if action.dest == "help" or action.help == argparse.SUPPRESS:
        return None

    description = action.help or ""
    if action.choices:
        choices_text = ", ".join(str(choice) for choice in action.choices)
        description = f"{description} Choices: {choices_text}".strip()

    if action.default is argparse.SUPPRESS:
        default_value = None
    else:
        default_value = action.default

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
    """Get configured Tasks client."""
    if not api_key:
        raise ValueError("API key is required")
    
    return TasksClient(api_key=api_key, base_url=BASE_URL)


def create_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Create a new task."""
    try:
        client = get_client(api_key)
        tags = args.get("tags")
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",") if t.strip()]
        
        task = client.create_task(
            title=args.get("title"),
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            body=args.get("body"),
            due_at=args.get("due-at"),
            priority=args.get("priority"),
            project=args.get("project"),
            tags=tags,
            rank=args.get("rank"),
            recurrence_rule=args.get("recurrence-rule")
        )
        
        return {
            "status": "success",
            "message": f"Task '{task['title']}' created successfully",
            "task": task
        }
    except ValidationError as e:
        return _error_response(f"Validation error: {str(e)}", "validation_error")
    except APIError as e:
        return _error_response(f"API error: {str(e)}", "api_error")
    except Exception as e:
        return _error_response(f"Unexpected error: {str(e)}", "unknown_error")


def update_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Update an existing task."""
    try:
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
        
        return {
            "status": "success",
            "message": f"Task '{task['title']}' updated successfully",
            "task": task
        }
    except NotFoundError as e:
        return _error_response(f"Task not found: {str(e)}", "not_found")
    except ValidationError as e:
        return _error_response(f"Validation error: {str(e)}", "validation_error")
    except APIError as e:
        return _error_response(f"API error: {str(e)}", "api_error")
    except Exception as e:
        return _error_response(f"Unexpected error: {str(e)}", "unknown_error")


def list_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """List tasks."""
    try:
        client = get_client(api_key)
        
        result = client.list_tasks(
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            priority=args.get("priority"),
            project=args.get("project"),
            q=args.get("q"),
            sort_by=args.get("sort-by"),
            sort_dir=args.get("sort-dir"),
            limit=args.get("limit"),
            offset=args.get("offset", 0)
        )
        
        return {
            "status": "success",
            "count": result["count"],
            "total": result.get("total", result["count"]),
            "pagination": result.get("pagination"),
            "tasks": result["tasks"]
        }
    except APIError as e:
        return _error_response(f"API error: {str(e)}", "api_error")
    except Exception as e:
        return _error_response(f"Unexpected error: {str(e)}", "unknown_error")


def get_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Get a single task by ID."""
    try:
        client = get_client(api_key)
        
        task = client.get_task(task_id=args.get("task-id"))
        
        return {
            "status": "success",
            "task": task
        }
    except NotFoundError as e:
        return _error_response(f"Task not found: {str(e)}", "not_found")
    except ValidationError as e:
        return _error_response(f"Validation error: {str(e)}", "validation_error")
    except APIError as e:
        return _error_response(f"API error: {str(e)}", "api_error")
    except Exception as e:
        return _error_response(f"Unexpected error: {str(e)}", "unknown_error")


def delete_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Delete a task by ID."""
    try:
        client = get_client(api_key)
        
        result = client.delete_task(task_id=args.get("task-id"))
        
        return {
            "status": "success",
            "message": "Task deleted successfully",
            "deleted": result
        }
    except NotFoundError as e:
        return _error_response(f"Task not found: {str(e)}", "not_found")
    except ValidationError as e:
        return _error_response(f"Validation error: {str(e)}", "validation_error")
    except APIError as e:
        return _error_response(f"API error: {str(e)}", "api_error")
    except Exception as e:
        return _error_response(f"Unexpected error: {str(e)}", "unknown_error")


def get_plugin_description(parser: argparse.ArgumentParser) -> Dict[str, Any]:
    """Return structured plugin description for --describe."""
    commands: List[Dict[str, Any]] = []
    subparsers_action = _get_subparsers_action(parser)

    if subparsers_action:
        command_help = {
            action.dest: action.help
            for action in subparsers_action._get_subactions()
        }
        for command_name, command_parser in subparsers_action.choices.items():
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
            "description": "Tasks Management API integration for Animus Letta MCP",
        },
        "commands": commands,
    }


def build_parser() -> argparse.ArgumentParser:
    """Create and configure the CLI argument parser."""
    parser = argparse.ArgumentParser(
        description="Tasks Management API integration for Animus Letta MCP",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Available commands:
  create-task    Create a new task
  update-task    Update an existing task
  list-tasks     List tasks with optional filtering
  get-task       Get a single task by ID
  delete-task    Delete a task by ID

Configuration:
  --api-key         API key from the admin panel (required for all commands)
  Base URL is hard-coded to: https://tasks.technonomicon.net

Examples:
  python cli.py create-task --api-key "YOUR_KEY" --title "Fix deployment bug" --body "Check logs" --status "todo" --assigned-to-user-id 1
  python cli.py update-task --api-key "YOUR_KEY" --task-id 123 --status "doing" --body "Updated description"
  python cli.py list-tasks --api-key "YOUR_KEY" --status "todo" --limit 10
  python cli.py get-task --api-key "YOUR_KEY" --task-id 123
  python cli.py delete-task --api-key "YOUR_KEY" --task-id 123
        """
    )

    parser.add_argument(
        "--describe",
        action="store_true",
        help="Output plugin description in JSON format",
    )
    parser.add_argument(
        "--debug",
        action="store_true",
        help="Include traceback details in structured error responses",
    )

    subparsers = parser.add_subparsers(dest="command", help="Available commands")

    def add_api_key_arg(subparser: argparse.ArgumentParser) -> None:
        subparser.add_argument(
            "--api-key",
            "--api_key",
            dest="api_key",
            required=True,
            help="API key for authentication (required)",
        )

    create_parser = subparsers.add_parser("create-task", help="Create a new task")
    add_api_key_arg(create_parser)
    create_parser.add_argument("--title", required=True, help="Task title")
    create_parser.add_argument("--status", default="todo", help="Task status slug")
    create_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="User ID to assign task to")
    create_parser.add_argument("--body", help="Task description/details")
    create_parser.add_argument("--due-at", dest="due_at", help="Due datetime (e.g. 2026-02-17T12:00:00Z)")
    create_parser.add_argument("--priority", choices=["low", "normal", "high", "urgent"], help="Task priority")
    create_parser.add_argument("--project", help="Project name")
    create_parser.add_argument("--tags", help="Comma-separated tags")
    create_parser.add_argument("--rank", type=int, help="Ordering rank")
    create_parser.add_argument("--recurrence-rule", dest="recurrence_rule", help="Recurrence rule (RRULE-like)")

    update_parser = subparsers.add_parser("update-task", help="Update an existing task")
    add_api_key_arg(update_parser)
    update_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")
    update_parser.add_argument("--title", help="New title")
    update_parser.add_argument("--status", help="New status slug")
    update_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="New assigned user ID")
    update_parser.add_argument("--body", help="New body/description (set to empty string to clear)")
    update_parser.add_argument("--clear-body", action="store_true", dest="clear_body", help="Clear body field")
    update_parser.add_argument("--unassign", action="store_true", help="Unassign task")
    update_parser.add_argument("--due-at", dest="due_at", help="Due datetime")
    update_parser.add_argument("--priority", choices=["low", "normal", "high", "urgent"], help="Task priority")
    update_parser.add_argument("--project", help="Project name")
    update_parser.add_argument("--tags", help="Comma-separated tags")
    update_parser.add_argument("--rank", type=int, help="Ordering rank")
    update_parser.add_argument("--recurrence-rule", dest="recurrence_rule", help="Recurrence rule (RRULE-like)")

    list_parser = subparsers.add_parser("list-tasks", help="List tasks")
    add_api_key_arg(list_parser)
    list_parser.add_argument("--status", help="Filter by status slug")
    list_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="Filter by assigned user ID")
    list_parser.add_argument("--priority", choices=["low", "normal", "high", "urgent"], help="Filter by priority")
    list_parser.add_argument("--project", help="Filter by project")
    list_parser.add_argument("--q", help="Search title/body")
    list_parser.add_argument("--sort-by", dest="sort_by", help="Sort field")
    list_parser.add_argument("--sort-dir", dest="sort_dir", choices=["ASC", "DESC", "asc", "desc"], help="Sort direction")
    list_parser.add_argument("--limit", type=int, help="Maximum number of tasks (max: 500, default: 100)")
    list_parser.add_argument("--offset", type=int, default=0, help="Number of tasks to skip")

    get_parser = subparsers.add_parser("get-task", help="Get a single task by ID")
    add_api_key_arg(get_parser)
    get_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")

    delete_parser = subparsers.add_parser("delete-task", help="Delete a task by ID")
    add_api_key_arg(delete_parser)
    delete_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")

    return parser


def main():
    """Main entry point for the plugin CLI."""
    global DEBUG_TRACEBACKS
    parser = build_parser()

    try:
        args = parser.parse_args()
    except SystemExit as e:
        # Help exits are normal (exit code 0) and should pass through untouched.
        if e.code == 0:
            raise
        error_msg = _error_response(
            "Invalid arguments. Check command syntax.",
            "argument_error",
            include_traceback=False,
        )
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(e.code if isinstance(e.code, int) else 2)
    except Exception as e:
        error_msg = _error_response(
            f"Failed to parse arguments: {str(e)}",
            "argument_error",
        )
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(2)

    DEBUG_TRACEBACKS = bool(getattr(args, "debug", False))

    # Handle --describe flag
    if args.describe:
        description = get_plugin_description(parser)
        print(json.dumps(description, indent=2))
        sys.exit(0)

    if not args.command:
        parser.print_help()
        sys.exit(1)

    # Extract API key (should be set by argparse now)
    api_key = getattr(args, 'api_key', None)
    if not api_key:
        error_msg = _error_response(
            "API key is required. Use --api-key to provide your API key.",
            "validation_error",
            include_traceback=False,
        )
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(1)

    try:
        # Convert args to dict, handling Namespace properly
        args_dict = {}
        for key, value in vars(args).items():
            if key not in ["command", "describe", "api_key", "debug"] and value is not None:
                # Convert underscores to hyphens for parameter names
                param_key = key.replace("_", "-")
                args_dict[param_key] = value

        # Execute command
        if args.command == "create-task":
            result = create_task(args_dict, api_key)
        elif args.command == "update-task":
            result = update_task(args_dict, api_key)
        elif args.command == "list-tasks":
            result = list_tasks(args_dict, api_key)
        elif args.command == "get-task":
            result = get_task(args_dict, api_key)
        elif args.command == "delete-task":
            result = delete_task(args_dict, api_key)
        else:
            result = _error_response(
                f"Unknown command: {args.command}",
                "argument_error",
                include_traceback=False,
            )

        print(json.dumps(result, indent=2))
        sys.exit(0 if result.get("status") == "success" else 1)

    except Exception as e:
        error_msg = _error_response(str(e), "unknown_error")
        # Print to stderr for debugging
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        # Also print to stdout for SMCP
        print(json.dumps(error_msg, indent=2))
        sys.exit(1)


if __name__ == "__main__":  # pragma: no cover
    main()
