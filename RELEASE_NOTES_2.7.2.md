<!-- release-title: 修复私有下载重复Content-Disposition导致Chrome拒绝文件 -->

### 修复：已购用户通过 Nginx 私有代理下载时 Chrome 报错

已完成 prepare 后，已购下载可能出现 Chrome 错误 ERR_RESPONSE_HEADERS_MULTIPLE_CONTENT_DISPOSITION。这是在 X-Accel-Redirect 内部代理链路中，WordPress PHP 和最终 OpenList 文件响应同时包含 Content-Disposition 导致的重复文件名响应头，不属于 Docker 本机端口、购买权限或文件损坏问题。

新版将最终文件名响应头交由 OpenList 提供：九流 WP 助手会移除 PHP（包括子比先前写入）的 Content-Disposition，然后继续使用原有 X-Accel-Redirect 私有下载。不会跳转至原始 OpenList URL，也不改变购买授权、可选下载出口、限流或下载审计规则。

### 安全与兼容

- **不用重新执行 install-auto 或 prepare**：本次不修改 root 管理的 Nginx 部署助手、虚拟主机配置或部署阶段及备份哈希。
- 保留 application/octet-stream、no-store、noindex 等安全响应头；不修改子比主题或 OpenList 本体。
- 通过真实 PHP HTTP 服务器模拟子比预设响应头并验证插件生成结果：只有内部跳转和安全缓存控制，不再带重复的 PHP Content-Disposition。
- 上线后应以真正已购账号检查 ZIP 下载成功及压缩包能否解压、浏览器不会见到 OpenList 原始源站 URL。必要时进一步核对最终 HTTP 的 Content-Disposition 是否恰好一个。
- **未完成实际下载前不要点击购买测试确认，更不要封锁公网 OpenList 付费目录**。

此次正式版本只更新 WordPress 插件，不会触碰生产 Nginx、Docker、OpenList 存储、WebDAV 或历史订单。
