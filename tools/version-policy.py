#!/usr/bin/env python3
"""One release version, decimal carry, and asset cache coherence.

Versions on *internal module options* are schema migration markers, not
independently released plugins. They must not be mass-renumbered.
"""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
VERSION_PATTERN = re.compile(r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$")


def parse(value, canonical=True):
    match = VERSION_PATTERN.fullmatch(value)
    if not match:
        raise ValueError('版本必须是无前导零的 X.Y.Z 三段十进制数：' + value)
    major, minor, patch = map(int, match.groups())
    if canonical and (minor > 9 or patch > 9):
        raise ValueError('十进制版本必须满十进一，禁止尾段达到 10：' + value)
    return major, minor, patch


def next_version(value):
    major, minor, patch = parse(value, canonical=False)
    # Repair legacy 2.5.10/2.5.11 with one correction release 2.6.0.
    if minor >= 10:
        major += 1
        minor, patch = 0, 0
    elif patch >= 10:
        minor += 1
        patch = 0
    else:
        patch += 1
        if patch >= 10:
            patch = 0
            minor += 1
    if minor >= 10:
        minor = 0
        major += 1
    return '{}.{}.{}'.format(major, minor, patch)


def fail(message):
    raise ValueError(message)


def current_version():
    code = (ROOT / 'jiuliu-wp-assistant.php').read_text(encoding='utf-8')
    header = re.search(r'^ \* Version: ([\d.]+)\s*$', code, re.M)
    const = re.search(r"define\( 'JLWA_VERSION', '([\d.]+)' \);", code)
    stable = re.search(r'^Stable tag: ([\d.]+)\s*$', (ROOT / 'readme.txt').read_text(encoding='utf-8'), re.M)
    if not (header and const and stable):
        fail('主插件头、JLWA_VERSION 常量或 readme.txt Stable tag 缺失')
    versions = [header.group(1), const.group(1), stable.group(1)]
    if len(set(versions)) != 1:
        fail('三个对外版本号不一致：{}'.format(', '.join(versions)))
    parse(versions[0])
    return versions[0]


def iter_register_calls(source):
    # A simple balanced-parentheses scanner, not a PHP parser. Only use this
    # as a safety check for the known custom WordPress resource enqueue calls.
    pattern = r'wp_(?:enqueue|register)_(?:script|style)\s*\('
    for match in re.finditer(pattern, source):
        depth, quote, escaped = 1, None, False
        i = match.end()
        while i < len(source) and depth:
            char = source[i]
            if quote:
                if escaped:
                    escaped = False
                elif char == '\\':
                    escaped = True
                elif char == quote:
                    quote = None
            elif char in "\"'":
                quote = char
            elif char == '(':
                depth += 1
            elif char == ')':
                depth -= 1
            i += 1
        yield source[match.start():i]


def check():
    version = current_version()
    release_note = ROOT / ('RELEASE_NOTES_' + version + '.md')
    if not release_note.is_file():
        fail('当前版本没有对应的发布说明 ' + str(release_note.name))
    first = release_note.read_text(encoding='utf-8').splitlines()[0]
    if not re.fullmatch(r'<!-- release-title: [^<>]+ -->', first):
        fail('当前发布说明缺少中文 Release 标题')

    # Every registered feature must follow the one plugin lifecycle. Avoid a fixed count when adding modules.
    registry = (ROOT / 'includes/class-jlwa-feature-registry.php').read_text(encoding='utf-8')
    reg_versions = re.findall(r"'version'\s*=>\s*([^,\n]+),", registry)
    if not reg_versions or any(v.strip() != 'JLWA_VERSION' for v in reg_versions):
        fail('功能注册表版本不统一：' + str(reg_versions))

    total = 0
    for base in ('features', 'includes'):
        for path in (ROOT / base).rglob('*.php'):
            data = path.read_text(encoding='utf-8')
            for call in iter_register_calls(data):
                # Built-in WP handles like wp-color-picker and wpaias-admin
                # enqueue without URL are fine: their registration is checked.
                if ('assets/' in call or 'assets\\/' in call) and 'JLWA_VERSION' not in call:
                    fail('资源未使用统一版本作为缓存号：{}: {}'.format(path.relative_to(ROOT), call[:130]))
                if 'assets/' in call or 'assets\\/' in call:
                    total += 1
    if total < 25:
        fail('检查到的 CSS/JS 资源数量异常，可能遗漏了资源：{}'.format(total))
    print('版本校验通过：v{}；功能域={}；带统一版本的资源注册={} 项'.format(version, len(reg_versions), total))
    return version


def tags():
    result = subprocess.run(['git', 'tag', '--list', 'v*'], cwd=str(ROOT),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            text=True, check=True)
    versions = []
    for raw in result.stdout.splitlines():
        try:
            versions.append(parse(raw[1:], canonical=False))
        except ValueError:
            continue
    return sorted(set(versions))


def valid_transition(previous, current):
    """Allow strict decimal carry OR an explicit major-zero release.

    Normal minor/patch changes can never skip (e.g. 2.7.4 -> 2.7.6).
    A purposefully designed major release may start 3.0.0 without requiring
    2.7.5 ... 2.9.9 first. The user must still change the versioned release
    notes and keep tags immutable.
    """
    previous = parse(previous) if isinstance(previous, str) else tuple(previous)
    current = parse(current) if isinstance(current, str) else tuple(current)
    expected = next_version('{}.{}.{}'.format(*previous))
    actual = '{}.{}.{}'.format(*current)
    return actual == expected or (
        current[0] == previous[0] + 1 and current[1:] == (0, 0)
    )


def verify_history():
    current = parse(check())
    previous = tags()
    if not previous:
        fail('没有找到 Git 历史标签，发布前必须先 git fetch --tags')
    latest = previous[-1]
    expected = next_version('{}.{}.{}'.format(*latest))
    actual = '{}.{}.{}'.format(*current)
    if not valid_transition(latest, current):
        fail('版本递增规则错误：上版 v{}，下版须为 v{}（连续进位）或显式升主版本 v{}.0.0，实际为 v{}'.format(
            '{}.{}.{}'.format(*latest), expected, latest[0] + 1, actual))
    kind = '独立大版本' if current[0] > latest[0] and actual != expected else '连续进位'
    print('历史标签版本校验通过（{}）：v{} → v{}'.format(kind, '{}.{}.{}'.format(*latest), actual))


def main():
    if len(sys.argv) == 2 and sys.argv[1] == 'check':
        check()
    elif len(sys.argv) == 3 and sys.argv[1] == 'next':
        print(next_version(sys.argv[2]))
    elif len(sys.argv) == 3 and sys.argv[1] == 'validate':
        parse(sys.argv[2])
        print('版本合法：v' + sys.argv[2])
    elif len(sys.argv) == 2 and sys.argv[1] == 'verify-history':
        verify_history()
    else:
        fail('用法：version-policy.py check | next <旧版本> | validate <版本> | verify-history')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print('版本规范错误：' + str(error), file=sys.stderr)
        sys.exit(1)
