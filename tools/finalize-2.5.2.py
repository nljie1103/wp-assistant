#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


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


# 1. API Key server-side persistence: no longer depend only on JavaScript hidden JSON.
admin_path = 'features/ai-article-summary/includes/class-wpaias-admin.php'
admin = read(admin_path)
admin = replace_once(
    admin,
    "\t\t\t$out['api_keys'] = JLWA_AI_Summary_Feature::sanitize_api_keys( $api_keys );\n\t\t\tif ( isset( $input['temperature'] ) ) {",
    "\t\t\t$api_keys = JLWA_AI_Summary_Feature::sanitize_api_keys( $api_keys );\n\n"
    "\t\t\t// API Key 输入框直接参与表单提交，避免后台 JS 未运行时出现‘设置已保存但 Key 丢失’。\n"
    "\t\t\t$current_api_key = isset( $input['api_key_current'] ) ? trim( (string) wp_unslash( $input['api_key_current'] ) ) : '';\n"
    "\t\t\t$current_api_key = str_replace( array( \"\\r\", \"\\n\" ), '', $current_api_key );\n"
    "\t\t\tif ( '' !== $current_api_key ) {\n"
    "\t\t\t\t$key_provider = isset( $out['provider'] ) ? sanitize_key( $out['provider'] ) : 'openai';\n"
    "\t\t\t\t$key_model    = ( 'custom' === $key_provider && ! empty( $out['custom_model'] ) ) ? $out['custom_model'] : ( isset( $out['model'] ) ? $out['model'] : '' );\n"
    "\t\t\t\t$model_slot   = JLWA_AI_Summary_Feature::api_key_slot( $key_provider, $key_model );\n"
    "\t\t\t\t$provider_slot = JLWA_AI_Summary_Feature::api_key_provider_slot( $key_provider );\n"
    "\t\t\t\tif ( '' !== $model_slot ) {\n"
    "\t\t\t\t\t$api_keys[ $model_slot ] = $current_api_key;\n"
    "\t\t\t\t}\n"
    "\t\t\t\tif ( '' !== $provider_slot ) {\n"
    "\t\t\t\t\t$api_keys[ $provider_slot ] = $current_api_key;\n"
    "\t\t\t\t}\n"
    "\t\t\t}\n"
    "\t\t\t// 空输入只表示不修改，绝不再静默删除数据库中的旧 Key。\n"
    "\t\t\t$out['api_keys'] = JLWA_AI_Summary_Feature::sanitize_api_keys( $api_keys );\n"
    "\t\t\tif ( isset( $input['temperature'] ) ) {",
    'persist current API key on server'
)
admin = replace_once(
    admin,
    "\t\t\t\t\t\t\t\t<input type=\"password\" class=\"regular-text\" id=\"wpaias-api-key\" value=\"<?php echo esc_attr( $current_key ); ?>\" autocomplete=\"off\">",
    "\t\t\t\t\t\t\t\t<input type=\"password\" class=\"regular-text\" id=\"wpaias-api-key\" name=\"<?php echo esc_attr( $opt ); ?>[api_key_current]\" value=\"<?php echo esc_attr( $current_key ); ?>\" autocomplete=\"new-password\">",
    'name API key field'
)
admin = admin.replace(
    '当前输入框只绑定当前服务商 + 模型；切换模型会自动切换到对应的 API Key。',
    'API Key 会保存到当前服务商，并兼容当前模型的独立绑定；切换同一服务商下的模型不会再要求重复填写。'
)
write(admin_path, admin)

