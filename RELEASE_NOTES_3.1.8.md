<!-- release-title: XML-RPC拦截响应码修复与真实HTTP回归 -->

- 修复 v3.1.7 严格反采集模块 XML-RPC 拒绝响应状态错误：WordPress `init` 早期的 XML-RPC 专用 `wp_die()` 尚无服务对象，原先 HTTP 200 空响应。现在在执行 XML-RPC 方法前直接返回 HTTP 403，防止误导的成功状态。
- 在隔离的真实 WordPress 测试站（并非只调用模拟函数）使用 HTTP 请求确认：启用严格封锁后匿名与管理员的 REST 文章/媒体、RSS/Atom 403；XML-RPC 请求 403，关闭保护后相同 XML-RPC 方法恢复 200 与 XML 文本响应。
- 新增 XML-RPC 开关组合及请求来源判定单元测试，保留 v3.1.7 反采集模块、后台设置、Cloudflare/Nginx 防护、OAuth、支付、下载等行为。插件默认仍不自动开启反采集，也不修改生产博客设置。
- 重要边界：公开 HTML 页面及静态媒体文件仍可被浏览器采集，严格 REST 模式也可能导致 WordPress 区块编辑器和媒体库不可用。因此此功能是标准导出接口封锁，不是不可绕过的全面反采集系统。
