from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def replace(path, old, new, count=1):
    p = ROOT / path
    text = p.read_text(encoding='utf-8')
    if old not in text:
        raise SystemExit(f'Missing expected text in {path}: {old[:100]!r}')
    text = text.replace(old, new, count)
    p.write_text(text, encoding='utf-8')


def prepend_after(path, marker, block):
    p = ROOT / path
    text = p.read_text(encoding='utf-8')
    if block.strip() in text:
        return
    if marker not in text:
        raise SystemExit(f'Missing marker in {path}: {marker!r}')
    text = text.replace(marker, marker + block, 1)
    p.write_text(text, encoding='utf-8')

# 主插件版本与描述。
replace('jiuliu-wp-assistant.php', ' * Description: 一个统一、完整的 WordPress 增强插件，集成页面美化、反调试保护、沉浸式预加载、媒体与多域名链接管理、AI 文章摘要。', ' * Description: 一个统一、完整的 WordPress 增强插件，集成页面美化、反调试保护、沉浸式预加载、媒体与多域名链接管理、AI 文章摘要和子比下载页美化。')
replace('jiuliu-wp-assistant.php', ' * Version: 2.4.0', ' * Version: 2.5.0')
replace('jiuliu-wp-assistant.php', "define( 'JLWA_VERSION', '2.4.0' );", "define( 'JLWA_VERSION', '2.5.0' );")

# 注册第六功能域。
feature_block = """\t\t\t'download-page' => array(\n\t\t\t\t'label'       => '下载页美化',\n\t\t\t\t'short_label' => '下载页美化',\n\t\t\t\t'icon'        => 'dashicons-download',\n\t\t\t\t'slug'        => 'jlwa-download-page',\n\t\t\t\t'version'     => '1.0.0',\n\t\t\t\t'entry_class' => 'JLWA_Download_Page_Feature',\n\t\t\t\t'bootstrap'   => JLWA_PLUGIN_DIR . 'features/download-page.php',\n\t\t\t\t'description' => '三套子比资源下载页模板、五种封面策略、自动回退与插件级模板接管。',\n\t\t\t\t'eyebrow'     => 'DOWNLOAD EXPERIENCE',\n\t\t\t\t'standalone'  => array(),\n\t\t\t),\n"""
replace('includes/class-jlwa-feature-registry.php', "\t\t\t'media-urls' => array(\n", feature_block + "\n\t\t\t'media-urls' => array(\n")
replace('includes/class-jlwa-feature-registry.php', "\t\tif ( ! empty( self::$statuses['media-urls']['loaded'] ) && class_exists( 'JLWA_Media_Urls_Feature' ) ) {\n", "\t\tif ( ! empty( self::$statuses['download-page']['loaded'] ) && class_exists( 'JLWA_Download_Page_Feature' ) ) {\n\t\t\tJLWA_Download_Page_Feature::activate();\n\t\t}\n\t\tif ( ! empty( self::$statuses['media-urls']['loaded'] ) && class_exists( 'JLWA_Media_Urls_Feature' ) ) {\n")
replace('includes/class-jlwa-feature-registry.php', "\t\t\tcase 'media-urls':\n\t\t\t\treturn defined( 'JRMU_VERSION' ) ? (string) JRMU_VERSION : $feature['version'];\n", "\t\t\tcase 'download-page':\n\t\t\t\treturn class_exists( 'JLWA_Download_Page_Feature', false ) ? (string) JLWA_Download_Page_Feature::VERSION : $feature['version'];\n\t\t\tcase 'media-urls':\n\t\t\t\treturn defined( 'JRMU_VERSION' ) ? (string) JRMU_VERSION : $feature['version'];\n")
replace('includes/class-jlwa-feature-registry.php', "\t\t\tcase 'media-urls':\n\t\t\t\tif ( class_exists( 'JRMU_Admin' ) ) {\n", "\t\t\tcase 'download-page':\n\t\t\t\tif ( class_exists( 'JLWA_Download_Page_Feature' ) ) {\n\t\t\t\t\tJLWA_Download_Page_Feature::instance()->render_admin_page();\n\t\t\t\t}\n\t\t\t\tbreak;\n\t\t\tcase 'media-urls':\n\t\t\t\tif ( class_exists( 'JRMU_Admin' ) ) {\n")
replace('includes/class-jlwa-feature-registry.php', "\t\t\tcase 'media-urls':\n\t\t\t\t$options = get_option( 'jiuliu_relative_media_urls_options', array() );\n", "\t\t\tcase 'download-page':\n\t\t\t\t$options = get_option( 'jlwa_download_page_options', array() );\n\t\t\t\treturn ! empty( $options['enabled'] );\n\t\t\tcase 'media-urls':\n\t\t\t\t$options = get_option( 'jiuliu_relative_media_urls_options', array() );\n")

