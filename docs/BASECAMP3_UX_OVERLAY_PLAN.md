# Basecamp 3–style optional UI overlay — research and plan

**Project:** Sanctum Tasks (`sanctumos/sanctum-tasks`)  
**Author:** Otto (planning doc; CC BY-SA 4.0 per repo `LICENSE-DOCS`)  
**Date:** 2026-05-02  

This document satisfies three requests: (1) inventory and sync state for Sanctum OS repositories in this workspace, (2) a structured survey of **Basecamp 3** product surfaces and API concepts, (3) a gap analysis between **Sanctum Tasks** data and that archetype, plus a phased plan for an **optional, user-toggleable UI/UX overlay** without abandoning the current bare-bones admin.

---

## 1. Workspace synchronization (Sanctum OS → local)

**Canonical task product repo:** `https://github.com/sanctumos/sanctum-tasks.git`

| Path | Role | Sync performed (2026-05-02) |
|------|------|------------------------------|
| `/root/projects/sanctum-tasks` | Primary working copy for this plan | `git fetch` + `git pull --ff-only origin main` — **already up to date** |
| `/root/projects/sanctumos/sanctum-tasks` | Mirror under `sanctumos/` tree | Same — **already up to date** |
| `/root/projects/sanctum/sanctum-tasks` | Additional mirror under `sanctum/` | Same — **already up to date** |
| `/root/projects/sanctumos/*` (broca, smcp, sanctum-router, etc.) | Separate git roots; not a monorepo | Example: `broca`, `sanctum-tasks` pulled — **up to date** |

**Note:** There is **no** single `.git` at `/root/projects/sanctumos`; the organization is represented as **many sibling repositories**. Keeping “sanctum” synchronized means periodically `git fetch` / `git pull` on each repo you care about, or a small manifest script (out of scope here unless requested).

**Related but distinct:** Broca (`sanctumos/broca`) uses SQLite `messages` / `queue` for agent traffic — not the same domain as task **work items**, though product UX might someday link “notifications” to Broca; this plan does not assume that coupling.

---

## 2. Basecamp 3 — screens and capabilities (product surface)

Sources: Basecamp marketing/features copy, Basecamp 3 Help Center articles (e.g. home screen, project tools intro), and the public **Basecamp API** documentation (`github.com/basecamp/bc3-api` README and section index). Basecamp’s UI naming and API naming align loosely (“Todos”, “Message Board”, etc.).

### 2.1 Account-level / cross-project

| Area | User-facing purpose |
|------|---------------------|
| **Home** | Pinned projects, stacks, recent projects, optional colors/logos; **Your Schedule** (mini calendar + link to full schedule); **Your Assignments** (up next, due soon, complete inline). |
| **Project directory** | List all projects with filters (active, pinned, client, all-access, archived, deleted) + search on title/description. |
| **Jump menu** | Quick navigation (⌘/Ctrl+J) across projects, people, pages. |
| **Hey! / notifications** | Aggregated notification stream (distinct from email). |
| **Pings** | Direct / small-group chat outside a project. |
| **Lineup** | Visual timeline across projects. |
| **Mission Control / Hill Charts / activity-style reports** | Portfolio-level visibility (progress, workload, “what’s actually happening”). |
| **My assignments** (API) | Consolidated assigned work. |
| **My notifications** (API) | Machine-facing counterpart to Hey! |
| **Search** (API) | Global search across recordings. |
| **People** | Directory of people; per-project membership. |
| **Templates** | Create projects from templates. |

### 2.2 Inside a project (“dock” tools)

The API models each project as having a **dock**: enabled tools such as:

| Tool (typical `name`) | Primary UX |
|----------------------|------------|
| `message_board` | Threaded announcements / discussions (**messages**). |
| `chat` (Campfire) | Real-time project chat. |
| `todoset` → **todolists** → **todos** | Hierarchical to-dos; groups optional (**todolist_groups**). |
| `kanban_board` | **Card tables**: columns, cards, steps. |
| `schedule` | Calendar **schedule entries**. |
| `questionnaire` | Automatic check-ins (**questions**, **answers**). |
| `vault` | **Docs & files** (documents, uploads, folders). |
| `inbox` | Email **forwards**. |
| **Doors** | Links out to external tools (Figma, Google Docs, etc.). |
| **Client visibility / approvals / correspondences** | Client-facing controls where enabled. |
| **Boosts**, **Drafts**, **Trash/archive** | Engagement, composition lifecycle, recovery. |
| **Hill charts**, **Gauges**, **Timesheets**, **Reports** | Progress and operational reporting. |
| **Webhooks**, **Events** | Integration and activity streams. |

