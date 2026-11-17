# 私有下载：九流 WP 助手 v2.6.3 自动部署教程

> 本教程全部采用 `example.com` 等示例，**不要直接照抄域名、目录或端口**。只需分清两个网站：WordPress 网站和 OpenList 文件网站。OpenList 绑定直连/CDN 两个域名时，如果都指向同一 Nginx 站点配置，只需要保护这一个站点配置。

## 1. 先弄清楚安全边界

- 子比主题负责购买资格、Nonce、会员和下载资格；**本插件不修改子比主题文件**。
- WordPress 通过 Nginx `internal` 在服务器内部向 OpenList 请求文件，浏览器只收到下载数据，不收到原始 OpenList 文件 URL。
- **OpenList 公开入口必须拒绝付费目录**。如果 WordPress 与 OpenList 在同一台机器上，本机 `127.0.0.1` 是最适合的内部访问路径，不需要给访客或 CDN 的 IP 开放权限。
- 通过 Nginx 拦截的是公网入口。仅隐藏域名、限制 `Referer`、检查前端 `Host` 都不可信；按真实网络来源限制 IP 则有作用，前提是信任链和反代配置正确。
- 禁止访问付费目录不等于关闭整个 OpenList。公开图片应在别的公开目录，WebDAV 仍可使用原有账号；OpenList API、其它端口、历史缓存必须单独验收。

## 2. 新手自动部署：先一次性授权，之后在插件后台操作

普通 PHP 用户通常无权写 root 所有的 Nginx 站点配置。v2.6.3 **随插件安装包自带受限部署助手** `ops/jlwa-nginx-helper.py`，不需安装另一个 WordPress 插件。

先用宝塔查看两份**实际的站点配置文件**，再从系统管理员终端执行以下**一次性命令示例**（必须逐项换成自己的值）：

```bash
sudo python3 /path/to/wp-content/plugins/jiuliu-wp-assistant/ops/jlwa-nginx-helper.py install \
  --wordpress-vhost /www/server/panel/vhost/nginx/site.example.com.conf \
  --openlist-vhost /www/server/panel/vhost/nginx/files.example.com.conf \
  --port 5244 \
  --source /d/storage/private/ \
  --host files.example.com \
  --php-user www
```

注意：

- 以上 `/path/to/...` 及各项值均是占位示例；`port` 必须是宿主机可通过 `127.0.0.1` 访问的 OpenList **本地端口**，不是 Docker 容器内部映射端口。
- 只能指定宝塔实际 Nginx 虚拟主机的两个普通 `.conf` 文件，必须由 root 管理且不可被其他用户写入。
- 助手只支持**单一 server 块**的简单站点配置；复杂 vhost 不会强制修改，改用下方手动教程。
- 该安装步骤会生成 root 所有的 `/usr/local/sbin/jlwa-nginx-helper` 和 `/etc/jlwa-download/approved.json`，仅授权 WordPress 的 PHP 用户通过 `sudo -n` 执行固定操作名（`status/preview/prepare/confirm/protect/rollback`）。**不允许任意 shell 命令或更改未批准的配置路径。**
- 安装脚本含 Nginx 语法检查；一旦有错误，停止并检查，**不要**将网站文件改为 `777` 或赋予 PHP 完整 root 权限。

然后进入 WordPress 后台 → 九流 WP 助手 → 下载页美化 → **Nginx 安全部署向导**：

1. **预览配置差异**：确认将增加的两个 Nginx 管理片段。未授权或复杂站点会显示错误，不改变任何文件。
2. **自动备份并配置内部代理**：备份 WordPress vhost 到 root-only 目录，写入 `internal` 规则，执行 `nginx -t` 并 reload；失败恢复原始配置。
3. **先启用私有交付**，使用真实拥有购买资格的账号从文章下载按钮测试：确认文件完整、浏览器 Network/下载历史没有 OpenList 原始 URL，断点续传符合预期。通过后勾选“已完成真实购买用户下载验证”并确认。**人工确认不代表助手已模拟完成实际文件下载。**
4. **封锁 OpenList 公网付费目录**：再次先备份，写入源站 `/d/` 和 `/p/` 公开拒绝规则，`nginx -t` 成功后才 reload。内部 `127.0.0.1` 通道继续可读。
5. 用外部无痕浏览器复测旧直链，期望 403。还需测试 CDN、其它入口、OpenList API（如 `/api/fs/get`）、图片与 WebDAV。**向导仅反映配置阶段，不声称已经证明全站无旁路。**

任何一步失败应停止；“回滚本次配置备份”只在已部署配置未被其它工具后续修改时自动恢复，避免覆盖其它管理员的新改动。

## 3. 无法首次安装助手时的手动方法

宝塔 → 网站 → WordPress 站点配置文件，在对应 `server {...}` 中添加：

