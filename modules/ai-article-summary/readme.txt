=== 九流 AI 文章摘要 ===
Contributors: jiuliu
Tags: ai, summary, openai, gemini, deepseek, claude, qwen, kimi, doubao, glm, animation, typewriter, cache
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

自动在文章顶部插入 AI 智能摘要，支持 16 家主流厂商、三级联动模型选择、10 种文字动画特效、完整缓存系统与暗黑极简卡片风格。

== Description ==

* 自动在文章顶部（标题下方、正文上方）插入 AI 智能摘要
* 三级联动：API 服务商 → 模型 → 独立 API Key（OpenAI / Gemini / DeepSeek / Claude / Kimi / 通义千问 / 文心一言 / 豆包 / 火山方舟 / 星火 / GLM / 360 / Mistral / Grok / OpenRouter / 自定义接口）
* 内置 10 种文字动画特效（打字机、淡入、滑入、缩放、弹跳、逐行渐入、霓虹呼吸 ...）
* 全站缓存 + 单文章缓存 + 编辑自动清缓存 + 后台精细化管理
* 暗黑极简卡片风格，自适应移动端，兼容 Zibll、Astra、Divi 等主流主题
* 安全规范：Nonce 校验 / 权限校验 / 输入过滤 / 输出转义 / 卸载自动清理

== Installation ==

1. 把整个 `wp-ai-article-summary` 目录上传到 `/wp-content/plugins/`
2. 在 WP 后台 → 插件 中启用
3. 在左侧菜单「AI 文章摘要」中配置 API 与样式

== Changelog ==

= 1.0.9 =
* 统一插件显示名称为“九流 AI 文章摘要”。
* 统一 Plugin URI、后台菜单图标与设置页标题区样式。
* 修正 readme 中错误的后台菜单名称。

= 1.0.8 =
* 清理旧版全局 `api_key` 设置项：默认配置、保存逻辑、表单字段与兼容兜底均已移除，读取 / 保存时会剔除旧字段。
* API Key 仅保存在 `api_keys` 映射中，按「服务商 + 模型」绑定，避免新旧结构并存造成歧义。
* 连通性测试改用临时 `current_api_key` 参数，不再把当前输入框当作全局设置字段提交。

= 1.0.7 =
* 修复：不同服务商 / 模型共用同一个 API Key，导致切换模型时必须反复改 Key 的问题。
* 新增：API Key 按「服务商 + 模型」独立保存，切换模型会自动加载对应 Key。

= 1.0.6 =
* 更新多家 AI 服务商的默认接口地址与模型预设，移除明显过期的旧模型。
* 修正 DeepSeek 与 Kimi 默认接口地址。
* 适配 OpenAI / Kimi 新模型的 `max_completion_tokens` 参数。
* 同步更新 README 中的模型示例与版本信息。

= 1.0.5 =
* 🎨 全新「外观样式」Tab：内置 15 套精心设计的卡片预设（深色极简、玻璃磨砂、蓝紫渐变、粉橙渐变、青绿渐变、细描边、米色纸张、赛博朋克霓虹、笔记本横线、白卡浮起、森系绿、日落橙、薰衣草、午夜蓝 + 完全自定义），点击即应用。
* 🎨 5 大核心颜色完全自定义（背景 / 边框 / 标题 / 正文 / 强调色），支持 `#rgb` / `#rrggbb` / `rgba(...)` / `transparent`，文本输入框 + 系统取色器双向同步。
* 🎨 后台实时预览：选择预设 / 调整颜色立即在预览卡片中所见即所得，无需保存即可看到最终效果。
* 🎨 前端卡片基于 CSS 变量驱动，预设之间无缝切换，5 种装饰效果（玻璃磨砂、霓虹光晕、笔记本横线、纸张色带、渐变背景）均独立可叠加。
* 🛠 颜色相关字段独立沙盒：仅在"外观样式" Tab 保存时覆盖，其它 Tab 完全互不干扰。

= 1.0.4 =
* 🛡️ 在线更新增加"设置 0 丢失"双重保护：开始前自动快照设置到 `wpaias_settings_backup`，结束后校验，异常自动恢复。
* 🛡️ 后台更新页新增「数据保留说明」面板，清晰告知用户：设置 / API Key / 缓存均保留在数据库，更新仅替换代码文件。
* 💫 动画兼容性大幅强化：所有 10 种动画的 CSS 改用 `aside.wpaias-summary` 高优先级选择器 + `!important`，可对抗 Zibll / Astra Pro / Divi / Elementor / Block 编辑器主题的 `*` 通配强样式覆盖。
* 💫 动画 keyframes 增加 `animation-fill-mode: both`，避免在某些主题下回到初始态。
* 💫 修复"逐行渐入"在老版 iOS Safari / 安卓微信内置浏览器报错的问题（移除 regex lookbehind）。
* 💫 修复部分主题强制 `transition: all !important` 时滑入 / 缩放 / 弹跳动画无效的问题。

= 1.0.3 =
* 🚀 新增「在线更新」Tab：一键检查 GitHub 最新版本 + 一键下载更新本地文件，无需手动下载 ZIP。
* 🚀 自动从 raw.githubusercontent.com 读取远程版本号进行对比，命中已是最新则禁用更新按钮。
* 🚀 自动拉取远程 readme.txt 的 Changelog 段落显示在后台预览。
* 🛡️ 使用 WP_Filesystem + download_url + unzip_file 安全下载与覆盖，跳过 .git/.github 等隐藏目录。

= 1.0.2 =
* 🎯 主题兼容性大幅增强：新增「注入模式」设置（自动 / 仅 the_content / 仅 JS 注入 / 仅短代码 / 完全手动）。
* 🎯 新增 wp_footer 模板 + JS DOM 智能注入，兼容 Zibll / Astra / Divi / Elementor / 块编辑器主题 / FSE 等绕过 the_content 的商用主题。
* 🎯 新增 MutationObserver 监听，自动适配 SPA / 懒加载 / 延迟渲染主题。
* 🎯 新增 `[wpaias_summary]` 短代码与 `wpaias_render_summary()` 模板函数，便于主题作者手动放置。
* 🎯 新增 CSS 选择器与注入位置（prepend/append/before/after）可配置。
* 🛡️ 内置 20+ 主流主题文章容器选择器作为兜底（.entry-content、.post-content、.typo、.elementor-widget-theme-post-content 等）。

= 1.0.1 =
* 修复：分 Tab 提交时其它 Tab 设置被意外清空的问题（保存 API 设置不会再关闭全局开关等）。
* 修复：自定义接口仅填写 Base URL 时连通性测试失败 / 返回内容为空的问题（OpenAI 协议下自动追加 /chat/completions，Claude 自动追加 /v1/messages）。

= 1.0.0 =
* 初版发布。
