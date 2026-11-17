# 九流 WP 助手：付费资源安全下载部署教程

> 适用：WordPress + 子比主题 + 九流 WP 助手 + Docker OpenList v4 + 宝塔 Nginx。本文针对九流网络的实际部署给出可直接定位的文件路径和示例；换服务器/域名时请替换。**仅在插件后台启用开关还不足以防止他人复制旧的公开直链。**

## 1. 先看清楚这三个入口

| 域名 | 作用 | 当前 Nginx 位置 |
|---|---|---|
| `blog.jiuliu.org` | WordPress 文章、购买、授权下载 | `/www/server/panel/vhost/nginx/blog.jiuliu.org.conf` |
| `openlist-blog.jiuliu.org` | OpenList 管理与九流采集器 WebDAV | `/www/server/panel/vhost/nginx/openlist-blog.jiuliu.org.conf` |
| `openlist-blog-cdn.jiuliu.org` | OpenList 图片与文件入口，可能经 Cloudflare 缓存 | **与上一项共用同一份 Nginx 配置** |

现有 Docker 容器为 `openlist-blog`，端口发布为 `127.0.0.1:5245 → 5244`，而非开放给公网的 `0.0.0.0:5245`。WordPress 实际站点目录为 `/www/yecao/www.jiuliu.org`。

**两个不同的权限层：**
1. 访问 `/download?post=2823` 时：新版插件在服务器端用子比 `zibpay_is_paid()` 判定是否有购买资格。无资格的访客直接 302 返回 `/2823.html` 的购买区域，原下载页不会渲染。
2. 访问真正文件时：子比验证 `/pay-download/`，插件通过 `X-Accel-Redirect` 转交 Nginx 内部入口；OpenList 公网直链同时应被拒绝。**第二层独立于第一层，缺一不可。**

## 2. 执行前：备份配置、检查文件目录

请在美国 ARM 服务器的宝塔终端执行（命令不接触数据库，不更改文件）：

```bash
sudo docker ps --filter name=openlist-blog --format 'table {{.Names}}\t{{.Ports}}'
sudo docker port openlist-blog
sudo ls -l /www/server/panel/vhost/nginx/blog.jiuliu.org.conf
sudo ls -l /www/server/panel/vhost/nginx/openlist-blog.jiuliu.org.conf
```

如果域名、路径与上表不同，**先停止，按实际站点核对**，不要复制到错误的配置文件。

配置修改前各留一份备份：

```bash
STAMP=$(date +%Y%m%d-%H%M%S)
sudo cp -a /www/server/panel/vhost/nginx/blog.jiuliu.org.conf "/www/server/panel/vhost/nginx/blog.jiuliu.org.conf.jlwa-$STAMP.bak"
sudo cp -a /www/server/panel/vhost/nginx/openlist-blog.jiuliu.org.conf "/www/server/panel/vhost/nginx/openlist-blog.jiuliu.org.conf.jlwa-$STAMP.bak"
```

**提前检查文章图片是否放在付费目录**：例如 `/d/google/付费资源/...` 内的 ZIP、JPG 或 PNG 都将一并被保护；文章正常向游客展示的封面和预览图片必须存放于类似 `/d/google/图片/...` 的公开目录。否则第三步实施后公开图片可能失效。

## 3. 第一步：WordPress Nginx 配置内部下载通道

宝塔面板 → **网站** → 找到 `blog.jiuliu.org` 所在站点 → **设置 → 配置文件**。

此站点的实际配置文件为：

```text
/www/server/panel/vhost/nginx/blog.jiuliu.org.conf
```

在实际处理 `blog.jiuliu.org` 的 `server { ... }` **里面**增加以下两个 `location`（不要放在已有的 `location` 内，也不要放到 OpenList 虚拟主机）。已有网站规则、SSL、PHP、重写均保留。

