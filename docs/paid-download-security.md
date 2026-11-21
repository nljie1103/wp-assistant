# OpenList 严格公网仅图片与 WordPress 私有交付

本模块随九流 WP 助手一起发布，**不需要独立 WordPress 插件**。它将 Nginx 的 OpenList 公网入口限定为一个批准目录，同时允许 WordPress 在已有购买授权后经本机端口交付私有文件。

**适用范围要分清：**

- OpenList 的 Nginx 路径防护模板不含作者域名、IP 或固定目录，可供其他站长按自己的环境配置。
- **自动部署**目前支持通过安全预检的**宝塔 Nginx、独立 WordPress/OpenList 虚拟主机和回环 OpenList 服务端口**。其它 Nginx 环境可以生成只读配置片段后由管理员手动部署。Apache、Caddy、共享主机不是自动支持对象。
- WordPress **购买资格检查与已购文件按钮**目前依赖**子比主题**提供的 `zibpay_download_file_local` 等钩子。其它主题或商城插件不会自动获得这套付费授权集成。
- 插件首次安装与 Nginx 实际保护是两件事。**只安装 WordPress ZIP 不会更改 Nginx**；必须先完成下面的部署流程。

## 唯一的全新安装安全策略

新安装一律使用 `strict_images_only`（规则修订 2），**不提供 `paid_only`、`images_only` 等弱模式和旧版本升级链**。

| 请求方式 | OpenList 公网域名 | 本机 OpenList `127.0.0.1:<端口>` |
|---|---|---|
| GET/HEAD `/d/storage/图片/a.jpg`（配置好的目录） | 允许；是否返回 200 由资源存在与否决定 | 允许 |
| OpenList 首页、登录、`/api/*`、`/dav/*`、WebDAV | 403 | 允许，仍受 OpenList 自身权限管理 |
| 私有目录 `/d/storage/private/a.zip`、`/p/*` | 403 | 允许，WordPress 必须先核对购买资格 |
| 图片目录下 POST、畸形双重编码、越权路径 | 403 | 回环接口保持可用 |

Nginx 只根据**路径**开放指定图片目录。它不检查文件的真实类型：**绝不可把付费 ZIP、数据库备份、配置文件等放进公开图片目录**。该规则也会拒绝 OpenList 公网的 ACME HTTP-01 证书校验路径；请提前使用 DNS-01 或另行安排可靠续期方案。它不会自动清除 CDN 上的历史缓存。

## 全新站点：宝塔自动部署

**准备条件：** WordPress 和 OpenList 在同一台宿主机；OpenList 宿主机端口仅绑定回环地址，例如 `127.0.0.1:5244`；两个站点各有一份 root 管理的宝塔 Nginx 配置，且每份目标配置只有一个 `server { ... }` 块。OpenList 可以有多个域名，但**所有填写的域名必须共用同一份虚拟主机配置**。例如直连与 CDN 域名分别在两份 Nginx 配置时，安装器会拒绝一半保护、一半裸奔的危险部署。

站长在 **九流 WP 助手 → 下载页美化 → 交付高级设置** 填写并保存：

- **受保护 OpenList 域名：** 例如 `files.example.com`，有 CDN 别名再另起一行填 `files-cdn.example.com`。
- **私有资源原路径：** 例如 `/d/storage/private/`，不能只填存储根目录。
- **唯一公开图片目录：** 例如 `/d/storage/图片/`，必须与私有目录互不包含。也允许另一个挂载，例如 `/d/public/图片/`。
- **OpenList 宿主机回环端口：** 例如 `5244`。Docker 需将容器端口映射到宿主机 `127.0.0.1:5244`，不可发布到 `0.0.0.0`。Docker 容器名称可选。

保存后打开 **Nginx 安全部署向导**，复制唯一的首次 `install-auto --public-images ...` 命令到服务器 root/sudo 终端执行一次。这步只安装受限部署助手、白名单清单与固定 sudo 规则，**不会修改 Nginx 站点配置**。

然后依次执行：**① 预览配置差异 → ② 备份并配置 WordPress 内部代理 → 用真实已购账号下载完整文件并验证 → ③ 确认购买测试 → ④ 应用严格公网封锁规则**。第三步的确认由站长明确提交，不是脚本自动证明订单有效。部署助手每次修改都运行 `nginx -t` 和 reload；出现语法错误会尝试恢复原文件。

**拒绝安装是安全行为，不要绕过：** 如果图片目录留空、路径重叠、多域名不同 Nginx vhost、端口监听公网、无法执行 `ss` 检查、存在旧的托管标记、无 root 或配置文件权限不符合要求，新安装器必须报错且不创建不完整保护。不要为了安装成功而给 PHP 或网站目录赋予任意 root 权限。

## 其它 Nginx 环境：只读生成配置

不在宝塔标准目录、虚拟主机结构复杂或自动预检不支持的站点，仍可以使用最新版中的**只读片段生成器**。在后台的首次安装区域复制「非宝塔 / 自定义 Nginx」命令，或在插件目录运行类似：

```bash
python3 ops/jlwa-nginx-helper.py render-config \
  --wordpress-host site.example.com \
  --openlist-host files.example.com \
  --openlist-hosts files.example.com,files-cdn.example.com \
  --port 5244 \
  --source /d/storage/private/ \
  --public-images /d/public/图片/
```

它只打印 **WordPress 的 `internal` 代理片段**和 **OpenList 的严格公网入口片段**；不会获取 root 权限、写配置或重启服务。管理员必须分别放进正确的 Nginx `server { ... }` 块中；OpenList 若有多个独立 vhost，**每个公网入口都必须部署同等封锁**，并手动执行备份、`nginx -t`、reload、逐域名验证。WordPress 内部代理必须在封锁前先完成已购文件的实际下载测试。

此模式只是**配置生成**，不是对任何 Nginx 发行版的自动部署保证。

## 验收与边界

每个 OpenList 公网域名都必须核对：正常图片 200/206，`/`、`/api/fs/list`、`/api/auth/login`、WebDAV、其他挂载及真实私有 ZIP 为 403；图片目录下 POST 和双重编码的危险 URL 为 403。模拟伪造 `X-Forwarded-For: 127.0.0.1` 不应进入回环白名单。WordPress 首页、登录、支付、已购订单完整下载必须保持正常；本机 `127.0.0.1:<端口>` 访问 OpenList API 不能被误伤。

**绕过风险不止一个 vhost：** 未纳入规则的其它域名、直连 Docker 公网端口、额外反向代理、旧 CDN 缓存都应独立检查。如果已在 Cloudflare 缓存过私有资源，需要在 CDN 侧清理并验证；此 WordPress 插件不会静默操作用户的 Cloudflare 账号。

**回退风险：** 首次安装备份会保留。如果在严格保护部署后执行完整 `rollback`，可能重新开放原始 OpenList 地址。请先采取临时防护、确认操作风险，并在紧急恢复后重新验收全部公网入口。

**现有已部署站点：** 更新 WordPress 插件不会覆写之前 root 所有的部署助手或已生效 Nginx。不要在生产服务器上用 `install-auto` 重新初始化已有保护；新版新安装器会拒绝已有托管标记。历史迁移过程不属于本新版全新安装向导。