# 2. API Key lookup: exact model first, then provider-wide fallback; migrate legacy single key.
plugin_path = 'features/ai-article-summary/includes/class-wpaias-plugin.php'
plugin = read(plugin_path)
plugin = replace_once(
    plugin,
    "\tpublic static function sanitize_api_keys( $api_keys ) {",
    "\tpublic static function api_key_provider_slot( $provider ) {\n"
    "\t\t$provider = sanitize_key( $provider );\n"
    "\t\treturn '' === $provider ? '' : $provider . '::__provider__';\n"
    "\t}\n\n"
    "\tpublic static function sanitize_api_keys( $api_keys ) {",
    'add provider API key slot'
)
plugin = replace_once(
    plugin,
    "\t\tif ( '' !== $slot && isset( $api_keys[ $slot ] ) ) {\n\t\t\treturn $api_keys[ $slot ];\n\t\t}\n\n\t\treturn '';",
    "\t\tif ( '' !== $slot && isset( $api_keys[ $slot ] ) ) {\n\t\t\treturn $api_keys[ $slot ];\n\t\t}\n\n"
    "\t\t$provider_slot = self::api_key_provider_slot( $provider );\n"
    "\t\tif ( '' !== $provider_slot && isset( $api_keys[ $provider_slot ] ) ) {\n"
    "\t\t\treturn $api_keys[ $provider_slot ];\n"
    "\t\t}\n\n"
    "\t\treturn '';",
    'provider-wide API key fallback'
)
plugin = replace_once(
    plugin,
    "\t\t$settings = wp_parse_args( $saved, $defaults );\n\t\tunset( $settings['api_key'] );\n\t\t$settings['api_keys'] = self::sanitize_api_keys( isset( $settings['api_keys'] ) ? $settings['api_keys'] : array() );\n\n\t\treturn $settings;",
    "\t\t$settings = wp_parse_args( $saved, $defaults );\n"
    "\t\t$legacy_key = isset( $settings['api_key'] ) ? str_replace( array( \"\\r\", \"\\n\" ), '', trim( (string) $settings['api_key'] ) ) : '';\n"
    "\t\tunset( $settings['api_key'] );\n"
    "\t\t$settings['api_keys'] = self::sanitize_api_keys( isset( $settings['api_keys'] ) ? $settings['api_keys'] : array() );\n\n"
    "\t\t// 从旧版单一 api_key 自动迁移，避免升级后看似 Key 丢失。\n"
    "\t\tif ( '' !== $legacy_key ) {\n"
    "\t\t\t$provider = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai';\n"
    "\t\t\t$model = ( 'custom' === $provider && ! empty( $settings['custom_model'] ) ) ? $settings['custom_model'] : ( isset( $settings['model'] ) ? $settings['model'] : '' );\n"
    "\t\t\t$model_slot = self::api_key_slot( $provider, $model );\n"
    "\t\t\t$provider_slot = self::api_key_provider_slot( $provider );\n"
    "\t\t\tif ( '' !== $model_slot && empty( $settings['api_keys'][ $model_slot ] ) ) {\n"
    "\t\t\t\t$settings['api_keys'][ $model_slot ] = $legacy_key;\n"
    "\t\t\t}\n"
    "\t\t\tif ( '' !== $provider_slot && empty( $settings['api_keys'][ $provider_slot ] ) ) {\n"
    "\t\t\t\t$settings['api_keys'][ $provider_slot ] = $legacy_key;\n"
    "\t\t\t}\n"
    "\t\t\tupdate_option( WPAIAS_OPTION_KEY, $settings, false );\n"
    "\t\t}\n\n"
    "\t\treturn $settings;",
    'migrate legacy API key'
)
write(plugin_path, plugin)

# 3. Admin JavaScript: provider fallback and never delete a key merely because an input is blank.
js_path = 'features/ai-article-summary/assets/js/admin.js'
js = read(js_path)
js = replace_once(
    js,
    "\tfunction syncApiKeysJson() {",
    "\tfunction providerApiKeySlot( providerKey ) {\n"
    "\t\treturn providerKey ? providerKey + '::__provider__' : '';\n"
    "\t}\n\n"
    "\tfunction syncApiKeysJson() {",
    'add provider slot helper'
)
js = replace_once(
    js,
    "\t\tvar value = $key.val() || '';\n\t\tif ( value ) {\n\t\t\tapiKeys[ slot ] = value;\n\t\t} else {\n\t\t\tdelete apiKeys[ slot ];\n\t\t}\n\t\tsyncApiKeysJson();",
    "\t\tvar value = ( $key.val() || '' ).trim();\n"
    "\t\tif ( ! value ) {\n"
    "\t\t\treturn;\n"
    "\t\t}\n"
    "\t\tapiKeys[ slot ] = value;\n"
    "\t\tvar providerSlot = providerApiKeySlot( currentProvider() );\n"
    "\t\tif ( providerSlot ) {\n"
    "\t\t\tapiKeys[ providerSlot ] = value;\n"
    "\t\t}\n"
    "\t\tsyncApiKeysJson();",
    'preserve and share API key'
)
js = replace_once(
    js,
    "\t\tif ( slot && Object.prototype.hasOwnProperty.call( apiKeys, slot ) ) {\n\t\t\t$key.val( apiKeys[ slot ] );\n\t\t} else {\n\t\t\t$key.val( '' );\n\t\t}",
    "\t\tvar providerSlot = providerApiKeySlot( providerKey );\n"
    "\t\tif ( slot && Object.prototype.hasOwnProperty.call( apiKeys, slot ) ) {\n"
    "\t\t\t$key.val( apiKeys[ slot ] );\n"
    "\t\t} else if ( providerSlot && Object.prototype.hasOwnProperty.call( apiKeys, providerSlot ) ) {\n"
    "\t\t\t$key.val( apiKeys[ providerSlot ] );\n"
    "\t\t} else {\n"
    "\t\t\t$key.val( '' );\n"
    "\t\t}",
    'load provider fallback key'
)
write(js_path, js)

