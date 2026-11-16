#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
check() { bash tools/check-release-format.sh "$@" >/dev/null 2>&1; }
check '2.5.7' 'v2.5.7：优化后台导航与配置指引' '后台导航与配置体验升级'
check '6.0.1' 'v6.0.1：修复 RPC 来源身份与迁移规则' '单支付方式兼容与身份修复'
if check '2.5.7' 'fix: old English commit' '后台导航与配置体验升级'; then exit 1; fi
if check '2.5.7' 'v2.5.6：修复旧版问题' '后台导航与配置体验升级'; then exit 1; fi
if check '2.5.7' 'v2.5.7:修复普通半角冒号' '后台导航与配置体验升级'; then exit 1; fi
if check '2.5.7' 'v2.5.7：优化导航' 'v2.5.7｜后台导航与配置体验升级'; then exit 1; fi
if check '2.5.7' 'v2.5.7：优化导航' 'Backend only'; then exit 1; fi
printf '版本化中文 Commit/Release 格式：7 组测试通过\n'
