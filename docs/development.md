# Development / Operations Guide

This document covers local development, schema migrations, testing/CI, and backup/restore for `tasks.technonomicon.net`.

## Local development

## Prerequisites

- PHP 8.1+ with SQLite3 extension
- Python 3.8+ (for SDK/plugin work)

## Start locally

```bash
# optional: app secrets (do not commit)
cp public/includes/secrets.php.example public/includes/secrets.php

# run web app
php -S 127.0.0.1:8080 -t public
```

Open:

- Home: `http://127.0.0.1:8080/`
- Admin: `http://127.0.0.1:8080/admin/`

## Database schema and migrations

Schema is managed in `public/includes/config.php` inside `initializeDatabase()`.

Migration strategy:

1. `CREATE TABLE IF NOT EXISTS` for new tables
2. `ensureColumnExists()` for additive table evolution
3. `ensureIndexExists()` for additive indexes
4. idempotent seed inserts for defaults (e.g. `task_statuses`)

This means app startup applies safe, additive migrations automatically.

### Current core tables

- `users`
- `api_keys`
- `task_statuses`
- `tasks`
- `task_comments`
- `task_attachments`
- `task_watchers`
- `audit_logs`
- `login_attempts`
- `api_rate_limits`

## Testing and CI

Repository CI workflow (`.github/workflows/ci.yml`) runs:

- PHP syntax lint over `public/**/*.php`
- Python checks for SDK/plugin import and syntax

Run equivalent checks locally:

```bash
# PHP lint
for f in $(rg --files public -g "*.php"); do php -l "$f"; done

# Python syntax/import checks
python -m py_compile tasks_sdk/*.py smcp_plugin/tasks/*.py
python - <<'PY'
from tasks_sdk import TasksClient
print("SDK import OK:", TasksClient.__name__)
PY
```

## Backup and restore (SQLite)

Database file path defaults to `db/tasks.db`.

## Backup

Recommended periodic backup:

```bash
sqlite3 db/tasks.db ".backup db/backups/tasks-$(date +%Y%m%d-%H%M%S).db"
```

or simple copy when app is quiet:

```bash
cp db/tasks.db db/backups/tasks-$(date +%Y%m%d-%H%M%S).db
```

## Restore

1. Stop traffic/app process
2. Replace `db/tasks.db` with selected backup file
3. Restart app
4. Validate with `/api/health.php` and admin UI

## Secrets management

Do not commit credentials or keys.

Use either:

- environment variables (`TASKS_*`), or
- untracked `public/includes/secrets.php`

See `public/includes/secrets.php.example` for supported values.