# 4. Versions and release notes.
bootstrap_path = 'features/ai-article-summary/bootstrap.php'
bootstrap = read(bootstrap_path).replace("define( 'WPAIAS_VERSION', '1.1.1' );", "define( 'WPAIAS_VERSION', '1.1.2' );", 1)
write(bootstrap_path, bootstrap)

registry_path = 'includes/class-jlwa-feature-registry.php'
registry = read(registry_path)
registry = replace_once(
    registry,
    "\t\t\t\t'version'     => '1.1.1',\n\t\t\t\t'entry_class' => 'JLWA_AI_Summary_Feature',",
    "\t\t\t\t'version'     => '1.1.2',\n\t\t\t\t'entry_class' => 'JLWA_AI_Summary_Feature',",
    'registry AI version'
)
write(registry_path, registry)

main_path = 'jiuliu-wp-assistant.php'
main = read(main_path)
main = re.sub(r'(?m)^ \* Version: 2\.5\.1$', ' * Version: 2.5.2', main, count=1)
main = replace_once(main, "define( 'JLWA_VERSION', '2.5.1' );", "define( 'JLWA_VERSION', '2.5.2' );", 'plugin version constant')
write(main_path, main)

readme_path = 'readme.txt'
readme = read(readme_path)
readme = re.sub(r'(?m)^Stable tag: 2\.5\.1$', 'Stable tag: 2.5.2', readme, count=1)
if '= 2.5.2 =' not in readme:
    readme = readme.replace('== Changelog ==', '== Changelog ==\n\n= 2.5.2 =\n* 修复 API Key 显示已保存但实际未写入的问题。\n* API Key 改为服务商级回退，同一服务商切换模型无需重复填写。\n* 空输入不再静默删除旧 Key，并自动迁移旧版单一 api_key。', 1)
write(readme_path, readme)

readme_md_path = 'README.md'
readme_md = read(readme_md_path)
readme_md = re.sub(r'(?m)^# 九流WP助手 2\.5\.1$', '# 九流WP助手 2.5.2', readme_md, count=1)
write(readme_md_path, readme_md)

changelog_path = 'CHANGELOG.md'
changelog = read(changelog_path)
if '## 2.5.2' not in changelog:
    changelog = '## 2.5.2 — 2026-08-06\n\n- 修复 AI 接口 API Key 依赖后台 JavaScript 隐藏 JSON，导致界面提示保存成功但 Key 实际丢失。\n- API Key 输入框直接提交给 WordPress，后台脚本失效时也能可靠保存。\n- 新增服务商级 Key 回退；同一服务商切换模型不再反复要求填写。\n- 空输入不再删除已保存 Key，并自动迁移旧版单一 `api_key`。\n\n' + changelog
write(changelog_path, changelog)

notes = '''## 九流WP助手 2.5.2\n\n本版本修复 AI 摘要接口 API Key 保存后仍被判断为空的问题。\n\n### 修复内容\n- API Key 输入框现在直接参与 WordPress 设置提交，不再完全依赖 JavaScript 隐藏 JSON。\n- 即使后台脚本未加载或被其他脚本打断，Key 也能可靠写入数据库。\n- 当前模型优先使用独立 Key；找不到时自动使用同一服务商的通用 Key。\n- 同一服务商下切换模型不再要求重复填写 API Key。\n- 空白输入不再静默删除已有 Key。\n- 自动迁移旧版单一 `api_key` 配置。\n'''
write('RELEASE_NOTES_2.5.2.md', notes)
