#!/bin/sh
set -eu
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=ON_ERROR_STOP=1 --set=app_password="$APP_DB_PASSWORD" --set=evolution_password="$EVOLUTION_DB_PASSWORD" <<'SQL'
CREATE USER bot WITH PASSWORD :'app_password';
CREATE DATABASE bot OWNER bot;
CREATE USER evolution WITH PASSWORD :'evolution_password';
CREATE DATABASE evolution OWNER evolution;
REVOKE CONNECT ON DATABASE bot FROM PUBLIC;
GRANT CONNECT ON DATABASE bot TO bot;
REVOKE CONNECT ON DATABASE evolution FROM PUBLIC;
GRANT CONNECT ON DATABASE evolution TO evolution;
SQL
