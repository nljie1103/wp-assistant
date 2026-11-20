# 登录安全：按风险触发验证、WordPress 登录及自定义路径

## 目标与默认行为

九流 WP 助手的「登录安全」使用独立 Cloudflare Turnstile，**无需 Cloudflare 橙云 CDN**。功能总开关默认关闭；打开后，默认使用「智能验证」：普通用户直接继续登录，不必每次验证。

前台正常触发时显示：“请完成安全验证”“检测到短时间内多次登录请求，为保障账号安全，请先完成验证后继续登录。”，不展示 CDN/部署等工程说明。可选「始终验证」模式，文案不会错误声称已检测到请求异常。

## 风险判定

- 统计窗口：60 秒。默认单来源 IP 6 次或全站 50 次访问受保护的登录入口，触发风险；风险维持 300 秒（可调 60–3600 秒）。超过阈值会影响本来源或全站，防御频繁换 IP 的请求。全站请求高峰期间正常访客也可能需要验证，这是有意的安全权衡。
- 使用服务器临时目录下私有文件，文件锁保护计数，保存来源 IP 的 HMAC 摘要，不将数万个匿名 IP 写入 WordPress 数据库。无法读写计数文件时**保守显示验证**，避免静默失效。单服务器部署有效，多台独立 WordPress 服务器需使用共享计数服务再启用该模式。
- Nginx 自引用 rurl 防护仍优先执行。被 Nginx 直接拒绝的请求不会进入 WordPress，也不会贡献插件的风险计数。
- 禁止从任意 `X-Forwarded-For` 信任访客 IP；计数使用服务器实际 `REMOTE_ADDR`。如站点背后另有受信代理，应在 Nginx 层配置并验证真实 IP 传递。

## 防护范围

### 彩虹聚合 OAuth `/oauth/clogin`

- 验证发起入口：无风险直接执行原子比 OAuth；触发风险后验证 Turnstile，服务器通过 Siteverify 确认成功、hostname 和 action，签发绑定原始登录 URL 的 90 秒一次性凭据。
- 第三方 OAuth 回调和子比的 `CLOGIN_STATE` 会话校验保持不变。
- 绝不能对 `/oauth/clogin/callback` 或支付回调直接加通用验证码。

### WordPress 原生密码登录（可选）

- 勾选「WordPress 后台登录」后，保护原生 `/wp-login.php` 的密码提交。
- 未登录访问 `/wp-admin/` 时 WordPress 通常先重定向到 `/wp-login.php`；因此无需拦整个 `/wp-admin` 文件夹。
- 达到风险阈值时，在 WordPress 原生登录表单里显示 Turnstile，且在 `authenticate` 服务端过滤器中核对，未验证的密码提交不能继续认证；直接构造 POST 也不能仅靠绕过登录页面跳过验证码。
- **不会**拦 `wp-admin/admin-ajax.php`、`wp-admin/admin-post.php`、密码找回、后台已登录操作；也**不等于**自动保护 XML-RPC、REST API 或子比自定义 AJAX 登录处理器。若使用自定义 AJAX 登录，需针对真实动作另外接入验证。后台设置不得宣传已经覆盖这些接口。

### 自定义 WordPress 动态路径（可选）

每行一条路径规则：

```text
/member-center
/members/private/*
```

- `/member-center` 精确匹配；`/members/private/*` 匹配该目录下经过 WordPress PHP 处理的页面/请求。
- 普通流量不显示验证码；风险触发时，未验证 GET 显示验证页，验证后获得作用于相应规则的 10 分钟签名、HttpOnly、Secure Cookie。风险期间未经验证的直接 POST 返回拒绝，不能重放 POST 自动继续。
- 仅是**防机器人验证**，不是用户授权/密码、订单鉴权、资源访问控制的替代品。直接被 Nginx 静态托管、不经过 WordPress PHP 的文件不在防护范围内。
- 拒绝将 `/wp-admin/*`、`/wp-login.php`、`/oauth/*`、`/wp-json/*`、`/xmlrpc.php`、结算支付和下载端点配置为普通动态路径，避免误伤后台 AJAX、登录、OAuth 回调和付款。`/wp-admin` 应使用上方的 WordPress 原生密码登录开关。
- 添加路径前应确认 WordPress 路由真实匹配（某些页面使用非 WordPress 处理链），并在匿名、已登录、表单 POST、缓存及移动设备上验收。不要将普通站点主页或高流量静态目录误配进该列表。

## 上线顺序

1. 先保留已有 Turnstile Site Key 和 Secret Key，不需要重建小组件；从「九流 WP 助手 → 登录安全」选择「智能验证」。
2. 先保持原有 OAuth 保护，确认正常访客没有验证码，单 IP 和全站超过阈值时验证码出现、验证成功后能登录。
3. 再按需启用 WordPress 原生密码登录开关，并使用隔离账号验证 `/wp-admin/` → `/wp-login.php`、错误密码、风险中带/不带 Turnstile token 的 POST。
4. 逐条启用自定义路径，并用测试账号验收 GET、POST、Cookie 过期、静态文件旁路。登录页、支付及后台 AJAX 不得被误拦。
5. 检查攻击日志的 302/403/503 分布；如果原有 Nginx 全局限流出现 503，需要区分是正常请求被阻断还是攻击命中。不要直接降低线上限流来测试验证码。

## 回滚

后台关闭「启用登录安全防护」即可停止所有插件新增验证；已存在的 Nginx 异常 `rurl` 规则不会改变。若后台无法进入，可以由服务器管理员单独调整数据库中 `jlwa_login_security_options` 的 `enabled` 为 0，或恢复此前版本。正式站点上线、密钥验证和多客户端真实回调不由本次代码自动完成。
