#!/usr/bin/env bash
# Avoid publishing deployment-specific infrastructure in the reusable manual.
set -euo pipefail
cd "$(dirname "$0")/.."
for file in README.md docs/paid-download-security.md docs/private-download-design.md; do
  if grep -En '(blog\.jiuliu\.org|openlist-blog|152\.70\.124\.102|/www/yecao/|/www/kaifa/|Kuwait-ID|psdstencil3|2823)' "$file"; then
    echo "Private deployment data found in public documentation: $file" >&2
    exit 1
  fi
done
printf 'Public deployment docs: generic examples only\n'
