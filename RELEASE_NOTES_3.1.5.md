<!-- release-title: 智能按需验证与自定义登录防护范围 -->

- 新版登录安全默认仅在短时大量请求出现时要求 Turnstile：每分钟单来源 6 次或全站 50 次触发，默认冷却 300 秒；可在后台修改阈值、切换始终验证。已有密钥和开关继续保留。
- 前台验证页移除 Cloudflare CDN 工程说明，改用「请完成安全验证」及简短安全提示；始终验证模式不冒称有风险。
- 新增原生 WordPress `/wp-login.php` 密码防爆破：未登录 `/wp-admin/` 由 WordPress 正常转到登录页，通过 `authenticate` 钩子强制校验，避开后台 AJAX、admin-post、密码找回与 OAuth 回调。
- 新增自定义 PHP 路由防护，支持精确与目录前缀，服务器在异常窗口中校验 Turnstile 并签发短期的有作用域 Cookie。直接静态文件、子比专用 AJAX/REST/XML-RPC 登录不在此机制的自动覆盖范围。
- 增加纯策略单元回归与 WordPress 实际后台渲染检查；Nginx 现有自引用 `rurl` 规则不变。上线前仍应通过真实登录与国内网络测试，生产站点不自动启用新增范围。
