#!/usr/bin/env python3
from pathlib import Path
import re


def remove_method(path, method):
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    rx = re.compile(r'(?:(?:public|protected|private)\s+)?(?:static\s+)?function\s+' + re.escape(method) + r'\s*\([^)]*\)\s*\{')
    match = rx.search(text)
    if not match:
        raise RuntimeError(f'method not found: {path}:{method}')
    start = match.start()
    prefix = text[:start]
    doc_start = prefix.rfind('/**')
    doc_end = prefix.rfind('*/')
    if doc_start >= 0 and doc_end >= doc_start and not prefix[doc_end + 2:].strip():
        start = doc_start
    open_pos = text.find('{', match.start(), match.end())
    depth = 0
    state = 'normal'
    i = open_pos
    while i < len(text):
        ch = text[i]
        nxt = text[i + 1] if i + 1 < len(text) else ''
        if state == 'normal':
            if ch == "'": state = 'single'
            elif ch == '"': state = 'double'
            elif ch == '/' and nxt == '/': state = 'line'; i += 1
            elif ch == '#': state = 'line'
            elif ch == '/' and nxt == '*': state = 'block'; i += 1
            elif ch == '{': depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    end = i + 1
                    while end < len(text) and text[end] in ' \t\r\n': end += 1
                    path.write_text(text[:start] + text[end:], encoding='utf-8')
                    return
        elif state == 'single':
            if ch == '\\': i += 1
            elif ch == "'": state = 'normal'
        elif state == 'double':
            if ch == '\\': i += 1
            elif ch == '"': state = 'normal'
        elif state == 'line':
            if ch == '\n': state = 'normal'
        elif state == 'block':
            if ch == '*' and nxt == '/': state = 'normal'; i += 1
        i += 1
    raise RuntimeError(f'unclosed method: {path}:{method}')


admin = Path('includes/class-jlwa-admin.php')
text = admin.read_text(encoding='utf-8')
if "rename_dashboard_submenu" not in text:
    text = text.replace(
        "\t\tadd_action( 'admin_menu', array( $this, 'register_menus' ), 20 );\n",
        "\t\tadd_action( 'admin_menu', array( $this, 'register_menus' ), 20 );\n\t\tadd_action( 'admin_menu', array( $this, 'rename_dashboard_submenu' ), 999 );\n",
        1,
    )
    duplicate = """\t\tadd_submenu_page(\n\t\t\tJLWA_MENU_SLUG,\n\t\t\t'助手总览',\n\t\t\t'助手总览',\n\t\t\t'manage_options',\n\t\t\tJLWA_MENU_SLUG,\n\t\t\tarray( $this, 'render_dashboard' )\n\t\t);\n"""
    if duplicate not in text:
        raise RuntimeError('dashboard submenu block not found')
    text = text.replace(duplicate, '', 1)
    marker = "\n\t/** @param string $key Feature key. @return array<int,mixed> */\n\tprotected function feature_callback"
    method = """
\t/** Rename WordPress' automatic first submenu. */
\tpublic function rename_dashboard_submenu() {
\t\tglobal $submenu;
\t\tif ( isset( $submenu[ JLWA_MENU_SLUG ][0][0] ) ) {
\t\t\t$submenu[ JLWA_MENU_SLUG ][0][0] = '助手总览';
\t\t}
\t}
"""
    if marker not in text:
        raise RuntimeError('admin insertion marker not found')
    text = text.replace(marker, method + marker, 1)
    admin.write_text(text, encoding='utf-8')

page = Path('features/page-effects/bootstrap.php')
ptext = page.read_text(encoding='utf-8').replace("            set_transient( 'xjpe_activated_notice', 1, 60 );\n", '')
page.write_text(ptext, encoding='utf-8')
for method_name in ('add_admin_menu', 'activation_notice', 'plugin_action_links'):
    if re.search(r'function\s+' + method_name + r'\s*\(', page.read_text(encoding='utf-8')):
        remove_method(page, method_name)

ai = Path('features/ai-article-summary/includes/class-wpaias-admin.php')
atext = ai.read_text(encoding='utf-8').replace("\n\t\t// 插件页操作链接。\n", "\n")
ai.write_text(atext, encoding='utf-8')
for method_name in ('plugin_action_links', 'add_menu'):
    if re.search(r'function\s+' + method_name + r'\s*\(', ai.read_text(encoding='utf-8')):
        remove_method(ai, method_name)

pre = Path('features/immersive-preloader/includes/class-jip-admin.php')
pre_text = pre.read_text(encoding='utf-8').replace("\t\tadd_action( 'admin_menu', array( $this, 'register_menu' ) );\n", '')
pre.write_text(pre_text, encoding='utf-8')
if re.search(r'function\s+register_menu\s*\(', pre.read_text(encoding='utf-8')):
    remove_method(pre, 'register_menu')

media = Path('features/relative-media-urls/includes/class-jrmu-admin.php')
media_text = media.read_text(encoding='utf-8').replace("\t\tadd_action( 'admin_menu', array( $this, 'add_admin_menu' ) );\n", '')
media.write_text(media_text, encoding='utf-8')
if re.search(r'function\s+add_admin_menu\s*\(', media.read_text(encoding='utf-8')):
    remove_method(media, 'add_admin_menu')
