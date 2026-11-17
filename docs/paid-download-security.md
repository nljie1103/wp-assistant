# WordPress 付费资源安全交付：Nginx + OpenList 通用教程

> 适用于 WordPress、子比主题、九流 WP 助手，以及由 Nginx 反向代理的 OpenList。以下全部使用 **example.com、示例目录和示例端口**，不对应任何实际站点。真实部署信息只应保存在站长自己的后台配置中，不应写入公开 README、教程、日志或默认代码。

## 一分钟理解：只需要两个网站角色

| 网站角色 | 示例地址 | 用途 |
|---|---|---|
| WordPress 网站 | `https://site.example.com` | 文章、订单、下载页、权限校验和下载入口 |
| OpenList 网站 | `https://files.example.com` | 图片和资源文件的存储访问 |

**一个 OpenList 网站可以绑定多个域名。** 例如 `files.example.com` 使用直连、`files-cdn.example.com` 使用 CDN，它们仍是**同一个 OpenList 服务**；如果两个域名绑定同一个 Nginx 站点/虚拟主机，只需在该站点配置**一次**保护规则，不需要部署两个 OpenList，也不需要重复配置。插件可以把不同访问域名填入允许列表，但这只是同一存储的不同入口。

**保护分为两层**：未经子比授权的人不能看到 WordPress 下载页；即使猜到了 OpenList 原始直链，也不能匿名下载文件。只保护下载页而不保护 OpenList 源站是不够的。

## 第一步：确认实际环境并备份（当前版本需管理员操作）

宝塔 → **网站 → WordPress 对应网站 → 设置 → 配置文件**，以及 **OpenList 对应网站 → 设置 → 配置文件**。

需要找到两份**网站配置**（可能是同一台服务器上的两个 Nginx 虚拟主机）。例如宝塔通常使用：

```text
/www/server/panel/vhost/nginx/<WordPress站点配置名>.conf
/www/server/panel/vhost/nginx/<OpenList站点配置名>.conf
```

这是模板路径，**不要原样复制尖括号**；使用宝塔显示的实际文件名。

在编辑前分别用宝塔备份功能，或通过 SSH/终端执行（替换两条路径为实际值）：

```bash
sudo cp -a '/path/to/wordpress-site.conf' '/path/to/wordpress-site.conf.jlwa.bak'
sudo cp -a '/path/to/openlist-site.conf' '/path/to/openlist-site.conf.jlwa.bak'
```

确认 OpenList 的 Docker 端口**没有直接向公网开放**，例如将容器的 5244 映射到宿主机 `127.0.0.1:5244`（这里只是示例；有其它端口时使用真实值）。

## 第二步：WordPress Nginx 内部下载位置

在 **WordPress 对应 Nginx 的 `server { ... }` 中**增加下面这两个 `location`。不要放进某个已有 `location` 内。

**先将三处示例值替换成你自己的配置：**

- `127.0.0.1:5244` —— WordPress 服务器能访问的 OpenList 内部地址和端口
- `/d/storage/private/` —— OpenList 真实的付费资源目录前缀
- `files.example.com` —— OpenList 网站的主机名

```nginx
location ^~ /_jlwa_private_openlist/ {
    internal;
    proxy_pass http://127.0.0.1:5244/d/storage/private/;
    proxy_set_header Host files.example.com;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Authorization "";
    proxy_set_header Cookie "";
    proxy_set_header Range $http_range;
    proxy_set_header If-Range $http_if_range;
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_read_timeout 600s;
    proxy_redirect off;
    proxy_hide_header Location;
    proxy_intercept_errors on;
    error_page 301 302 303 307 308 =502 /__jlwa_private_upstream_error;
    add_header Cache-Control "private, no-store" always;
    add_header X-Content-Type-Options nosniff always;
}

location = /__jlwa_private_upstream_error {
    internal;
    return 502;
}
```

**重要：** 这只是 Nginx 的通用示例，不保证所有 OpenList 存储驱动都直接返回文件内容。若 OpenList 再次重定向至外部云盘，必须确认它不会将上游 `Location` 暴露给浏览器；必要时改用更合适的服务端转发方案。不要为了让下载成功而关闭这个安全限制。

