#!/usr/bin/env bash
# Script to add a local /etc/hosts entry for calendar.favala.es
HOST_ENTRY="127.0.0.1 calendar.favala.es"
if grep -qi "calendar.favala.es" /etc/hosts; then
  echo "/etc/hosts already contains an entry for calendar.favala.es"
  exit 0
fi
echo "Adding '$HOST_ENTRY' to /etc/hosts (requires sudo)"
sudo bash -c "echo '$HOST_ENTRY' >> /etc/hosts"
echo "Done."
