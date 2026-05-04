# Changelog

## [Unreleased]

### Added

- **Organizations & project directory** — SQLite `organizations`, `projects`, `project_members`; `users.org_id` and `users.person_kind` (`team_member` \| `client`); `tasks.project_id` (nullable FK) for later backfill from legacy `tasks.project` string. Default org **Default** + user org linking on bootstrap. API: `GET /api/list-organizations.php`, `GET /api/list-directory-projects.php`, `POST /api/create-directory-project.php`. PHP + FastAPI mirror; SDK `list_organizations`, `list_directory_projects`, `create_directory_project`; `create_user` accepts optional `org_id` / `person_kind`. Admin **Users** UI shows org id and person kind. Idempotent migration notes in `tools/migrations/sanctum_001_organizations_and_projects.sql`.
- **Heartbeat setup wizard** — `scripts/setup_heartbeat.sh`: interactive bash wizard to configure an open-claw heartbeat worker. Asks for agent name, sanctum folder, Tasks creds (type now or path to existing .env), heartbeat project, optional worker user id, interval, and whether to add a crontab entry. Writes `run_heartbeat.sh` (bash + curl) and `.env.heartbeat` under `sanctum/agents/<name>/`. See [docs/HEARTBEAT.md](docs/HEARTBEAT.md) and [docs/HEARTBEAT_WIZARD_CONTEXT.md](docs/HEARTBEAT_WIZARD_CONTEXT.md).
