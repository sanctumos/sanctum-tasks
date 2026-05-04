#!/usr/bin/env python3
"""
Admin walkthrough capture: log in, visit every admin screen,
save desktop screenshots for UX review.

Run from repo root after starting PHP locally:
  cd public && php -S 127.0.0.1:8877
  ADMIN_BASE_URL=http://127.0.0.1:8877 \
  ADMIN_USER=admin ADMIN_PASS=... \
  python3 tools/design-smoke/admin_walkthrough.py
"""
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

OUT = Path(__file__).resolve().parent / "output"
DESKTOP = {"width": 1440, "height": 900}
MOBILE = {"width": 390, "height": 844}


PAGES = [
    ("/admin/login.php", "01_login"),
    ("/admin/", "02_tasks_index_board"),
    ("/admin/?view=list", "03_tasks_index_list"),
    ("/admin/workspace-projects.php", "04_projects"),
    ("/admin/project.php?id=1", "05_project_tasks"),
    ("/admin/project.php?id=1&tab=lists", "06_project_lists"),
    ("/admin/project.php?id=1&tab=members", "07_project_members"),
    ("/admin/project.php?id=1&tab=settings", "08_project_settings"),
    ("/admin/users.php", "09_users"),
    ("/admin/settings.php", "10_settings_password"),
    ("/admin/settings.php?tab=mfa", "11_settings_mfa"),
    ("/admin/settings.php?tab=api-keys", "12_settings_api_keys"),
    ("/admin/settings.php?tab=audit", "13_settings_audit"),
    ("/admin/view.php?id=1", "14_task_view"),
    ("/", "15_root_landing"),
]


def main() -> int:
    base = os.environ.get("ADMIN_BASE_URL", "http://127.0.0.1:8877").rstrip("/")
    user = os.environ.get("ADMIN_USER", "admin")
    pwd = os.environ.get("ADMIN_PASS")
    if not pwd:
        print("Set ADMIN_PASS env var", file=sys.stderr)
        return 1

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("Install: pip install playwright && playwright install chromium", file=sys.stderr)
        return 1

    OUT.mkdir(parents=True, exist_ok=True)
    desktop_dir = OUT / "desktop"
    mobile_dir = OUT / "mobile"
    desktop_dir.mkdir(exist_ok=True)
    mobile_dir.mkdir(exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        for label, viewport, outdir in [
            ("desktop", DESKTOP, desktop_dir),
            ("mobile", MOBILE, mobile_dir),
        ]:
            context = browser.new_context(viewport=viewport)
            page = context.new_page()
            # Login
            page.goto(f"{base}/admin/login.php", wait_until="networkidle", timeout=60000)
            page.screenshot(path=str(outdir / "01_login.png"), full_page=True)
            page.fill('input[name="username"]', user)
            page.fill('input[name="password"]', pwd)
            page.click('button[type="submit"]')
            page.wait_for_load_state("networkidle", timeout=30000)
            for path, name in PAGES:
                if name == "01_login":
                    continue
                url = f"{base}{path}"
                try:
                    page.goto(url, wait_until="networkidle", timeout=60000)
                except Exception as e:
                    print(f"WARN goto {url}: {e}", file=sys.stderr)
                    page.goto(url, wait_until="domcontentloaded", timeout=60000)
                # let any layout settle
                time.sleep(0.4)
                shot = outdir / f"{name}.png"
                page.screenshot(path=str(shot), full_page=True)
                print(f"[{label}] {shot}")
            context.close()
        browser.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
