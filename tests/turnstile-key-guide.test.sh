#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DOC='docs/turnstile-keys-guide.md'
test -s "$DOC"
# README keeps details in a dedicated guide, with a single clear entry.
grep -Fq '[Turnstile 密钥获取与配置教程](docs/turnstile-keys-guide.md)' README.md
grep -Fq '[Cloudflare Turnstile 密钥获取与配置教程](turnstile-keys-guide.md)' docs/oauth-turnstile-security.md
# Dashboard and both credential fields must link to the exact same versioned-repository guide.
grep -Fq "https://github.com/nljie1103/wp-assistant/blob/main/$DOC" features/login-security/bootstrap.php
for title in 'Site Key' 'Secret Key' 'Add widget' 'Hostname management' 'Managed' 'Secret Key 不能'; do
  grep -Fq "$title" "$DOC"
done
grep -Fq 'esc_url( $keys_guide_url )' features/login-security/bootstrap.php
grep -Fq 'rel="noopener noreferrer"' features/login-security/bootstrap.php
printf 'Turnstile key-guide links, required steps and admin help: OK\n'
