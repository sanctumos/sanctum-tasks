#!/usr/bin/env bash
# Optional: keep this repo and any local sanctum-tasks mirrors on origin/main.
# Usage:
#   ./tools/sync-sanctum-tasks-clones.sh
#   SANCTUM_TASKS_MIRROR_PATHS="/path/a /path/b" ./tools/sync-sanctum-tasks-clones.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REMOTE="${SANCTUM_TASKS_GIT_REMOTE:-origin}"
BRANCH="${SANCTUM_TASKS_GIT_BRANCH:-main}"

sync_one() {
  local d="$1"
  if [[ ! -d "$d/.git" ]]; then
    echo "skip (not a git repo): $d" >&2
    return 0
  fi
  echo ">>> $d"
  git -C "$d" fetch "$REMOTE"
  git -C "$d" pull --ff-only "$REMOTE" "$BRANCH"
}

sync_one "$ROOT"

if [[ -n "${SANCTUM_TASKS_MIRROR_PATHS:-}" ]]; then
  # shellcheck disable=SC2086
  for extra in $SANCTUM_TASKS_MIRROR_PATHS; do
    [[ -n "$extra" ]] || continue
    sync_one "$extra"
  done
fi

echo "done."
