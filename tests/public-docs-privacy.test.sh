#!/usr/bin/env bash
# Ensure public docs do not embed a user's infrastructure identifiers.
set -euo pipefail
cd "$(dirname "$0")/.."
for file in README.md docs/paid-download-security.md docs/private-download-design.md docs/media-nginx-optional.md; do
  if grep -En 'jiuliu\.org|/www/(yecao|kaifa)/|([0-9]{3}\.){1}[0-9]{2}\.[0-9]{3}\.[0-9]{3}' "$file"; then
    echo "Private infrastructure identifiers found in public document: $file" >&2
    exit 1
  fi
done
printf 'Public deployment docs: generic examples only\n'
