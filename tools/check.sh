#!/usr/bin/env bash
# Required checks for every pull request and official release.
set -euo pipefail
cd "$(dirname "$0")/.."
count_php=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  count_php=$((count_php + 1))
done < <(find . -name '*.php' -type f -not -path './vendor/*' -not -path './.git/*' -print0)
printf 'PHP syntax: %s files OK\n' "$count_php"
count_js=0
while IFS= read -r -d '' file; do
  node --check "$file"
  count_js=$((count_js + 1))
done < <(find . -name '*.js' -type f -not -path './vendor/*' -not -path './.git/*' -print0)
printf 'JavaScript syntax: %s files OK\n' "$count_js"
phpunit --configuration phpunit.xml.dist --no-coverage
node --test tests/js/*.test.js
python3 -m unittest discover -s tests -p test_nginx_helper.py -v
python3 -m unittest discover -s tests -p test_media_nginx_helper.py -v
bash tests/private-deploy-status-open-basedir.test.sh
bash tests/public-docs-privacy.test.sh
python3 tools/version-policy.py check
bash tests/version-policy.test.sh
bash tests/release-format.test.sh
