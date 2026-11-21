<!-- release-title: OpenList 公网严格仅图片安全交付 -->

- 修复“仅公开图片”此前只限制 `/d/`、`/p/`，而 OpenList 首页、API、WebDAV、登录等入口仍能公网访问的策略缺口。新增可升级的严格规则：指定图片目录仅 GET/HEAD 对公网开放，所有其他路径公网 403；本机 OpenList 端口继续可用。
- root 管控的安装助手新增只读预览、原子升级、定向回退，部署前验证原 OpenList vhost SHA 和原 WordPress 内部 X-Accel-Redirect 规则，保留后续独立新增的 Cloudflare 源站 ACL；写入或 nginx -t 失败即恢复。不会扩大 WordPress PHP 的 sudo 权限。
- WordPress 后台新增严格模式命令与风险提示。插件更新**不会直接改服务器 Nginx**；已有 `images_only` 必须由 root 明确执行升级；新安装且设置图片目录后，首次保护采用严格模式。
- 请先确认远端 WebDAV/API 可停止使用、证书 HTTP-01 不依赖该站点；实测两个公网域名的所有非图片 URL 403、图片 200、WordPress 实际已购 ZIP 下载、本机 API 正常、CDN 旧缓存未绕过。不能把自动化单元测试冒称生产完整验收。
