import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Optional

import pytest
import requests


REPO_ROOT = Path(__file__).resolve().parents[1]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))


def _find_php_binary() -> Optional[str]:
    for candidate in ("php", "php8.3", "php8.2", "php8.1"):
        path = shutil.which(candidate)
        if path:
            return path
    return None


def _pick_free_tcp_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


@dataclass
class PhpServerContext:
    process: subprocess.Popen
    base_url: str
    api_key: str
    admin_username: str
    admin_password: str
    db_path: str
    temp_dir: str

    def stop(self) -> None:
        if self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=5)
        shutil.rmtree(self.temp_dir, ignore_errors=True)


def _start_php_server(env_overrides: Dict[str, str]) -> PhpServerContext:
    php_bin = _find_php_binary()
    if not php_bin:
        pytest.skip("PHP binary not available in test environment")

    tmp_dir = tempfile.mkdtemp(prefix="tasks-php-tests-")
    db_dir = Path(tmp_dir) / "db"
    db_dir.mkdir(parents=True, exist_ok=True)
    db_path = db_dir / "tasks_test.db"

    admin_username = env_overrides.get("TASKS_BOOTSTRAP_ADMIN_USERNAME", "admin")
    admin_password = env_overrides.get("TASKS_BOOTSTRAP_ADMIN_PASSWORD", "AdminPass123!")
    api_key = env_overrides.get("TASKS_BOOTSTRAP_API_KEY", "b" * 64)

    env = os.environ.copy()
    env.update(
        {
            "TASKS_DB_PATH": str(db_path),
            "TASKS_BOOTSTRAP_ADMIN_USERNAME": admin_username,
            "TASKS_BOOTSTRAP_ADMIN_PASSWORD": admin_password,
            "TASKS_BOOTSTRAP_API_KEY": api_key,
            "TASKS_PASSWORD_COST": "8",
            "TASKS_APP_DEBUG": "1",
            "TASKS_SESSION_COOKIE_SECURE": "0",
            "TASKS_API_RATE_LIMIT_REQUESTS": "10000",
            "TASKS_API_RATE_LIMIT_WINDOW_SECONDS": "60",
            "TASKS_LOGIN_LOCK_THRESHOLD": "50",
            "TASKS_LOGIN_LOCK_WINDOW_SECONDS": "900",
            "TASKS_LOGIN_LOCK_SECONDS": "900",
        }
    )
    env.update(env_overrides)

    port = _pick_free_tcp_port()
    base_url = f"http://127.0.0.1:{port}"
    process = subprocess.Popen(
        [php_bin, "-S", f"127.0.0.1:{port}", "-t", str(REPO_ROOT / "public")],
        cwd=str(REPO_ROOT),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    start = time.time()
    last_error: Optional[Exception] = None
    while time.time() - start < 12:
        if process.poll() is not None:
            break
        try:
            resp = requests.get(f"{base_url}/api/health.php", timeout=1.0)
            if resp.status_code in (200, 401):
                return PhpServerContext(
                    process=process,
                    base_url=base_url,
                    api_key=api_key,
                    admin_username=admin_username,
                    admin_password=admin_password,
                    db_path=str(db_path),
                    temp_dir=tmp_dir,
                )
        except Exception as exc:  # noqa: BLE001
            last_error = exc
        time.sleep(0.15)

    if process.poll() is None:
        process.terminate()
        process.wait(timeout=5)
    shutil.rmtree(tmp_dir, ignore_errors=True)
    raise RuntimeError(f"Failed to start PHP server for tests: {last_error}")


@pytest.fixture(scope="session")
def php_server() -> PhpServerContext:
    ctx = _start_php_server(
        {
            "TASKS_BOOTSTRAP_ADMIN_USERNAME": "admin",
            "TASKS_BOOTSTRAP_ADMIN_PASSWORD": "AdminPass123!",
            "TASKS_BOOTSTRAP_API_KEY": "a" * 64,
            "TASKS_LOGIN_LOCK_THRESHOLD": "50",
        }
    )
    try:
        yield ctx
    finally:
        ctx.stop()


@pytest.fixture(scope="function")
def php_lockout_server() -> PhpServerContext:
    ctx = _start_php_server(
        {
            "TASKS_BOOTSTRAP_ADMIN_USERNAME": "admin",
            "TASKS_BOOTSTRAP_ADMIN_PASSWORD": "AdminPass123!",
            "TASKS_BOOTSTRAP_API_KEY": "c" * 64,
            "TASKS_LOGIN_LOCK_THRESHOLD": "2",
            "TASKS_LOGIN_LOCK_WINDOW_SECONDS": "600",
            "TASKS_LOGIN_LOCK_SECONDS": "120",
        }
    )
    try:
        yield ctx
    finally:
        ctx.stop()


@pytest.fixture()
def api_headers(php_server: PhpServerContext) -> Dict[str, str]:
    return {
        "X-API-Key": php_server.api_key,
        "Content-Type": "application/json",
    }
