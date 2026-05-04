# Basecamp-shaped project management — domain-first plan (v2)

**Project:** Sanctum Tasks (`sanctumos/sanctum-tasks`)  
**Status:** Supersedes overlay-first sequencing in `BASECAMP3_UX_OVERLAY_PLAN.md` §6–7 for **new work** after the BC UI experiment rollback.  
**Date:** 2026-05-04  

---

## 1. What changed (teardown)

The **Basecamp-style UI experiment** (layout toggle, `bc3/` routes, `project_labels`, string-project directory helpers, related API churn) did not deliver Basecamp-level product semantics; it optimized the wrong layer. **`main` was reset** to the commit immediately before that work (`317d5cc`). The full experiment remains recoverable on branch **`backup/bc3-experiment-before-teardown-2026-05-02`** if anything should be cherry-picked.

**Rule going forward:** **Model accounts, clients, and projects as data first.** UI that mimics Basecamp comes **after** those entities exist and APIs expose them. No “shell without dock.”

---

## 2. Reference materials (your links, resolved)

These are the canonical destinations behind the Google Share redirects (fetchable with normal HTTP clients from this environment):

| Topic | Resolved URL |
|-------|----------------|
| Everhour — long-form BC3 guide | https://everhour.com/blog/basecamp-3-ultimate-guide/ |
| Unito — beginner’s guide | https://unito.io/blog/ultimate-beginners-guide-to-basecamp/ |
| Arcstone — best practices / tips | https://www.arcstone.com/new-to-basecamp-3/ |
| Official Basecamp Help | https://3.basecamp-help.com/ |

