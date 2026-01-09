#!/usr/bin/env bash
set -euo pipefail
# Installs and enables the calendar.favala.conf site on Debian/Ubuntu Apache
CONF_SRC="$(dirname "$0")/calendar.favala.conf"
CONF_DEST="/etc/apache2/sites-available/calendar.favala.conf"

if [ ! -f "$CONF_SRC" ]; then
  echo "Error: source vhost not found: $CONF_SRC"
  exit 1
fi

echo "Copying $CONF_SRC -> $CONF_DEST (requires sudo)"
sudo cp "$CONF_SRC" "$CONF_DEST"

echo "Enabling site: calendar.favala.conf"
sudo a2ensite calendar.favala.conf || true

echo "Checking Apache config"
sudo apache2ctl configtest

echo "Reloading Apache"
sudo systemctl reload apache2

echo "Done. Remember to add hosts entry if needed:"
echo "  sudo sh -c 'echo \"127.0.0.1 calendar.favala.es\" >> /etc/hosts'"
