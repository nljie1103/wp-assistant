#!/usr/bin/env python3
from pathlib import Path
import base64, io, re, shutil, zipfile

ROOT = Path(__file__).resolve().parents[1]
PART_DIR = ROOT / 'tools' / 'release-2.5.1'


def read(path):
    return (ROOT / path).read_text('utf-8')


def write(path, text):
    p = ROOT / path
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(text, 'utf-8')


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 match, found {count}')
    return text.replace(old, new, 1)


def replace_all_checked(text, old, new, expected_min, label):
    count = text.count(old)
    if count < expected_min:
        raise RuntimeError(f'{label}: expected at least {expected_min} matches, found {count}')
    return text.replace(old, new)


# 1) Expand the complete three-template payload.
parts = sorted(PART_DIR.glob('payload.b64.*'))
if not parts:
    raise RuntimeError('2.5.1 payload parts are missing')
encoded = ''.join(p.read_text('ascii').strip() for p in parts)
payload = base64.b64decode(encoded)
with zipfile.ZipFile(io.BytesIO(payload)) as zf:
    for info in zf.infolist():
        target = (ROOT / info.filename).resolve()
        if ROOT.resolve() not in target.parents and target != ROOT.resolve():
            raise RuntimeError(f'unsafe payload path: {info.filename}')
    zf.extractall(ROOT)

# 2) AI summary: stable main-post lookup, visible card, HTML text extraction.
frontend_path = 'features/ai-article-summary/includes/class-wpaias-frontend.php'
text = read(frontend_path)
helper_anchor = "\tprotected $rendered = array();\n"
helper = r'''

	/** Return the queried main post instead of a related-post loop item. */
	protected function current_post() {
		$post_id = absint( get_queried_object_id() );
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return $post;
			}
		}
		return get_post();
	}

	/** Extract readable text from classic content and custom HTML articles. */
	protected function extract_source_text( $post, $max_chars ) {
		$content = $post instanceof WP_Post ? (string) $post->post_content : '';
		if ( '' === trim( $content ) ) {
			return '';
		}

		$content = preg_replace( '#<(script|style|noscript|iframe|svg)\\b[^>]*>.*?</\\1>#is', ' ', $content );
		$content = strip_shortcodes( $content );
		$content = preg_replace( '#</(?:p|div|section|article|header|footer|h[1-6]|li|ul|ol|blockquote|pre|table|tr|td|th)>#i', "\\n", $content );
		$content = preg_replace( '#<br\\s*/?>#i', "\\n", $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$content = preg_replace( '/[\\t\\x{00A0} ]+/u', ' ', $content );
		$content = preg_replace( '/\\n{3,}/u', "\\n\\n", $content );
		$content = trim( (string) $content );

		$max_chars = max( 2000, min( 100000, (int) $max_chars ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $content, 0, $max_chars ) : substr( $content, 0, $max_chars );
	}
'''
text = replace_once(text, helper_anchor, helper_anchor + helper, 'insert frontend helpers')
text = replace_all_checked(text, "\t\t$post = get_post();", "\t\t$post = $this->current_post();", 4, 'use queried main post')
text = replace_once(
    text,
    "\t\tif ( '' === $summary && empty( $settings['public_generation'] ) && ! current_user_can( 'edit_posts' ) ) {\n\t\t\treturn '';\n\t\t}\n",
    '',
    'remove empty-card early return'
)
text = replace_once(
    text,
    "\t\t$state = ( '' === $summary ) ? 'loading' : 'ready';",
    "\t\t$can_generate = ! empty( $settings['public_generation'] ) || current_user_can( 'edit_posts' );\n\t\t$state = ( '' === $summary ) ? ( $can_generate ? 'loading' : 'waiting' ) : 'ready';",
    'add waiting state'
)
old_empty = """\t\t\t\t<?php if ( '' === $summary ) : ?>
\t\t\t\t\t<div class=\"wpaias-summary__placeholder\">
\t\t\t\t\t\t<span class=\"wpaias-dot\"></span><span class=\"wpaias-dot\"></span><span class=\"wpaias-dot\"></span>
\t\t\t\t\t\t<span class=\"wpaias-summary__loading-text\"><?php esc_html_e( 'AI 摘要生成中…', 'wp-ai-article-summary' ); ?></span>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"wpaias-summary__text\" data-pending=\"1\"></div>
"""
new_empty = """\t\t\t\t<?php if ( '' === $summary ) : ?>
\t\t\t\t\t<div class=\"wpaias-summary__placeholder\">
\t\t\t\t\t\t<?php if ( $can_generate ) : ?>
\t\t\t\t\t\t\t<span class=\"wpaias-dot\"></span><span class=\"wpaias-dot\"></span><span class=\"wpaias-dot\"></span>
\t\t\t\t\t\t\t<span class=\"wpaias-summary__loading-text\"><?php esc_html_e( 'AI 摘要生成中…', 'wp-ai-article-summary' ); ?></span>
\t\t\t\t\t\t<?php else : ?>
\t\t\t\t\t\t\t<span class=\"wpaias-summary__loading-text\"><?php esc_html_e( '摘要暂未生成，管理员生成后会自动显示。', 'wp-ai-article-summary' ); ?></span>
\t\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"wpaias-summary__text\" data-pending=\"1\"></div>
"""
text = replace_once(text, old_empty, new_empty, 'render waiting placeholder')
text = replace_once(
    text,
    "\t\t\t\t'post_id'  => $post ? (int) $post->ID : 0,\n",
    "\t\t\t\t'post_id'       => $post ? (int) $post->ID : 0,\n\t\t\t\t'mobile_enable' => ! empty( $settings['mobile_enable'] ),\n",
    'localize mobile setting'
)
text = replace_once(
    text,
    "\t\t\t\t$content   = wp_strip_all_tags( (string) $post->post_content );\n\t\t\t\t$max_chars = max( 2000, min( 100000, (int) $settings['max_source_chars'] ) );\n\t\t\t\t$content   = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, $max_chars ) : substr( $content, 0, $max_chars );\n",
    "\t\t\t\t$max_chars = max( 2000, min( 100000, (int) $settings['max_source_chars'] ) );\n\t\t\t\t$content   = $this->extract_source_text( $post, $max_chars );\n",
    'custom HTML text extraction'
)
text = replace_once(
    text,
    "\t\t\t\t\tWPAIAS_Cache::set( $post_id, $result['data'], $ttl );\n\t\t\t\t\t$result['cached'] = false;",
    "\t\t\t\t\tWPAIAS_Cache::set( $post_id, $result['data'], $ttl );\n\t\t\t\t\tclean_post_cache( $post_id );\n\t\t\t\t\t$result['cached'] = false;",
    'clear post cache after generation'
)
write(frontend_path, text)

