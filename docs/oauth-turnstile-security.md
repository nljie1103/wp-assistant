# 登录安全：彩虹聚合 OAuth 与 Cloudflare Turnstile

## 适用场景

面向子比 Zibll 主题的彩虹聚合 OAuth 登录：`/oauth/clogin?type=qq&rurl=...`。

无需 Cloudflare 橙云代理，博客继续直连源站。**不能替代 Cloudflare 边缘 WAF、五秒盾或大流量 DDoS 防护**。

## 初始化

第一次配置 Site Key / Secret Key：请先阅读 [Cloudflare Turnstile 密钥获取与配置教程](turnstile-keys-guide.md)。

1. Cloudflare Turnstile 创建 Widget（Managed 模式），允许主机名填写实际 WordPress `home_url()` 域名。
2. 九流WP助手 → 登录安全：填 Site Key 和 Secret Key，保存。Secret Key 不会回显。智能验证默认只在风险触发时显示验证码；其它登录和路径保护范围详见 [登录安全智能模式及自定义路径说明](login-security-adaptive.md)。建议通过 `wp-config.php` 设置 `define('JLWA_TURNSTILE_SECRET_KEY', '...');`，然后仅在插件界面录入 Site Key。
3. 先用中国大陆网络打开 Turnstile 并完成正常 QQ/微信登录发起、授权、回调、返回文章页；确认没有浏览器拦截/验证码网络故障，再开启“保护状态”。
4. 观察 `/oauth/clogin` 的 200/302/403/503 分布，确认已有 Nginx 限流不会把正常用户拦为 503。该模块不变更服务器的 Nginx 文件。

## 安全边界

- 插件总开关默认关闭，密钥缺失时管理界面无法开启；开启后新旧设置默认采用智能风险模式（单 IP 与全站频率），可切换「始终验证」。
- 彩虹 OAuth 默认只保护 `/oauth/clogin` 的登录发起，不干扰 `/oauth/clogin/callback` 和第三方 SDK。可独立开启 WordPress 原生密码登录验证及自定义动态路径防护，细节见智能模式专门说明。
- 后端每次向 Turnstile Siteverify 验证令牌，要求成功、对应主机和 `jlwa_oauth_login` action；令牌不能只在浏览器检查。
- 成功后服务器发行 90 秒的一次性随机票据，HttpOnly + Secure + SameSite=Lax Cookie，票据记录绑定原始 OAuth URL，消费后删除。
- 后台密钥不回显；Secret Key 存独立非自动加载选项或 wp-config 常量。插件与域名 HTTPS 必须有效。
- 此验证需要 WordPress 执行 PHP 才能拒绝未经验证的登录；对资源消耗的最前置防线仍是 Nginx/边缘网络。自引用 rurl 在 Nginx 层阻断更省资源。
- 不修改子比商业主题文件、彩虹 SDK、WordPress 用户表、支付与会员接口。

## 回滚

如果验证码影响登录：通过 WordPress 后台“九流WP助手 → 登录安全”取消启用并保存。万一后台不可用，可以从 WordPress 数据库中将 `jlwa_login_security_options` 的 `enabled` 值设为 0，或停用九流 WP 助手，后者会同时停用其它功能。

## 发布前验证门槛

- 插件的 PHP/JS 全量测试与 Vue 构建通过。
- 真正的 Site Key/Secret Key 完整可用，QQ/微信至少各一个真实 OAuth 回调成功；普通文章与会员登录不受影响。
- 验证码中国大陆不同运营商网络能稳定加载。
- 已有 Nginx 速率限制不得持续误伤正常用户。
