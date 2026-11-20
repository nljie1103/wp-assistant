<!-- release-title: 登录安全密钥获取指引与后台帮助入口 -->

- README 增加极简“Site Key / Secret Key 在哪里获取？”入口，直接链接独立教程 `docs/turnstile-keys-guide.md`；详细步骤不挤进 README。
- 独立教程说明 Cloudflare Turnstile 中创建 Widget、Managed 模式、Hostname 管理、复制 Site Key 和 Secret Key、重新获取/轮换密钥及不启用橙云的注意事项。
- 登录安全设置页在 Site Key、Secret Key 字段和提示区域增加“如何获取”链接，指向同一个具体教程。
- 不修改 Turnstile 令牌验证、子比 OAuth 回调、Nginx 自引用 `rurl` 拦截或已保存设置；仍默认关闭，需管理员自行配置密钥并实际验收。
