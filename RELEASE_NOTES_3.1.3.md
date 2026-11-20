<!-- release-title: 新增彩虹 OAuth 登录安全与 Turnstile 验证 -->

- 九流WP助手新增“登录安全”模块，与现有 Vue 3 导航、总览卡片、统一管理表单融合。
- 可配置 Cloudflare Turnstile Site Key 和 Secret Key（也支持 wp-config.php 常量），默认关闭；密钥不全无法启用。
- 仅对 `/oauth/clogin` 发起路由启用服务端验证码门禁，严格校验 Siteverify 的 hostname/action，放行凭据短时、单次且绑定 OAuth 原始 URL；原 `/oauth/clogin/callback` 保持不变。
- 自引用 `rurl` 防御仍由已独立部署的 Nginx 规则负责；插件中也再次拒绝明显的递归返回地址。无需 Cloudflare 橙云 CDN。
- 代码回归测试覆盖注册、编码边界和校验返回结果。正式启用前需要管理员创建密钥、真机完成彩虹 OAuth 登录回归；本更新不会自动打开该功能。
