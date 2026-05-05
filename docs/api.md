# Sanctum Tasks API (v1)

This document describes HTTP behavior as implemented under `public/api/`. When in doubt, the PHP entrypoint is authoritative.

## Authentication

Most `/api/*` endpoints require an API key via either:

- `X-API-Key: <token>`
- `Authorization: Bearer <token>`

Session endpoints (`session-login.php`, `session-me.php`, `session-logout.php`) use cookie-based admin/web sessions instead.

**Important:** There is **no** unauthenticated JSON health/ping endpoint under `/api/` — see [Health](#health) below.

## Response and error schema

### Success (`apiSuccess`)

Successful responses include:

| Field | Meaning |
| ----- | ------- |
| `success` | Always `true` at the **top level** for any HTTP-success response (including bulk endpoints with partial row failures — see below). |
| `data` | Copy of the endpoint-specific payload. For **bulk create/update**, inspect **`data.success`** (boolean) and **`data.results`** — not top-level `success`. |
| … | Endpoint-specific fields are also merged at the top level (e.g. `tasks`, `created`, `failed`) for backward compatibility. |
| `meta` | Optional pagination or extras when returned by the endpoint. |

HTTP status is usually `200`; creates often use `201`.

**Bulk caveat:** `apiSuccess` forces top-level `success: true` even when `data.success` is `false` (some rows failed). Treat **`data.failed`**, **`data.results[]`**, or **`data.success`** as the source of truth for batch outcomes.

### Error (`apiError`)

Errors include:

| Field | Meaning |
| ----- | ------- |
| `success` | `false` |
| `error` | Legacy human-readable string (duplicates `error_object.message` in most cases). |
| `error_object` | `{ "code", "message", "details" }` — stable programmatic shape. |

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
- Error code `rate_limited`

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

---

## Endpoint reference

### Health

#### `GET /api/health.php`

**Authentication:** Required — same API key rules as other `/api/*` routes. This is **not** a public unauthenticated liveness probe; unauthenticated requests receive **401** (`auth.invalid_api_key`).

**Response `200`:** JSON includes `ok`, `user` (`id`, `username`, `role`, `is_active`), plus standard `success` / `data` wrapping.

---

### Tasks

#### `GET /api/list-tasks.php`

**Query parameters:**

| Parameter | Description |
| --------- | ----------- |
| `status` | Filter by status slug (invalid non-empty value → `400` `validation.invalid_status`) |
| `priority` | `low` \| `normal` \| `high` \| `urgent` (invalid → `400` `validation.invalid_priority`) |
| `assigned_to_user_id`, `created_by_user_id` | Filters |
| `project`, `project_id`, `list_id` | Project / list filters |
| `q` | Search |
| `due_before`, `due_after` | ISO-ish datetime strings |
| `watcher_user_id` | Filter |
| `sort_by`, `sort_dir` | Sorting |
| `limit`, `offset` | Pagination |

**Response:** `tasks`, pagination helpers (`pagination` / `meta` per implementation).

#### `GET /api/get-task.php`

**Query:** `id` (required), optional `include_relations=1`.

#### `POST /api/create-task.php`

**Body (JSON):**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `title` | Yes | |
| `status` | No | Defaults to default workflow status |
| `assigned_to_user_id` | No | |
| `body` | No | |
| `due_at`, `priority`, `project`, `project_id`, `list_id`, `tags`, `rank`, `recurrence_rule` | No | |

**Response:** `201` with created task payload (`task`).

#### `POST /api/update-task.php`

**Body:** `id` (required), plus any writable task fields.

#### `POST /api/delete-task.php`

**Body:** `id` (task id).

#### `GET /api/search-tasks.php`

**Query:** `q`, optional filters (`status`, `priority`, `assigned_to_user_id`, `sort_by`, `sort_dir`, `limit`, `offset`). Invalid `status` / `priority` → `400`.

---

### Bulk operations

Maximum **100** items per request for both endpoints. Larger batches return **`400`** with code `validation.batch_too_large`.

#### `POST /api/bulk-create-tasks.php`

**Body:**

```json
{
  "tasks": [
    {
      "title": "…",
      "status": "todo",
      "assigned_to_user_id": null,
      "body": null,
      "due_at": null,
      "priority": "normal",
      "project": null,
      "tags": [],
      "rank": 0,
      "recurrence_rule": null
    }
  ]
}
```

Each array element follows the same shape as fields passed into single-task create (see `bulkCreateTasks` in `public/includes/functions.php`).

**Response `200`:** Top-level `success` is always `true` (HTTP succeeded). Use nested fields for batch outcome:

| Field | Type | Meaning |
| ----- | ---- | ------- |
| `success` (inside **`data`**) | bool | **`true` only if every item succeeded.** If any item fails, **`false`** (partial failure). |
| `created` | int | Count of successful creates |
| `failed` | int | Count of failures |
| `results` | array | One entry per input index |

Each `results[]` element includes:

- `index` — position in the request `tasks` array
- `success` — bool for that row
- On success: `id` (new task id), etc. from `createTask`
- On failure: `error` string

#### `POST /api/bulk-update-tasks.php`

**Body:**

```json
{
  "updates": [
    {
      "id": 101,
      "title": "optional",
      "status": "doing",
      "assigned_to_user_id": null,
      "body": null,
      "due_at": null,
      "priority": "urgent",
      "project": null,
      "tags": [],
      "rank": 0,
      "recurrence_rule": null
    }
  ]
}
```

Only fields present are applied per row (`id` required).

**Response `200`:** Same partial-failure pattern as bulk create (check **`data.success`**, not top-level):

| Field | Type | Meaning |
| ----- | ---- | ------- |
| `success` (inside **`data`**) | bool | **`true` only if every update succeeded** |
| `updated` | int | Success count |
| `failed` | int | Failure count |
| `results` | array | Per index: `index`, `id`, `success`, plus `error` on failure |

---

### Status workflow

#### `GET /api/list-statuses.php`

Returns configured task statuses.

#### `POST /api/create-status.php`

Admin-only — creates a status (see PHP for body fields).

---

### Collaboration

#### Comments

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/list-comments.php` | `task_id` query |
| `POST` | `/api/create-comment.php` | JSON body |

#### Attachments (metadata only)

**`POST /api/add-attachment.php`** does **not** accept multipart file uploads. It stores **metadata** for a URL you already host elsewhere.

**Body (JSON):**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `task_id` | Yes | |
| `file_name` | Yes | Display name |
| `file_url` | Yes | URL string (verified/stored as provided) |
| `mime_type` | No | |
| `size_bytes` | No | |

**Response `201`:** Minimal payload: `task_id`, `attachment_id` (plus standard `success` / `data`).

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/list-attachments.php` | `task_id` |

#### Watchers

| Method | Path |
| ------ | ---- |
| `GET` | `/api/list-watchers.php` |
| `POST` | `/api/watch-task.php` |
| `POST` | `/api/unwatch-task.php` |

---

### Taxonomy helpers

#### `GET /api/list-projects.php`

Distinct non-empty legacy `tasks.project` strings with counts.

#### `GET /api/list-tags.php`

Tag aggregation helper.

---

### Workspace (organizations & directory projects)

First-class organizations and directory projects. User rows include `org_id`, `person_kind` (`team_member` \| `client`), etc.

#### `GET /api/list-organizations.php`

Visibility rules apply (admin/manager vs member scope).

#### `GET /api/list-directory-projects.php`

Projects visible per directory rules (`all_access`, `project_members`, role, client visibility).

#### `POST /api/create-directory-project.php`

JSON: `name`, optional `description`, `client_visible`, `all_access`.

#### `GET /api/get-directory-project.php`

Query: `id`.

#### `POST /api/update-directory-project.php`

JSON: `id`, plus fields to update.

#### `GET /api/list-project-members.php`

Query: `project_id`.

#### `POST /api/add-project-member.php`

JSON: `project_id`, `user_id`, optional `role` (`lead` \| `member` \| `client`).

#### `POST /api/remove-project-member.php`

JSON: `project_id`, `user_id`.

#### To-do lists & pins

See `list-todo-lists.php`, `create-todo-list.php`, `list-project-pins.php`, `set-project-pin.php`.

### Tasks ↔ directory project

- `POST /api/create-task.php` — optional `project_id`, `list_id`
- `POST /api/update-task.php` — optional `project_id`, `list_id`
- `GET /api/list-tasks.php` — filter `project_id`, `list_id`

---

### Users (admin API key required)

All routes below use `requireAdminApiUser()` unless noted.

#### `GET /api/list-users.php`

Lists users per server rules.

#### `POST /api/create-user.php`

Creates a user and optionally mints an API key in the same request.

**Body (JSON):**

| Field | Required | Default / notes |
| ----- | -------- | --------------- |
| `username` | Yes | |
| `password` | Yes | |
| `role` | No | `member` |
| `must_change_password` | No | default `true` |
| `org_id` | No | Falls back to first/default org |
| `person_kind` | No | `team_member` |
| `limited_project_access` | No | boolean |
| `create_api_key` | No | If **`true`**, also creates an API key for the new user |
| `api_key_name` | No | Label for that key; default **`"default"`** |

**Response `201`:** Always includes `user`. If `create_api_key` was true, includes **`api_key`** — the **plaintext secret** (only returned at creation time).

#### `POST /api/disable-user.php`

**Not only disable:** sets active flag from JSON.

**Body:**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `id` | Yes | Target user id |
| `is_active` | No | If omitted, treated as **`false`** (disable). Pass **`true`** to **re-enable** the same user |

You cannot disable your own user via this endpoint (`validation.self_disable_not_allowed`).

**Response `200`:** `user` with updated fields.

#### `POST /api/reset-user-password.php`

Admin password reset (see PHP for body).

---

### API key lifecycle

#### `GET /api/list-api-keys.php`

**Query parameters:**

| Parameter | Value | Effect |
| --------- | ----- | ------ |
| `mine` | `1` | Only keys belonging to the **authenticated** user (even for admins). |
| `include_revoked` | `1` | Include revoked keys; default is active keys only. |

**Visibility:**

- **Non–admin users:** Always see **only their keys** (same as `mine=1`).
- **Admins/managers:** Default lists **all** keys in the system; use **`mine=1`** to list only their own.

Each row exposes `api_key_preview` (truncated); **never** the full secret after creation.

#### `POST /api/create-api-key.php`

**Body:** optional `user_id` (admin only; defaults to caller), `key_name`.

**Response `201`:** Returns plaintext `api_key` once.

#### `POST /api/revoke-api-key.php`

**Body (JSON):**

```json
{ "id": 42 }
```

`id` is the **`api_keys` table row id**, **not** the secret string.

- **Admins** may revoke any key id.
- **Non-admins** may only revoke keys that belong to them (`403` otherwise).

**Response `200`:** `revoked: true`, `id`.

---

### Session endpoints (cookie-based)

| Method | Path | Notes |
| ------ | ---- | ----- |
| `POST` | `/api/session-login.php` | `username`, `password`, optional `mfa_code` |
| `GET` | `/api/session-me.php` | Current session user |
| `POST` | `/api/session-logout.php` | CSRF when logged in |

---

### Auditing

#### `GET /api/list-audit-logs.php`

Admin — audit entries.

---

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

### Bulk update (check partial results)

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"updates":[{"id":101,"status":"doing"},{"id":102,"priority":"urgent"}]}' \
  https://tasks.example.com/api/bulk-update-tasks.php
```

Inspect JSON: **`data.success === false`** means at least one row failed (top-level `success` may still be `true`). Use **`data.results[]`** for per-item errors.

### Create user + API key in one call

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_ADMIN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "username":"integration-bot",
    "password":"***",
    "role":"api",
    "create_api_key": true,
    "api_key_name": "CI"
  }' \
  https://tasks.example.com/api/create-user.php
```

---

## SDK

The Python `tasks_sdk` and related clients may lag this document; validate edge cases against this file or the PHP entrypoint.