```nginx
# 仅 X-Accel-Redirect 能进入；网页直接请求此路径会返回 404
location ^~ /_jlwa_private_openlist/ {
    internal;

    # 本机直连 Docker OpenList，绕开公开域名及其 CDN
    # 末尾斜线用于把内部前缀替换为真实的付费目录前缀
    proxy_pass http://127.0.0.1:5245/d/google/付费资源/;
    proxy_set_header Host openlist-blog.jiuliu.org;
    proxy_http_version 1.1;
    proxy_set_header Connection "";

    # 不把访客 Cookie/Authorization 送到 OpenList
    proxy_set_header Authorization "";
    proxy_set_header Cookie "";
    proxy_set_header Range $http_range;
    proxy_set_header If-Range $http_if_range;

    # 大文件流式传输，尽量保留 Range 续传支持
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_read_timeout 600s;

    # 不允许 OpenList 上游重定向暴露真实下载地址
    proxy_redirect off;
    proxy_hide_header Location;
    proxy_intercept_errors on;
    error_page 301 302 303 307 308 =502 /__jlwa_private_upstream_error;

    add_header X-Content-Type-Options nosniff always;
    add_header Cache-Control "private, no-store" always;
}

location = /__jlwa_private_upstream_error {
    internal;
    return 502;
}
```

**代码含义：** 用户拿到的是 `blog.jiuliu.org/pay-download/...`；PHP 通过 `X-Accel-Redirect: /_jlwa_private_openlist/psdstencil3/.../Kuwait-ID.zip` 把请求交给当前 Nginx 服务器，Nginx 再连接本机 OpenList，不向浏览器返回文件源地址。

宝塔保存配置后先校验。具体 Nginx 路径以宝塔安装实际情况为准：

```bash
sudo /www/server/nginx/sbin/nginx -t
# 仅当上一行输出 successful，才重载
sudo /www/server/nginx/sbin/nginx -s reload
```

**第一步验收（游客执行）：**

```bash
curl -sSI --max-time 15 'https://blog.jiuliu.org/_jlwa_private_openlist/probe.zip' | head -n 8
```

应返回 `404`，不能直接返回文件。**注意：404 只是说明 internal 生效，不是购买下载已联通。**

## 4. 第二步：启用插件内的私有交付

安装并启用最新版九流 WP 助手 → WordPress 后台 → **九流 WP 助手 → 下载页美化 → 付费资源 · 私有安全交付**。

填入下表；和 Nginx 前缀必须严格一致：

| 后台字段 | 本站配置 |
|---|---|
| 启用私有交付拦截 | **打开**（仅在完成第 3 步 Nginx 配置之后） |
| 受保护源站域名 | `openlist-blog-cdn.jiuliu.org` 和 `openlist-blog.jiuliu.org`，每行一个 |
| 公开源路径前缀 | `/d/google/付费资源/` |
| WordPress Nginx 私有内部入口 | `/_jlwa_private_openlist/` |

点击 **保存下载页设置**。**“下载页美化”开关与“私有交付”开关彼此独立。** 购买权限判断不要求用户一定登录：游客若被子比识别为已经成功购买/持有合法授权，也能够进入下载页；没有授权的账号或游客都会被送回文章购买区域。

**现在先验收一篇已购资源，不要先关闭 OpenList 公网：**

1. 用一个确实拥有文章 2823 下载权限的测试账号访问 `https://blog.jiuliu.org/download?post=2823`。
2. 点击下载，检查浏览器 Network：应来自 `blog.jiuliu.org/pay-download/...`，而不是 302 跳转到 `openlist-blog-cdn.jiuliu.org`。
3. 检查下载文件长度/ZIP 完整性、改名与断点续传。若上游返回 302 导致内部下载失败，会出现 502 而不会向用户泄露 URL，需先查 OpenList 存储状态。
4. 另开无痕浏览器，访问同一下载页：应跳回原文章，出现“尚未购买商品，请先购买商品，再来访问下载页”的提示。原文章里的普通公开图片应正常显示。

