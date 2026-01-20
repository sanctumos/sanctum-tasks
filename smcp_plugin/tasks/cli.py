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
from typing import Dict, Any, Optional

# Add parent directory to path to import SDK
sys.path.insert(0, str(Path(__file__).parent.parent.parent))
try:
    from tasks_sdk import TasksClient, APIError, NotFoundError, ValidationError
except ImportError:
    # Fallback if SDK not in path
    sys.path.insert(0, str(Path(__file__).parent.parent.parent / "tasks_sdk"))
    from client import TasksClient
    from exceptions import APIError, NotFoundError, ValidationError


# Hard-coded base URL for the live site
BASE_URL = "https://tasks.technonomicon.net"

def get_client(api_key: str) -> TasksClient:
    """Get configured Tasks client."""
    if not api_key:
        raise ValueError("API key is required")
    
    return TasksClient(api_key=api_key, base_url=BASE_URL)


def create_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Create a new task."""
    try:
        client = get_client(api_key)
        
        task = client.create_task(
            title=args.get("title"),
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            body=args.get("body")
        )
        
        return {
            "status": "success",
            "message": f"Task '{task['title']}' created successfully",
            "task": task
        }
    except ValidationError as e:
        return {
            "status": "error",
            "error": f"Validation error: {str(e)}",
            "error_type": "validation_error"
        }
    except APIError as e:
        return {
            "status": "error",
            "error": f"API error: {str(e)}",
            "error_type": "api_error"
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Unexpected error: {str(e)}",
            "error_type": "unknown_error"
        }


def update_task(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """Update an existing task."""
    try:
        client = get_client(api_key)
        
        task = client.update_task(
            task_id=args.get("task-id"),
            title=args.get("title"),
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            body=args.get("body")
        )
        
        return {
            "status": "success",
            "message": f"Task '{task['title']}' updated successfully",
            "task": task
        }
    except NotFoundError as e:
        return {
            "status": "error",
            "error": f"Task not found: {str(e)}",
            "error_type": "not_found"
        }
    except ValidationError as e:
        return {
            "status": "error",
            "error": f"Validation error: {str(e)}",
            "error_type": "validation_error"
        }
    except APIError as e:
        return {
            "status": "error",
            "error": f"API error: {str(e)}",
            "error_type": "api_error"
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Unexpected error: {str(e)}",
            "error_type": "unknown_error"
        }


def list_tasks(args: Dict[str, Any], api_key: str) -> Dict[str, Any]:
    """List tasks."""
    try:
        client = get_client(api_key)
        
        result = client.list_tasks(
            status=args.get("status"),
            assigned_to_user_id=args.get("assigned-to-user-id"),
            limit=args.get("limit"),
            offset=args.get("offset", 0)
        )
        
        return {
            "status": "success",
            "count": result["count"],
            "tasks": result["tasks"]
        }
    except APIError as e:
        return {
            "status": "error",
            "error": f"API error: {str(e)}",
            "error_type": "api_error",
            "traceback": traceback.format_exc()
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Unexpected error: {str(e)}",
            "error_type": "unknown_error",
            "traceback": traceback.format_exc()
        }


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
        return {
            "status": "error",
            "error": f"Task not found: {str(e)}",
            "error_type": "not_found",
            "traceback": traceback.format_exc()
        }
    except ValidationError as e:
        return {
            "status": "error",
            "error": f"Validation error: {str(e)}",
            "error_type": "validation_error",
            "traceback": traceback.format_exc()
        }
    except APIError as e:
        return {
            "status": "error",
            "error": f"API error: {str(e)}",
            "error_type": "api_error",
            "traceback": traceback.format_exc()
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Unexpected error: {str(e)}",
            "error_type": "unknown_error",
            "traceback": traceback.format_exc()
        }


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
        return {
            "status": "error",
            "error": f"Task not found: {str(e)}",
            "error_type": "not_found",
            "traceback": traceback.format_exc()
        }
    except ValidationError as e:
        return {
            "status": "error",
            "error": f"Validation error: {str(e)}",
            "error_type": "validation_error",
            "traceback": traceback.format_exc()
        }
    except APIError as e:
        return {
            "status": "error",
            "error": f"API error: {str(e)}",
            "error_type": "api_error",
            "traceback": traceback.format_exc()
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Unexpected error: {str(e)}",
            "error_type": "unknown_error",
            "traceback": traceback.format_exc()
        }


def get_plugin_description() -> Dict[str, Any]:
    """Return structured plugin description for --describe."""
    return {
        "plugin": {
            "name": "tasks",
            "version": "0.1.0",
            "description": "Tasks Management API integration for Animus Letta MCP"
        },
        "commands": [
            {
                "name": "create-task",
                "description": "Create a new task",
                "parameters": [
                    {
                        "name": "api-key",
                        "type": "string",
                        "description": "API key for authentication (required)",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "title",
                        "type": "string",
                        "description": "Task title",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "status",
                        "type": "string",
                        "description": "Task status: 'todo', 'doing', or 'done' (default: 'todo')",
                        "required": False,
                        "default": "todo"
                    },
                    {
                        "name": "assigned-to-user-id",
                        "type": "integer",
                        "description": "User ID to assign task to",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "body",
                        "type": "string",
                        "description": "Task description/details",
                        "required": False,
                        "default": None
                    }
                ]
            },
            {
                "name": "update-task",
                "description": "Update an existing task",
                "parameters": [
                    {
                        "name": "api-key",
                        "type": "string",
                        "description": "API key for authentication (required)",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "task-id",
                        "type": "integer",
                        "description": "Task ID",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "title",
                        "type": "string",
                        "description": "New title",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "status",
                        "type": "string",
                        "description": "New status: 'todo', 'doing', or 'done'",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "assigned-to-user-id",
                        "type": "integer",
                        "description": "New assigned user ID (set to null to unassign)",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "body",
                        "type": "string",
                        "description": "New body/description (set to null or empty string to clear)",
                        "required": False,
                        "default": None
                    }
                ]
            },
            {
                "name": "list-tasks",
                "description": "List tasks with optional filtering and pagination",
                "parameters": [
                    {
                        "name": "api-key",
                        "type": "string",
                        "description": "API key for authentication (required)",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "status",
                        "type": "string",
                        "description": "Filter by status: 'todo', 'doing', or 'done'",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "assigned-to-user-id",
                        "type": "integer",
                        "description": "Filter by assigned user ID",
                        "required": False,
                        "default": None
                    },
                    {
                        "name": "limit",
                        "type": "integer",
                        "description": "Maximum number of tasks to return (max: 500, default: 100)",
                        "required": False,
                        "default": 100
                    },
                    {
                        "name": "offset",
                        "type": "integer",
                        "description": "Number of tasks to skip",
                        "required": False,
                        "default": 0
                    }
                ]
            },
            {
                "name": "get-task",
                "description": "Get a single task by ID",
                "parameters": [
                    {
                        "name": "api-key",
                        "type": "string",
                        "description": "API key for authentication (required)",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "task-id",
                        "type": "integer",
                        "description": "Task ID",
                        "required": True,
                        "default": None
                    }
                ]
            },
            {
                "name": "delete-task",
                "description": "Delete a task by ID",
                "parameters": [
                    {
                        "name": "api-key",
                        "type": "string",
                        "description": "API key for authentication (required)",
                        "required": True,
                        "default": None
                    },
                    {
                        "name": "task-id",
                        "type": "integer",
                        "description": "Task ID",
                        "required": True,
                        "default": None
                    }
                ]
            }
        ]
    }


def main():
    """Main entry point for the plugin CLI."""
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
    
    # Add --describe flag
    parser.add_argument("--describe", action="store_true",
                       help="Output plugin description in JSON format")
    
    subparsers = parser.add_subparsers(dest="command", help="Available commands")
    
    # Helper function to add API key argument to subparsers
    def add_api_key_arg(subparser):
        subparser.add_argument("--api-key", "--api_key", dest="api_key", required=True,
                             help="API key for authentication (required)")
    
    # Create task command
    create_parser = subparsers.add_parser("create-task", help="Create a new task")
    add_api_key_arg(create_parser)
    create_parser.add_argument("--title", required=True, help="Task title")
    create_parser.add_argument("--status", choices=["todo", "doing", "done"], default="todo", help="Task status")
    create_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="User ID to assign task to")
    create_parser.add_argument("--body", help="Task description/details")
    
    # Update task command
    update_parser = subparsers.add_parser("update-task", help="Update an existing task")
    add_api_key_arg(update_parser)
    update_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")
    update_parser.add_argument("--title", help="New title")
    update_parser.add_argument("--status", choices=["todo", "doing", "done"], help="New status")
    update_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="New assigned user ID")
    update_parser.add_argument("--body", help="New body/description (set to empty string to clear)")
    
    # List tasks command
    list_parser = subparsers.add_parser("list-tasks", help="List tasks")
    add_api_key_arg(list_parser)
    list_parser.add_argument("--status", choices=["todo", "doing", "done"], help="Filter by status")
    list_parser.add_argument("--assigned-to-user-id", type=int, dest="assigned_to_user_id", help="Filter by assigned user ID")
    list_parser.add_argument("--limit", type=int, help="Maximum number of tasks (max: 500, default: 100)")
    list_parser.add_argument("--offset", type=int, default=0, help="Number of tasks to skip")
    
    # Get task command
    get_parser = subparsers.add_parser("get-task", help="Get a single task by ID")
    add_api_key_arg(get_parser)
    get_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")
    
    # Delete task command
    delete_parser = subparsers.add_parser("delete-task", help="Delete a task by ID")
    add_api_key_arg(delete_parser)
    delete_parser.add_argument("--task-id", type=int, required=True, dest="task_id", help="Task ID")
    
    try:
        args = parser.parse_args()
    except SystemExit as e:
        # argparse already printed help/error, capture and return as JSON
        error_msg = {
            "status": "error",
            "error": "Invalid arguments. Check command syntax.",
            "error_type": "argument_error"
        }
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(2)
    except Exception as e:
        error_msg = {
            "status": "error",
            "error": f"Failed to parse arguments: {str(e)}",
            "error_type": "argument_error",
            "traceback": traceback.format_exc()
        }
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(2)
    
    # Handle --describe flag
    if args.describe:
        description = get_plugin_description()
        print(json.dumps(description, indent=2))
        sys.exit(0)
    
    if not args.command:
        parser.print_help()
        sys.exit(1)
    
    # Extract API key (should be set by argparse now)
    api_key = getattr(args, 'api_key', None)
    if not api_key:
        error_msg = {
            "status": "error",
            "error": "API key is required. Use --api-key to provide your API key.",
            "error_type": "validation_error"
        }
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        print(json.dumps(error_msg, indent=2))
        sys.exit(1)
    
    try:
        # Convert args to dict, handling Namespace properly
        args_dict = {}
        for key, value in vars(args).items():
            if key not in ["command", "describe", "api_key"] and value is not None:
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
            result = {"status": "error", "error": f"Unknown command: {args.command}"}
        
        print(json.dumps(result, indent=2))
        sys.exit(0 if result.get("status") == "success" else 1)
        
    except Exception as e:
        error_msg = {
            "status": "error",
            "error": str(e),
            "error_type": "unknown_error",
            "traceback": traceback.format_exc()
        }
        # Print to stderr for debugging
        print(json.dumps(error_msg, indent=2), file=sys.stderr)
        # Also print to stdout for SMCP
        print(json.dumps(error_msg, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