验证配置成功再重载：

```bash
sudo nginx -t
sudo nginx -s reload
```

使用浏览器或 curl 直接请求 `https://site.example.com/_jlwa_private_openlist/probe.zip`，应该被 Nginx 的 `internal` 拒绝（一般 404），不能下载任何文件。

## 第三步：在九流 WP 助手中启用私有交付

WordPress 后台 → **九流 WP 助手 → 下载页美化 → 付费资源 · 私有安全交付**：

| 字段 | 例子 |
|---|---|
| 启用私有交付拦截 | **先测试内网位置，再开启** |
| 受保护源站域名 | `files.example.com`（有 CDN 别名再另起一行填写） |
| 公开源路径前缀 | `/d/storage/private/` |
| WordPress Nginx 私有内部入口 | `/_jlwa_private_openlist/` |

开启后，用已经获得合法购买权限的测试用户点击子比下载按钮，检查下载是否完整、能否续传，以及浏览器 Network/下载历史中是否还出现 OpenList 原始文件 URL。**确认成功前，不要封锁公共文件入口。**

若匹配源文件下载失败，先关插件里的私有交付开关排障；无需修改子比主题。

## 第四步：在同一个 OpenList 网站的 Nginx 上关闭付费文件直链

只有第三步已购下载实测正常，才在 **OpenList 对应网站的 Nginx server{}** 中加入以下规则（目录必须与插件配置和实际存储一致）：

```nginx
location = /d/storage/private { return 403; }
location ^~ /d/storage/private/ { return 403; }

location = /p/storage/private { return 403; }
location ^~ /p/storage/private/ { return 403; }
```

即使 OpenList 绑定了直连域名和 CDN 域名，只要两者属于相同的 Nginx 站点配置，就**只修改这一处**；若由不同 Nginx/CDN 入口代理到同一服务，还必须分别核实每个入口都无法绕过拦截。

再次 `nginx -t`、reload。匿名访问 `https://files.example.com/d/storage/private/sample.zip` 应返回 **403**。如果 CDN 曾缓存文件，需要在 CDN 中绕过该目录缓存并清理旧缓存，否则边缘节点仍有可能发送历史 ZIP。

对 `/d/storage/public-images/` 等公开图片目录和 OpenList 的 `/dav`（如业务需要 WebDAV）不要误拦截；不需要更换 WebDAV 密码。

## 第五步：验收与回滚

| 测试 | 期望结果 |
|---|---|
| 未购买或未登录者访问 `/download?post=<文章ID>` | 返回文章购买区域，不加载下载页资源 |
| 子比有效购买者打开下载页并点击下载 | 正常下载完整文件 |
| 把 A 的 `/pay-download/` 地址给无权限的 B | 被子比拒绝 |
| 未购买者直接访问 OpenList 旧文件链接 | 403，不得到 ZIP 数据 |
| 已购买者的浏览器下载历史 | 不暴露 OpenList 文件源 URL |
| 内部 URL 被浏览器直接访问 | Nginx `internal` 拒绝 |
| 已购买者断点续传、多个文件 | 文件正常，且不泄露上游重定向 |
| 公开图片、OpenList 管理、WebDAV | 正常工作 |
| 云端缓存、OpenList API、其它端口或其它反代入口 | 无法绕过文件保护 |

回滚顺序：**先恢复 OpenList Nginx 的外部目录规则**，再关闭九流 WP 助手的私有交付开关，最后按需移除 WordPress 内部转发配置。这样可以避免在回滚期间把已经付款的用户锁在下载页外。

## 后续：插件内自动配置（开发约束）

自动检测、备份、写入、`nginx -t`、重载、验证、失败回滚、审计及限流全部在 **九流 WP 助手** 的功能域内实现；绝不修改子比主题源文件。由于 WordPress 的 PHP 进程通常没有系统配置写权限，自动配置若需要特权操作，应通过**随本插件分发、一次性由站长授权安装的严格受限部署组件**完成，不能给 PHP 用户 root 权限或直接 chmod 777。未经验证的站点不应被插件自动改写。

开发边界和验收要求见 [《付费下载部署与权限边界》](private-download-design.md)。
