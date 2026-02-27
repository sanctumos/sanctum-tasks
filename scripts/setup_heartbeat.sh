#!/usr/bin/env bash
# Heartbeat setup wizard (bash). Asks a few questions, writes run_heartbeat.sh
# and optionally a minimal .env or sources existing sanctum/letta .env.
# See docs/HEARTBEAT.md and docs/HEARTBEAT_WIZARD_CONTEXT.md.

set -e

echo "=== Sanctum Tasks heartbeat setup ==="
echo ""

# 1) Agent name
agent_name=""
while [[ ! "$agent_name" =~ ^[a-zA-Z0-9_-]+$ ]]; do
  read -r -p "Agent name (e.g. athena, monday): " agent_name
  agent_name="${agent_name// /}"
  if [[ -z "$agent_name" ]]; then
    echo "  (required)"
  elif [[ ! "$agent_name" =~ ^[a-zA-Z0-9_-]+$ ]]; then
    echo "  Use only letters, numbers, hyphen, underscore."
  fi
done

# 2) Sanctum folder -> output dir
sanctum_default="${HOME}/sanctum"
read -r -p "Sanctum folder [${sanctum_default}]: " sanctum_in
SANCTUM="${sanctum_in:-$sanctum_default}"
SANCTUM="${SANCTUM/#\~/$HOME}"
OUTDIR="${SANCTUM}/agents/${agent_name}"
mkdir -p "$OUTDIR"
OUTDIR="$(cd "$OUTDIR" && pwd)"
echo "  -> Output dir: $OUTDIR"

# 3) Where to get Tasks creds: type now vs path to folder with .env
echo ""
echo "Where should we get TASKS_BASE_URL and TASKS_API_KEY?"
echo "  1) I'll type them now (we'll write a small .env here)"
echo "  2) Path to a folder that has a .env (e.g. $SANCTUM or ${HOME}/.letta)"
read -r -p "Choice [1 or 2]: " cred_choice
cred_choice="${cred_choice:-1}"

ENV_SOURCE=""   # if set, runner will source this path

if [[ "$cred_choice" == "2" ]]; then
  read -r -p "Path to folder (or file) containing .env: " env_path
  env_path="${env_path/#\~/$HOME}"
  if [[ -f "$env_path" ]]; then
    ENV_SOURCE="$env_path"
  elif [[ -f "${env_path}/.env" ]]; then
    ENV_SOURCE="${env_path}/.env"
  else
    echo "  No .env found at $env_path or ${env_path}/.env. Exiting."
    exit 1
  fi
  echo "  -> Will source: $ENV_SOURCE"
else
  read -r -p "Tasks base URL (e.g. https://tasks.example.com): " tasks_url
  tasks_url="${tasks_url%/}"
  read -rs -p "Tasks API key: " tasks_key
  echo ""
  if [[ -z "$tasks_url" || -z "$tasks_key" ]]; then
    echo "  URL and API key are required. Exiting."
    exit 1
  fi
  ENV_FILE="${OUTDIR}/.env"
  {
    echo "TASKS_BASE_URL=${tasks_url}"
    echo "TASKS_API_KEY=${tasks_key}"
  } > "$ENV_FILE"
  chmod 600 "$ENV_FILE"
  ENV_SOURCE="$ENV_FILE"
  echo "  -> Wrote $ENV_FILE"
fi

# 4) Heartbeat project
read -r -p "Heartbeat project name [heartbeat]: " project
project="${project:-heartbeat}"

# 5) Worker user id (optional)
read -r -p "Tasks worker user id for claiming (optional, press Enter to skip): " worker_id
worker_id="${worker_id// /}"

# 6) Interval
read -r -p "Cron interval in minutes [1]: " interval
interval="${interval:-1}"
if ! [[ "$interval" =~ ^[0-9]+$ ]] || [[ "$interval" -lt 1 ]]; then
  interval=1
fi

# 7) Add to crontab?
read -r -p "Add to crontab? [y/N]: " add_cron
add_cron="${add_cron:-n}"

# --- Optional .env.heartbeat for project/worker_id (when creds from path) ---
HEARTBEAT_ENV="${OUTDIR}/.env.heartbeat"
{
  echo "TASKS_HEARTBEAT_PROJECT=${project}"
  [[ -n "$worker_id" ]] && echo "TASKS_WORKER_USER_ID=${worker_id}"
} > "$HEARTBEAT_ENV"
chmod 600 "$HEARTBEAT_ENV"

