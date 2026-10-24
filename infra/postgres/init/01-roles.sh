#!/bin/sh
# KRATOS PostgreSQL bootstrap (runs once, on an empty data directory).
#
# Creates two login roles:
#   kratos_owner - owns the schema; used only to run migrations.
#   kratos_app   - used by the application and workers; DML only.
# and two databases (application + test), both owned by kratos_owner.
#
# Default privileges grant kratos_app DML on every table kratos_owner creates;
# individual migrations may revoke further (e.g. audit_logs is append-only).
set -eu

: "${KRATOS_DB_OWNER_PASSWORD:?KRATOS_DB_OWNER_PASSWORD must be set}"
: "${KRATOS_DB_APP_PASSWORD:?KRATOS_DB_APP_PASSWORD must be set}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v owner_pw="$KRATOS_DB_OWNER_PASSWORD" -v app_pw="$KRATOS_DB_APP_PASSWORD" <<'SQL'
CREATE ROLE kratos_owner LOGIN PASSWORD :'owner_pw';
CREATE ROLE kratos_app LOGIN PASSWORD :'app_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;

CREATE DATABASE kratos OWNER kratos_owner;
CREATE DATABASE kratos_test OWNER kratos_owner;
SQL

for db in kratos kratos_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE $db TO kratos_app;
ALTER SCHEMA public OWNER TO kratos_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO kratos_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kratos_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO kratos_app;
ALTER DEFAULT PRIVILEGES FOR ROLE kratos_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO kratos_app;
SQL
done

echo "KRATOS database roles initialised."
