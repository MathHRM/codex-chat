#!/bin/sh
set -eu
if [ ! -s /workspace/AGENTS.md ]; then
  echo 'runner instructions missing' >&2
  exit 1
fi
if [ ! -d /workspace/.git ]; then
  git init -q /workspace
fi
php /runner/supervise.php &
supervisor_pid=$!
php -S 0.0.0.0:8081 /runner/http.php &
http_pid=$!
trap 'kill "$supervisor_pid" "$http_pid" 2>/dev/null || true; wait; exit 0' TERM INT
while kill -0 "$supervisor_pid" 2>/dev/null && kill -0 "$http_pid" 2>/dev/null; do
  sleep 1 &
  wait $! || true
done
kill "$supervisor_pid" "$http_pid" 2>/dev/null || true
wait
exit 1