# 3) AI front-end animation: hide only after JS actually initializes the card.
js_path = 'features/ai-article-summary/assets/js/frontend.js'
js = read(js_path)
old_init = """\tfunction initBox( box ) {
\t\tif ( box.dataset.wpaiasInit ) return;
\t\tbox.dataset.wpaiasInit = '1';

\t\tvar state = box.getAttribute( 'data-state' );
\t\tvar delay = parseInt( box.getAttribute( 'data-delay' ), 10 ) || 0;

\t\tif ( 'ready' === state ) {
\t\t\t// 已有缓存，直接动画。
\t\t\tsetTimeout( function () {
\t\t\t\tplayAnimation( box );
\t\t\t}, delay );
\t\t\treturn;
\t\t}

\t\t// 否则 Ajax 拉取。
\t\tfetchSummary( box );
\t}
"""
new_init = """\tfunction initBox( box ) {
\t\tif ( box.dataset.wpaiasInit ) return;
\t\tbox.dataset.wpaiasInit = '1';

\t\tvar state = box.getAttribute( 'data-state' );
\t\tvar delay = parseInt( box.getAttribute( 'data-delay' ), 10 ) || 0;
\t\tvar duration = parseInt( box.getAttribute( 'data-duration' ), 10 ) || 800;
\t\tvar anim = box.getAttribute( 'data-anim' ) || 'none';

\t\tif ( anim !== 'none' && state !== 'waiting' ) {
\t\t\tbox.classList.add( 'wpaias-anim-prepared' );
\t\t\twindow.setTimeout( function () {
\t\t\t\tbox.classList.remove( 'wpaias-anim-prepared' );
\t\t\t}, delay + duration + 1500 );
\t\t}

\t\tif ( 'ready' === state ) {
\t\t\tsetTimeout( function () {
\t\t\t\tplayAnimation( box );
\t\t\t}, delay );
\t\t\treturn;
\t\t}

\t\tif ( 'waiting' === state ) {
\t\t\treturn;
\t\t}

\t\tfetchSummary( box );
\t}
"""
js = replace_once(js, old_init, new_init, 'prepare animation only after JS init')
write(js_path, js)

