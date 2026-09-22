#!/bin/sh
set -e

# Wait for the database before doing anything that talks to it. Compose health
# checks cover the common case; this covers a database that restarts under us.
if [ -n "${DB_HOST:-}" ]; then
    tries=0
    until php -r "new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 3306), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "Database did not become available in time." >&2
            exit 1
        fi
        sleep 2
    done
fi

exec "$@"