```nginx
location ^~ /_jlwa_private_openlist/ {
    internal;
    proxy_pass http://127.0.0.1:5244/d/storage/private/;
    proxy_set_header Host files.example.com;
    proxy_set_header Authorization "";
    proxy_set_header Cookie "";
    proxy_set_header Range $http_range;
    proxy_set_header If-Range $http_if_range;
    proxy_buffering off;
    proxy_read_timeout 600s;
    proxy_redirect off;
    proxy_hide_header Location;
    proxy_intercept_errors on;
    error_page 301 302 303 307 308 =502 /__jlwa_private_upstream_error;
    add_header Cache-Control "private, no-store" always;
}
location = /__jlwa_private_upstream_error { internal; return 502; }
```

注意更改示例本地端口、源目录和 Host。先保存备份、`nginx -t` 成功才 reload，然后测试真实已购下载。

在 OpenList 网站的**同一个** Nginx 配置中，且只在已购下载测试成功后添加：

```nginx
location = /d/storage/private { return 403; }
location ^~ /d/storage/private/ { return 403; }
location = /p/storage/private { return 403; }
location ^~ /p/storage/private/ { return 403; }
```

再次执行 `nginx -t`，成功才 reload。OpenList 如同时绑定两个域名并共用一个 Nginx vhost，不需要在同一个文件重复添加这些规则。

## 4. 自定义下载出口域名及 CDN

例如：

- WordPress 主站：`site.example.com`
- 额外的下载出口：`download.example.com`
- OpenList 文件站点：`files.example.com`

首先确保 `download.example.com` **经过 HTTPS 正常抵达同一套 WordPress 实例**（DNS 指向和 TLS 证书由站长在 DNS/主机/CDN 平台配置，插件无法代替你拥有域名）。不能把下载出口直接指向 OpenList。

然后在插件“下载页美化 → 私有安全交付”中设置：

- 受保护源站域名：`files.example.com`，如有同一服务的 CDN 访问别名可再填一行；
- 私有目录：`/d/storage/private/`（与内部代理目标完全相同）；
- 下载出口域名：`download.example.com`（仅填主机名），确认能正确解析到同一个 WordPress 站点后再启用“自定义下载出口”；
- 下载凭证有效期默认 180 秒，可设置 60～600 秒。

工作机制：子比在主站授权后，插件创建 **48 位十六进制随机票据**，仅把短期票据通过 HTTPS 302 到下载域名的 `/?jlwa_dl=<票据>`。下载域名的 WordPress 对票据和 Host 校验，通过后再使用本机 Nginx 内部代理交付。票据不包含真实 OpenList 文件 URL 或路径。下载开始后持续传输；Range 续传需要在票据未过期前重新发起，过期后回到原文章再次获取。

**特别注意：短期票据是 Bearer 访问凭证。拥有票据的人在有效期内可能重复使用；它不是一次一请求的不可转发凭证。** 不推荐做 IP 绑定，否则移动网络、断点续传和代理变化会误伤购买用户。

**CDN 必须设置绕过缓存**：对于 `download.example.com` 上携带 `jlwa_dl` 参数的请求设置 Cache Rule → Bypass；对于主站的 `/pay-download/*`、`/download?post=...`，同样旁路整页缓存。插件发送 `Cache-Control: private, no-store`、`CDN-Cache-Control: no-store`，但 CDN 如果设置了忽略源站 Cache-Control 的强制缓存规则，仍有风险。

> 此模式只提供 CDN 代理网络路径、TLS/链路层的便利，**不保证大文件内容会命中 CDN 边缘缓存加速**。要在边缘缓存私有付费 ZIP，必须另外设计边缘令牌校验（例如受控 Worker + 签名方案），不能直接把这个票据 URL 设成“Cache Everything”。

## 5. 审计、限流和验收

- **审计默认开启**：最近 120 条授权事件，保留时间、用户 ID、文章 ID、线路和处理结果；不记录密码、票据、Cookie、完整真实文件 URL。
- **限流默认关闭**：可选按用户与文章限制 60 秒内的重新申请下载次数（默认阈值 30），不对每次 Range/续传片段重复计数；不根据单纯 IP 判断购买身份。
- **子比主题不修改**：原生 Nonce、订单、会员权限保持原样。下载页未购状态仍在服务端拦截。

上线必须验证：游客和未购买者不能打开付费资源页、跨账号下载被子比拒绝、真实已购用户成功获取文件、旧 OpenList 付费 ZIP 公网地址返回 403、图片和 WebDAV 继续可用、CDN 不缓存任何带授权票据的下载内容。

**回滚顺序**：先撤除源站公网封锁，保证买家暂时能正常下载；再关闭插件私有交付/自定义出口开关；最后恢复 WordPress 代理的本次备份。如果其他管理员已经修改过 Nginx，助手会拒绝覆盖，需人工对比备份。

公开安装包不会携带任何真实站长域名、文件 IP、私有下载路径。详细开发边界见 [设计约束](private-download-design.md)。
