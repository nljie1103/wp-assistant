=== 九流沉浸式预加载 ===
Contributors: jiuliu
Tags: preloader, loading, animation, splash, immersive
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为 WordPress 网站提供多种炫酷的沉浸式预加载效果，完全掩盖页面加载过程，实现丝滑的开门式进入体验。

== 描述 ==

九流沉浸式预加载是一款专注于「页面加载体验」的 WordPress 插件。

它不是普通的固定时长开屏动画，而是在页面资源加载过程中持续播放循环动画，
直到 window.load 事件触发（页面所有资源加载完成）后，才优雅地淡出，
从而完全掩盖加载过程，给访客带来丝滑的"开门式"进入体验。

= 主要特性 =

* 沉浸式逻辑：等待真实加载完成 + 最小展示时长 + 最大等待超时保护
* 5 种内置精美效果，第一项为推荐的核心效果（立体 Logo + 动态蒙版 + 文字弹入 + 荧光扫描）
* 自定义 Logo（WordPress 原生媒体上传器）
* 自定义背景色（WordPress 原生颜色选择器）
* 仅首页显示 / 显示站点名称 / 点击跳过 等高级选项
* 零代码修改，激活即用
* 完整中文界面，所有动画在手机、平板、电脑流畅运行

= 兼容性 =

* WordPress 5.8 及以上
* PHP 7.4 及以上
* 兼容 Zibll、Astra、Divi 等主流主题

== 安装 ==

1. 进入「插件」→「安装插件」→「上传插件」
2. 选择压缩包 jiuliu-immersive-preloader.zip 并安装
3. 激活插件
4. 在后台左侧菜单「沉浸式预加载」中配置参数

== 常见问题 ==

= 为什么我看不到预加载？ =
请确认：
1. 插件已启用；
2. 若开启了「仅在首页显示」，请访问首页；
3. 主题模板调用了 wp_body_open() 与 wp_head() / wp_footer()。

= 会拖慢网站速度吗？ =
不会。所有静态资源都很小（CSS+JS 不到 20KB），且使用 GPU 加速动画。

== 变更日志 ==

= 1.0.6 =
* 统一 Plugin URI、作者主页与九流插件套件品牌信息。
* 统一后台菜单图标与设置页标题区样式。
* 将后台菜单名称统一为“沉浸式预加载”。

= 1.0.5 =
* 新增后台在线更新功能，可从 GitHub 检查最新版本并一键更新插件文件。
* 更新流程会在覆盖文件前备份当前设置，更新后保留已有配置。
* 后台设置页新增远程版本状态、变更日志预览与数据保留说明。

= 1.0.4 =
* 更新开源许可证为 GPLv2 or later，并补齐标准 LICENSE 文件。
* 修复 README、插件头与 WordPress readme 的版本号/许可证信息不一致问题。
* 优化无 wp_body_open 主题下的 JS 兜底注入时机。
* 增强 multisite 卸载清理逻辑。
