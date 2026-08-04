#!/usr/bin/env python3
from pathlib import Path
import re
import shutil

ROOT = Path('.')


def read(path):
    return Path(path).read_text(encoding='utf-8')


def write(path, content):
    Path(path).write_text(content, encoding='utf-8')


def replace_method_body(path, method, new_body='\n\t\treturn;\n\t'):
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    pattern = re.compile(r'(?:(?:public|protected|private)\s+)?(?:static\s+)?function\s+' + re.escape(method) + r'\s*\([^)]*\)\s*\{')
    match = pattern.search(text)
    if not match:
        raise RuntimeError(f'method not found: {path}:{method}')
    open_pos = text.find('{', match.start(), match.end())
    depth = 0
    state = 'normal'
    i = open_pos
    while i < len(text):
        ch = text[i]
        nxt = text[i + 1] if i + 1 < len(text) else ''
        if state == 'normal':
            if ch == "'":
                state = 'single'
            elif ch == '"':
                state = 'double'
            elif ch == '/' and nxt == '/':
                state = 'line'
                i += 1
            elif ch == '#':
                state = 'line'
            elif ch == '/' and nxt == '*':
                state = 'block'
                i += 1
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    text = text[:open_pos + 1] + new_body + text[i:]
                    path.write_text(text, encoding='utf-8')
                    return
        elif state == 'single':
            if ch == '\\':
                i += 1
            elif ch == "'":
                state = 'normal'
        elif state == 'double':
            if ch == '\\':
                i += 1
            elif ch == '"':
                state = 'normal'
        elif state == 'line':
            if ch == '\n':
                state = 'normal'
        elif state == 'block':
            if ch == '*' and nxt == '/':
                state = 'normal'
                i += 1
        i += 1
    raise RuntimeError(f'unclosed method: {path}:{method}')


def remove_lines(path, fragments):
    path = Path(path)
    lines = path.read_text(encoding='utf-8').splitlines(True)
    path.write_text(''.join(line for line in lines if not any(fragment in line for fragment in fragments)), encoding='utf-8')


# Convert the package-style modules directory into internal feature domains.
if Path('modules').exists():
    if Path('features').exists():
        raise RuntimeError('features already exists while modules is still present')
    shutil.move('modules', 'features')

# Remove nested plugin metadata and uninstall lifecycles.
for feature in Path('features').iterdir():
    if not feature.is_dir():
        continue
    for name in ('.gitignore', 'LICENSE', 'uninstall.php'):
        target = feature / name
        if target.exists():
            target.unlink()

# Page effects: keep the mature implementation, remove standalone-plugin identity.
page_old = Path('features/page-effects/wp-page-effects.php')
page_boot = Path('features/page-effects/bootstrap.php')
if page_old.exists():
    page_old.rename(page_boot)
page = read(page_boot)
page = re.sub(
    r'^<\?php\s*/\*\*.*?\*/',
    "<?php\n/** Internal page effects feature. */",
    page,
    count=1,
    flags=re.S,
)
page = page.replace('XJPE_Plugin', 'JLWA_Page_Effects_Feature')
write(page_boot, page)
remove_lines(page_boot, (
    "add_action( 'admin_menu'",
    "add_action( 'admin_notices', array( $this, 'activation_notice'",
    "add_filter( 'plugin_action_links_'",
    'register_activation_hook(',
))
replace_method_body(page_boot, 'add_admin_menu')
replace_method_body(page_boot, 'activation_notice')

# AI summary: replace the old plugin bootstrap, rename only the entry class,
# and keep all focused service classes intact.
ai_root = Path('features/ai-article-summary')
ai_old = ai_root / 'wp-ai-article-summary.php'
if ai_old.exists():
    ai_old.unlink()
shutil.copyfile('.refactor/ai-bootstrap.php', ai_root / 'bootstrap.php')
for path in ai_root.rglob('*'):
    if path.is_file() and path.suffix.lower() in {'.php', '.js'}:
        text = path.read_text(encoding='utf-8', errors='strict')
        path.write_text(text.replace('WPAIAS_Plugin', 'JLWA_AI_Summary_Feature'), encoding='utf-8')
ai_admin = ai_root / 'includes/class-wpaias-admin.php'
remove_lines(ai_admin, (
    "add_action( 'admin_menu'",
    "add_filter( 'plugin_action_links_'",
))
replace_method_body(ai_admin, 'add_menu')

# Preloader and media/URL features receive internal bootstraps with no plugin hooks.
pre_root = Path('features/immersive-preloader')
pre_old = pre_root / 'jiuliu-immersive-preloader.php'
if pre_old.exists():
    pre_old.unlink()
shutil.copyfile('.refactor/preloader-bootstrap.php', pre_root / 'bootstrap.php')
replace_method_body(pre_root / 'includes/class-jip-admin.php', 'register_menu')

media_root = Path('features/relative-media-urls')
media_old = media_root / 'jiuliu-relative-media-urls.php'
if media_old.exists():
    media_old.unlink()
shutil.copyfile('.refactor/media-bootstrap.php', media_root / 'bootstrap.php')
replace_method_body(media_root / 'includes/class-jrmu-admin.php', 'add_admin_menu')

# Retire the old package loader and temporary audit/staging files.
for path in (
    'includes/class-jlwa-module-loader.php',
    'refactor-inventory.txt',
    '.github/workflows/refactor-inventory.yml',
    '.github/workflows/unified-plugin-v2.yml',
    '.refactor/ai-bootstrap.php',
    '.refactor/preloader-bootstrap.php',
    '.refactor/media-bootstrap.php',
    '.refactor/refactor.py',
):
    target = Path(path)
    if target.exists():
        target.unlink()

for directory in (Path('.refactor'), Path('.github/workflows'), Path('.github')):
    if directory.exists() and not any(directory.iterdir()):
        directory.rmdir()

# Refresh updater wording; behavior remains the hardened backup/hash/rollback flow.
updater = Path('includes/class-jlwa-updater.php')
text = updater.read_text(encoding='utf-8')
text = text.replace('Safe suite updater', 'Safe unified plugin updater')
text = text.replace('安全更新九流WP助手套件', '安全更新九流WP助手')
updater.write_text(text, encoding='utf-8')