**Official help articles** to ground naming and workflows (non-exhaustive): [Projects](https://3.basecamp-help.com/article/25-projects), [Teams and HQ](https://3.basecamp-help.com/article/686-teams-and-hq), [Clients in projects](https://3.basecamp-help.com/article/689-clients-in-projects). Third-party posts are **secondary**; they reinforce onboarding patterns and vocabulary—they are not the source of truth for behavior.

---

## 3. Basecamp 3 — minimal conceptual stack (what we are borrowing)

Basecamp is not “tasks with a project tag.” At minimum it assumes:

1. **Account / company** — One workspace where billing, admins, and policies live.
2. **People** — Individuals with an account-wide role; importantly, **client** vs **team member** is a **user-level type** (not “client on one project only” in BC’s model—you cannot mix types per project without admin correction).
3. **Projects** — Named containers with description, lifecycle (active / archived / trashed), optional **client involvement**, **invite-only vs all-access**, and a **tool dock** (message board, chat, to-dos, schedule, files, etc.).
4. **Project directory** — Search and filters across projects (pinned, client, archived…).
5. **Home** — Assignments, schedule snippets, and navigation into projects—not a bare task table.

Sanctum Tasks today is **task-centric** with `tasks.project` as a **string**. That can stand in for “project name” in filters but **cannot** represent clients, membership, visibility, or archival without **first-class `projects` (and related) rows**.

---

## 4. Current Sanctum Tasks baseline (post-reset `main`)

Authoritative schema bootstrap: `public/includes/config.php` (`initializeDatabase`), mirrored in `api_python/db.py`.

**Present:** `users`, `api_keys`, `task_statuses`, `tasks` (including string `project`), `task_comments`, `task_attachments`, `task_watchers`, operational tables (`audit_logs`, rate limits, login attempts).

**Absent for BC-shaped PM:** organizations/companies, client companies, **projects table**, project membership, client visibility rules, multi-assignee, soft lifecycle on tasks, non-task collaboration surfaces (messages, chat), notifications stream.

See `BASECAMP3_UX_OVERLAY_PLAN.md` §4–5 for the detailed gap table; it remains valid as an analysis reference.

---

## 5. Target architecture — phases (domain first)

Phases are **ordered by dependency**. UI milestones come **after** Phase 2 minimum viable data exists unless explicitly scoped as read-only mockups.

### Phase 0 — Seed data & requirements lock

**Input:** Mark-supplied **seed data** (CSV/JSON/SQL sketch): organizations, clients, users (with member vs client), projects, and how tasks map to projects.

**Output (deliverable):**

- ERD sketch (Markdown + diagram optional): entities, FKs, uniqueness rules.
- Decision log: aligns with **§8** (resolved defaults); migration/backfill notes for `tasks.project` strings.
- Migration compatibility: how existing deployments upgrade without orphaning `tasks.project` strings.

**Exit criterion:** Signed-off schema sketch before Phase 1 DDL.

### Phase 1 — Organizations & people

Introduce **account boundary** appropriate to Sanctum (may map to a single `organizations` row for small installs):

- `organizations` (or `accounts`) — id, name, settings JSON.
- Extend **`users`**: link to org; **`person_kind`** enum `team_member` \| `client` (distinct from **`role`**, which stays admin/manager/member/api as today). Basecamp rule: one kind per person account-wide.
- **No** `client_companies` and **no** billing/invoice entities here — flat clients only; billing stays outside PM.

Idempotent migrations under `tools/migrations/` per repo conventions; SQLite `IF NOT EXISTS` / guarded `ALTER`.

### Phase 2 — Projects as entities

- **`projects`** — id, org_id, name, description, status (`active` | `archived` | `trashed`), flags for **client project** / **all-access** if we mirror BC semantics, timestamps.
- **`project_members`** — project_id, user_id, role (`lead` \| `member` \| `client`). Exact permission matrix TBD in Phase 3; start minimal (e.g. clients read-heavy unless elevated).
- **Task linkage:** `tasks.project_id` nullable FK; **backfill** from `tasks.project` string via normalized match; keep legacy column deprecated until removal in a later major.

API and Python mirror: CRUD projects, list directory with filters, assign task to `project_id`.

### Phase 3 — Clients & visibility (MVP)

- Enforce **who sees which project** using `project_members` + person_kind.
- “Client can see only client-enabled projects” — rules TBD from seed scenarios.
- Avoid building Basecamp **Correspondence** or approvals until core read/write paths are stable.

### Phase 4 — Dock tools (incremental)

Order suggests:

1. **To-dos / lists** — Already closest to current product: introduce **`todo_lists`** (per project), optional groups; tasks gain `list_id` or equivalent; matches BC hierarchy todoset → list → todo at a **simplified** depth.
2. **Schedule** — Aggregate `due_at` per user/project; optional `schedule_entries` table later if you need events without tasks.
3. **Message board / chat** — **Defer** unless scoped; highest complexity. Prefer linking “Doors” (external URLs) first.

### Phase 5 — Home & Basecamp-flavored UX

Only after Phases 1–3 API stable:

- **Home:** assignments-first, due soon, pinned projects (needs **`user_project_pins`** or prefs JSON).
- **Directory:** search/filter projects (Phase 2 data).
- **Jump / omnibar:** client-side index over API lists.

Follow workspace **design verification** (Playwright, screenshots) for any user-visible layout work.

---

## 6. API & SDK policy

- Every new PHP route gets **FastAPI mirror** and **`tasks_sdk`** method where applicable.
- Preserve backward compatibility: nullable FKs, deprecated string `project` until removal window documented in `CHANGELOG.md`.
- SMCP plugin parity for agent-facing operations Mark cares about.

---

## 7. Relationship to `BASECAMP3_UX_OVERLAY_PLAN.md`

That document’s **§2–5** (surface survey + gap analysis) and **§8 references** remain valuable. **§6 Milestones M1–M5** described **overlay-first** delivery; **do not follow that order** for net-new implementation. Use **this document’s phases** instead. When the domain plan reaches Phase 5, reuse overlay §6.3–6.4 for **visual/interaction and testing** guidance only.

---

## 8. Product decisions (locked) and implementation defaults

### 8.1 Locked by Mark

| Topic | Decision |
|-------|-----------|
| **Team vs client** | Use **`person_kind`** on `users` (`team_member` \| `client`), separate from **`role`**. |
| **Client shape** | **Flat clients only** — no nested “client company” org chart in this product. |
| **Billing** | **Not in scope** for Sanctum Tasks — no subscription/invoicing artifacts in schema. |
| **Everything else** | Engineering may apply judgment on detail (below); escalate only when trade-offs affect UX promises. |

### 8.2 Defaults (implementation judgment — revise if Mark contradicts)

| Topic | Default |
|-------|---------|
| **Tenancy** | One SQLite database file per deployment; **`organizations`** table + **`org_id`** on scoped rows. Single-org installs use one org row; multi-org later is additive without DB-per-tenant. |
| **Client login surface** | Same web app and session auth as today (**`/admin/`**) for MVP; a narrower **client portal** is optional later, not required for Phase 1–3. |
| **Phase 4 ordering** | After **to-do lists**: **schedule-style aggregation** (due dates / calendar views from existing fields) → **Doors** (external URLs on projects) → **message board / realtime chat** stays deferred unless scoped. |
| **`tasks.project` migration** | Add **`project_id`**; backfill by **normalized string match** against `projects.name`; unmatched strings stay on legacy column until fixed in admin or a small remap table/import pass. |
| **Visibility MVP** | **`person_kind` + `project_members`** + flags on **`projects`** (e.g. internal vs client-visible); refine when seed scenarios arrive. |

---

## 9. Next engineering action

**Policy:** §8 closed enough to draft DDL and APIs.

**Still useful from Mark:** **seed data** (or a thin fixture) so Phase 0 ERD matches real names and counts — not blocking judgment calls above.

Implement **Phase 1 migration + read/list APIs**, then **Phase 2 projects + `project_id`**, before any new BC-themed PHP layout ships on `main`.
