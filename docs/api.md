# Sanctum Tasks API (v1)

This document describes HTTP behavior as implemented under `public/api/`. When in doubt, the PHP entrypoint is authoritative.

**See also:** [Task authorization, operational patterns, and product notes](api-authorization-and-product-notes.md) — who sees which tasks, 404 vs ACL, service-account patterns, client visibility, audit/attachments scope, and Python SDK response handling.

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
| `tag` | Exact tag match (case-insensitive) against `tags_json` |
| `exclude_done` | `1` to omit tasks whose status is marked done |
| `include_archived_projects` | `1` to include tasks whose directory project is archived/trashed. Default: omit those tasks on cross-project lists. Ignored when `project_id` is set (scoped board views always include that board). |
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
| `list_id` | **Yes** | Todo list (`todo_lists.id`) the task belongs to. You may send **`list_id` alone** (the task’s directory project is inferred from the list). If you also send **`project_id`**, it must match the list’s project. |
| `project_id` | No | Directory / workspace `projects.id`. Optional when `list_id` alone identifies the project; if both are sent, they must agree. |
| `due_at`, `priority`, `project`, `tags`, `rank`, `recurrence_rule` | No | `project` is a legacy display string; new tasks should rely on `project_id` + `list_id`. |

**Response:** `201` with created task payload (`task`).

#### `POST /api/update-task.php`

**Body:** `id` (required), plus any writable task fields. You cannot clear **`project_id`** or **`list_id`** (sending `null` / empty is rejected). When changing **`project_id`**, if you omit **`list_id`**, the server assigns the **first** list in the target project (must exist).

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
      "project_id": 1,
      "assigned_to_user_id": null,
      "body": null,
      "due_at": null,
      "priority": "normal",
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

#### Attachments

**Metadata mode (`POST /api/add-attachment.php`):** stores a URL you already host elsewhere.

**Body (JSON):**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `task_id` | Yes | |
| `file_name` | Yes | Display name |
| `file_url` | Yes | URL string (verified/stored as provided) |
| `mime_type` | No | |
| `size_bytes` | No | |

**Response `201`:** Minimal payload: `task_id`, `attachment_id` (plus standard `success` / `data`).

**Upload mode (`POST /api/upload-attachment.php`):** authenticated multipart upload for files stored by Tasks (images, PDF, office docs, and a few other types).

- Content-Type: `multipart/form-data`
- Fields:
  - `task_id` (required)
  - `file` (required upload field)
- Allowed MIME types: `image/png`, `image/jpeg`, `image/gif`, `image/webp`, `video/mp4`, `application/pdf`, `text/plain`, `text/csv`, `text/markdown`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/vnd.ms-excel`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/zip`, `application/x-zip-compressed`
- Max size: `TASKS_ASSET_MAX_BYTES` (default 8 MiB)
- **Disk layout:** default `TASKS_ASSET_STORAGE_DIR` is **`public/uploads/task-assets/`** (under the web docroot’s `uploads/` tree). That matches standard multihost **`deploy.sh`** behavior: `public/` is mirrored with `--delete`, but **`public/uploads/` is preserved**. Bytes are still served **only** through `GET /api/get-asset.php` (task ACL), not as public hotlinks. Override `TASKS_ASSET_STORAGE_DIR` only for legacy installs (e.g. older `storage/task-assets` paths).

**Authentication (either):**

- **API key** — `X-API-Key` or `Authorization: Bearer` (subject to endpoint rate limiting).
- **Admin session** (browser) — cookie session plus **CSRF** on the multipart body (`csrf_token` field) **or** `X-CSRF-Token` header matching the logged-in admin session token. The admin task page uses session + CSRF for drag-and-drop / file uploads.

**Upload response `201`:** `task_id`, `attachment_id`, canonical `file_url`, and markdown snippet (`markdown`) for inline paste.

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/list-attachments.php` | `task_id` |
| `GET` | `/api/get-asset.php` | `id` attachment id; session auth or API key with task ACL. **Public share:** optional `document_share_token` (64-hex from `/shared-document.php`) when the attachment id is embedded in that document’s body — no login required. |

#### Watchers

| Method | Path |
| ------ | ---- |
| `GET` | `/api/list-watchers.php` |
| `POST` | `/api/watch-task.php` |
| `POST` | `/api/unwatch-task.php` |

#### Board archive ZIP (archived projects)

Anyone with **project access** may request/list/download once the directory project `status` is **`archived`**. Jobs are async (`project_board_exports`); ZIP files live under `TASKS_BOARD_EXPORT_DIR` (outside `public/`).

| Method | Path | Notes |
| ------ | ---- | ----- |
| `POST` | `/api/request-board-export.php` | JSON `project_id` — queues worker; returns `job_id`. If a ready ZIP already matches the current board content fingerprint, reuses that job (`unchanged: true`, HTTP 200) instead of writing a duplicate file. |
| `GET` | `/api/list-board-exports.php` | `project_id` — recent jobs |
| `GET` | `/api/download-board-export.php` | `id` job id — streams ZIP when `status=ready` (session or API key) |

#### Notifications (per-user feed)

Aggregated **for the authenticated API user only** (cookie session or API key). Rows are created when you are assigned to a task, `@mentioned` in a task body or comment (or document body/comment), when someone comments on a task you watch or are assigned to, or when someone comments on a document you created.

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/list-notifications.php` | Query: `limit` (default 50, max 100), `before_id` (cursor — return older than this id), `unread_only` (`1`/`true`). Response includes `notifications`, `next_before_id`, `unread_count`. Each item has `kind`, `title`, `label`, `href` (admin UI path), optional `snippet`, `read_at`, ids. |
| `POST` | `/api/mark-notifications-read.php` | JSON: `{ "id": 123 }` or `{ "ids": [1,2] }` or `{ "all": true }`. Response includes `unread_count`. |

