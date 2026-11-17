# 媒体与链接：Nginx 可选静态缓存

**不是多域名访问或媒体 URL 转换的必要条件。** 这些功能在 WordPress 层工作；已正常访问时，没必要修改 Nginx。

## 适用场景

仅当站长有一个独立的 Nginx 反向代理入口（例如 `proxy.example.com`），想对其公开 CSS、JS、图片和字体增加服务器缓存时使用。两个域名如果只共用一个 WordPress Nginx 站点，或 Cloudflare 已经缓存静态资源，通常无需配置。

## 一键配置流程

1. 在“媒体与链接 → 多域名适配”里把独立反代域名加入白名单并保存。
2. 打开“Nginx 可选配置”，填写独立反代域名及真正的源站 `https://origin.example.com`。
3. 保存后，插件自动根据自己的绝对路径生成完整单行命令：`preview`（预览）、`apply`（安装）和 `rollback`（恢复）。
4. 首次只执行 `preview`，只读检查站点配置，无写入。确认确实是独立反代入口而非 WordPress 主站，才执行 `apply`。
5. `apply` 在管理员权限下自动查找唯一的宝塔 Nginx vhost，备份原配置，仅为特定公开静态目录添加缓存，执行 `nginx -t`，通过后 reload；失败会恢复原配置。
6. 执行后验证静态文件的 `X-JLWA-Media-Cache` 响应头和两次请求后的缓存状态。再测试登录、图片、文章、付费下载和 WebDAV。必要时运行 `rollback`。

## 自动部署不会修改的内容

- 不会修改 WordPress PHP 站点或子比主题源码
- 不会缓存任何动态页面、会员、订单、`/pay-download/`、私有文件或 WebDAV
- 不会缓存带 Cookie、Authorization 或查询字符串的请求
- 不会修改已有复杂静态规则、现有 Nginx 缓存、多个 server 块或可能循环的反向代理配置
- 如果 Nginx 站点不唯一、不是独立代理或检测到可疑配置，会拒绝执行而不是猜测

默认缓存最大 1 GiB，超过 7 天未访问的资源可自动清理。回滚恢复 Nginx 原配置，但不会强制删除已有缓存文件。

管理员命令会运行插件包中的 Python 文件。应先验证正式安装包完整性，确认文件没有被无关用户篡改；不要赋予 PHP 全权 sudo。Nginx 的 `proxy_cache_path` 仅允许在 `http` 上下文使用，具体宝塔部署层级仍需通过 `nginx -t` 检测。

参考：[Nginx 官方缓存配置](https://nginx.org/en/docs/http/ngx_http_proxy_module.html)。
