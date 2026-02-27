# Heartbeat: Open-claw pattern using Sanctum Tasks

This document describes how to implement an **open-claw style heartbeat** using Sanctum Tasks only. The heartbeat is a scheduled worker that, on each “tick,” either continues work on one in-flight task or grabs one new task from a dedicated queue. All queue and state live in **tasks**; no separate heartbeat service is required in the Sanctum suite.

---

## What “heartbeat” and “open claw” mean here

- **Heartbeat:** A process or job that runs on a fixed interval (e.g. every 30 seconds). Each run is one “beat.”
- **Open claw:** On each beat, the worker does **at most one** unit of work: it either (1) continues processing a task it already claimed, or (2) grabs **one** new task from the queue. So “one reach, one grab” per beat—like a gripper that opens, closes on one item, processes it, then releases.

The queue and all state are **Sanctum Tasks tasks**. The worker uses the Tasks API (or SDK / SMCP plugin) to list, claim, update, and complete tasks.

---

## Keeping “heartbeat” tasks separate from other work

You will usually want a **list of just heartbeat tasks** for the agent (or worker), and other lists for other contexts—e.g. short-term task management, research, or inbox.

Use Sanctum Tasks’ **project** (and optionally **tags**) to define categories:

| Category      | Purpose                          | Example filter for “this list only”   |
|---------------|-----------------------------------|---------------------------------------|
| Heartbeat     | Scheduled work for the heartbeat worker | `project=heartbeat` (or `agent-heartbeat`) |
| Short-term    | Day-to-day task management       | `project=short-term` (or tag)         |
| Research      | Research or long-running work     | `project=research` (or tag)           |
| Inbox         | Un triaged                       | `project=inbox` (or tag)               |

- **Heartbeat queue:** List tasks with `status=todo` and `project=heartbeat` (and optionally `tags=heartbeat`). That list is the **heartbeat queue**—only tasks intended for the heartbeat worker.
- **Other categories:** Use different projects or tags. The agent (or admin UI) can list “just heartbeat” vs “just short-term” vs “just research” by changing the `project` (and tag) filter. No need to mix heartbeat work with other lists.

Create a dedicated **project** (e.g. `heartbeat` or `agent-heartbeat`) for heartbeat work. You can add a status like `queued` if you create it via the admin or API, but the built-in `todo` / `doing` / `done` are enough: `todo` = queued, `doing` = claimed/in progress, `done` = finished.

---

## Queue and task shape

- **Queue:** Tasks in Sanctum Tasks with:
  - `project=heartbeat` (or your chosen project name).
  - `status=todo` for “pending.”
- **Payload:** Store whatever the worker needs in the task **body** (e.g. JSON). Examples:
  - `{ "kind": "cursor_cli_poll", "agent_uid": "<uid>" }` if the work is “poll until done, then fetch output” (e.g. using another Sanctum or MCP tool).
  - `{ "kind": "notify", "channel": "#ops", "message": "..." }` for a notification job.
  - Any other `kind` and parameters your worker understands.
- **Result:** When the worker finishes, it can write the result back into the task **body** or add a **comment**, then set `status=done`.

**Worker identity:** Create a dedicated user (e.g. `heartbeat-worker`) and use its API key (or user id) for the worker. Claiming is done by setting `assigned_to_user_id` to that user and `status=doing`.

---

## Open-claw worker loop (per beat)

Run the following logic every N seconds (cron, systemd timer, or a loop with `sleep`).

### Step 1: Re-check in-flight task (optional but recommended)

- **List** tasks: `status=doing`, `assigned_to_user_id=<heartbeat_worker_user_id>`, `project=heartbeat`, `limit=1`.
- If **one exists:** That is the task the worker is already processing.
  - **Process** it (read `body`, run the work—e.g. call an external tool or API, or poll something until done).
  - If the work is **finished:** Update the task (e.g. write result to `body` or add a comment), set `status=done`.
  - If the work is **not yet finished:** Leave the task as `doing`; the next beat will pick it up again.
  - Then **skip** grabbing a new task this beat (one unit of work per beat).
- If **none:** There is no in-flight task; go to Step 2.

### Step 2: Grab one task from the queue

- **List** tasks: `status=todo`, `project=heartbeat`, `limit=1`, sort by `created_at` ASC (FIFO).
- If **none:** Do nothing this beat; wait for the next tick.
- If **one:** **Claim** it: **update** that task with `status=doing` and `assigned_to_user_id=<heartbeat_worker_user_id>`.
  - If you run multiple workers, you can try to make the claim “atomic” by only updating when `status` is still `todo`; if the update affects zero rows, another worker likely claimed it—skip and try again next beat.
- On the **next** beat, this task will appear as in-flight in Step 1 and will be processed until done.

### Step 3: Next beat

- Wait until the next interval, then run Step 1 again.

So: **one beat ⇒ at most one task advanced** (either one in-flight task re-checked and possibly completed, or one new task claimed). That is the open-claw behavior.

---

## API usage summary

All of the above uses only Sanctum Tasks concepts:

- **List “just heartbeat tasks” (queue):** `GET /api/list-tasks.php` with `project=heartbeat`, `status=todo`, `limit=1`, and sort by `created_at` ASC (or your preferred order).
- **List in-flight:** Same endpoint with `status=doing`, `assigned_to_user_id=<worker_user_id>`, `project=heartbeat`, `limit=1`.
- **Claim:** `POST /api/update-task.php` with `id`, `status=doing`, `assigned_to_user_id=<worker_user_id>`.
- **Complete:** `POST /api/update-task.php` with `id`, `status=done`, and optionally updated `body` or a comment via `POST /api/create-comment.php`.

Same filters (e.g. `project=heartbeat`) give the agent a list of **only** heartbeat tasks; other projects/tags give other categories (short-term, research, etc.) without mixing.

---

## Example: heartbeat task that polls until done

The **work** a heartbeat task does is up to you. One example: “poll something until it’s done, then store the result.” That might involve another Sanctum or MCP integration (e.g. a plugin that runs a long-running job and exposes status/output). The flow is still the same:

1. Producer creates a task with `project=heartbeat`, `status=todo`, and body e.g. `{ "kind": "poll_until_done", "target_id": "..." }`.
2. Worker claims it (Step 2 above).
3. On each beat, worker sees it in-flight (Step 1), calls the external system (e.g. status API or MCP tool). If still running, leave task as `doing`. If done, fetch result, write to task body or comment, set `status=done`.

Cursor CLI (e.g. via an SMCP plugin) is one possible implementation of that “poll until done” step—but the heartbeat pattern and the “list of just heartbeat tasks” are defined entirely within Sanctum Tasks (tasks, project, status, assignee, list-tasks, update-task).

---

## Summary

- **Heartbeat** = a scheduled worker that runs on a fixed interval and uses Sanctum Tasks as the queue.
- **Open claw** = one beat ⇒ at most one task: either advance an in-flight task to done or grab one new `todo` task.
- **“Just heartbeat tasks”** = list tasks with `project=heartbeat` (and optional tag); other categories use other projects/tags.
- All state and behavior are in **Sanctum Tasks** (tasks, project, status, assignee, body, API). External tools (e.g. Cursor CLI) are optional implementations of the work a heartbeat task performs.

For workflow context (agent-only vs hybrid vs human-only), see [Workflows](WORKFLOWS.md).
