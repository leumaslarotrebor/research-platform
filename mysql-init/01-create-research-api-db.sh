#!/bin/bash
# mysql-init/01-create-research-api-db.sh
#
# Files in /docker-entrypoint-initdb.d/ are run once, on first init of
# an empty data directory, by the official mysql image. We use a .sh
# script instead of a plain .sql file specifically because raw .sql
# files are NOT env-var-substituted by the entrypoint — a script lets
# us reuse the same MYSQL_USER the official image already created for
# the OJS database, instead of hardcoding a second username.
#
# OJS's own schema is created separately, by OJS's own install wizard
# (or lib/pkp/tools/upgrade.php) — this script only provisions the
# extra database for the Laravel Research API.
set -euo pipefail

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS research_api
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON research_api.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