This is intentionally broad: a “Basecamp 3 clone” is a **multi-surface collaboration suite**. Sanctum Tasks today is intentionally narrower (tasks + comments + attachments + watchers).

---

## 3. Basecamp API — data archetype (what “full BC3” implies)

From `bc3-api` “Key concepts”:

- **Account** contains **projects**; each project has a **bucket** ID equal to the project.
- **Recordings** unify most content types with `status` (`active` / `archived` / `trashed`), tree structure, visibility, subscriptions — dozens of **types** (`Todo`, `Message`, `Schedule::Entry`, etc.).
- **Dock** describes which tools exist and their URLs/IDs.
- **Todos** live under **todolists** under a **todoset**; completion is `completed`, distinct from recording `status`.
- **Assignees** are arrays of person IDs (`assignee_ids`).
- Rich text is **HTML** for many resources; comments attach to **recordings** broadly.

**Implication:** Replicating Basecamp’s *data* model inside SQLite is a **large normalization exercise** (polymorphic “recording” or typed tables per resource). The overlay UX can still be valuable if it maps **only** the subset Sanctum supports, while the schema evolves in phases.

---

## 4. Sanctum Tasks today — screens and capabilities

### 4.1 Admin UI (`public/admin/`)

| Screen | File | Capability |
|--------|------|------------|
| Task list + filters | `index.php` | Filter by status, assignee, priority, project, search; sort; link to CRUD. |
| Task detail | `view.php` | Single task view. |
| Create / update / delete | `create.php`, `update.php`, `delete.php` | Standard CRUD. |
| Auth | `login.php`, `logout.php`, `change-password.php`, `mfa.php` | Session auth, MFA. |
| Users | `users.php` | User lifecycle. |
| API keys | `api-keys.php` | Integration keys. |

**Public home:** `public/index.php` (marketing / entry).  

**Styling:** Bootstrap 5, dark navbar — **no** user-selectable theme or layout mode today.

### 4.2 Data model (SQLite) — summary

Authoritative schema: `public/includes/config.php` (`initializeDatabase`), mirrored in `api_python/db.py`.

| Entity | Purpose |
|--------|---------|
| `users`, `api_keys` | Auth and automation. |
| `task_statuses` | Customizable workflow slugs + `is_done`. |
| `tasks` | Core work item: title, body, status, due, priority, **project (string)**, tags JSON, rank, recurrence, assignee, creator, timestamps. |
| `task_comments` | Threaded discussion **per task** (not generic polymorphic). |
| `task_attachments` | Metadata + URL (not blob storage in DB). |
| `task_watchers` | Subscribers. |
| `audit_logs`, `login_attempts`, `api_rate_limits` | Ops/security. |

**API:** Documented in `docs/api.md` — list/get/create/update/delete, bulk, statuses, comments, attachments, watchers, taxonomy helpers (`list-projects`, `list-tags`), users, keys, session, audit.

---

## 5. Gap analysis — Basecamp 3 vs Sanctum Tasks

Legend: **Y** = good enough for a BC3-*style* presentation of that slice; **P** = partial / convention-only; **N** = missing for faithful BC3 semantics.

| Basecamp concept | Sanctum Tasks today | Overlay without schema change | Schema evolution (if faithful) |
|------------------|---------------------|-------------------------------|--------------------------------|
| Project as container | `tasks.project` is a **string label**, not an entity | Treat string as “project name”; group in UI | `projects` table + `project_id` FK |
| To-do list / groups | **N** — flat task list per filter | Fake “lists” as saved views or tags convention | `todo_lists`, optional `list_id` on tasks |
| To-do set / dock | **N** | N/A in data; UI could show fixed “tool: Tasks” | `project_tools` or embed in `projects` |
| Assignees (multiple) | **Single** `assigned_to_user_id` | Show one; “+N” disabled or flatten to watchers | `task_assignees` junction |
| Completed vs status | `task_statuses.is_done` + slug | Map “done” statuses to BC-style checkbox | Optional `completed_at` for clarity |
| Recording status (trashed/archived) | **N** — delete is destructive in UI/API | Soft-delete column + filter | `status` column `active|archived|trashed` |
| Schedule / calendar | `due_at` only | “Schedule” = due dates aggregation | `schedule_entries` or link-out |
| Message board | **N** | Link to external or defer | `messages` table or integration |
| Chat (Campfire) | **N** | Defer or embed Broca/chat URL | Separate service |
| Card table / Kanban | `rank` + `status` | **Kanban-style column = status** (reasonable MVP) | `kanban_columns` if decoupled from status |
| Hill Charts / gauges | **N** | Defer | New entities or integration |
| Watchers / subscriptions | `task_watchers` | Map to “who’s watching” | Optional notification prefs |
| Comments on anything | Comments **task-only** | OK for task-scoped overlay | Polymorphic `comments` if expanded |
| Files / vault | Attachment **metadata** | “Files” = task attachments list | Blob storage + `vaults` if first-class |
| Client visibility | **N** | N/A | ACL / `visibility` on projects |
| My assignments / home | API filters + UI | Dedicated “Me” dashboard route | Same + saved home layout prefs |
| Pings / global DM | **N** | Out of scope | Separate product |
| Search | `search-tasks.php` | BC-style omnibar **within tasks** | Cross-entity if more tables |

