#!/bin/sh
set -eu
bot_project=${COMPOSE_PROJECT_NAME:-whatsapp-codex-bot}
bot_env=${BOT_ENV_FILE:-.env}
bot_extra=${BOT_COMPOSE_EXTRA:-}
bot_helper=${BOT_HELPER_IMAGE:-whatsapp-codex-app:local}
case "$bot_project" in ''|*[!a-zA-Z0-9_-]*) echo 'Invalid project name' >&2; exit 1;; esac
bot_compose() {
    if [ -n "$bot_extra" ]; then
        docker compose --env-file "$bot_env" -p "$bot_project" -f compose.yaml -f compose.sandbox.yaml -f "$bot_extra" "$@"
    else
        docker compose --env-file "$bot_env" -p "$bot_project" -f compose.yaml -f compose.sandbox.yaml "$@"
    fi
}
