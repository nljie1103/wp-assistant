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
# GitHub push/PR CI uses a shallow checkout without tags. The official
# release job fetches all tags and runs verify-history separately.
printf '版本进位与非法版本拒绝：16 组测试通过\n'