**Conclusion:** Sanctum Tasks already supports a **credible “Assignments + To-dos + basic project grouping”** slice of Basecamp. It does **not** support the full **recording + dock** model without substantial schema and API work.

---

## 6. Optional overlay — UX and technical approach

### 6.1 Product definition

- **Overlay** = alternate presentation layer (layouts, navigation, copy, density) that consumes the **same** JSON/API and session auth as today.
- **Optional** = per-user preference: default remains current Bootstrap admin unless the user enables “Basecamp-style” (working name; avoid trademark issues in shipped strings — e.g. “Calm classic”, “Camp layout”, or licensed branding if Mark chooses).
- **Install** = preference stored **per deployment user** after login (and optionally exposed as a query param for demos), not a separate npm “package” unless you later split front-end assets.

### 6.2 Suggested implementation shape (phased)

**Phase A — Preference + shell (low risk)**  
- Add `user_preferences` (or column on `users`): e.g. `ui_mode` enum `default|camp` (names TBD).  
- New PHP layout under `public/admin/` or parallel path `public/overlay/` that reuses `includes/auth.php` + task functions.  
- Toggle in navbar or profile menu; POST updates preference + CSRF.

**Phase B — BC3-flavored routes (still same data)**  
- **Home:** cards for “My open tasks”, “Due this week”, “By project” (aggregate `list-tasks` / `list-projects`).  
- **Project view:** `project=<name>` filter with list + optional Kanban by `status`.  
- **Task detail:** two-column layout (title/meta left, body/comments right) reminiscent of BC information hierarchy — still `view.php` logic.  
- **“Jump” palette:** client-side fuzzy search over recent projects/tasks (cached JSON).

**Phase C — Schema extensions (idempotent migrations)** — only when needed for fidelity  
- `projects` table + `project_id` on `tasks`.  
- `task_lists` + `list_id` (nullable).  
- `task_assignees` for multi-assignee.  
- `tasks.lifecycle_status` for trashed/archived vs workflow `status`.

Each phase should keep **API backward compatibility** (nullable FKs, defaults).

### 6.3 Visual and interaction notes

- Basecamp 3 emphasizes **low noise**, strong hierarchy, card-like **projects**, and **assignments-first** home. Sanctum can mimic **structure**, not pixel-perfect CSS, unless you want to invest in a dedicated design system.
- Respect licensing: do not copy proprietary assets; “inspired-by” layout and neutral iconography.

### 6.4 Testing and verification

- Any new user-visible layout must follow workspace **design verification** rules: Playwright smoke + mobile and desktop screenshots, inspected before claiming visual completion.

### 6.5 Out of scope (unless explicitly greenlit)

- Full message boards, Campfire chat, Hill Charts, client portals, Pings, OAuth to real Basecamp, or feature parity with `bc3-api`’s entire section list.

---

## 7. Recommended milestones

1. **M1 — Preference + empty shell** — DB migration for `ui_mode`, toggle, route guard.  
2. **M2 — Home + project grouping** — aggregate queries only; no new tables.  
3. **M3 — Kanban by status** — drag-and-drop updates `status` / `rank` via existing APIs.  
4. **M4 — Schema: projects + lists** — idempotent SQL under `tools/migrations/` (per repo conventions).  
5. **M5 — Multi-assignee + soft delete** — API v2 considerations and mobile polish.

---

## 8. References

- Basecamp 3 Help — Home: `https://3.basecamp-help.com/article/21-the-home-screen`  
- Customize home: `https://3.basecamp-help.com/article/838-customize-your-home-screen`  
- Project tools index: `https://3.basecamp-help.com/article/119-intro-to-the-project-tools`  
- Basecamp API README (concepts + endpoint index): `https://github.com/basecamp/bc3-api`  
- Sanctum Tasks API: `docs/api.md`  
- Sanctum Tasks schema: `public/includes/config.php`  

---

## 9. Open questions for Mark

1. Should the overlay live **only** in `sanctum-tasks`, or ship as a **separate static bundle** consumed by PHP includes?  
2. Trademark: acceptable **in-repo codename** vs user-visible “Basecamp-style” wording?  
3. Is **multi-repo** `sanctumos` sync automation (manifest + script) desired as a follow-up task?
