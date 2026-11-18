#!/usr/bin/env bash
# The independent WordPress version smoke test must work after every release.
set -euo pipefail
cd "$(dirname "$0")/.."
file=tests/integration/version-consistency-smoke.php
# Do not freeze an old release number in the test fixture.
if grep -Eq '\$expected[[:space:]]*=[[:space:]]*["\x27][0-9]+\.[0-9]+\.[0-9]+' "$file"; then
  echo 'WordPress smoke test must derive expected version from JLWA_VERSION' >&2
  exit 1
fi
grep -Fq "defined( 'JLWA_VERSION' ) ? JLWA_VERSION" "$file"
grep -Fq 'v{$expected}' "$file"
php -l "$file" >/dev/null
echo 'Independent WordPress smoke test follows the active release version'
