#!/bin/sh
# Run from the repository root. The stack intentionally remains stopped/paused.
set -eu
. ./docker/operations.sh
[ "$#" -eq 1 ] || { echo 'Usage: sh docker/backup.sh /absolute/new-backup-directory' >&2; exit 1; }
case "$1" in /*) bot_backup=$1;; *) echo 'Use an absolute backup directory' >&2; exit 1;; esac
[ ! -e "$bot_backup" ] || { echo 'Backup destination must be new' >&2; exit 1; }
umask 077
mkdir -m 700 "$bot_backup"
bot_compose exec -T app php artisan bot:status --pause --no-interaction
bot_deadline=$(( $(date +%s) + ${BOT_DRAIN_SECONDS:-3720} ))
while ! bot_compose exec -T app php artisan bot:status --no-interaction | grep -q 'execucoes_ativas=0'; do
    [ "$(date +%s)" -lt "$bot_deadline" ] || { echo 'Drain timed out; inspect the runner before proceeding' >&2; exit 1; }
    sleep 2
done
bot_compose stop app scheduler worker codex-runner evolution redis
bot_compose exec -T postgres pg_dump -U administrator -d bot --format=custom > "$bot_backup/bot.dump"
bot_compose exec -T postgres pg_dump -U administrator -d evolution --format=custom > "$bot_backup/evolution.dump"
for bot_volume in app-storage redis-data evolution-session workspace runner-state codex-session; do
    docker run --rm --user 0 --entrypoint tar \
        -v "${bot_project}_${bot_volume}:/volume:ro" \
        "$bot_helper" --numeric-owner -cpf - -C /volume . > "$bot_backup/$bot_volume.tar"
done
bot_compose images --format json > "$bot_backup/images.json"
(cd "$bot_backup" && sha256sum ./*.dump ./*.tar images.json > SHA256SUMS)
chmod 600 "$bot_backup"/*
echo 'Consistent backup complete. Services remain stopped; retain matching .env and source revision securely.'
