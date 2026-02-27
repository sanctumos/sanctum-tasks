# Heartbeat setup wizard: context and spec

This document captures the **context** (host layout, patterns) and **wizard spec** (prompts, outputs) for the interactive heartbeat setup wizard. The wizard generates a runner script and optional cron entry for the [open-claw heartbeat](HEARTBEAT.md) pattern.

**Implementation:** A single **shell script** (`scripts/setup_heartbeat.sh`) — no Python or extra dependencies. Creds come from either (1) typing base URL and API key now (wizard writes a minimal `.env` in the agent dir) or (2) path to an existing folder/file that has a `.env` (e.g. sanctum root or `~/.letta`); the runner sources that. Generated runner is bash + curl (+ one `python3` one-liner for JSON parsing).

---

## Purpose

- **Input:** User answers a short set of questions (agent name, Tasks URL, project, interval, etc.).
- **Output:** A heartbeat runner script (and optionally a `.env` or config), written to a designated folder (e.g. `sanctum/agents/<agent_name>/`), plus an optional cron line (or “add to crontab?” step).
- **Scope:** Sanctum Tasks only. The runner uses the Tasks API (list, claim, update) as in HEARTBEAT.md. Optional: ask for Letta-related vars if the heartbeat will drive Letta work (e.g. send message to agent); wizard does not implement that work, it only records the vars for the runner to use.

---

## Host layout (reference)

Derived from a scan of a representative host (Letta + Sanctum in place, no Sanctum Tasks yet):

| Item | Pattern |
|------|--------|
| **Sanctum root** | `~/sanctum/` with `agents/`, `control/`, `smcp/`, `venv/` |
| **Agent folder** | `~/sanctum/agents/<agent_name>/` — e.g. athena has `broca/`, `origin_conversation/`, `start-athena-broca.sh` |
| **Cron** | Scripts in agent dir or `sanctum/control/cron/`; crontab entries run every N minutes; logs under `~/logs/<name>-*.log` |
| **Env** | Agent-specific `.env` in the agent dir (or one shared file); keys like `TASKS_API_KEY`, `TASKS_BASE_URL`, `AGENT_ID`, `AGENT_ENDPOINT` |
| **Letta** | Server at e.g. `http://localhost:8284`; config in `~/.letta/`; agents identified by `agent_id`; optional `LETTA_API_KEY` for auth |

The wizard should assume **Tasks is deployed elsewhere** (user supplies base URL and API key) unless we add an optional “install Sanctum Tasks” step later. The generated runner only needs Tasks API + optional Letta vars.

---

## Wizard prompts (spec)

Ask the following in order. Defaults and validation notes are suggestions.

1. **Agent name** (e.g. `athena`, `monday`)  
   - Used for: folder name `sanctum/agents/<agent_name>/`, script name, log file name, cron comment.  
   - Validation: non-empty, safe for paths (alphanumeric + hyphen/underscore).

2. **Sanctum Tasks base URL** (e.g. `https://tasks.example.com`, `http://localhost:8080`)  
   - No trailing slash. Required.

3. **Tasks API key**  
   - Required. Stored in generated `.env` (or `.env.example` with placeholder); never echo back.

4. **Heartbeat project** (Tasks project filter)  
   - Default: `heartbeat`. Used for list-tasks filter `project=<value>`.

5. **Optional tag** (Tasks tag filter)  
   - Optional. If set, list-tasks also filters by this tag.

6. **Worker user id** (Tasks `assigned_to_user_id` for claiming)  
   - Optional if Tasks API allows unassigned claim; otherwise required. User must have created this user in Tasks admin and have its numeric id. Default: empty / “I’ll set later”.

7. **Interval (minutes)**  
   - Default: `1`. Cron expression: `*/<N> * * * *` (every N minutes). Alternatively offer “every 1/2/5/15 minutes” as presets.

8. **Output directory**  
   - Default: `~/sanctum/agents/<agent_name>/` (expand `~`). User can override so the script works on their layout.

9. **Add to crontab?**  
   - `[y/N]`. If yes: append one line to current user’s crontab, e.g.  
     `*/<N> * * * * /path/to/run_heartbeat.sh >> ~/logs/<agent_name>-heartbeat-cron.log 2>&1`  
   - If no: print the line and tell user to add it manually.

10. **(Optional) Letta integration**  
    - “Will this heartbeat call the Letta API?” `[y/N]`. If yes, ask:  
      - `LETTA_BASE_URL` (e.g. `http://localhost:8284`)  
      - `LETTA_AGENT_ID` (e.g. `agent-5712c4be...`)  
      - `LETTA_API_KEY` (optional, for Bearer auth)  
    - Store in the same `.env`. The wizard does not implement the Letta call; the runner (or a plugin) uses these vars when processing a task whose body references Letta.

---

## Generated artifacts

- **`run_heartbeat.sh`** (or `run_heartbeat.py` if we prefer one language):  
  - Sources or loads the generated `.env`.  
  - Implements one beat: (1) list in-flight (`status=doing`, `assigned_to_user_id=<worker_user_id>`, `project=<heartbeat_project>`); if found, process (see HEARTBEAT.md), then update to `done` or leave `doing`. (2) If none, list one `todo` with `project=<heartbeat_project>` (and optional tag), claim it (`status=doing`, `assigned_to_user_id`), then on next beat it will be processed.  
  - Logging: stderr or a small log line so cron log is useful.

- **`.env`** (only if user chose “type now”): `TASKS_BASE_URL`, `TASKS_API_KEY`.  
- **`.env.heartbeat`** (always): `TASKS_HEARTBEAT_PROJECT`, optional `TASKS_WORKER_USER_ID`. Runner sources the main env then this file.

- **Cron line** (if “add to crontab” yes):  
  - `*/<N> * * * * /absolute/path/to/run_heartbeat.sh >> ~/logs/<agent_name>-heartbeat-cron.log 2>&1`  
  - Wizard creates `~/logs` when adding to crontab.

---

## How to run the wizard

From the sanctum-tasks repo (or with the script on your PATH):

```bash
./scripts/setup_heartbeat.sh
```

Or copy the script to the host where sanctum/agents live and run it there. It only needs bash, curl, and python3 (for JSON in the generated runner). No pip or venv.

---

## Cross-references

- [HEARTBEAT.md](HEARTBEAT.md) — open-claw loop, queue shape, API usage.
- [WORKFLOWS.md](WORKFLOWS.md) — agent-only vs hybrid vs human-only; heartbeat fits agent/hybrid.
- [integrations.md](integrations.md) — API key creation, SDK/plugin usage.

---

## Letta API (for runner authors)

If the runner or a plugin performs Letta work (e.g. send message to an agent):

- **Auth:** `LETTA_API_KEY` as Bearer (optional for local server).  
- **Base URL:** `LETTA_BASE_URL` (e.g. `http://localhost:8284`); self-hosted Letta uses `/api/v1` or similar.  
- **Endpoints:** Create agent `POST /v1/agents`; send message `POST /v1/agents/{agent_id}/messages` with `input`.  
- **SDK:** `pip install letta-client`; `Letta(base_url=..., api_key=...)`.  
- **Local reference:** See installer `docs/reference/letta-api-reference.md` for identity/memory/block notes (e.g. cache block IDs; avoid relying on `blocks.list()`).

The wizard only collects the vars; it does not implement Letta calls.