Kinds include: `task_assigned`, `task_mention`, `task_comment_mention`, `task_comment_activity`, `document_mention`, `document_comment_mention`, `document_comment_activity`.

#### Directory activity (timeline)

| Method | Path | Notes |
| ------ | ---- | ----- |
| `GET` | `/api/list-schedule.php` | Due-date aggregation (`due_at`) scoped by ACL. |
| `GET` | `/api/list-activity.php` | **Exactly one** of `project_id` or `user_id`. |

**Query parameters**

| Name | Required | Notes |
| ---- | -------- | ----- |
| `project_id` | One-of | Events tied to this directory project (task lifecycle, comments, attachments, watchers, documents, doc comments, project membership/settings, list creation, task deletes with `project_id` in audit metadata). Caller must pass `userCanAccessDirectoryProject`. |
| `user_id` | One-of | Events where that user is the **actor**, limited to directory projects the caller can access. **403** if the caller may not read another user’s feed (members and limited managers cannot query other users; **self** is always allowed). Admins and unrestricted managers may query peers. |
| `limit` | No | Default **50**, max **200**. |
| `before_id` | No | Cursor: return rows with audit log `id` **strictly less** than this value (older items). |

**Response `200`:** `events` (each item includes `id`, `actor_user_id`, `actor_username`, `action`, `entity_type`, `entity_id`, `metadata`, `created_at`, human `summary`, admin `href`, Bootstrap-icon `icon`, optional `task_title` / `document_title`), and `count`. **`ip_address` is never included** in API JSON.

---

#### `GET /api/list-schedule.php`

Aggregates tasks with **`due_at`** set, respecting the same directory ACL as `list-tasks.php`. Returns flat `entries` plus `grouped_by_date` for calendar-style UIs.

| Name | Required | Notes |
| ---- | -------- | ----- |
| `scope` | No | `mine` (default — assignee is caller), `all` (visible projects), or `project` (requires `project_id`). |
| `project_id` | When `scope=project` | Directory project id; **404-style** empty when inaccessible. |
| `due_after` / `due_before` | No | ISO-ish datetimes; default window is today → +30 days, with overdue lookback when `include_overdue=true`. |
| `include_done` | No | Default false — omits tasks whose status has `is_done`. |
| `include_overdue` | No | Default true — extends `due_after` backward 90 days. |
| `limit` | No | Default **200**, max **500**. |

**Response `200`:** `schedule` object with `scope`, `due_after`, `due_before`, `entries[]`, `grouped_by_date[]`, `count`.

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

**Query:** `limit` (default 200), `include_archived` (`1` to include archived; default omits archived and lists **active** only). Trashed projects are never returned.

#### `POST /api/create-directory-project.php`

JSON: `name`, optional `description`, `client_visible`, `all_access`.

#### `GET /api/get-directory-project.php`

Query: `id`.

#### `POST /api/update-directory-project.php`

JSON: `id`, plus fields to update: `name`, `description`, `status` (`active` \| `archived` \| `trashed`), `client_visible`, `all_access`.

#### `GET /api/list-project-members.php`

Query: `project_id`.

#### `POST /api/add-project-member.php`

JSON: `project_id`, `user_id`, optional `role` (`lead` \| `member` \| `client`).

#### `POST /api/remove-project-member.php`

JSON: `project_id`, `user_id`.

#### To-do lists, pins & doors

See `list-todo-lists.php`, `create-todo-list.php`, `delete-todo-list.php`, `list-project-pins.php`, `set-project-pin.php`.

#### `GET /api/list-project-doors.php`

Query: `project_id`. Returns `project_doors` array (title, url, description, sort_order). Requires project read access.

#### `POST /api/create-project-door.php`

JSON: `project_id`, `title`, `url`, optional `description`. Requires project manage permission (lead / admin).

