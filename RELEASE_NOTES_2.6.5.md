<!-- release-title: 一键授权安装与 OpenList 目录精确隔离 -->

### 新手安装无需手动寻找 Nginx 配置

- 在九流 WP 助手后台填写 **OpenList 宿主机本地端口**；如果需要指定 Docker 容器，可展开高级选项填写名称，通常留空即可。
- 保存设置后，插件读取当前 WordPress 域名、插件绝对路径、OpenList 域名及私有目录，生成一条完整、可复制的首次授权命令。**无需手动拼接 --wordpress-vhost、--openlist-vhost 等文件参数。**
- root 受限助手自动扫描宝塔虚拟主机的 \`server_name\`，安全匹配唯一的 WordPress 和 OpenList 配置，并尝试按宿主机端口识别 Docker。无效、未监听或对公网暴露的端口会停止安装，不会将 Nginx 配置改成可被公众读取。

### 付费文件与公开图片分别处理

- WordPress 已购用户经内部代理取得 OpenList 付费文件；OpenList 公网 \`/d/私有目录/\`、\`/p/私有目录/\` 返回 403。
- OpenList 公共图片目录不会被这套规则封锁；同一 OpenList 的直连与 CDN 域名共用 Nginx 站点时只配置一次。
- 需要确认图片没有错误存放在付费目录内，同时继续核对 OpenList API、匿名 WebDAV、其它端口及 CDN 旧缓存的旁路风险。

### 实施边界与测试

首次命令仅安装 root-owned 的受限部署助手；实际写入和封锁由插件后台按步骤执行：预览 → 自动备份并部署 WordPress 代理 → 已购下载验证 → 源站封锁 → 匿名直链/CDN/WebDAV 复测。

只修改九流 WP 助手，**不修改子比主题、订单、WebDAV 凭据或当前生产服务器**。包含虚拟主机自动识别、Docker 端口检查、错误输入拒绝、原有 Nginx 备份回滚和 WordPress 保存设置测试。
