<!-- release-title: 修复Nginx受限助手安装状态误判 -->

### 已安装成功却显示“尚未安装”

在部分宝塔 PHP-FPM 环境中，PHP 的 open_basedir 限制不能直接读取 root 拥有的 /usr/local/sbin 系统目录。旧版使用 is_file 检查 root helper，误判为“尚未安装”。

本版不再依赖 PHP 读取 helper 文件，而是通过预先授权的固定命令 sudo -n /usr/local/sbin/jlwa-nginx-helper status 查询系统真实状态。

### 操作与安全

- WordPress PHP 只能运行固定白名单操作，不允许通过表单提交任意命令、路径或 shell 参数。
- 未确认助手返回有效 JSON 状态时不显示 Nginx 修改/封锁按钮；PHP-FPM 禁用 proc_open 或 sudo 权限不一致时会显示实际执行错误。
- 已经看到终端输出 installed 的用户**不需要重新安装**，只更新 WordPress 插件并刷新 Nginx 自动部署页面。
- 如仍失败，可用系统管理员终端通过 sudo -u www /usr/bin/sudo -n /usr/local/sbin/jlwa-nginx-helper status 只读诊断。

### 测试与兼容

- 新增 PHP open_basedir 受限运行环境测试与插件受限操作白名单测试；隔离 WordPress 后台检查失败态的只读诊断与危险按钮隐藏。
- 保留 PHP/JS/Python 全量测试，不修改生产 Nginx、OpenList、Docker、WebDAV 或子比主题文件与订单。
