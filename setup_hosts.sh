#!/usr/bin/env bash
# Script to add a local /etc/hosts entry for example.com
HOST_ENTRY="127.0.0.1 example.com"
if grep -qi "example.com" /etc/hosts; then
  echo "/etc/hosts already contains an entry for example.com"
  exit 0
fi
echo "Adding '$HOST_ENTRY' to /etc/hosts (requires sudo)"
sudo bash -c "echo '$HOST_ENTRY' >> /etc/hosts"
echo "Done."
