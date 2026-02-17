"""Helper functions mirroring PHP functions.php."""
import re
import json
from datetime import datetime, timezone
from typing import Any


def truncate_string(value: str, max_len: int) -> str:
    if len(value) <= max_len:
        return value
    return value[:max_len]


def normalize_slug(value: str) -> str:
    value = value.lower().strip()
    value = re.sub(r"[^a-z0-9_-]+", "-", value)
    value = value.strip("-_")
    return truncate_string(value, 50)


def normalize_nullable_text(value: Any, max_len: int) -> str | None:
    if value is None:
        return None
    v = str(value).strip()
    if v == "":
        return None
    return truncate_string(v, max_len)


def normalize_priority(priority: str) -> str | None:
    allowed = ["low", "normal", "high", "urgent"]
    p = str(priority).lower().strip()
    if p == "":
        return None
    return p if p in allowed else None


def parse_datetime_or_null(value: Any) -> str | None:
    if value is None:
        return None
    s = str(value).strip()
    if s == "":
        return None
    try:
        # Accept ISO-like and common formats; normalize to UTC Y-m-d H:i:s
        if "T" in s:
            dt = datetime.fromisoformat(s.replace("Z", "+00:00"))
        else:
            dt = datetime.strptime(s[:19], "%Y-%m-%d %H:%M:%S")
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
        if dt.tzinfo:
            dt = dt.astimezone(timezone.utc)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except (ValueError, TypeError):
        return None


def normalize_tags(tags: Any) -> list[str]:
    if tags is None:
        return []
    if isinstance(tags, str):
        if not tags.strip():
            return []
        tags = [t.strip() for t in tags.split(",") if t.strip()]
    if not isinstance(tags, list):
        return []
    out: dict[str, str] = {}
    for tag in tags:
        t = str(tag).strip()
        if not t:
            continue
        t = truncate_string(t, 32)
        out[t.lower()] = t
        if len(out) >= 20:
            break
    return list(out.values())


def decode_tags_json(tags_json: str | None) -> list[str]:
    if not tags_json or not str(tags_json).strip():
        return []
    try:
        decoded = json.loads(tags_json)
        if isinstance(decoded, list):
            return normalize_tags(decoded)
        return []
    except (json.JSONDecodeError, TypeError):
        return []


def encode_tags_json(tags: list[str]) -> str | None:
    normalized = normalize_tags(tags)
    if not normalized:
        return None
    return json.dumps(normalized)


def normalize_task_title(title: Any) -> str | None:
    t = str(title).strip() if title is not None else ""
    if t == "":
        return None
    return truncate_string(t, 200)


def normalize_task_body(body: Any) -> str | None:
    if body is None:
        return None
    b = str(body).strip()
    if b == "":
        return None
    return truncate_string(b, 10000)


def normalize_task_project(project: Any) -> str | None:
    return normalize_nullable_text(project, 120)


def normalize_task_recurrence_rule(rrule: Any) -> str | None:
    return normalize_nullable_text(rrule, 255)


def now_utc() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def normalize_username(username: str) -> str:
    return str(username).lower().strip()


def validate_username(username: str, min_length: int = 3, max_length: int = 50) -> str | None:
    u = normalize_username(username)
    if not u:
        return "Username is required"
    if len(u) < min_length or len(u) > max_length:
        return "Username must be 3-50 characters and only contain letters, numbers, dot, underscore, or hyphen"
    if not re.match(r"^[a-zA-Z0-9._-]+$", u):
        return "Username must be 3-50 characters and only contain letters, numbers, dot, underscore, or hyphen"
    return None


def validate_password(password: str, min_length: int = 12) -> str | None:
    if len(password) < min_length:
        return f"Password must be at least {min_length} characters"
    if not re.search(r"[A-Z]", password) or not re.search(r"[a-z]", password) or not re.search(r"[0-9]", password):
        return "Password must contain uppercase, lowercase, and a number"
    return None


def normalize_role(role: str) -> str | None:
    allowed = ["admin", "manager", "member", "api"]
    r = str(role).lower().strip()
    return r if r in allowed else None


def generate_temporary_password(length: int = 16, min_length: int = 12) -> str:
    import random
    length = max(min_length, length)
    upper = "ABCDEFGHJKLMNPQRSTUVWXYZ"
    lower = "abcdefghijkmnopqrstuvwxyz"
    digits = "23456789"
    all_chars = upper + lower + digits
    chars = [
        random.choice(upper),
        random.choice(lower),
        random.choice(digits),
    ]
    while len(chars) < length:
        chars.append(random.choice(all_chars))
    random.shuffle(chars)
    return "".join(chars)
