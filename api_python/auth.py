"""
API key auth and rate limiting mirroring PHP api_auth.php and functions.php.
"""
import hashlib
import time
from typing import Any

import re
from fastapi import Request

from . import config
from . import db
from .response import api_error


def get_api_key_from_request(request: Request) -> str | None:
    """Mirror PHP getApiKeyFromRequest: X-API-Key or Authorization: Bearer <token>."""
    x = request.headers.get("X-API-Key")
    if x and str(x).strip():
        return str(x).strip()
    auth = request.headers.get("Authorization")
    if auth:
        m = re.match(r"Bearer\s+(.+)", auth, re.I)
        if m:
            return m.group(1).strip()
    return None


def check_api_rate_limit(api_key: str) -> dict:
    """Same fixed-window logic as PHP checkApiRateLimit. Returns rate_state dict."""
    max_requests = max(1, config.API_RATE_LIMIT_REQUESTS)
    window_seconds = max(1, config.API_RATE_LIMIT_WINDOW_SECONDS)
    key_hash = hashlib.sha256(api_key.encode()).hexdigest()
    window_start = int(time.time() // window_seconds) * window_seconds
    reset_epoch = window_start + window_seconds

    db.init_schema()
    conn = db.get_connection()
    try:
        # Cleanup old windows
        min_window = window_start - (window_seconds * 10)
        conn.execute("DELETE FROM api_rate_limits WHERE window_start < ?", (min_window,))

        cur = conn.execute(
            "SELECT request_count FROM api_rate_limits WHERE api_key_hash = ? AND window_start = ? LIMIT 1",
            (key_hash, window_start),
        )
        row = cur.fetchone()
        if not row:
            conn.execute(
                """INSERT INTO api_rate_limits (api_key_hash, window_start, request_count, last_request_at)
                   VALUES (?, ?, 1, datetime('now'))""",
                (key_hash, window_start),
            )
            conn.commit()
            return {
                "allowed": True,
                "limit": max_requests,
                "remaining": max(0, max_requests - 1),
                "reset_epoch": reset_epoch,
                "retry_after": 0,
            }

        count = int(row[0])
        if count >= max_requests:
            conn.close()
            return {
                "allowed": False,
                "limit": max_requests,
                "remaining": 0,
                "reset_epoch": reset_epoch,
                "retry_after": max(1, reset_epoch - int(time.time())),
            }

        conn.execute(
            """UPDATE api_rate_limits SET request_count = request_count + 1, last_request_at = datetime('now')
               WHERE api_key_hash = ? AND window_start = ?""",
            (key_hash, window_start),
        )
        conn.commit()
        return {
            "allowed": True,
            "limit": max_requests,
            "remaining": max(0, max_requests - (count + 1)),
            "reset_epoch": reset_epoch,
            "retry_after": 0,
        }
    finally:
        conn.close()


def validate_api_key_and_get_user(api_key: str) -> dict | None:
    """Look up by sha256(api_key) in api_keys.api_key_hash; return user row or None."""
    db.init_schema()
    key_hash = hashlib.sha256(api_key.encode()).hexdigest()
    conn = db.get_connection()
    try:
        cur = conn.execute(
            """SELECT ak.id AS api_key_id, ak.user_id AS user_id, u.username AS username, u.role AS role,
                      u.is_active AS is_active, u.must_change_password AS must_change_password,
                      u.mfa_enabled AS mfa_enabled, u.created_at AS created_at
               FROM api_keys ak JOIN users u ON u.id = ak.user_id
               WHERE ak.api_key_hash = ? AND ak.revoked_at IS NULL LIMIT 1""",
            (key_hash,),
        )
        row = cur.fetchone()
        if not row:
            return None
        d = dict(row)
        # API expects user.id (PHP returns id => user_id)
        d["id"] = d.get("user_id")
        return d
    finally:
        conn.close()


def is_admin_role(role: str) -> bool:
    return role in ("admin", "manager")


async def require_api_user(request: Request, require_admin: bool = False):
    """FastAPI dependency: require valid API key; optionally require admin/manager. Raises HTTPException."""
    from fastapi import HTTPException

    api_key = get_api_key_from_request(request)
    if not api_key:
        payload, status = api_error("auth.invalid_api_key", "Invalid or missing API key", 401)
        raise HTTPException(status_code=status, detail=payload)

    rate_state = check_api_rate_limit(api_key)
    # Attach to request state so route can set X-RateLimit-* headers
    request.state.rate_limit = rate_state
    if not rate_state.get("allowed"):
        retry_after = int(rate_state.get("retry_after", 1))
        payload, _ = api_error(
            "rate_limited",
            "Rate limit exceeded. Slow down and retry later.",
            429,
            {"retry_after": retry_after},
            {"rate_limit": rate_state},
        )
        raise HTTPException(status_code=429, detail=payload, headers={"Retry-After": str(retry_after)})

    user = validate_api_key_and_get_user(api_key)
    if not user:
        payload, status = api_error("auth.invalid_api_key", "Invalid or missing API key", 401)
        raise HTTPException(status_code=status, detail=payload)

    if require_admin and not is_admin_role(str(user.get("role", ""))):
        payload, status = api_error("auth.forbidden", "Admin role required", 403)
        raise HTTPException(status_code=status, detail=payload)

    return user


async def require_admin_user(request: Request):
    """Dependency that requires admin or manager role."""
    return await require_api_user(request, require_admin=True)
