#!/usr/bin/env bash
set -euo pipefail
OUTDIR="$(dirname "$0")/../ssl"
mkdir -p "$OUTDIR"
CERT="$OUTDIR/example.crt"
KEY="$OUTDIR/example.key"
if [ -f "$CERT" ] && [ -f "$KEY" ]; then
  echo "Self-signed cert already exists: $CERT"
  exit 0
fi
echo "Generating self-signed certificate for example.com"
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout "$KEY" -out "$CERT" -subj "/CN=example.com"
chmod 644 "$CERT"
chmod 600 "$KEY"
echo "Created: $CERT and $KEY"
