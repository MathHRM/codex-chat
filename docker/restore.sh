#!/bin/sh
# Restoration only creates a fresh, isolated project. Never overwrite live volumes.
set -eu
. ./docker/operations.sh
[ "$#" -eq 1 ] || { echo 'Usage: sh docker/restore.sh /absolute/backup-directory' >&2; exit 1; }
case "$1" in /*) bot_backup=$1;; *) echo 'Use an absolute backup directory' >&2; exit 1;; esac
for bot_volume in postgres-data app-storage redis-data evolution-session workspace runner-state codex-session; do
    if docker volume inspect "${bot_project}_${bot_volume}" >/dev/null 2>&1; then
        echo 'Destination project already has volumes; choose a fresh project name' >&2; exit 1
    fi
done
(cd "$bot_backup" && sha256sum -c SHA256SUMS >/dev/null)
bot_compose config --quiet
bot_compose create app worker scheduler codex-runner evolution postgres redis
for bot_volume in app-storage redis-data evolution-session workspace runner-state codex-session; do
    docker run --rm --user 0 --entrypoint tar \
        -v "${bot_project}_${bot_volume}:/volume" -v "$bot_backup:/backup:ro" \
        "$bot_helper" --numeric-owner -xpf "/backup/$bot_volume.tar" -C /volume
done
bot_compose up -d --wait postgres
bot_compose exec -T postgres pg_restore -U administrator -d bot --exit-on-error < "$bot_backup/bot.dump"
bot_compose exec -T postgres pg_restore -U administrator -d evolution --exit-on-error < "$bot_backup/evolution.dump"
echo 'Restoration complete. Only PostgreSQL is running. Inspect the restored state before starting consumers.'
