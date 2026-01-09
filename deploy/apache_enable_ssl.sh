#!/usr/bin/env bash
set -euo pipefail
ROOT="$(dirname "$0")"
CONF_SRC="$ROOT/apache/example.ssl.conf"
CONF_DEST="/etc/apache2/sites-available/example.ssl.conf"

if [ ! -f "$CONF_SRC" ]; then
  echo "Error: source vhost not found: $CONF_SRC"
  exit 1
fi

echo "Copying $CONF_SRC -> $CONF_DEST (requires sudo)"
sudo cp "$CONF_SRC" "$CONF_DEST"

echo "Enabling SSL module and site"
sudo a2enmod ssl || true
sudo a2ensite example.ssl.conf || true

echo "Checking Apache config"
sudo apache2ctl configtest

echo "Reloading Apache"
sudo systemctl reload apache2

echo "Done. Ensure certificate exists at ./ssl/example.crt and key at ./ssl/example.key"
