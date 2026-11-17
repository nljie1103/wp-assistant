<!-- release-title: 修复OpenList私有下载一键安装参数错误 -->

### 修复自动安装直接崩溃

- 修复 install-auto 成功找到 WordPress 和 OpenList Nginx 站点后，因自动命令没有 args.host 参数而抛出 AttributeError 的 Bug。
- 新版自动模式使用 --openlist-host，手动模式继续使用 --host，两个入口都由同一个受限安装器正确验证与写入授权清单。
- 支持中文付费目录（例如 /d/storage/付费资源/）和原有 Docker 本机端口设置，不要求修改历史 WordPress 资源地址。

### 提前检查并确保失败安全

- 在创建授权清单、root helper、sudoers 文件之前检查已有安装、Nginx 语法、本机端口和 Docker 绑定是否满足条件；错误时明确拒绝。
- sudoers 配置先完成语法检查，部分文件写入失败会删除本次创建的安装文件，不去覆盖原有安装与站点配置。
- 旧版该 AttributeError 发生在首次写入之前，因此仅出现本次错误时不会改动 Nginx。升级后必须重新执行完整自动安装命令。

### 测试与安装步骤

- 针对真正的 argparse 参数执行端到端模拟：自动识别 WordPress/OpenList Nginx 虚拟主机、执行授权安装、检查端口与中文目录、校验写入结果；原始站点文件保持原样。
- 测试无法连接/不安全端口时不落盘、部分写入失败清理和原有手动安装模式兼容。
- 安装仅完成 root 受限授权；之后要回 WordPress 后台按照预览配置、配置内部代理、已购账号下载测试、确认、封锁公网付费目录、CDN/API 测试的步骤完成真实部署。若 Docker 端口向公网监听，新版会拒绝继续，这是必须先解决的源站安全问题。

本次修复仅修改九流 WP 助手；不修改子比主题源码、OpenList 数据、生产 Nginx、WebDAV 或会员订单。