# --- Generate run_heartbeat.sh ---
RUNNER="${OUTDIR}/run_heartbeat.sh"
if [[ -n "$ENV_SOURCE" ]]; then
  SOURCE_LINE="set -a; source \"${ENV_SOURCE}\"; source \"\$(dirname \"\$0\")/.env.heartbeat\" 2>/dev/null; set +a"
else
  SOURCE_LINE="# No env source (set TASKS_BASE_URL and TASKS_API_KEY in this script or env)"
fi

# Runner: one beat. List in-flight (doing + assignee + project); if task, mark done. Else list todo, claim one.
# Uses curl + Python for JSON (one-liner; no pip). Requires curl.
cat > "$RUNNER" << 'RUNNER_TOP'
#!/usr/bin/env bash
# One heartbeat beat: process in-flight task or claim one todo. Source your .env or set TASKS_*.
set -e
RUNNER_TOP

echo "$SOURCE_LINE" >> "$RUNNER"
cat >> "$RUNNER" << 'RUNNER_BODY'

BASE="${TASKS_BASE_URL%/}"
KEY="${TASKS_API_KEY}"
PROJECT="${TASKS_HEARTBEAT_PROJECT:-heartbeat}"
WORKER_ID="${TASKS_WORKER_USER_ID:-}"
API="${BASE}/api"

if [[ -z "$BASE" || -z "$KEY" ]]; then
  echo "[heartbeat] TASKS_BASE_URL and TASKS_API_KEY required" >&2
  exit 1
fi

list() {
  local status="$1"
  local extra=""
  if [[ -n "$WORKER_ID" && "$status" == "doing" ]]; then
    extra="&assigned_to_user_id=${WORKER_ID}"
  fi
  curl -sS -H "X-API-Key: ${KEY}" -H "Content-Type: application/json" \
    "${API}/list-tasks.php?status=${status}&project=${PROJECT}&limit=1&sort_by=created_at&sort_dir=ASC${extra}"
}

update() {
  local id="$1"
  shift
  local json="$*"
  curl -sS -X POST -H "X-API-Key: ${KEY}" -H "Content-Type: application/json" \
    -d "${json}" "${API}/update-task.php"
}

# Parse first task id from list-tasks response (needs python3 for JSON)
task_id_from() {
  echo "$1" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    tasks = d.get('tasks') or []
    print(tasks[0]['id'] if tasks else '')
except Exception:
    print('')
" 2>/dev/null
}

# Step 1: in-flight task?
resp=$(list "doing")
tid=$(task_id_from "$resp")
if [[ -n "$tid" ]]; then
  # Mark done (minimal: no custom work; user can add handler script later)
  update "$tid" "{\"id\":$tid,\"status\":\"done\"}" >/dev/null
  echo "[heartbeat] done task $tid" >&2
  exit 0
fi

# Step 2: grab one todo
resp=$(list "todo")
tid=$(task_id_from "$resp")
if [[ -z "$tid" ]]; then
  echo "[heartbeat] no task" >&2
  exit 0
fi

# Claim: doing + assignee
if [[ -n "$WORKER_ID" ]]; then
  update "$tid" "{\"id\":$tid,\"status\":\"doing\",\"assigned_to_user_id\":${WORKER_ID}}" >/dev/null
else
  update "$tid" "{\"id\":$tid,\"status\":\"doing\"}" >/dev/null
fi
echo "[heartbeat] claimed task $tid" >&2
RUNNER_BODY

chmod +x "$RUNNER"
echo ""
echo "Wrote: $RUNNER"
echo "Wrote: $HEARTBEAT_ENV (project=${project}, worker_id=${worker_id:-none})"

# Cron
LOG_DIR="${HOME}/logs"
CRON_LINE="*/${interval} * * * * ${RUNNER} >> ${LOG_DIR}/${agent_name}-heartbeat-cron.log 2>&1"
if [[ "${add_cron,,}" == "y" || "${add_cron}" == "yes" ]]; then
  mkdir -p "$LOG_DIR"
  ( crontab -l 2>/dev/null | grep -v "run_heartbeat.sh" | grep -v "${agent_name}-heartbeat-cron"; echo "$CRON_LINE" ) | crontab -
  echo "Added to crontab. Log: ${LOG_DIR}/${agent_name}-heartbeat-cron.log"
else
  echo ""
  echo "Add to crontab manually:"
  echo "  $CRON_LINE"
  echo "  (ensure ${LOG_DIR} exists)"
fi

echo ""
echo "Done. Run once: $RUNNER"
