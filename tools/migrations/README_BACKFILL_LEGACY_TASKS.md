# Backfill legacy `tasks.project` → directory `project_id`

Production often has rows where **`tasks.project`** is a legacy namespace (e.g. `invoicing`) but **`tasks.project_id`** is still **NULL**. That breaks listing and sync expectations once every new task must belong to a directory project.

## Safety

1. **Backup the SQLite file** (`TASKS_DB_PATH`) before running anything mutating.
2. Prefer **`link`** with **creator-org scoping** (default): only tasks whose **creator** is in the **same org** as the target directory project are updated.
3. Use **`--force-cross-org`** only if you understand cross-org data (rare).

## Tool

From the repo root on the server (or anywhere with `TASKS_DB_PATH` set):

```bash
export TASKS_DB_PATH=/path/to/tasks.db

# 1) Match legacy text to a directory project *name* in the creator's org (case-insensitive)
php tools/backfill_legacy_tasks.php directory-names

# 2) Map known legacy labels to a specific directory project (e.g. invoice bucket)
php tools/backfill_legacy_tasks.php link \
  --project-id=YOUR_DIRECTORY_PROJECT_ID \
  --labels=invoicing,invoice,invoices

# Or resolve project by name + org:
php tools/backfill_legacy_tasks.php link \
  --project-name=Invoicing \
  --org-id=1 \
  --labels=invoicing,invoice,invoices
```

Output is JSON with `"updated"` row counts. Running twice is **idempotent** (second run updates `0` rows unless new orphans appeared).

## PHP helpers

- `backfillTaskProjectIdsFromLegacyNames()` — same logic as `directory-names`; **case-insensitive** name match.
- `backfillLegacyTasksToDirectoryProject($projectId, $labels, $ignoreOrgMismatch)` — same logic as `link`.
