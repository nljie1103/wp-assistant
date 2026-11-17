# 付费资源安全交付：OpenList → 子比主题 → 九流 WP 助手 → Nginx

> **不能只安装插件就认为文件已受保护。** 必须按顺序建立站内私有转发，再封锁 OpenList 公共直链。默认插件开关关闭，不会自动改动现有付费交易。本文仅包含部署配置样例，**不会由插件自动写入 Nginx**。

## 适用范围与前提

- WordPress 正式入口：`blog.jiuliu.org`；OpenList：`openlist-blog.jiuliu.org` 和 `openlist-blog-cdn.jiuliu.org`（同一个 OpenList Nginx 虚拟主机）。
- OpenList 在相同服务器上，通过 `127.0.0.1:5245` 接入；付费文件的路径为 `/d/google/付费资源/...`。
- 子比 `/pay-download/{post_id}?key=...&down_id=...` 先执行它原生的已购、会员、免费次数/限额验证，再调用下载过滤器。九流 WP 助手在过滤器里进行内部交付，不独立判断或存储订单。
- **只保护匹配的公开源站域名及目录。** 非匹配链接、公开图片、WebDAV 路径不改变。
- **不影响源站存储和已支付记录**；不会变更 Google Drive 挂载或 WebDAV 的账号密码。OpenList 的管理员仍然可通过有身份认证的 WebDAV 访问文件。
- 浏览器端即使查看 DevTools 也不会获得私有 OpenList 直链，合法买家仍能将下载到的文件二次分享，这不是 DRM。

## 第一步：WordPress Nginx vhost 添加私有入口（先做，不要封锁源站）

在 `blog.jiuliu.org` 的 server{} 内加入；配置中 `internal` 必不可少：

```nginx
# WordPress 已购下载内部通道；浏览器直接请求这个路径会被 Nginx 拒绝
location ^~ /_jlwa_private_openlist/ {
    internal;
    proxy_pass http://127.0.0.1:5245/d/google/付费资源/;
    proxy_set_header Host openlist-blog.jiuliu.org;
    proxy_set_header Authorization "";
    proxy_set_header Cookie "";
    proxy_set_header Range $http_range;
    proxy_set_header If-Range $http_if_range;
    proxy_set_header Connection "";
    proxy_http_version 1.1;
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_read_timeout 600s;
    proxy_redirect off;
    proxy_hide_header Location;
    proxy_intercept_errors on;
    error_page 301 302 303 307 308 =502 /__jlwa_private_upstream_error;
    add_header X-Content-Type-Options nosniff always;
    add_header Cache-Control "private, no-store" always;
}
location = /__jlwa_private_upstream_error { internal; return 502; }
```

**必须检查该站点的真实 Nginx 配置和 server{} 结构**：不能重复冲突的 location；如果 OpenList 并非本机，需要改为受限的私有源 IP/隧道，绝不允许未授权直连。存储端不能把 302 Location 直接交还浏览器，因此示例明确不透出上游重定向。

测试 `nginx -t`，再 reload；在浏览器未登录直接请求 `https://blog.jiuliu.org/_jlwa_private_openlist/test.zip` 应得 **404**（Nginx internal 不允许公开访问），而不是文件数据。这里 404 只验证内部位置拒绝外部请求，**不能证明内部转发成功**。

## 第二步：在九流 WP 助手打开私有交付

后台 → 九流 WP 助手 → 下载页美化 → 私有安全交付。按实际情况确认：

| 字段 | 示例 |
|---|---|
| 受保护源站域名 | `openlist-blog-cdn.jiuliu.org`、`openlist-blog.jiuliu.org` |
| 公开源路径前缀 | `/d/google/付费资源/` |
| Nginx 私有内部入口 | `/_jlwa_private_openlist/` |
| 开关 | 配置完 Nginx 才启用 |

插件在收到匹配的源地址后，只接受 http(s)、允许的主机、正规目录前缀、没有用户名/密码/查询参数/路径穿越的文件 URL。非匹配资源保持子比原下载逻辑，匹配资源如有错误则拒绝而不重定向到公开源站。

用**已经购买的测试账号**打开一篇测试文章，检查请求、实际文件内容（下载大小、SHA-256）、速度、断点续传和 `Content-Disposition`。浏览器的下载管理/Network 中不能出现 OpenList 的文件源 URL。

**此时公开旧链接可能依然能访问；还不能称为已修复绕过付款。**

## 第三步：阻断 OpenList 的公开目录直链

确定第二步已购下载成功后，在**同时服务 `openlist-blog.jiuliu.org` 与 `openlist-blog-cdn.jiuliu.org` 的 OpenList Nginx server{}** 内，加入比原有 `location ^~ /` 更具体的前缀：

```nginx
# OpenList 文件直链、缩略预览禁止匿名公开访问付费目录
location = /d/google/付费资源 { return 403; }
location ^~ /d/google/付费资源/ { return 403; }
location = /p/google/付费资源 { return 403; }
location ^~ /p/google/付费资源/ { return 403; }
```

Nginx 按规范化 URI 匹配前缀，编码后的中文路径也应命中，但**必须在实际环境分别测试** URL 编码、大小写、额外斜杠及转义路径。上述规则作用在 OpenList 公网 Nginx 入口，不妨碍 WordPress 内部代理直接连接 `127.0.0.1:5245`；也不封禁 `/dav`，故原采集器 WebDAV 用户继续可以认证上传。

**特别重要：** 需要确保 `5245` 继续只监听 `127.0.0.1`；防火墙/反代其他域名和端口不能绕过公网拦截。如果有其他受信任服务能直接访问 `/d/google/付费资源` 的本机端口，仍应另行限制访问权限。

如 CDN 曾缓存 `/d/google/付费资源/*`，设置 Cloudflare 缓存旁路并清除该目录的旧缓存，否则边缘节点可能继续返回旧文件数据。

## 验收矩阵（不通过则回滚本次 Nginx 改动，不修改文章或订单）

| 请求 | 期望 |
|---|---|
| 游客访问 `/download?post=2823` | 可看到介绍，但不提供真实文件地址或付费提取码 |
| 游客直接请求 `/pay-download/2823?...` | 子比拒绝，无文件数据 |
| 游客请求 OpenList 旧 ZIP 直链 | **403**，不能下载文件 |
| 游客探测 OpenList preview `/p/google/付费资源/...` | **403** |
| 浏览器直接访问 `/_jlwa_private_openlist/... ` | 被 Nginx `internal` 拒绝 |
| 已购买用户点击子比下载按钮 | **200**、ZIP 完整、不出现 OpenList URL |
| 已购买用户断点续传 | **206**、Content-Range 正确（具体取决于上游能力） |
| 公开图片 `/d/google/图片/...` | 可正常显示 |
| 九流采集器 WebDAV `/dav` | 原凭据仍可上传、读取 |
| 其他代理、IP、镜像和缓存绕过入口 | 不可公开获取付费 ZIP |

## 回滚原则

先撤回 OpenList vhost 的公开目录拦截（立即恢复已购用户旧下载），再取消插件私有交付开关；如需撤回 WordPress 内部 Nginx 位置，确认私有交付已关。整个过程不重置用户订单、文章源 URL 或 WebDAV 密码。

**故障排查：** WordPress HTTP 503 通常是配置无法匹配/校验失败；付费下载返回 404/502 通常是 WordPress 内部 Nginx 位置配置错误或 OpenList 上游出错；如果浏览器仍看到 302 OpenList 地址，说明钩子未生效或该源 URL 没命中所配置的域名、路径前缀。
