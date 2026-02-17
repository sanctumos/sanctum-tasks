"""
Human-facing CLI to list and view tasks via the Tasks API.
Auth via TASKS_API_KEY; calls local Python or PHP API (TASKS_API_BASE_URL).
"""
import os
import sys
import argparse
import requests

try:
    from rich.console import Console
    from rich.table import Table
    from rich.panel import Panel
    from rich.text import Text
    RICH_AVAILABLE = True
except ImportError:
    RICH_AVAILABLE = False


def get_api_key() -> str | None:
    key = os.environ.get("TASKS_API_KEY", "").strip()
    return key or None


def get_base_url() -> str:
    return (os.environ.get("TASKS_API_BASE_URL") or "http://127.0.0.1:8000").rstrip("/")


def api_request(
    method: str,
    path: str,
    api_key: str,
    params: dict | None = None,
    json_body: dict | None = None,
) -> dict:
    base = get_base_url()
    url = f"{base}/api/{path}"
    headers = {"X-API-Key": api_key, "Content-Type": "application/json"}
    if method.upper() == "GET":
        r = requests.get(url, headers=headers, params=params or {}, timeout=30)
    else:
        r = requests.request(method, url, headers=headers, params=params, json=json_body, timeout=30)
    data = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
    if not data.get("success", False):
        err = data.get("error") or data.get("error_object", {}).get("message") or r.text or "Request failed"
        raise SystemExit(f"API error: {err}")
    return data.get("data") or data


def cmd_list(
    api_key: str,
    status: str | None = None,
    project: str | None = None,
    q: str | None = None,
    limit: int = 50,
    sort_by: str = "updated_at",
    sort_dir: str = "DESC",
) -> None:
    params = {"limit": limit, "sort_by": sort_by, "sort_dir": sort_dir}
    if status:
        params["status"] = status
    if project:
        params["project"] = project
    if q:
        params["q"] = q
    data = api_request("GET", "list-tasks.php", api_key, params=params)
    tasks = data.get("tasks") or []
    if not tasks:
        print("No tasks found.")
        return
    if RICH_AVAILABLE:
        console = Console()
        table = Table(title="Tasks")
        table.add_column("ID", style="dim")
        table.add_column("Title")
        table.add_column("Status")
        table.add_column("Priority")
        table.add_column("Assignee")
        table.add_column("Updated")
        for t in tasks:
            table.add_row(
                str(t.get("id", "")),
                str(t.get("title", ""))[:60],
                str(t.get("status", "")),
                str(t.get("priority", "")),
                str(t.get("assigned_to_username") or t.get("assigned_to_user_id") or "-"),
                str(t.get("updated_at", ""))[:19],
            )
        console.print(table)
    else:
        for t in tasks:
            print(f"  {t.get('id')}  {t.get('title', '')[:50]}  {t.get('status')}  {t.get('priority')}  {t.get('updated_at', '')}")


def cmd_view(api_key: str, task_id: int) -> None:
    data = api_request("GET", "get-task.php", api_key, params={"id": task_id})
    task = data.get("task")
    if not task:
        raise SystemExit("Task not found.")
    comments_data = api_request("GET", "list-comments.php", api_key, params={"task_id": task_id})
    comments = comments_data.get("comments") or []
    attachments_data = api_request("GET", "list-attachments.php", api_key, params={"task_id": task_id})
    attachments = attachments_data.get("attachments") or []
    watchers_data = api_request("GET", "list-watchers.php", api_key, params={"task_id": task_id})
    watchers = watchers_data.get("watchers") or []

    if RICH_AVAILABLE:
        console = Console()
        lines = [
            f"[bold]#{task.get('id')}[/]  {task.get('title', '')}",
            f"Status: {task.get('status')}  Priority: {task.get('priority')}  Project: {task.get('project') or '-'}",
            f"Created: {task.get('created_at')}  Updated: {task.get('updated_at')}",
            f"Created by: {task.get('created_by_username') or task.get('created_by_user_id') or '-'}  Assigned to: {task.get('assigned_to_username') or task.get('assigned_to_user_id') or '-'}",
            "",
            (task.get("body") or "(no description)"),
        ]
        console.print(Panel("\n".join(lines), title="Task", border_style="blue"))
        if comments:
            console.print("\n[bold]Comments[/]")
            for c in comments:
                console.print(f"  [{c.get('created_at', '')}] {c.get('username', '')}: {c.get('comment', '')[:80]}")
        if attachments:
            console.print("\n[bold]Attachments[/]")
            for a in attachments:
                console.print(f"  {a.get('file_name')}  {a.get('file_url', '')}")
        if watchers:
            console.print("\n[bold]Watchers[/]")
            console.print("  " + ", ".join(str(w.get("username") or w.get("user_id")) for w in watchers))
    else:
        print(f"#{task.get('id')}  {task.get('title')}")
        print(f"Status: {task.get('status')}  Priority: {task.get('priority')}")
        print(task.get("body") or "(no description)")
        print("\nComments:", len(comments))
        for c in comments:
            print(f"  {c.get('username')}: {c.get('comment')[:60]}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Peek at tasks via the Sanctum Tasks API")
    sub = parser.add_subparsers(dest="command", required=True)
    list_parser = sub.add_parser("list", help="List tasks")
    list_parser.add_argument("--status", "-s", help="Filter by status slug")
    list_parser.add_argument("--project", "-p", help="Filter by project")
    list_parser.add_argument("--query", "-q", dest="q", help="Search query")
    list_parser.add_argument("--limit", "-n", type=int, default=50, help="Max tasks (default 50)")
    list_parser.add_argument("--sort-by", default="updated_at", help="Sort field (default updated_at)")
    list_parser.add_argument("--sort-dir", default="DESC", choices=("ASC", "DESC"))
    view_parser = sub.add_parser("view", help="View one task by id")
    view_parser.add_argument("id", type=int, help="Task ID")
    args = parser.parse_args()

    api_key = get_api_key()
    if not api_key:
        print("Set TASKS_API_KEY to your API key.", file=sys.stderr)
        sys.exit(1)

    try:
        if args.command == "list":
            cmd_list(
                api_key,
                status=args.status,
                project=args.project,
                q=args.q,
                limit=args.limit,
                sort_by=args.sort_by,
                sort_dir=args.sort_dir,
            )
        elif args.command == "view":
            cmd_view(api_key, args.id)
    except requests.RequestException as e:
        print(f"Request failed: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
