=== 九流WP助手 ===
Contributors: jiuliu
Tags: wordpress, ai summary, media urls, effects, preloader
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

九流WP助手将页面美化、媒体相对地址、AI 文章摘要和沉浸式预加载整合到一个统一后台入口中。

== Description ==

当前版本是合体套件的起步版本：

* 顶级菜单统一为「九流WP助手」，放在后台侧边栏底部。
* 子菜单包含「页面美化」「媒体相对地址」「AI 文章摘要」「沉浸式预加载」和「更新中心」。
* 四个模块沿用原插件功能逻辑和设置项，便于从独立插件迁移。
* 后台总览和更新中心采用统一的九流后台样式。
* 如果检测到旧独立插件已启用，会跳过对应模块，避免同名类冲突。

== Changelog ==

= 0.1.1 =
* 将页面美化和沉浸式预加载改为类似 AI 文章摘要的 tab 分区后台。
* 移除套件内四个模块各自的在线更新入口。
* 新增九流WP助手主仓库统一更新中心，仅从 nljie1103/wp-assistant 更新整个套件。

= 0.1.0 =
* 新增九流WP助手套件插件骨架。
* 迁入四个既有插件作为模块。
* 统一顶级后台菜单、模块入口和更新中心。
