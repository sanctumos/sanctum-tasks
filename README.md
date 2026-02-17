# Sanctum Tasks

![Sanctum Tasks logo](docs/logo.png)

Sanctum Tasks is an API-first task management system with a Bootstrap admin UI, API keys, role-labeled user management, and automation-friendly SDK/plugin integrations.

This repository is a fork of a prior task management application. The codebase was a full tasks app before the fork; this repo continues that application under the Sanctum project.

## Licensing

- **All code** in this repository is licensed under the **GNU Affero General Public License v3.0 (AGPL-3.0)**. See [LICENSE](LICENSE) for the full text.
- **All other content** (documentation, images, and any non-code material) is licensed under **Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)**. See [LICENSE-DOCS](LICENSE-DOCS) for details and links to the full license.

## What the app does (user-facing)

### Core workflows

1. **Admin login**
   - Sign in at `/admin/login.php`
   - Supports optional MFA (TOTP) and account lockout after repeated failures
   - Users can be required to rotate password on first login/reset

2. **Task operations**
   - Create, update, view, and delete tasks from `/admin/`
   - Filter/search/sort by status, assignee, priority, project, due date, and free-text query
   - Task model includes richer metadata (see below)

3. **User and access management**
   - `/admin/users.php` supports create/disable/reset-password
   - `/admin/api-keys.php` supports API key lifecycle for integrations
   - `/admin/mfa.php` supports per-user TOTP MFA setup/disable

4. **Automation + integrations**
   - HTTP JSON API under `/api/*`
   - Python SDK under `tasks_sdk/`
   - SMCP plugin under `smcp_plugin/tasks/`

## Role model

| Role | Purpose | Typical permissions (current implementation) |
|---|---|---|
| `admin` | Full system administration | Manage users, keys, statuses, audits |
| `manager` | Operational management | Treated as admin-equivalent for most admin checks |
| `member` | Standard user | Authenticated task/API usage; not strict task ownership isolation |
| `api` | API-oriented service identity | API use via key, subject to endpoint-level auth checks |

Audit events are captured in `audit_logs` for security-relevant/admin operations.

Current authorization boundaries are coarse-grained: admin-sensitive routes enforce admin/manager checks, but there is not yet a comprehensive task-level ownership/ABAC policy across all task endpoints. Refer to [docs/security.md](docs/security.md) and [docs/api.md](docs/api.md) for endpoint behavior details.

## Task model

Task records support:

- Identity & ownership: `id`, `created_by_user_id`, `assigned_to_user_id`
- Content: `title`, `body`
- Workflow: `status` (customizable via `task_statuses`)
- Scheduling: `due_at`, `recurrence_rule`
- Prioritization: `priority`, `rank`
- Organization: `project`, `tags`
- Collaboration:
  - comments (`task_comments`)
  - attachments metadata (`task_attachments`)
  - watchers/subscribers (`task_watchers`)
- Timestamps: `created_at`, `updated_at`

## API overview

- Auth: `X-API-Key: <token>` or `Authorization: Bearer <token>`
- Rate limits: response headers (`X-RateLimit-*`) + `429` on limit exceeded
- Error schema:
  - legacy key: `error`
  - stable object: `error_object = { code, message, details }`
- Pagination metadata/links included on list/search endpoints

Full endpoint reference: [docs/api.md](docs/api.md)

## Repository structure

- `public/` - web-accessible PHP/UI/API code
- `api_python/` - **Python API mirror** (agentic-first): same endpoints as PHP, same DB; see [api_python/README.md](api_python/README.md)
- `db/` - SQLite database files and runtime secrets (ignored in git)
- `docs/` - architecture, API, integration, and operational docs
- `tasks_sdk/` - Python SDK
- `smcp_plugin/tasks/` - SMCP CLI plugin

## Running the Python API (agentic-first)

You can run the Python API instead of (or alongside) the PHP API, using the same database and API contract:

```bash
pip install -r api_python/requirements.txt
# Optional: TASKS_DB_PATH, TASKS_BOOTSTRAP_* (same as PHP)
uvicorn api_python.main:app --host 127.0.0.1 --port 8000
```

Use `http://127.0.0.1:8000` as `base_url` with the existing SDK or API key. A small **TUI** for humans to peek at tasks: set `TASKS_API_KEY` and run `python -m api_python.cli list` or `python -m api_python.cli view <id>`. See [api_python/README.md](api_python/README.md) for env vars and details.

## Local development quick start

```bash
# 1) Install PHP and Python dependencies as needed (system package manager)
# 2) Copy optional secret template (recommended)
cp public/includes/secrets.php.example public/includes/secrets.php

# 3) Start local PHP server from repo root
php -S 127.0.0.1:8080 -t public

# 4) Open admin UI
# http://127.0.0.1:8080/admin/login.php
```

Bootstrap credentials/key are created at first run:

- Admin username defaults to `admin` unless overridden by env
- Admin password is either:
  - `TASKS_BOOTSTRAP_ADMIN_PASSWORD`, or
  - generated and stored in `db/bootstrap_admin_password.txt`
- Bootstrap API key is either:
  - `TASKS_BOOTSTRAP_API_KEY`, or
  - generated and stored in `db/api_key.txt`
- Optional canonical app origin for absolute pagination links:
  - `TASKS_APP_BASE_URL` (for example `https://tasks.example.com`)

## Documentation index

- API: [docs/api.md](docs/api.md)
- Development, schema/migrations, backups, CI/testing: [docs/development.md](docs/development.md)
- Integration walkthrough (admin -> API key -> SDK/plugin): [docs/integrations.md](docs/integrations.md)
- Deployment structure: [docs/github-repository-setup-guide.md](docs/github-repository-setup-guide.md)
