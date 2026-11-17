#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
actual() { python3 tools/version-policy.py next "$1"; }
for pair in \
  '2.5.8=2.5.9' \
  '2.5.9=2.6.0' \
  '2.5.10=2.6.0' \
  '2.5.11=2.6.0' \
  '2.6.0=2.6.1' \
  '2.6.9=2.7.0' \
  '2.9.9=3.0.0' \
  '6.0.9=6.1.0'; do
  old="${pair%%=*}"
  expected="${pair#*=}"
  test "$(actual "$old")" = "$expected"
done
for invalid in '2.5.10' '2.5.11' '2.6.10' '2.10.0' '2.06.0' '2.5' 'v2.6.0'; do
  if python3 tools/version-policy.py validate "$invalid" >/dev/null 2>&1; then
    echo "应拒绝的版本号通过了：$invalid" >&2
    exit 1
  fi
done
python3 tools/version-policy.py validate 2.6.0 >/dev/null
python3 - <<'PYTEST'
import importlib.util
spec = importlib.util.spec_from_file_location('version_policy', 'tools/version-policy.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)
for older, newer, expected in [
    ('2.7.4', '2.7.5', True),
    ('2.7.4', '3.0.0', True),
    ('2.7.4', '2.7.6', False),
    ('2.7.4', '3.0.1', False),
    ('2.7.4', '4.0.0', False),
    ('2.9.9', '3.0.0', True),
    ('3.0.0', '3.0.1', True),
    ('3.0.0', '3.1.0', False),
]:
    assert policy.valid_transition(older, newer) is expected, (older, newer)
PYTEST
# GitHub push/PR CI uses a shallow checkout without tags. The official
# release job fetches all tags and runs verify-history separately.
printf '版本进位、显式大版本与非法版本拒绝：24 组测试通过\n'
