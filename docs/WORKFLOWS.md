# Workflows: Agent-only, Hybrid, and Human-only

This document describes three ways to use Sanctum Tasks depending on who (or what) is doing the work: **agent-only**, **hybrid agent–human**, and **human-only**. You can run one style exclusively or mix them; the same task store and API support all of them.

---

## Overview

| Workflow        | Who acts                    | How they interact with tasks                          | Typical use |
|----------------|-----------------------------|--------------------------------------------------------|-------------|
| **Agent-only** | Automation only (API/key)  | Create, update, list, complete via API/SDK/plugin      | Pipelines, bots, scheduled jobs |
| **Hybrid**     | Agents + people            | Agents use API; people use admin UI; same tasks       | Triage, orchestration, review |
| **Human-only** | People only (admin UI)      | Create, assign, update, close in the browser          | Classic task board, no automation |

---

## 1. Agent-only workflow

**Who:** Only automation—scripts, cron jobs, MCP agents, or other services. No human operators in the loop for day-to-day task execution.

**How:** All access is via the HTTP API (or the Python SDK / SMCP plugin that call it). Authentication is by API key. The admin UI may exist for setup (e.g. creating keys, viewing audit logs) but the primary interaction with tasks is programmatic.

**Typical flows:**

- **Pipeline / CI:** A job creates tasks (e.g. “Deploy to staging”), updates status as steps run, and marks them done. Other agents might list tasks by `project` or `tags` to drive the next stage.
- **Scheduled worker:** A cron or scheduler runs on an interval. It lists tasks in a dedicated project (e.g. `heartbeat`), claims one (e.g. sets `status=doing` and `assigned_to_user_id`), does the work, then sets `status=done`. See [Heartbeat](HEARTBEAT.md) for the open-claw pattern.
- **Event-driven:** An agent ingests events (alerts, webhooks), creates tasks with appropriate `project` and `tags`, and optionally assigns or updates them. Another process or the same agent can list and process those tasks.

**What you need:**

- API key (create in admin: API Keys).
- Base URL for the Tasks API (PHP or Python mirror).
- Client: HTTP, [Python SDK](../tasks_sdk/README.md), or [SMCP plugin](integrations.md) (e.g. from an MCP client).

**Docs to use:** [Integrations](integrations.md), [API reference](api.md), [Heartbeat](HEARTBEAT.md) (if you use a scheduled heartbeat worker).

---

## 2. Hybrid agent–human workflow

**Who:** Both automation (via API) and people (via admin UI). They work on the **same** task list; the same `project`, `status`, and `assignee` are visible to both.

**How:** Agents use the API with an API key. Humans sign in at `/admin/` and use the web UI. Both read and update the same tasks (same database). Use **project** and **tags** to separate contexts (e.g. heartbeat queue vs short-term vs research) so each side can focus on the right list.

**Typical flows:**

- **Triage:** An agent creates tasks from incidents or alerts (with `project` and `tags`). Humans open the admin UI, filter by that project (or tag), assign, change priority, add comments, and move status to `doing` or `done`. Agents can list tasks and add comments or update status based on external events.
- **Orchestration:** A human creates high-level tasks (e.g. “Research topic X”). An agent (or a heartbeat worker) picks them up from a dedicated project, does the work (e.g. runs tools, calls other services), and updates the task body or status. Humans review results in the admin UI.
- **Audit and review:** Agents perform bulk updates (status, project, tags) via the API. Humans use the admin UI to filter, search, and inspect history and audit logs.

**What you need:**

- API key for the agent(s).
- Admin access for humans (login, optional MFA).
- Agreed use of **project** (and optionally **tags**) so that “heartbeat tasks,” “short-term,” “research,” etc. are distinct lists. See [Heartbeat](HEARTBEAT.md) for keeping a dedicated heartbeat queue.

**Docs to use:** [Integrations](integrations.md), [API reference](api.md), [Heartbeat](HEARTBEAT.md), [Security](security.md).

---

## 3. Human-only workflow

**Who:** Only people. No automation is calling the API for normal task flow.

**How:** All task work happens in the admin UI. Users sign in, create and edit tasks, assign, set due dates, change status, and add comments. The API still exists (e.g. for creating API keys or future integrations) but is not used by agents for day-to-day tasks.

**Typical flow:**

- **Task board:** Create tasks, set project and tags, assign to users, set due dates. Use filters (status, assignee, project, search) to view “my tasks,” “this week,” or “by project.” Move status (e.g. todo → doing → done) as work progresses. Use comments and (if enabled) attachments for details.
- **User and key management:** Admins create or disable users, reset passwords, and create API keys when they later want to add an agent or integration.

**What you need:**

- Admin login (and optional MFA). No API key required unless you later enable agents.

**Docs to use:** [Integrations](integrations.md) (sections on admin and API keys), [Security](security.md).

---

## Choosing a workflow

- **Agent-only:** Use when everything is driven by scripts, MCP, or scheduled workers. No need for humans to work tasks in the UI.
- **Hybrid:** Use when both automation and people need to see and update the same work. Use **project** and **tags** to keep “heartbeat,” “short-term,” and “research” (or similar) as separate lists.
- **Human-only:** Use when only people manage tasks. You can still create API keys for future use.

You can start human-only and add an agent later, or run hybrid from day one; the same task model and API support all three.
