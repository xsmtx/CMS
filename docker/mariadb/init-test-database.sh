#!/bin/bash
set -e

# The test suite runs against MariaDB, never SQLite, so a second schema is
# created next to the development one. MariaDB 11 ships the `mariadb` client;
# the legacy `mysql` name is no longer present in the image.
mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE}_test\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}_test\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
