# Integration Guide (Admin -> API Key -> SDK/Plugin)

This is an end-to-end workflow for connecting automation agents to `tasks.technonomicon.net`.

## 1) Create API key in admin UI

1. Open `/admin/login.php` and sign in.
2. Go to **API Keys** (`/admin/api-keys.php`).
3. Create a key name (for example `Agent Runner`).
4. Copy the full key immediately.

Store it securely as `TASKS_API_KEY`.

## 2) Use the Python SDK

```python
from tasks_sdk import TasksClient

client = TasksClient(
    api_key="YOUR_API_KEY",
    base_url="https://tasks.technonomicon.net",
)

# Create
task = client.create_task(
    title="Nightly deployment verification",
    body="Validate canary and rollback hooks",
    status="todo",
    priority="high",
    project="Platform",
    tags=["automation", "deploy"],
)

# Assign and start
task = client.update_task(task_id=task["id"], status="doing", assigned_to_user_id=1)

# Mark complete
task = client.update_task(task_id=task["id"], status="done")
```

## 3) Use the SMCP plugin

```bash
python smcp_plugin/tasks/cli.py create-task \
  --api-key "YOUR_API_KEY" \
  --title "Reconcile failed jobs" \
  --status "todo" \
  --priority "urgent" \
  --project "Ops" \
  --tags "automation,nightly"

python smcp_plugin/tasks/cli.py list-tasks \
  --api-key "YOUR_API_KEY" \
  --q "jobs" \
  --sort-by "updated_at" \
  --sort-dir "DESC" \
  --limit 20
```

## 4) Automation patterns

## Pattern A: Agent triage queue

1. Ingest incident/events.
2. Create tasks with project/tag metadata.
3. Auto-watch task for owning service account.
4. Add comments with investigation notes.

API endpoints:

- `create-task.php`
- `watch-task.php`
- `create-comment.php`

## Pattern B: Batch status transitions

1. Query candidate tasks by project/tag/search.
2. Use `bulk-update-tasks.php` to move all to `doing` or `done`.
3. Write audit records and report results.

## Pattern C: Human + agent handoff

1. Agent creates task and assignment.
2. Human reviews in admin UI and updates details.
3. Agent polls `list-tasks.php` and closes loop automatically.

## 5) Operational recommendations

- Rotate API keys regularly (`revoke-api-key.php` + create new).
- Scope keys per integration and label by purpose.
- Use rate-limit headers to throttle agent behavior.
- Prefer idempotent task titles/tags for repeated jobs.
