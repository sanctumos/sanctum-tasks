# Sanctum Tasks – Python API (agentic-first mirror)

This directory contains a **feature-for-feature mirror** of the Sanctum Tasks PHP API. Same endpoint paths, request/response shapes, auth (API key + session), and rate limiting. It uses the **same SQLite database** as the PHP app, so you can run PHP, Python, or both against one database.

## Running the Python API

From the repository root:

```bash
# Install dependencies (from repo root)
pip install -r api_python/requirements.txt

# Optional: set database path (default: db/tasks.db)
set TASKS_DB_PATH=db/tasks.db

# Run the server
uvicorn api_python.main:app --host 127.0.0.1 --port 8000
```

Then use the same API base URL as PHP, e.g. `http://127.0.0.1:8000/api/`. The existing [tasks_sdk](https://github.com/your-org/sanctum-tasks/tree/main/tasks_sdk) and any client that uses `X-API-Key` or `Authorization: Bearer` work by changing only `base_url`.

## Environment variables (mirror of PHP)

| Variable | Default | Description |
|----------|---------|-------------|
| `TASKS_DB_PATH` | `db/tasks.db` | SQLite database path (shared with PHP if desired) |
| `TASKS_DB_TIMEOUT` | 30 | DB connection timeout (seconds) |
| `TASKS_API_RATE_LIMIT_REQUESTS` | 240 | Max requests per window |
| `TASKS_API_RATE_LIMIT_WINDOW_SECONDS` | 60 | Rate limit window (seconds) |
| `TASKS_BOOTSTRAP_ADMIN_USERNAME` | `admin` | Bootstrap admin username |
| `TASKS_BOOTSTRAP_ADMIN_PASSWORD` | (env or file) | Bootstrap admin password; or read from `db/bootstrap_admin_password.txt` |
| `TASKS_BOOTSTRAP_API_KEY` | (env or file) | Bootstrap API key; or read from `db/api_key.txt` |
| `TASKS_PASSWORD_COST` | 12 | bcrypt cost for password hashing |
| `TASKS_PASSWORD_MIN_LENGTH` | 12 | Min password length for validation |
| `TASKS_SESSION_LIFETIME` | 3600 | Session lifetime (seconds) for session-login |
| `TASKS_SESSION_NAME` | `sanctum_tasks` | Session cookie name base |
| `TASKS_SESSION_COOKIE_SECURE` | true | Set secure flag on session cookie |
| `TASKS_LOGIN_LOCK_THRESHOLD` | 5 | Failed attempts before lockout |
| `TASKS_LOGIN_LOCK_WINDOW_SECONDS` | 900 | Window for counting failed attempts |
| `TASKS_LOGIN_LOCK_SECONDS` | 900 | Lockout duration (seconds) |
| `TASKS_APP_BASE_URL` | (none) | Optional base URL for pagination links |

## TUI (peek at tasks)

A small CLI uses the API to list and view tasks (read-only):

```bash
# From repo root; ensure API is running (e.g. uvicorn on port 8000)
set TASKS_API_KEY=<your-api-key>
set TASKS_API_BASE_URL=http://127.0.0.1:8000   # optional; default 8000

python -m api_python.cli list
python -m api_python.cli list --status todo --project MyProject -q "bug"
python -m api_python.cli view 123
```

## Endpoint reference

Same paths and behavior as the PHP API. See the main project docs:

- **[docs/api.md](../docs/api.md)** – Full endpoint reference, request/response shapes, pagination, and error format.

## Layout

- `main.py` – FastAPI app; routes under `/api/*.php` for SDK compatibility
- `config.py` – Env-based config
- `db.py` – SQLite connection and idempotent schema bootstrap (mirrors PHP)
- `auth.py` – API key validation and rate limiting
- `response.py` – Success/error JSON shape
- `session.py` – Session create/validate/destroy for session-login, session-me, session-logout
- `logic/` – Business logic mirroring PHP (tasks, users, statuses, comments, attachments, watchers, API keys, audit)
- `cli/` – TUI: `python -m api_python.cli list | view <id>`
