#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('.')


def replace_once(path: str, old: str, new: str) -> None:
    file = ROOT / path
    text = file.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'missing replacement target in {path}: {old[:80]!r}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


admin = 'includes/class-jlwa-admin.php'
old_enqueue = """\t/** @param string $hook Current admin hook. */
\tpublic function enqueue_assets( $hook ) {
\t\t$page = $this->current_page();
\t\tif ( ! $this->is_plugin_page( $page ) ) {
\t\t\treturn;
\t\t}
\t\twp_enqueue_style( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/css/admin.css', array(), JLWA_VERSION );
\t\twp_enqueue_script( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/js/admin.js', array(), JLWA_VERSION, true );
\t\twp_localize_script(
\t\t\t'jlwa-admin',
\t\t\t'JLWA_ADMIN',
\t\t\tarray(
\t\t\t\t'ajaxUrl' => admin_url( 'admin-ajax.php' ),
\t\t\t\t'nonce'   => wp_create_nonce( 'jlwa_update_nonce' ),
\t\t\t\t'page'    => $page,
\t\t\t)
\t\t);
\t}
"""
new_enqueue = """\t/** @param string $hook Current admin hook. */
\tpublic function enqueue_assets( $hook ) {
\t\t$page = $this->current_page();
\t\tif ( ! $this->is_plugin_page( $page ) ) {
\t\t\treturn;
\t\t}

\t\t$feature_key = JLWA_Feature_Registry::key_from_slug( $page );
\t\tif ( '' !== $feature_key ) {
\t\t\t$this->enqueue_feature_assets( $feature_key );
\t\t}

\t\twp_enqueue_style( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/css/admin.css', array(), JLWA_VERSION );
\t\twp_enqueue_style( 'jlwa-admin-content', JLWA_PLUGIN_URL . 'assets/css/admin-content.css', array( 'jlwa-admin' ), JLWA_VERSION );
\t\twp_enqueue_script( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/js/admin.js', array(), JLWA_VERSION, true );
\t\twp_enqueue_script( 'jlwa-admin-content', JLWA_PLUGIN_URL . 'assets/js/admin-content.js', array( 'jlwa-admin' ), JLWA_VERSION, true );
\t\twp_localize_script(
\t\t\t'jlwa-admin',
\t\t\t'JLWA_ADMIN',
\t\t\tarray(
\t\t\t\t'ajaxUrl' => admin_url( 'admin-ajax.php' ),
\t\t\t\t'nonce'   => wp_create_nonce( 'jlwa_update_nonce' ),
\t\t\t\t'page'    => $page,
\t\t\t)
\t\t);
\t}

\t/**
\t * Force-load each feature's mature assets with the exact submenu hook.
\t * This avoids pages becoming unstyled when a legacy hook suffix changes.
\t *
\t * @param string $key Feature key.
\t */
\tprotected function enqueue_feature_assets( $key ) {
\t\t$feature = JLWA_Feature_Registry::get( $key );
\t\tif ( ! $feature ) {
\t\t\treturn;
\t\t}
\t\t$feature_hook = JLWA_MENU_SLUG . '_page_' . $feature['slug'];

\t\tswitch ( $key ) {
\t\t\tcase 'page-effects':
\t\t\t\tif ( class_exists( 'JLWA_Page_Effects_Feature' ) ) {
\t\t\t\t\tJLWA_Page_Effects_Feature::instance()->enqueue_admin_assets( $feature_hook );
\t\t\t\t}
\t\t\t\tbreak;
\t\t\tcase 'ai-summary':
\t\t\t\tif ( class_exists( 'JLWA_AI_Summary_Feature' ) ) {
\t\t\t\t\t$plugin = JLWA_AI_Summary_Feature::instance();
\t\t\t\t\tif ( isset( $plugin->admin ) && is_object( $plugin->admin ) ) {
\t\t\t\t\t\t$plugin->admin->enqueue_assets( $feature_hook );
\t\t\t\t\t}
\t\t\t\t}
\t\t\t\tbreak;
\t\t\tcase 'preloader':
\t\t\t\tif ( class_exists( 'JIP_Admin' ) ) {
\t\t\t\t\tJIP_Admin::instance()->enqueue_assets( $feature_hook );
\t\t\t\t}
\t\t\t\tbreak;
\t\t\tcase 'media-urls':
\t\t\t\tif ( class_exists( 'JRMU_Admin' ) ) {
\t\t\t\t\tJRMU_Admin::instance()->enqueue_assets( $feature_hook );
\t\t\t\t}
\t\t\t\tbreak;
\t\t}
\t}
"""
replace_once(admin, old_enqueue, new_enqueue)

