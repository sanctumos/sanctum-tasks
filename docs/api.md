# Sanctum Tasks API (v1)

## Authentication

Most `/api/*` endpoints require an API key via either:

- `X-API-Key: <token>`
- `Authorization: Bearer <token>`

Session endpoints (`session-login.php`, `session-me.php`, `session-logout.php`) use cookie-based admin/web sessions instead.

## Response and error schema

### Success

Success payloads include:

- `success: true`
- endpoint-specific fields (for compatibility, e.g. `task`, `tasks`, `count`)
- `data` mirror of endpoint-specific payload
- optional `meta` object

### Error

Errors include both:

- legacy string: `error`
- stable object: `error_object`

Example:

```json
{
  "success": false,
  "error": "Invalid or missing API key",
  "error_object": {
    "code": "auth.invalid_api_key",
    "message": "Invalid or missing API key",
    "details": {}
  }
}
```

## Rate limiting

API-key endpoints enforce fixed-window rate limiting.

Response headers:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset` (unix epoch)

On limit exceeded:

- HTTP `429`
- `Retry-After` header
- error code `rate_limited`

## Task model

Task fields:

- `id` (int)
- `title` (string)
- `body` (string|null)
- `status` (string slug; customizable)
- `due_at` (UTC datetime|null)
- `priority` (`low|normal|high|urgent`)
- `project` (string|null)
- `tags` (array of strings)
- `rank` (int)
- `recurrence_rule` (string|null)
- `created_by_user_id` (int)
- `assigned_to_user_id` (int|null)
- `created_at` (UTC datetime)
- `updated_at` (UTC datetime)
- `comment_count`, `attachment_count`, `watcher_count` (ints)

Convenience fields:

- `created_by_username`
- `assigned_to_username`
- `status_label`, `status_sort_order`, `status_is_done`

## Endpoint reference

## Health

- `GET /api/health.php`

## Tasks

- `GET /api/list-tasks.php`
  - filters: `status`, `assigned_to_user_id`, `created_by_user_id`, `priority`, `project`, `q`, `due_before`, `due_after`, `watcher_user_id`
  - invalid non-empty `status` or `priority` values return `400` (`validation.invalid_status` / `validation.invalid_priority`)
  - sorting: `sort_by`, `sort_dir`
  - pagination: `limit`, `offset`
  - returns `pagination` object with `next_url`/`prev_url`
- `GET /api/get-task.php?id=<id>&include_relations=1`
- `POST /api/create-task.php`
  - fields: `title` required, plus task model write fields
- `POST /api/update-task.php`
  - fields: `id` required, any task model write fields
- `POST /api/delete-task.php`
  - fields: `id`
- `GET /api/search-tasks.php?q=<query>`
  - optional filters: `status`, `priority`, `assigned_to_user_id`, `sort_by`, `sort_dir`, `limit`, `offset`
  - invalid non-empty `status` or `priority` values return `400` (`validation.invalid_status` / `validation.invalid_priority`)

## Bulk operations

- `POST /api/bulk-create-tasks.php`
  - body: `{ "tasks": [ ... ] }`, max 100
- `POST /api/bulk-update-tasks.php`
  - body: `{ "updates": [ ... ] }`, max 100

## Status workflow

- `GET /api/list-statuses.php`
- `POST /api/create-status.php` (admin)

## Collaboration

- comments:
  - `GET /api/list-comments.php?task_id=<id>`
  - `POST /api/create-comment.php`
- attachments (metadata URLs):
  - `GET /api/list-attachments.php?task_id=<id>`
  - `POST /api/add-attachment.php`
- watchers/subscribers:
  - `GET /api/list-watchers.php?task_id=<id>`
  - `POST /api/watch-task.php`
  - `POST /api/unwatch-task.php`

## Taxonomy helpers

- `GET /api/list-projects.php` — distinct non-empty `tasks.project` strings with task counts (legacy string grouping).
- `GET /api/list-tags.php`

## Workspace (organizations & project directory)

First-class **organizations** and **projects** (Basecamp-shaped directory). Users gain `org_id` and `person_kind` (`team_member` \| `client`); API responses include these fields where users are returned.

- `GET /api/list-organizations.php` — organizations visible to the caller (admins/managers: all; others: their org only).
- `GET /api/list-directory-projects.php` — project rows in the user’s org (`limit` query param); visibility uses `all_access`, `project_members`, or admin/manager override. Users with `person_kind=client` only see projects with `client_visible=1` (plus membership / all-access rules).
- `POST /api/create-directory-project.php` — JSON: `name`, optional `description`, optional `client_visible`, optional `all_access`; creator becomes project **lead** in `project_members`.
- `GET /api/get-directory-project.php?id=<id>` — one directory project (404 if not visible).
- `POST /api/update-directory-project.php` — JSON: `id`, plus any of `name`, `description`, `status` (`active`|`archived`|`trashed`), `client_visible`, `all_access` (admin/manager or project **lead**).
- `GET /api/list-project-members.php?project_id=<id>`
- `POST /api/add-project-member.php` — `project_id`, `user_id`, optional `role` (`lead`|`member`|`client`)
- `POST /api/remove-project-member.php` — `project_id`, `user_id`
- `GET /api/list-todo-lists.php?project_id=<id>` — to-do list rows for a project.
- `POST /api/create-todo-list.php` — `project_id`, `name` (lead / admin / manager).
- `GET /api/list-project-pins.php` — pinned projects for the current user (`limit`).
- `POST /api/set-project-pin.php` — `project_id`, `pinned` (bool), optional `sort_order` (unpin with `pinned: false`).

### Tasks ↔ directory project

- `POST /api/create-task.php` — optional `project_id` (sets legacy `project` name from directory when valid), optional `list_id` (must belong to the same project; can imply `project_id` if omitted).
- `POST /api/update-task.php` — optional `project_id` (null clears link and legacy `project`), optional `list_id`.
- `GET /api/list-tasks.php` — filter by `project_id` and/or `list_id`.

## Users (admin)

- `GET /api/list-users.php`
- `POST /api/create-user.php` — optional `org_id`, optional `person_kind` (`team_member` \| `client`), optional `limited_project_access` (boolean; when true, managers/members follow project membership + `all_access` for directory and task lists); defaults to first organization and `team_member` when omitted.
- `POST /api/disable-user.php` (`is_active` controls enable/disable)
- `POST /api/reset-user-password.php`

## API key lifecycle

- `GET /api/list-api-keys.php`
- `POST /api/create-api-key.php`
- `POST /api/revoke-api-key.php`

## Session/auth endpoints (cookie-based)

- `POST /api/session-login.php` (`username`, `password`, optional `mfa_code`)
- `GET /api/session-me.php` — `user` includes `org_id` and `person_kind` when present
- `POST /api/session-logout.php` (requires CSRF token when logged in)

## Auditing

- `GET /api/list-audit-logs.php` (admin)

## Quick examples

### Create task with metadata

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "title":"Investigate deployment error",
    "body":"Check logs and deployment status",
    "status":"todo",
    "priority":"high",
    "project_id":3,
    "tags":["infra","release"],
    "due_at":"2026-02-20T16:00:00Z",
    "rank":10
  }' \
  https://tasks.example.com/api/create-task.php
```

### Search tasks

```bash
curl -sS -H "X-API-Key: $TASKS_API_KEY" \
  "https://tasks.example.com/api/search-tasks.php?q=deployment&limit=20"
```

### Bulk update

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"updates":[{"id":101,"status":"doing"},{"id":102,"priority":"urgent"}]}' \
  https://tasks.example.com/api/bulk-update-tasks.php
```
