# 私有下载：九流 WP 助手 v2.6.3 自动部署教程

> 本教程全部采用 `example.com` 等示例，**不要直接照抄域名、目录或端口**。只需分清两个网站：WordPress 网站和 OpenList 文件网站。OpenList 绑定直连/CDN 两个域名时，如果都指向同一 Nginx 站点配置，只需要保护这一个站点配置。

## 1. 先弄清楚安全边界

- 子比主题负责购买资格、Nonce、会员和下载资格；**本插件不修改子比主题文件**。
- WordPress 通过 Nginx `internal` 在服务器内部向 OpenList 请求文件，浏览器只收到下载数据，不收到原始 OpenList 文件 URL。
- **OpenList 公开入口必须拒绝付费目录**。如果 WordPress 与 OpenList 在同一台机器上，本机 `127.0.0.1` 是最适合的内部访问路径，不需要给访客或 CDN 的 IP 开放权限。
- 通过 Nginx 拦截的是公网入口。仅隐藏域名、限制 `Referer`、检查前端 `Host` 都不可信；按真实网络来源限制 IP 则有作用，前提是信任链和反代配置正确。
- 禁止访问付费目录不等于关闭整个 OpenList。公开图片应在别的公开目录，WebDAV 仍可使用原有账号；OpenList API、其它端口、历史缓存必须单独验收。

## 自动安装报错处理

若旧版执行 install-auto 时出现 AttributeError: 'Namespace' object has no attribute 'host'，这是旧安装程序将自动模式的 --openlist-host 误当成手动模式 --host 导致的程序缺陷。错误发生在系统授权及 Nginx 写入之前。请升级到 v2.6.9 或更高版本，重新复制当前后台生成的完整命令。不要手动修改 Python 脚本，也不要为解决此错误开放 Nginx 文件权限。

此外，自动安装要求 OpenList 的宿主机端口可通过 127.0.0.1 访问，并且不允许监听 0.0.0.0 等公网地址。如果安全检查拒绝端口映射，需先调整 Docker/宝塔绑定并确认文件数据正常，再重新执行。

## 2. 推荐：后台填本机端口，复制一条命令

不需要手动寻找 WordPress 和 OpenList 两份 Nginx 配置文件，也不必先知道 Docker 容器名。

1. 打开 **九流 WP 助手 → 下载页美化 → 付费资源 · 私有安全交付**。
2. 填写 OpenList 文件访问域名、付费目录原始路径，并在 **OpenList 本机端口** 里输入 Docker 映射到宿主机 \`127.0.0.1\` 的端口号，例如 \`5244\`。**不是容器内部端口。** 一般不必填写「Docker 容器名称」。
3. **保存下载页设置**。下方「Nginx 安全部署向导」将自动显示一条符合当前服务器情况的**完整安装命令**，点击 **复制一键安装命令**，在该服务器的 root/sudo 终端执行即可。命令中的网站域名、插件安装路径、OpenList 源站和私有目录由插件读取已保存的配置生成，不需要手动改路径。
4. 首次授权安装时，管理员脚本自动扫描宝塔 \`/www/server/panel/vhost/nginx/\` 的站点配置，按 Nginx \`server_name\` 匹配 WordPress 网站和 OpenList 网站。按本机端口检测 Docker 映射（容器名可选），检查该端口能从 \`127.0.0.1\` 连接、没有向公网监听，并保存允许管理的两份 Nginx 配置清单。
5. 首次命令**只安装受限的系统助手，不会立即封锁文件**。返回 WordPress 插件后台，依次点「预览配置差异」→「自动备份并配置内部代理」→ **用真实已购账号验证下载** →「确认已购用户下载测试」→「封锁 OpenList 公网付费目录」。
6. 用无痕浏览器验收旧 ZIP 直链返回 403、公开图片正常显示、WebDAV 正常工作；检查源 IP、其它端口、OpenList API 及 CDN 是否还能绕过访问。

**示例界面里只应填写**：

| 设置 | 示例 |
|---|---|
| OpenList 访问域名 | \`files.example.com\`（可选另填同服务的 CDN 别名） |
| 私有源目录 | \`/d/storage/private/\` |
| OpenList 本机端口 | \`5244\` |
| Docker 容器名 | 留空，自动匹配；识别有误时再填写 |

这比复制多行带占位符的命令更简单，但仍保留一道系统管理员授权：WordPress PHP 进程通常无权读取 root 持有的 Nginx 配置文件，**不能**为了省事赋予 PHP 全权 sudo 或将配置文件 chmod 777。生成的一条命令会复制助手到 root 所有的系统位置，之后只允许插件后台运行固定命令。

**适用边界**：自动匹配要求服务器为受支持的宝塔 Nginx 结构，且每个主机名只能唯一命中一个站点配置。复杂多 \`server{}\` 配置、容器端口不通、端口监听公网、匹配有歧义，都会停止并提示；可切换下方手动方式。不能把执行安装命令理解为“源站已保护”。

### 为什么只有付费目录被禁止，图片不受影响

假设 OpenList 的私有目录是 \`/d/storage/private/\`，公开图片目录是 \`/d/storage/public-images/\`：

| 路径 | 公网访问 | 已购内部下载 |
|---|---|---|
| \`/d/storage/private/\` | Nginx 返回 403 | WordPress 通过回环端口读取 |
| \`/p/storage/private/\` | Nginx 返回 403 | 不对外公开预览 |
| \`/d/storage/public-images/\` | 保持正常访问 | 不受影响 |

即使一个 OpenList 网站同时绑定两个域名（一个走 CDN，一个直连），**只要共用同一 Nginx 配置**，自动助手也只配置这一个站点，不会要求用户重复配置。这只是 URL 路径级封锁：仍须检测其它 API、WebDAV 匿名读取、Docker 端口和 CDN 缓存，不能仅凭 /d 返回 403 就宣布完全安全。

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