css_path = 'features/ai-article-summary/assets/css/frontend.css'
css = read(css_path)
for anim in ('fade', 'slide-up', 'slide-down', 'zoom', 'bounce', 'neon'):
    old = f'.wpaias-summary.wpaias-anim-{anim} .wpaias-summary__text {{'
    new = f'.wpaias-summary.wpaias-anim-{anim}.wpaias-anim-prepared .wpaias-summary__text {{'
    css = replace_once(css, old, new, f'animation fallback {anim}')
write(css_path, css)

# 4) Internal module versions and plugin version.
bootstrap = read('features/ai-article-summary/bootstrap.php')
bootstrap = replace_once(bootstrap, "define( 'WPAIAS_VERSION', '1.1.0' );", "define( 'WPAIAS_VERSION', '1.1.1' );", 'AI module version')
write('features/ai-article-summary/bootstrap.php', bootstrap)

registry = read('includes/class-jlwa-feature-registry.php')
registry = replace_once(
    registry,
    "\t\t\t\t'version'     => '1.1.0',\n\t\t\t\t'entry_class' => 'JLWA_AI_Summary_Feature',",
    "\t\t\t\t'version'     => '1.1.1',\n\t\t\t\t'entry_class' => 'JLWA_AI_Summary_Feature',",
    'registry AI version'
)
registry = replace_once(
    registry,
    "\t\t\t\t'version'     => '1.0.0',\n\t\t\t\t'entry_class' => 'JLWA_Download_Page_Feature',",
    "\t\t\t\t'version'     => '1.1.0',\n\t\t\t\t'entry_class' => 'JLWA_Download_Page_Feature',",
    'registry download version'
)
registry = registry.replace(
    "'description' => '三套子比资源下载页模板、五种封面策略、自动回退与插件级模板接管。',",
    "'description' => '三套完整子比资源中心模板、真实账户与额度信息、五种封面策略及横竖图自适应。',"
)
write('includes/class-jlwa-feature-registry.php', registry)

main = read('jiuliu-wp-assistant.php')
main = re.sub(r'(?m)^ \* Version: 2\.5\.0$', ' * Version: 2.5.1', main, count=1)
main = replace_once(main, "define( 'JLWA_VERSION', '2.5.0' );", "define( 'JLWA_VERSION', '2.5.1' );", 'plugin constant')
write('jiuliu-wp-assistant.php', main)

readme = read('readme.txt')
readme = re.sub(r'(?m)^Stable tag: 2\.5\.0$', 'Stable tag: 2.5.1', readme, count=1)
if '== Changelog ==' in readme and '= 2.5.1 =' not in readme:
    readme = readme.replace('== Changelog ==', '== Changelog ==\n\n= 2.5.1 =\n* 修复 AI 摘要有调用但前端卡片不显示、页脚拿错文章和动画失败透明问题。\n* 自定义 HTML 正文改为提取可见文字后生成摘要。\n* 恢复科技蓝紫、简洁商务、VIP 资源中心三套完整下载页及横竖图自动识别。', 1)
write('readme.txt', readme)

readme_md = read('README.md')
readme_md = re.sub(r'(?m)^# 九流WP助手 2\.5\.0$', '# 九流WP助手 2.5.1', readme_md, count=1)
write('README.md', readme_md)

changelog = read('CHANGELOG.md')
if '## 2.5.1' not in changelog:
    section = '''## 2.5.1 — 2026-08-05\n\n- 修复 AI 摘要生成成功但前端不输出卡片的 2.1.0 回归。\n- DOM 兜底固定主文章，自定义 HTML 提取可见文字，动画失败时直接显示。\n- 恢复科技蓝紫、简洁商务、VIP 资源中心三套完整下载页模板。\n- 恢复账户、会员额度、热门资源、公告、资源属性、密码信息和下载步骤。\n- 三套模板恢复横图、方图、竖图自动识别与对应容器比例。\n\n'''
    marker = changelog.find('## ')
    changelog = changelog[:marker] + section + changelog[marker:] if marker >= 0 else section + changelog
write('CHANGELOG.md', changelog)

# Remove obsolete simplified front-end stylesheet; templates carry their own complete CSS.
old_css = ROOT / 'assets/css/download-page.css'
if old_css.exists():
    old_css.unlink()

# Remove temporary release payload after final source has been written.
shutil.rmtree(PART_DIR, ignore_errors=True)
try:
    Path(__file__).unlink()
except OSError:
    pass
