# 彩虹聚合 OAuth：返回地址防循环与认证主域名

## 已复现的问题

子比 Zibll 主题 `zib_get_oauth_login_url($type, $rurl = '')` 在没有显式传入 `$rurl` 时，优先读取当前请求的 `$_GET['redirect_to']`，否则用 `zib_get_current_url()`。当 OAuth 错误页再次输出社交登录链接，当前 `/oauth/clogin?...` 有机会再次变成新的 `rurl`，最终生成重复编码的嵌套 URL。单独使用 `wp_safe_redirect` 不会排除**站内登录入口**作为回调返回地址。

## v3.1.6 的防护边界

- 原始的 Nginx `rurl` 自引用拒绝仍是最前面的低成本防线；插件不删除或放宽原限流。
- 在 WordPress `init` 阶段，对危险的 `redirect_to` 递归登录地址进行清理。进入 OAuth 发起入口时，把主题生成错误页登录链接所依赖的 `redirect_to` 默认值固定成博客首页。只处理含有递归登录风险的值，不全局改写无关的支付、订单重定向。
- OAuth 发起路径的 `rurl` 如果指回 OAuth 登录链，直接 403，即使管理员未开启 Turnstile；非法异域返回地址统一改为主域名首页。
- 通过非主域名（例如 `boke.jiuliu.org`）访问 OAuth 发起入口时，安全 GET 重定向到 `home_url()` 的认证主域名；非主域名的直接 POST 被拒绝，不从其他域名接受已提交的 Turnstile 令牌。OAuth 回调路径不做这条自动重定向，避免丢失 state。
- 在 **`/oauth/clogin/callback`** 的 `wp_safe_redirect_fallback` / `wp_redirect` 阶段，保证非法目标回退博客首页，并校验同站 HTTPS、排除重新进入 `/oauth/clogin`、`/user-sign`、`wp-login.php` 的地址，以及多层百分号编码和外域；不安全时回退到博客首页。正常文章和个人中心返回地址保留。
- 为避免商业主题文件被更新覆盖，不直接修改子比主题源码。但**主题内部显式传入 `$rurl` 的全部调用无法仅通过本插件的 WordPress 通用钩子重写**；此时仍有 OAuth 入口与最终回调防护。需要结合主题版本的真实链路复测，不宣称覆盖所有可能的自定义主题调用。

## 上线与验收

1. 升级到 v3.1.6 前保留原插件设置和 Nginx 备份。不要为了测试此功能增加 Nginx 全局限流额度。
2. 在灰云 `blog.jiuliu.org` 直接发起正常 QQ 登录，验证 Turnstile（若开启）、第三方授权、`CLOGIN_STATE`、回调与文章返回。
3. 在 `boke.jiuliu.org` 发起登录，确认先安全跳转到 `blog.jiuliu.org`，然后正常授权。
4. 测试 `rurl` 指向 `/oauth/clogin` 的单层和多层编码，期望 Nginx 或插件 403。
5. 确认异常 callback 的结果回到博客首页，而不是无限重定向；正常文章回调不受影响。
6. 检查 Nginx 日志的 200、302、403、429/503 分布，保证正常用户没有受到原有全站共享限流误伤。

## 为什么单靠域名配置不能阻止源站 IP 绕过？

- 客户端可指定 HTTP Host 和 TLS SNI，无法仅根据这两个字段确认访客是否从正常 DNS 解析路径到达。
- Nginx 可以配置默认虚拟主机拒绝裸 IP/陌生 Host，**但保留灰云直连的正常域名**时，攻击者仍可使用源站 IP 加正确 Host/SNI 连接。
- 如需严格保证来自指定 Cloudflare 代理，应让相关域名橙云代理，并由防火墙限制源站入站为 CF IP，再在认证入口实施自定义客户端证书认证（Authenticated Origin Pulls）或 Cloudflare Tunnel。仅信任共享 CF IP 还不能证明来自自己的 Cloudflare 账户。
- 若必须保持公开文章的灰云直连，需要接受这一网络边界，并在源站继续使用 Nginx 参数拦截、限流和应用层 Turnstile/2FA；不能把灰云站点整个 443 端口限制成 CF IP。

参考：[NGINX 默认服务器处理](https://nginx.org/en/docs/http/request_processing.html) · [Cloudflare Authenticated Origin Pulls](https://developers.cloudflare.com/ssl/origin-configuration/authenticated-origin-pull/)