old_feature = """\t\t} else {
\t\t\techo '<div class=\"jlwa-feature-host jlwa-feature-host--' . esc_attr( $key ) . '\">';
\t\t\tJLWA_Feature_Registry::render_admin( $key );
\t\t\techo '</div>';
\t\t}
"""
new_feature = """\t\t} else {
\t\t\t$state = JLWA_Feature_Registry::state( $key );
\t\t\techo '<section class=\"jlwa-feature-intro jlwa-feature-intro--' . esc_attr( $key ) . '\">';
\t\t\techo '<span class=\"jlwa-feature-intro__icon\"><span class=\"dashicons ' . esc_attr( $feature['icon'] ) . '\"></span></span>';
\t\t\techo '<div><h2>' . esc_html( $feature['label'] ) . '设置中心</h2><p>所有设置都属于九流WP助手本体，保存后继续沿用原有数据，不需要重复配置。</p></div>';
\t\t\techo '<div class=\"jlwa-feature-intro__meta\"><span class=\"jlwa-feature-intro__version\">功能 v' . esc_html( JLWA_Feature_Registry::version( $key ) ) . '</span><span class=\"jlwa-feature-intro__state is-' . esc_attr( $state['tone'] ) . '\">' . esc_html( $state['label'] ) . '</span></div>';
\t\t\techo '</section>';
\t\t\techo '<div class=\"jlwa-feature-canvas\"><div class=\"jlwa-feature-host jlwa-feature-host--' . esc_attr( $key ) . '\">';
\t\t\tJLWA_Feature_Registry::render_admin( $key );
\t\t\techo '</div></div>';
\t\t}
"""
replace_once(admin, old_feature, new_feature)

# Version bump.
replace_once('jiuliu-wp-assistant.php', ' * Version: 2.0.0', ' * Version: 2.0.1')
replace_once('jiuliu-wp-assistant.php', "define( 'JLWA_VERSION', '2.0.0' );", "define( 'JLWA_VERSION', '2.0.1' );")

readme = ROOT / 'readme.txt'
text = readme.read_text(encoding='utf-8')
text = text.replace('Stable tag: 2.0.0', 'Stable tag: 2.0.1', 1)
marker = '== Changelog ==\n\n'
entry = """= 2.0.1 =
* 修复功能页旧资源 hook 不匹配导致的正文无样式问题。
* 由统一后台强制调度四项功能的 CSS、JavaScript、媒体上传器和颜色选择器资源。
* 新增统一功能状态卡、内容画布、卡片、表单、选项卡和响应式布局。
* 重做页面美化特效卡片、AI 摘要设置、预加载效果网格和媒体扫描诊断界面。
* 增加未保存修改提示与选择卡片状态同步。

"""
if marker not in text:
    raise SystemExit('readme changelog marker missing')
text = text.replace(marker, marker + entry, 1)
readme.write_text(text, encoding='utf-8')

readme_md = ROOT / 'README.md'
if readme_md.exists():
    md = readme_md.read_text(encoding='utf-8')
    md = md.replace('# 九流WP助手 2.0', '# 九流WP助手 2.0.1', 1)
    md += "\n\n## 2.0.1 后台界面修复\n\n统一加载四项功能的后台资源，并重做功能正文的卡片、表单、选项卡与响应式排版。\n"
    readme_md.write_text(md, encoding='utf-8')

# Remove a harmless typo in the new stylesheet.
css = ROOT / 'assets/css/admin-content.css'
css_text = css.read_text(encoding='utf-8').replace('\n\tdis: block;\n', '\n')
css.write_text(css_text, encoding='utf-8')

# Self-clean temporary release machinery.
for temp in (ROOT / '.ui-fix', ROOT / '.github/workflows/apply-admin-ui-201.yml'):
    if temp.is_dir():
        import shutil
        shutil.rmtree(temp)
    elif temp.exists():
        temp.unlink()
