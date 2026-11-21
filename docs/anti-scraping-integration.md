# 反采集防护（九流 WP 助手内置模块）

本功能属于九流 WP 助手本体，通过 **九流WP助手 → 反采集防护** 管理，不需要另装 MU 插件。首次安装及升级默认关闭，必须由管理员显式启用。

## 防护范围

启用主开关后，下列规则可以独立开启或关闭：

1. **严格 WordPress REST 内容读取封锁**：拦截 `GET/HEAD /wp-json/wp/v2` 及其子路径（WordPress 解析后的 `/wp/v2/*` 路由），包括文章、页面、自定义文章类型、媒体、分类、标签、评论、用户、搜索等。`/?rest_route=/wp/v2/posts` 走相同的路由，亦被封锁。**不因已登录或管理员身份而豁免**。仅拦截读取方法，不改变 `POST/PUT/PATCH/DELETE` 路由。
2. **RSS / Atom / RDF**：拒绝 WordPress Feed 的页面响应，包括评论、分类 Feed。
3. **oEmbed**：拒绝标准 `GET /oembed/1.0/embed` REST 端点和页面嵌入；不会封锁 oEmbed 内部代理及其他非内容类 REST 端点。
4. **XML-RPC**：WordPress 处理层拦截 `XMLRPC_REQUEST`，同时禁用认证及所有 XML-RPC 方法；既有 Nginx XML-RPC 403 可继续保留。

所有设置以 `jlwa_anti_scraping_options` 保存，关闭主开关即可立即恢复上述 WordPress 输出。保存需 `manage_options` 与 WordPress nonce。开启严格 REST 封锁时必须额外勾选兼容性确认，避免误操作。

## 必须知晓的兼容性影响

- 区块编辑器（Gutenberg）、媒体库选择器、文章搜索、第三方编辑器、部分主题/插件的前端功能依赖 WordPress 的 `wp/v2` GET API，启用严格封锁后可能无法正常编辑、检索或展示。管理员也不例外。
- 若要同时保留 Gutenberg，可取消勾选 **封锁 WordPress 内容 REST 读取**，保留 RSS、oEmbed、XML-RPC 项。这个回退是功能级的，不是登录白名单。
- 某些缓存插件或 Cloudflare CDN 可能已缓存 REST、RSS 的旧版 200 响应；启用后应清理相关路径缓存，并检查公开响应是否仍能从缓存获得。
- 对网页 HTML 的真实浏览器抓取、截图和手工复制，无法在允许公众阅读文章的条件下彻底阻止。现有 Cloudflare Bot Fight Mode、规则限速、主题反调试可以增加批量采集成本，但不是不可绕过的 DRM。
- WordPress 常规 sitemap 不在本模块的关闭范围内，以便继续被搜索引擎索引。第三方主题、插件自定义的导出接口需单独审计。

## 测试和验收

1. 默认关闭时，访问 `/wp-json/wp/v2/posts?per_page=1` 仍按旧行为响应。
2. 启用主开关及 REST 后，无论匿名还是登录状态，`GET /wp-json/wp/v2/posts`、`HEAD /wp-json/wp/v2/posts` 和 `GET /?rest_route=/wp/v2/posts` 都应返回 403，错误码 `jlwa_anti_scraping_blocked`。
3. `/wp-json/jlwc/v1/run`、WordPress 其他命名空间和各类写入方法不应被本模块一刀切阻止；真正权限仍由原处理程序判断。
4. 启用 RSS 后，`/feed/` 和文章 `/feed/` 返回 403；启用 oEmbed 后，相关嵌入路由返回 403。
5. 测试区块编辑器、经典编辑器、子比主题支付/登录/评论及站点缓存。发现问题可直接关闭反采集主开关，无需删除插件。
6. **不要单凭 REST 返回 403 就认定 HTML 已不可采集。**

## 更新流程

这是内置模块。变更需要通过 WP 助手本体版本发布，不单独增加插件下载或外部运行依赖。该功能默认处于关闭状态，首次升级不会改变原网站行为。
