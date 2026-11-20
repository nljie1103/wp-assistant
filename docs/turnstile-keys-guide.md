# Cloudflare Turnstile：获取 Site Key 与 Secret Key

本教程用于**九流 WP 助手 → 登录安全**中的两项密钥配置，不要求把网站域名开启 Cloudflare 橙云/CDN。

## 一、在 Cloudflare 创建 Turnstile Widget

1. 登录 [Cloudflare 控制台](https://dash.cloudflare.com/)，选择账户，进入左侧 **Turnstile**。也可以查看 [Cloudflare 官方创建 Widget 教程](https://developers.cloudflare.com/turnstile/get-started/widget-management/dashboard/)。
2. 点击 **Add widget**（添加 Widget）。
3. **Widget name**（名称）填写便于辨认的名称，例如 `Blog OAuth Login`。
4. **Hostname management**（允许的主机名）填写 WordPress 正式域名，例如 `blog.example.com`，**不要**填写 `https://`、斜杠或文章路径。需要匹配网站后台 WordPress 地址所使用的实际主机名。
5. **Widget mode** 选择 **Managed**（托管）；**Pre-clearance** 暂不启用，因为这里不依赖 Cloudflare WAF/橙云放行 Cookie。
6. 如果创建流程要求选择 **Region**（区域），通常选择你账户可使用的区域。Cloudflare 的 **China** 区域需获得相应账号权限，且区域在创建后无法更改；面向中国大陆的访问仍须实测 Turnstile 的可用性和速度。
7. 点击 **Create** 创建 Widget。

## 二、复制两项密钥

创建成功后，Widget 详情中会提供两项不同的值：

| 字段 | 它是什么 | 在九流 WP 助手填写哪里 |
| --- | --- | --- |
| **Site Key**（sitekey） | 可公开的 Widget 标识，浏览器用来加载验证码 | **登录安全 → Site Key** |
| **Secret Key**（secret key） | 私密服务端凭据，后端调用 Siteverify 验证令牌 | **登录安全 → Secret Key** |

如果已经创建过 Widget，在 Cloudflare 的 **Turnstile** 列表点击该 Widget，进入详情页查看其设置和密钥。**如果不能再次查看已丢失的 Secret Key**，按照 [Cloudflare 官方轮换密钥步骤](https://developers.cloudflare.com/turnstile/troubleshooting/rotate-secret-key/) 在 Widget 的 **Settings → Rotate Secret Key** 更新密钥，并同步更新 WordPress。

> **安全提醒：** Site Key 可以公开；**Secret Key 不能上传到代码仓库、发在聊天/工单、写在 README 或前端 JavaScript 中**。九流 WP 助手在后台保存后不会回显 Secret Key。也可在服务器 `wp-config.php` 中配置 `JLWA_TURNSTILE_SECRET_KEY` 常量，由服务器保存密钥。

## 三、在 WordPress 中启用

1. 登录 WordPress 后台，打开 **九流 WP 助手 → 登录安全**。
2. 分别填写上述 **Site Key** 与 **Secret Key**，先保存，保持“保护状态”关闭。
3. 用国内网络实测验证码是否能够加载；确认彩虹 QQ、微信等已使用的 OAuth 登录渠道能正常完成验证、第三方授权和回调，并成功回到原页面。
4. 验收通过后，再打开“开启彩虹 OAuth 登录前的强制人机验证”并保存。若验证无法加载，先关闭该功能；现有 Nginx `rurl` 拦截不会受影响。

**常见误区：** Turnstile 是独立的验证服务，Cloudflare 域名即便处于 DNS only（灰云）也能用；但这**不等于**获得 Cloudflare 边缘五秒盾或 WAF。验证仍需要浏览器访问 Turnstile 服务，服务器发起 Siteverify 请求。

参考：[Cloudflare Turnstile 官方入门](https://developers.cloudflare.com/turnstile/get-started/) · [Cloudflare 创建管理 Widget](https://developers.cloudflare.com/turnstile/get-started/widget-management/dashboard/)
