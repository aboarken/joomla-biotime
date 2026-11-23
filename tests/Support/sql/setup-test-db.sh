#!/usr/bin/env bash
# Creates the PostgreSQL test database the "Real PostgreSQL" tests use (1.3.7): ZKBioTime's tables
# from zkbiotime-pgsql.sql, owned by zkb_owner, and the portal's read-only user joomla_portal with
# SELECT on them (a column grant only on auth_user) and default_transaction_read_only on, as
# docs/DATABASE.md sets up a real server. Test values only: never use these passwords elsewhere.
#
# Run as a PostgreSQL superuser, e.g. in CI:  PGHOST=127.0.0.1 PGUSER=postgres PGPASSWORD=… tests/Support/sql/setup-test-db.sh
# Then:  BIOTIME_TEST_PG='127.0.0.1:5432:biotime:joomla_portal:portal-pass-123' vendor/bin/phpunit
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
portal_password="${BIOTIME_TEST_PORTAL_PASSWORD:-portal-pass-123}"

psql -v ON_ERROR_STOP=1 -v portal="$portal_password" -d postgres <<'SQL'
DROP DATABASE IF EXISTS biotime;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'zkb_owner') THEN CREATE ROLE zkb_owner NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'joomla_portal') THEN CREATE ROLE joomla_portal LOGIN; END IF;
END $$;
ALTER ROLE joomla_portal PASSWORD :'portal';
CREATE DATABASE biotime OWNER zkb_owner;
SQL

psql -v ON_ERROR_STOP=1 -d biotime <<SQL
ALTER ROLE joomla_portal IN DATABASE biotime SET default_transaction_read_only = on;
GRANT CONNECT ON DATABASE biotime TO joomla_portal;
GRANT USAGE ON SCHEMA public TO joomla_portal;
GRANT CREATE ON SCHEMA public TO zkb_owner;
ALTER DEFAULT PRIVILEGES FOR ROLE zkb_owner IN SCHEMA public GRANT SELECT ON TABLES TO joomla_portal;
SET ROLE zkb_owner;
\i ${here}/zkbiotime-pgsql.sql
SQL

echo "Test database biotime ready (owner zkb_owner, read-only user joomla_portal)."
