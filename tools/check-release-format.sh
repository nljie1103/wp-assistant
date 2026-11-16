#!/usr/bin/env bash
# Require versioned Chinese commit subjects and a unique, descriptive Chinese release title.
set -euo pipefail
if [ "$#" -ne 3 ]; then
  echo '用法：check-release-format.sh 版本号 Commit主题 Release主题' >&2
  exit 2
fi
version="$1"
subject="$2"
topic="$3"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo '版本号必须采用 X.Y.Z。' >&2; exit 1
fi
if [[ ! "$subject" =~ ^v${version}：(修复|更新|优化|完善|新增).+ ]]; then
  printf 'Commit 标题格式错误：%s\n要求：v%s：修复/更新内容\n' "$subject" "$version" >&2
  exit 1
fi
if [[ -z "$topic" || "$topic" == *"v$version"* || "$topic" == *"｜"* || "$topic" == *"："* ]]; then
  printf 'Release 主题无效：%s\n需使用不重复版本号的中文内容主题。\n' "$topic" >&2
  exit 1
fi
if ! [[ "$topic" =~ [一-龥] ]]; then
  echo 'Release 主题必须包含中文内容。' >&2; exit 1
fi
printf 'Commit：%s\nRelease：v%s｜%s\n' "$subject" "$version" "$topic"