# 统一后台映射与资源加载。
replace('includes/class-jlwa-admin.php', "\t\t\t'media-urls'   => 'render_media_urls',\n", "\t\t\t'media-urls'   => 'render_media_urls',\n\t\t\t'download-page' => 'render_download_page',\n")
replace('includes/class-jlwa-admin.php', "\t\t\tcase 'media-urls':\n\t\t\t\tif ( class_exists( 'JRMU_Admin' ) ) {\n", "\t\t\tcase 'download-page':\n\t\t\t\tif ( class_exists( 'JLWA_Download_Page_Feature' ) ) {\n\t\t\t\t\tJLWA_Download_Page_Feature::instance()->enqueue_admin_assets( $feature_hook );\n\t\t\t\t}\n\t\t\t\tbreak;\n\t\t\tcase 'media-urls':\n\t\t\t\tif ( class_exists( 'JRMU_Admin' ) ) {\n")
replace('includes/class-jlwa-admin.php', "\tpublic function render_media_urls() { $this->render_feature( 'media-urls' ); }\n", "\tpublic function render_media_urls() { $this->render_feature( 'media-urls' ); }\n\tpublic function render_download_page() { $this->render_feature( 'download-page' ); }\n")
for old, new in [
    ('一个插件管理五项核心能力', '一个插件管理六项核心能力'),
    ('页面体验、反调试保护、加载体验、内容智能和媒体链接', '页面体验、反调试保护、加载体验、内容智能、媒体链接和下载体验'),
    ('五项能力，一套体验', '六项能力，一套体验'),
    ('五项功能由中央注册表启动', '六项功能由中央注册表启动'),
]:
    p = ROOT / 'includes/class-jlwa-admin.php'
    t = p.read_text(encoding='utf-8')
    if old in t:
        p.write_text(t.replace(old, new), encoding='utf-8')

# CHANGELOG 真实记录。
changelog = """\n## 2.5.0 — 2026-08-05\n\n### 子比下载页美化\n\n- 新增第六功能域“下载页美化”，由统一控制台注册、启停和加载。\n- 内置科技蓝紫、简洁商务、VIP 资源中心三套页面风格，可在后台直接切换。\n- 新增自动、特色图片、正文首图、稳定随机图和默认图五种左上角封面策略。\n- 自动模式按“特色图 → 正文首图 → 稳定随机图 → 默认图”逐级回退，避免无特色图文章出现空白。\n- 正文取图兼容常见懒加载属性，并排除头像、表情、Logo、图标、占位图和跟踪像素。\n- 模板由插件级请求接管实现，不改写子比主题文件；关闭功能或缺少子比接口时自动恢复原下载页。\n- 支付状态、会员权限、免费额度和真实下载按钮继续调用子比原生函数。\n- 正式 Release 同步提供可安装 ZIP 与 SHA-256 文件，供插件后台安全更新器校验。\n\n"""
prepend_after('CHANGELOG.md', '本项目只使用 `main` 作为长期维护分支。每个正式版本都同步更新版本号、更新记录、Git 标签、GitHub Release 和可安装 ZIP。\n', changelog)

# WordPress readme。
replace('readme.txt', 'Stable tag: 2.4.0', 'Stable tag: 2.5.0')
replace('readme.txt', '一个真正统一的 WordPress 增强插件：页面美化、反调试保护、沉浸式预加载、AI 文章摘要、媒体与多域名链接管理。', '一个真正统一的 WordPress 增强插件：页面美化、反调试保护、沉浸式预加载、AI 文章摘要、媒体与多域名链接管理和子比下载页美化。')
replace('readme.txt', '* 五项能力作为内部功能域启动', '* 六项能力作为内部功能域启动')
replace('readme.txt', '五项内置能力：', '六项内置能力：')
replace('readme.txt', '5. 媒体与链接：媒体相对地址、安全域名白名单、明确源站、反向代理诊断、扫描预览、安全修复和 SEO 辅助。', '5. 媒体与链接：媒体相对地址、安全域名白名单、明确源站、反向代理诊断、扫描预览、安全修复和 SEO 辅助。\n6. 下载页美化：科技蓝紫、简洁商务、VIP 资源中心三套子比下载页模板，以及特色图、首图、稳定随机图、默认图和自动回退。')
readme_changelog = """\n= 2.5.0 =\n* 新增第六功能域“下载页美化”，在统一后台选择科技蓝紫、简洁商务或 VIP 资源中心模板。\n* 新增自动、特色图片、正文首图、稳定随机图和默认图五种封面策略。\n* 自动模式按特色图、首图、稳定随机图、默认图逐级回退。\n* 插件级接管下载页，不改写子比主题文件；关闭功能或接口缺失时恢复原页面。\n* 支付、会员权限、免费额度和下载按钮继续使用子比原生逻辑。\n\n"""
prepend_after('readme.txt', '== Changelog ==\n', readme_changelog)

# README 项目说明。
replace('README.md', '# 九流WP助手 2.4.0', '# 九流WP助手 2.5.0')
replace('README.md', '设计成五个内部功能域', '设计成六个内部功能域')
replace('README.md', '总览、五项功能和系统更新', '总览、六项功能和系统更新')
replace('README.md', '### 媒体与链接\n\n支持媒体相对地址、安全入口域名白名单、明确反向代理源站、历史内容扫描、预览与修复、响应头检测和 SEO 辅助。', '### 媒体与链接\n\n支持媒体相对地址、安全入口域名白名单、明确反向代理源站、历史内容扫描、预览与修复、响应头检测和 SEO 辅助。\n\n### 下载页美化\n\n内置科技蓝紫、简洁商务、VIP 资源中心三套子比资源下载页模板；支持特色图、正文首图、稳定随机图、默认图和自动回退，不修改主题文件。')
replace('README.md', '│   └── relative-media-urls/', '│   ├── relative-media-urls/\n│   └── download-page.php')

# 正式提交后不再保留一次性脚本。
Path(__file__).unlink()
