"""
Response helpers mirroring PHP api_auth.php: apiSuccess, apiError, same JSON shape.
"""
from typing import Any

from fastapi import Request
from fastapi.responses import JSONResponse


def api_error(
    code: str,
    message: str,
    status_code: int = 400,
    details: dict | None = None,
    extra: dict | None = None,
) -> tuple[dict, int]:
    """Return (payload, status_code) for error response. Legacy 'error' + error_object."""
    payload: dict = {
        "success": False,
        "error": message,
        "error_object": {
            "code": code,
            "message": message,
            "details": details or {},
        },
    }
    if extra:
        payload.update(extra)
    return payload, status_code


def api_success(
    payload: dict | None = None,
    meta: dict | None = None,
    status_code: int = 200,
) -> tuple[dict, int]:
    """Return (payload, status_code) for success. data = copy of payload; optional meta."""
    payload = payload or {}
    response = {"success": True, **payload, "data": payload}
    if meta:
        response["meta"] = meta
    return response, status_code


def _rate_limit_headers(request: Request) -> dict:
    """Get X-RateLimit-* headers from request.state.rate_limit if set."""
    rate = getattr(request.state, "rate_limit", None)
    if not rate:
        return {}
    return {
        "X-RateLimit-Limit": str(int(rate.get("limit", 0))),
        "X-RateLimit-Remaining": str(int(rate.get("remaining", 0))),
        "X-RateLimit-Reset": str(int(rate.get("reset_epoch", 0))),
    }


def json_success(request: Request, payload: dict, meta: dict | None = None, status_code: int = 200) -> JSONResponse:
    """Return JSONResponse with success payload and rate limit headers."""
    body, _ = api_success(payload, meta, status_code)
    headers = _rate_limit_headers(request)
    return JSONResponse(content=body, status_code=status_code, headers=headers)


def json_error(
    code: str,
    message: str,
    status_code: int = 400,
    details: dict | None = None,
    extra: dict | None = None,
) -> JSONResponse:
    """Return JSONResponse for error (no rate limit headers)."""
    body, sc = api_error(code, message, status_code, details, extra)
    return JSONResponse(content=body, status_code=sc)


def pagination_meta(
    request: Request,
    path: str,
    base_query_params: dict,
    limit: int,
    offset: int,
    total: int,
) -> dict:
    """Mirror PHP paginationMeta: next_url, prev_url, limit, offset, total, next_offset, prev_offset."""
    from urllib.parse import urlencode

    base_url = str(request.base_url).rstrip("/")
    next_offset = (offset + limit) if (offset + limit < total) else None
    prev_offset = (offset - limit) if (offset - limit >= 0) else None
    next_url = None
    if next_offset is not None:
        q = dict(base_query_params, limit=limit, offset=next_offset)
        next_url = f"{base_url}{path}?{urlencode(q)}"
    prev_url = None
    if prev_offset is not None:
        q = dict(base_query_params, limit=limit, offset=prev_offset)
        prev_url = f"{base_url}{path}?{urlencode(q)}"
    return {
        "limit": limit,
        "offset": offset,
        "total": total,
        "next_offset": next_offset,
        "prev_offset": prev_offset,
        "next_url": next_url,
        "prev_url": prev_url,
    }
