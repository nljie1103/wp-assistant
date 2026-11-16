#!/usr/bin/env bash
# Require versioned Chinese commit subjects and a unique, descriptive Chinese release title.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ "$#" -ne 3 ]; then
  echo '用法：check-release-format.sh 版本号 Commit主题 Release主题' >&2
  exit 2
fi
version="$1"
subject="$2"
topic="$3"
# Reject misleading 2.5.10 / 2.5.11: every segment after the major
# has only digits 0–9; rollover uses 2.6.0 instead.
if ! python3 tools/version-policy.py validate "$version" >/dev/null; then
  echo '版本号必须采用 X.Y.Z，每段满十进一，例如 2.5.9 → 2.6.0。' >&2; exit 1
fi
if [[ ! "$subject" =~ ^v${version}：(修复|更新|优化|完善|新增).+ ]]; then
  printf 'Commit 标题格式错误：%s\n要求：v%s：修复/更新内容\n' "$subject" "$version" >&2
  exit 1
fi
if [[ -z "$topic" || "$topic" == *"v$version"* || "$topic" == *"｜"* || "$topic" == *"："* ]]; then
  printf 'Release 主题无效：%s\n需使用不重复版本号的中文内容主题。\n' "$topic" >&2
  exit 1
fi
# Bash Unicode character ranges differ between runner locales (C.UTF-8 vs
# en_US.UTF-8). Validate Han characters by Unicode code point instead.
if ! python3 - "$topic" <<'PYCODE'
import sys
s = sys.argv[1]
raise SystemExit(0 if any(0x3400 <= ord(ch) <= 0x9FFF for ch in s) else 1)
PYCODE
then
  echo 'Release 主题必须包含中文内容。' >&2; exit 1
fi
printf 'Commit：%s\nRelease：v%s｜%s\n' "$subject" "$version" "$topic"
