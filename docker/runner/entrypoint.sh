#!/bin/sh
set -eu
if [ ! -s /workspace/AGENTS.md ]; then
  echo 'runner instructions missing' >&2
  exit 1
fi
if [ ! -d /workspace/.git ]; then
  git init -q /workspace
fi
exec php -S 0.0.0.0:8081 /runner/http.php
