#!/usr/bin/env python3
"""
Design smoke: Tasks admin shell + login at mobile and desktop widths.
Requires: pip install playwright && playwright install chromium
Run from repo root after starting PHP locally, e.g.:
  cd public && php -S 127.0.0.1:8877
  ADMIN_BASE_URL=http://127.0.0.1:8877 python3 tools/design-smoke/admin_shell.py
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
VIEWPORTS = [
    ("mobile", {"width": 390, "height": 844}),
    ("desktop", {"width": 1280, "height": 800}),
]


def main() -> int:
    base = os.environ.get("ADMIN_BASE_URL", "http://127.0.0.1:8877").rstrip("/")
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("Install: pip install playwright && playwright install chromium", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    paths = [
        ("/admin/login.php", "login"),
        ("/admin/", "admin_index_redirect_or_login"),
    ]

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        for name, size in VIEWPORTS:
            context = browser.new_context(viewport=size)
            page = context.new_page()
            for path, label in paths:
                url = f"{base}{path}"
                try:
                    page.goto(url, wait_until="networkidle", timeout=60000)
                except Exception as e:
                    print(f"WARN {url}: {e}", file=sys.stderr)
                    page.goto(url, wait_until="domcontentloaded", timeout=60000)
                shot = OUT / f"{label}_{name}_{size['width']}x{size['height']}.png"
                page.screenshot(path=str(shot), full_page=True)
                print(shot)
            context.close()
        browser.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
