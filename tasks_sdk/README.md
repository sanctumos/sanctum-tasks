# Tasks SDK

Python SDK for tasks.technonomicon.net API.

## Installation

```bash
pip install -e .
```

Or install dependencies manually:

```bash
pip install requests>=2.31.0
```

## Usage

```python
from tasks_sdk import TasksClient

# Initialize client
client = TasksClient(
    api_key="your_api_key_here",
    base_url="https://tasks.technonomicon.net"
)

# Create a task
task = client.create_task(
    title="Fix deployment bug",
    status="todo",
    assigned_to_user_id=1,
    priority="high",
    project="Platform",
    tags=["deploy", "infra"],
    due_at="2026-02-20T12:00:00Z"
)

# List tasks
result = client.list_tasks(status="todo", q="deployment", sort_by="updated_at", sort_dir="DESC", limit=10)
for task in result['tasks']:
    print(task['title'])

# Update a task
client.update_task(
    task_id=123,
    status="doing",
    assigned_to_user_id=2,
    rank=50
)

# Get a task
task = client.get_task(123)

# Delete a task
client.delete_task(123)
```

## API Methods

- `health()` - Check API health and get user info
- `create_task(...)` - Create a task with metadata (`priority`, `project`, `tags`, `due_at`, etc.)
- `update_task(...)` - Update a task with metadata and clear/unassign helpers
- `get_task(task_id)` - Get a single task
- `list_tasks(...)` - Filter/search/sort/paginate tasks
- `search_tasks(q, ...)` - Search by title/body
- `delete_task(task_id)` - Delete a task
- `bulk_create_tasks(tasks)` / `bulk_update_tasks(updates)` - Batch operations
- User/admin methods: `list_users`, `create_user`, `disable_user`, `reset_user_password`
- API key lifecycle: `list_api_keys`, `create_api_key`, `revoke_api_key`
- Collaboration: comments/attachments/watchers helpers
- Workflow taxonomy: `list_statuses`, `create_status`, `list_projects`, `list_tags`
- Auditing: `list_audit_logs`

## Error Handling

The SDK raises custom exceptions:

- `AuthenticationError` - Invalid or missing API key
- `NotFoundError` - Task not found
- `ValidationError` - Invalid request data
- `APIError` - Other API errors

The API also returns stable error objects (`error_object`) in responses.

## License

This project is licensed under the terms in the repository root [`LICENSE`](../LICENSE) file.
