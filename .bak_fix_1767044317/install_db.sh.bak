#!/usr/bin/env bash
set -euo pipefail
ROOT=$(dirname "$0")
SQL="$ROOT/schema_clean.sql"
DBUSER=${DB_USER:-root}
DBPASS=${DB_PASS:-satriani}

if [ ! -f "$SQL" ]; then echo "schema.sql not found"; exit 1; fi

echo "Importing schema into MySQL as $DBUSER@localhost"
mysql -u"$DBUSER" -p"$DBPASS" < "$SQL"

echo "Creating initial admin user 'admin' (password 'admin') - change it after first login"
HASH=$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')
mysql -u"$DBUSER" -p"$DBPASS" -e "USE gestion_horas; INSERT IGNORE INTO users (username,password,is_admin) VALUES ('admin', '${HASH}', 1);"

echo "Done. Admin user: admin / admin"