**如果已购用户无法正常下载，不执行下一步封锁，先检查 WordPress 日志和 Nginx 配置**；可随时关闭插件私有交付开关恢复原流程。

## 5. 第三步：在 OpenList 的 Nginx 阻断公开文件直链

现在才去宝塔 → 网站 → `openlist-blog.jiuliu.org` 站点 → **设置 → 配置文件**。文件是：

```text
/www/server/panel/vhost/nginx/openlist-blog.jiuliu.org.conf
```

该 `server_name` 同时包含 `openlist-blog.jiuliu.org` 与 `openlist-blog-cdn.jiuliu.org`。因此只需在**这个共用的 server 块**里增加四条规则；保持原来的 `location ^~ /` 反代到 `127.0.0.1:5245` 不变：

```nginx
# 禁止外网获得付费文件直链（包括文件夹和子文件）
location = /d/google/付费资源 { return 403; }
location ^~ /d/google/付费资源/ { return 403; }

# 禁止付费文件的 OpenList 公网预览入口
location = /p/google/付费资源 { return 403; }
location ^~ /p/google/付费资源/ { return 403; }
```

这**不是**在 OpenList 后台禁用整个 `/google` 挂载，也不需要关闭 Docker 容器。Nginx 只拦公开 URL 的付费目录；WordPress 内部通道使用本机 `127.0.0.1:5245`，因此不经此公开 Nginx 拦截。公开 `/d/google/图片/`、管理后台和 `/dav` 都不会被这四条规则直接拦截。

重复执行：

```bash
sudo /www/server/nginx/sbin/nginx -t
sudo /www/server/nginx/sbin/nginx -s reload
```

**第三步验收：** 浏览器退出登录，使用已经泄露的旧直链测试；现在应该 HTTP 403。编码中文的 URL 和原始中文 URL 都应被阻止。

```bash
# 用之前已泄露的资源路径测试（只看响应，不下载 ZIP 内容）
curl -sSI --max-time 15 'https://openlist-blog-cdn.jiuliu.org/d/google/%E4%BB%98%E8%B4%B9%E8%B5%84%E6%BA%90/psdstencil3/%E8%8F%9C%E5%8D%95%E4%B8%80/Kuwait-ID.zip'
curl -sSI --max-time 15 'https://openlist-blog.jiuliu.org/d/google/%E4%BB%98%E8%B4%B9%E8%B5%84%E6%BA%90/psdstencil3/%E8%8F%9C%E5%8D%95%E4%B8%80/Kuwait-ID.zip'
```

同时测试 `/p/google/付费资源/...` 预览、原本公开的图片地址、下载后的文件完整性，并核查**源 IP、其它反代、OpenList API、WebDAV 是否存在未认证读取付费文件的旁路**。特别地，`/api/fs/list` 返回 401 不等于已经防止已知文件下载；还须确认 `/api/fs/get` 等接口不会未经授权签出可下载链接。

## 6. OpenList 管理后台应该如何设置？

OpenList 管理后台：`https://openlist-blog.jiuliu.org/@manage`（请以真实域名为准）。

- **全局 → 签名所有：保持原样，不要贸然打开。** 因为你现在的公共图片仍需要可直接访问。
- **全局 → 直链有效期：无需因为本教程而改动。** 真正的目录保护来自公网 Nginx 拦截，不是此字段。
- **存储 → /google：不要停用、不要修改 Google Drive 凭据。** 它仍给 WordPress 内部通道和九流采集器提供文件。
- **元信息：可以作为后续第二层保护，但不作为本教程必须条件。** 误设父目录密码可能破坏公开图片或 WebDAV 以外的链接。
- **用户/WebDAV：无需修改 `admin` 密码。** 采集器继续使用 `https://openlist-blog.jiuliu.org/dav`。

