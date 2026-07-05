=== 九流媒体相对地址 ===
Contributors: jiuliu
Tags: reverse proxy, media urls, relative urls, multi-domain, nginx cache
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 4.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress 反向代理与多域名链接助手：媒体相对地址、站内链接跟随当前域名、扫描预览修复、恢复完整 URL、反代检测与 Nginx 配置建议。

== Description ==

九流媒体相对地址用于处理 WordPress 在反向代理、多入口域名、源站保护、HTTPS 迁移场景下的 URL 固定问题。

核心原则：

* 默认不启用任何转换。
* 启用插件不会自动修改文章、媒体库、数据库或 WordPress 核心。
* 永久修改必须手动扫描、预览、勾选并确认。
* postmeta 只读扫描，不自动修改。
* 从旧版本升级到 4.1.0 时，会重置会改变输出或写库的功能开关为关闭，但保留域名和路径配置。

主要功能：

* 媒体库地址输出相对化。
* 新上传媒体相对地址输出。
* 文章内容媒体 URL 保存时转换或前台临时转换。
* 站内链接跟随当前访问域名。
* Canonical 主域名控制。
* 全站链接扫描、Dry Run 预览、勾选文章修复。
* 相对媒体 URL 恢复完整 URL。
* HTTP 混合内容修复。
* 源站域名/IP 暴露检测。
* 反代环境检测、缓存响应头检测、Nginx 配置建议。

== Installation ==

1. 上传插件压缩包。
2. 启用插件。
3. 进入“媒体相对地址”设置页。
4. 填写源站域名、多域名白名单和转换范围。
5. 先扫描和预览，再执行任何永久修复。

== Changelog ==

= 4.1.1 =
* 统一 Plugin URI、作者主页与九流插件套件品牌信息。
* 统一后台菜单图标与设置页标题区样式。
* 保持原有 URL 转换、扫描与诊断逻辑不变。

= 4.1.0 =
* 稳定硬化版。
* 从旧版本升级时重置危险开关为关闭，保留域名/路径配置。
* 扫描预览按具体动作显示 Dry Run 样本，减少误操作。
* 批量执行增加动作白名单、权限检查、并发锁、跳过/错误日志。
* 批量更新前尽量创建文章修订。
* 响应头检测增加 nonce 和域名白名单限制。
* 媒体允许域名同时识别数据库原始 home/siteurl，避免动态多域名模式下漏识别源站。

= 4.0.0 =
* 高级初版：集成扫描、恢复、诊断、Nginx 建议和多域名适配。
