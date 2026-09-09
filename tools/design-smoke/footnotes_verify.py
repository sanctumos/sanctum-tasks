#!/usr/bin/env python3
"""
Design verification for Markdown footnote rendering (ParsedownExtra via
ParsedownTasks). Spins up the PHP app on an ephemeral port, seeds a
document that uses [^id] footnote refs + definitions (same syntax as
Tasks doc #1286), then screenshots the doc page at desktop + mobile.

Run from repo root with the .venv-ci venv (has Playwright):

    .venv-ci/bin/python tools/design-smoke/footnotes_verify.py

Output: tools/design-smoke/output/footnotes_*.png
"""
from __future__ import annotations

import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path

import requests

OUT_DIR = Path(__file__).resolve().parent / "output"
DESKTOP = {"width": 1440, "height": 900}
MOBILE = {"width": 390, "height": 844}

FOOTNOTE_DOC = """# Footnote rendering check

Body text cites a source inline.[^berg2026] A second citation follows.[^googleblog]
And a repeat reference to the first source.[^berg2026]

## Discussion

Regular **markdown** still works: lists, `code`, [links](https://example.com).

- one
- two

## Sources

[^berg2026]: Berg et al. "Sexual dimorphism in the complete *Drosophila* male
    central nervous system connectome." *Cell* 189(18), 3 Sept 2026.
    https://doi.org/10.1016/j.cell.2026.08.015

[^googleblog]: Januszewski and Jain. "A connectomics milestone." Google
    Research blog, 3 Sept 2026.
    https://research.google/blog/a-connectomics-milestone-mapping-the-complete-male-fruit-fly-brain/
"""


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return int(s.getsockname()[1])


def main() -> int:
    repo_root = Path(__file__).resolve().parents[2]
    public = repo_root / "public"
    php = shutil.which("php")
    if not php:
        print("PHP not found", file=sys.stderr)
        return 1

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("playwright not installed in this python", file=sys.stderr)
        return 1

    OUT_DIR.mkdir(parents=True, exist_ok=True)

    tmp = Path(tempfile.mkdtemp(prefix="tasks-footnotes-design-"))
    db_dir = tmp / "db"
    db_dir.mkdir()
    db_path = db_dir / "tasks.db"

    api_key = "a" * 64
    admin_user = "admin"
    admin_pass = "AdminPass123!"

    env = os.environ.copy()
    env.update({
        "TASKS_DB_PATH": str(db_path),
        "TASKS_BOOTSTRAP_ADMIN_USERNAME": admin_user,
        "TASKS_BOOTSTRAP_ADMIN_PASSWORD": admin_pass,
        "TASKS_BOOTSTRAP_API_KEY": api_key,
        "TASKS_PASSWORD_COST": "8",
        "TASKS_SESSION_COOKIE_SECURE": "0",
        "TASKS_API_RATE_LIMIT_REQUESTS": "10000",
    })

    port = free_port()
    base = f"http://127.0.0.1:{port}"
    proc = subprocess.Popen(
        [php, "-S", f"127.0.0.1:{port}", "-t", str(public)],
        cwd=str(repo_root), env=env,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )

    try:
        for _ in range(60):
            try:
                r = requests.get(f"{base}/api/health.php", timeout=1.0)
                if r.status_code in (200, 401):
                    break
            except Exception:
                pass
            time.sleep(0.2)
        else:
            print("PHP server did not come up", file=sys.stderr)
            return 1

        h = {"X-API-Key": api_key, "Content-Type": "application/json"}

        r = requests.post(f"{base}/api/create-directory-project.php", headers=h,
                          json={"name": "Theory", "all_access": True})
        r.raise_for_status()
        proj_id = int(r.json()["data"]["project"]["id"])

        r = requests.post(f"{base}/api/create-document.php", headers=h,
                          json={"project_id": proj_id,
                                "title": "Footnote rendering check",
                                "body": FOOTNOTE_DOC})
        r.raise_for_status()
        doc_id = int(r.json()["document"]["id"])

        # Clear must_change_password so each viewport can log in cleanly.
        import sqlite3
        with sqlite3.connect(str(db_path)) as conn:
            conn.execute("UPDATE users SET must_change_password = 0 WHERE username = ?", (admin_user,))

        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            for label, vp in (("desktop", DESKTOP), ("mobile", MOBILE)):
                ctx = browser.new_context(viewport=vp)
                page = ctx.new_page()
                page.goto(f"{base}/admin/login.php", wait_until="networkidle", timeout=60_000)
                page.fill('input[name="username"]', admin_user)
                page.fill('input[name="password"]', admin_pass)
                with page.expect_navigation(wait_until="networkidle", timeout=30_000):
                    page.press('input[name="password"]', "Enter")

                page.goto(f"{base}/admin/doc.php?id={doc_id}", wait_until="networkidle", timeout=30_000)
                time.sleep(0.4)

                # Functional assertions on the rendered page.
                sup_count = page.locator('.doc-body sup[id^="fnref"]').count()
                fn_items = page.locator('.doc-body .footnotes ol li').count()
                backrefs = page.locator('.doc-body .footnote-backref').count()
                print(f"[{label}] sup refs={sup_count} footnote items={fn_items} backrefs={backrefs}")
                assert sup_count == 3, f"[{label}] expected 3 inline refs, got {sup_count}"
                assert fn_items == 2, f"[{label}] expected 2 footnote list items, got {fn_items}"
                assert backrefs >= 2, f"[{label}] expected backrefs, got {backrefs}"

                # Raw markdown markers must not leak through.
                body_text = page.locator('.doc-body').inner_text()
                assert '[^berg2026]' not in body_text, f"[{label}] raw footnote marker leaked"
                assert '[^googleblog]:' not in body_text, f"[{label}] raw footnote definition leaked"

                page.screenshot(path=str(OUT_DIR / f"footnotes_doc_{label}.png"), full_page=True)

                # Click the first footnote ref to exercise the jump link.
                page.locator('.doc-body sup[id^="fnref"] a').first.click()
                page.wait_for_timeout(300)
                page.screenshot(path=str(OUT_DIR / f"footnotes_jumped_{label}.png"))

                ctx.close()
            browser.close()

        print(f"Screenshots written to {OUT_DIR}")
        return 0
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
