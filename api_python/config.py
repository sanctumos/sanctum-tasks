"""
Sanctum Tasks Python API - configuration from environment (mirror of PHP config).
"""
import os
from pathlib import Path


def _env(name: str, default: str | int | bool | None = None) -> str | int | bool | None:
    v = os.environ.get(name)
    if v is None or v == "":
        return default
    return v


def _env_int(name: str, default: int) -> int:
    v = _env(name, default)
    if isinstance(v, int):
        return v
    try:
        return int(v)
    except (TypeError, ValueError):
        return default


def _env_bool(name: str, default: bool) -> bool:
    v = _env(name, "1" if default else "0")
    if isinstance(v, bool):
        return v
    s = str(v).strip().lower()
    if s in ("1", "true", "yes", "on"):
        return True
    if s in ("0", "false", "no", "off", ""):
        return False
    return default


# Database
def _default_db_path() -> str:
    base = Path(__file__).resolve().parent.parent
    return str(base / "db" / "tasks.db")


DB_PATH: str = _env("TASKS_DB_PATH") or _default_db_path()
DB_TIMEOUT: int = _env_int("TASKS_DB_TIMEOUT", 30)

# Rate limiting (mirror PHP)
API_RATE_LIMIT_REQUESTS: int = _env_int("TASKS_API_RATE_LIMIT_REQUESTS", 240)
API_RATE_LIMIT_WINDOW_SECONDS: int = _env_int("TASKS_API_RATE_LIMIT_WINDOW_SECONDS", 60)

# Bootstrap (same env as PHP)
def get_bootstrap_admin_username() -> str:
    return str(_env("TASKS_BOOTSTRAP_ADMIN_USERNAME", "admin"))


def get_bootstrap_admin_password() -> str:
    configured = str(_env("TASKS_BOOTSTRAP_ADMIN_PASSWORD", "")).strip()
    if configured:
        return configured
    db_dir = Path(DB_PATH).parent
    secret_file = db_dir / "bootstrap_admin_password.txt"
    if secret_file.is_file():
        existing = secret_file.read_text().strip()
        if existing:
            return existing
    secret_file.parent.mkdir(parents=True, exist_ok=True)
    import secrets
    token = secrets.token_hex(24)
    secret_file.write_text(token)
    try:
        secret_file.chmod(0o600)
    except OSError:
        pass
    return token


def get_bootstrap_api_key() -> str:
    configured = str(_env("TASKS_BOOTSTRAP_API_KEY", "")).strip()
    if configured:
        return configured
    db_dir = Path(DB_PATH).parent
    secret_file = db_dir / "api_key.txt"
    if secret_file.is_file():
        existing = secret_file.read_text().strip()
        if existing:
            return existing
    secret_file.parent.mkdir(parents=True, exist_ok=True)
    import secrets
    token = secrets.token_hex(32)
    secret_file.write_text(token)
    try:
        secret_file.chmod(0o600)
    except OSError:
        pass
    return token


# Password hashing (match PHP PASSWORD_COST)
PASSWORD_COST: int = _env_int("TASKS_PASSWORD_COST", 12)
PASSWORD_MIN_LENGTH: int = _env_int("TASKS_PASSWORD_MIN_LENGTH", 12)

# Session (for api_sessions table)
SESSION_NAME: str = str(_env("TASKS_SESSION_NAME", "sanctum_tasks"))
SESSION_LIFETIME_SECONDS: int = _env_int("TASKS_SESSION_LIFETIME", 3600)
SESSION_COOKIE_SECURE: bool = _env_bool("TASKS_SESSION_COOKIE_SECURE", True)

# Login lockout (mirror PHP)
LOGIN_LOCK_THRESHOLD: int = _env_int("TASKS_LOGIN_LOCK_THRESHOLD", 5)
LOGIN_LOCK_WINDOW_SECONDS: int = _env_int("TASKS_LOGIN_LOCK_WINDOW_SECONDS", 900)
LOGIN_LOCK_SECONDS: int = _env_int("TASKS_LOGIN_LOCK_SECONDS", 900)

# App base URL for pagination links (optional)
def get_app_base_url() -> str | None:
    v = str(_env("TASKS_APP_BASE_URL", "")).strip()
    return v or None
