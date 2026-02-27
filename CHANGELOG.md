# Changelog

## [Unreleased]

### Added

- **Heartbeat setup wizard** — `scripts/setup_heartbeat.sh`: interactive bash wizard to configure an open-claw heartbeat worker. Asks for agent name, sanctum folder, Tasks creds (type now or path to existing .env), heartbeat project, optional worker user id, interval, and whether to add a crontab entry. Writes `run_heartbeat.sh` (bash + curl) and `.env.heartbeat` under `sanctum/agents/<name>/`. See [docs/HEARTBEAT.md](docs/HEARTBEAT.md) and [docs/HEARTBEAT_WIZARD_CONTEXT.md](docs/HEARTBEAT_WIZARD_CONTEXT.md).
