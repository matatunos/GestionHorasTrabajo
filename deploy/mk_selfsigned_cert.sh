#!/usr/bin/env bash
set -euo pipefail
OUTDIR="$(dirname "$0")/../ssl"
mkdir -p "$OUTDIR"
CERT="$OUTDIR/calendar.favala.crt"
KEY="$OUTDIR/calendar.favala.key"
if [ -f "$CERT" ] && [ -f "$KEY" ]; then
  echo "Self-signed cert already exists: $CERT"
  exit 0
fi
echo "Generating self-signed certificate for calendar.favala.es"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout "$KEY" -out "$CERT" -subj "/CN=calendar.favala.es"
chmod 644 "$CERT"
chmod 600 "$KEY"
echo "Created: $CERT and $KEY"
