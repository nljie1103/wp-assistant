<!-- release-title: OpenList 异常编码入口拦截加固 -->

- 修复 v3.2.1 严格图片路径允许规则仍可能将双重编码的畸形 URL 转交给 OpenList 并返回 500 的边界漏洞。新增面向公网原始 URI 的危险百分号编码拒绝，异常路径直接在 Nginx 返回 403，正常图片 GET/HEAD 和本机完整 OpenList 访问不变。
- root-only 部署助手新增 `preview-strict-refresh` / `refresh-strict-public`；对现有严格策略做受控原子版本刷新，校验现有 OpenList vhost SHA 和 WordPress 内部代理，不破坏 Cloudflare 源站 ACL、已购交付或早期备份。保留 `rollback-strict-public` 专用回退。
- 修复已通过真实临时 Nginx 配置对比验证：模拟外部双重编码 URL 返回 403、普通图片仍 200、回环请求继续允许；完善 Python / PHP 回归测试。仍需对现有已购订单实际下载及外部 CDN 缓存做独立验收。
- 此版本的安装包更新**不会自动刷新 root Nginx 规则**：旧严格部署须由 root 明确执行新版预览和刷新操作，既有 `images_only` 升级时则直接安装最新规则。