**重要边界：** 此方案是网站 Nginx 层的访问控制，并不是在 OpenList 内独立为每个文件配置了授权令牌。只要其它公网入口能够绕过该 Nginx 直接访问 OpenList 的 5244/5245 或相关 API，仍可能泄露文件；要实际检查所有端口、API、Cloudflare 和反代入口。

## 7. Cloudflare / WordPress 页面缓存清理

下载页权限是**用户级、请求级**的判断，不能被静态 HTML 缓存绕过。配置 Cloudflare 的缓存规则和 WordPress 缓存插件时必须**同时**处理下面两个域名：

1. 对 `blog.jiuliu.org/download`（包含 `?post=...`）设置 **Cache Bypass**，WordPress 整页缓存（如果有）也必须排除该下载页。否则 CDN 可能继续返回此前缓存的“未购买可见”页面，绕过新插件的 PHP 校验。
2. 对 `blog.jiuliu.org/pay-download/*` 强制 **Cache Bypass**；它依赖用户购买资格，绝不能把别人的下载响应缓存后复用。
3. 对带 `jlwa_purchase_required` 参数的文章提示页保持不缓存（插件会发送 `no-store`，但请确认 CDN 不覆盖该响应头）。
4. 对 `openlist-blog-cdn.jiuliu.org/d/google/付费资源/*` 及其 `/p/` 预览入口设置 **Cache Bypass**；在 Cloudflare **缓存清除（Purge Cache）** 清除历史上已缓存的文件。
5. 使用外部网络、无痕窗口分别测试 `blog` 和 `openlist-blog-cdn`，确认 Cloudflare 不再返回已缓存的旧 ZIP 或未经权限检查的下载页。

**不要为省事缓存整个 /download 或 /pay-download 路径，即便启用了防盗链插件也不行。**

## 8. 上线最终验收

| 测试 | 预期 |
|---|---|
| 无痕访问 `/download?post=2823` | 302 回 `/2823.html?jlwa_purchase_required=2823#posts-pay`，原文提示未购买 |
| 已登录但未购买 | 同样被拦截 |
| 子比识别为已购买的用户 | 可进入原下载页和正常下载 |
| 未购买者访问 `/pay-download/2823?...` | 子比拒绝文件交付 |
| 游客直连 WordPress `/_jlwa_private_openlist/...` | 被 Nginx internal 拦截（通常 404） |
| 旧 `openlist-blog-cdn.jiuliu.org/d/google/付费资源/...` URL | 403，无 ZIP 数据 |
| OpenList 第二个域名与 `/p/` 预览 | 403 |
| 外部绕过（源 IP、其它端口/API/WebDAV 匿名读取） | 不允许未经授权读取付费文件 |
| `/d/google/图片/...` | 不受影响，正常显示 |
| 九流采集器已有 WebDAV 连接 | 继续正常上传/读取 |
| 已购用户大文件与续传 | 文件完整；上游支持 Range 时返回 206，不能出现对外暴露的真实源 URL |

**特别提醒：** 为保护购买凭据，不能只按用户是否登录来判断权限；子比有效购买资格为最终依据。这个下载页拦截是减少页面信息暴露，而不是代替文件层访问控制。

## 9. 回滚（出现购买用户无法下载时）

按顺序：
1. **先恢复 OpenList 公网规则**（临时允许原流程继续），校验并 reload Nginx。
2. **再关闭**插件「启用私有交付拦截」，已购用户恢复使用子比原下载方式。
3. 如需移除 WordPress Nginx 内部 `location`，确认开关已关闭后再删除或还原备份。
4. 整个回滚不修改 WordPress 付费文章、支付订单、OpenList Docker 挂载、Google Drive 凭据或九流采集器 WebDAV 密码。

若本机 OpenList 对测试 ZIP 本来就返回 30x，内部代理会安全拒绝，需调整源站响应方式。绝对不能为了消除 502 就把真实源 `Location` 发给浏览器。
