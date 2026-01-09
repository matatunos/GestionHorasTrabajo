#!/usr/bin/env bash
set -euo pipefail
# Installs and enables the example.conf site on Debian/Ubuntu Apache
CONF_SRC="$(dirname "$0")/example.conf"
CONF_DEST="/etc/apache2/sites-available/example.conf"

if [ ! -f "$CONF_SRC" ]; then
  echo "Error: source vhost not found: $CONF_SRC"
  exit 1
fi

echo "Copying $CONF_SRC -> $CONF_DEST (requires sudo)"
sudo cp "$CONF_SRC" "$CONF_DEST"

echo "Enabling site: example.conf"
sudo a2ensite example.conf || true

echo "Checking Apache config"
sudo apache2ctl configtest

echo "Reloading Apache"
sudo systemctl reload apache2

echo "Done. Remember to add hosts entry if needed:"
echo "  sudo sh -c 'echo \"127.0.0.1 example.com\" >> /etc/hosts'"