#### `POST /api/update-project-door.php`

JSON: `id`, plus fields to update: `title`, `url`, `description`, `sort_order`.

#### `POST /api/delete-project-door.php`

JSON: `id`.

### Tasks ↔ directory project

- `POST /api/create-task.php` — required **`list_id`** on every task; include **`project_id`** too unless it is implied by the list alone
- `POST /api/update-task.php` — **`project_id`** / **`list_id`** cannot be cleared; optional `list_id` when consistent with the task’s project (when moving projects without `list_id`, first list is picked server-side)
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

#### `POST /api/update-user.php`

Admin partial update for an existing user.

**Body (JSON):**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `user_id` (or `id`) | Yes | Target user id |
| `person_kind` | No | `team_member` \| `client` — clients cannot create tasks/docs |
| `limited_project_access` | No | boolean |
| `org_id` | No | Primary organization id |

Provide at least one updatable field. Response includes `user` and `updated`.

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

#### `POST /api/delete-user.php`

Admin-only hard delete of a user.

**Body (JSON):**

| Field | Required | Notes |
| ----- | -------- | ----- |
| `id` | Yes | Target user id |
| `force` | No | If `true`, reassign authored content (tasks, comments, attachments, documents) to the calling admin and remove project memberships / API keys before deleting. Without `force`, refuses with `409` and a `references` map of related row counts so the caller can decide. |

You cannot delete your own user (`validation.self_delete_not_allowed`).

**Response `200`:** `id`, `already_deleted` (true if user was missing), and `references` (the activity counts that existed before deletion).

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

**Q Vernal bridge keys (`key_kind = q_bridge`):** Created automatically for every new user (and via `php tools/backfill_q_bridge_api_keys.php` for existing users). These keys are **hidden** from `list-api-keys` and admin Settings; the plaintext is derived server-side for the webchat PHP bridge only (`getQBridgeDefaultApiKeyPlaintextForUser` in app code). Users still use normal `standard` keys for integrations they create themselves.

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

**Admin API user required.** Pagination via `limit` / `offset` (see PHP). No filters for actor, task, or action type in the reference implementation — see [product notes](api-authorization-and-product-notes.md#2-audit-log-api).

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

**HTTP contract:** body must be an object with an **`updates`** array — not a bare JSON array. (CLI/MCP `--json` still accepts a bare array and wraps it via the SDK.)

```bash
curl -sS -X POST \
  -H "X-API-Key: $TASKS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"updates":[{"id":101,"status":"doing"},{"id":102,"priority":"urgent"}]}' \
  https://tasks.example.com/api/bulk-update-tasks.php
```

Inspect JSON: **`data.success === false`** means at least one row failed (top-level `success` may still be `true`). Use **`data.results[]`** for per-item errors.

### Search tasks (optional project filter)

`GET /api/search-tasks.php?q=…` accepts the same filters as list where applicable, including **`project_id`**.

### Documents — optimistic concurrency + busy reads

- **`POST /api/update-document.php`**: optional **`expected_updated_at`** (string matching the document’s current `updated_at`). On mismatch → HTTP **409**, code **`document.conflict`**, details include `current_updated_at`. Omit the field for classic last-writer-wins full-body replace.
- **`GET /api/get-document.php`**: under SQLite lock contention may return **503** **`db.busy`** with `retryable: true` — clients should retry idempotent GETs.

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

### Documents — body size

`body` on create/update is silently truncated to **1,000,000** characters (~1 MB markdown). Task bodies remain capped at **10,000** characters. **Public share:** inline images using `/api/get-asset.php?id=…` in the document body are rewritten on `/shared-document.php` with `document_share_token` so guests can load them without logging in.

### Documents — public anonymous read

Project documents normally require session or API credentials. Editors who can **`userCanManageDocument`** may optionally enable **`public_link_enabled`**, which issues a **`public_link_token`** (never returned in JSON) and **`public_share_url`**: **`…/shared-document.php?token=<64-hex>`** for read-only markdown rendering (**comments stay private**).

| Field | Create | Update (`POST /api/update-document.php`) | Returned by API |
| ----- | ------ | ----------------------------------------- | ----------------- |
| `public_link_enabled` | Optional boolean (`false` default). | Toggle public access. Omit with `rotate_public_link` only to keep prior setting. | Boolean — effective state (requires valid token internally). |
| `rotate_public_link` | — | Optional boolean. When **`true`** and sharing stays **on**, issues a new token (old URLs stop working). | — |
| `public_share_url` | — | — | Full URL while sharing is effectively active; **`null`** when off. **`public_link_token` is never included.** |

Stable origin for `public_share_url`: set **`TASKS_APP_BASE_URL`** (recommended for production).

---

## SDK

The Python `tasks_sdk` and related clients may lag this document; validate edge cases against this file or the PHP entrypoint.
