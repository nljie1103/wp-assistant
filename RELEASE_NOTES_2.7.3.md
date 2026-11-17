<!-- release-title: 新增OpenList公网图片白名单与安全迁移回退 -->

### 默认拒绝所有 OpenList 公网文件，只开放图片

原有受限 Nginx 助手仅封锁一个已知付费文件夹的 /d/ 与 /p/ 直链，同一挂载下的其它文件仍可能公开访问。

本版新增可选 **公网图片白名单**：在 OpenList 的全部文件访问路由 /d/ 和 /p/ 下默认返回 403，唯一放行管理员指定的某个图片目录及子文件夹，例如：

- 允许 /d/storage/public-images/ 和 /p/storage/public-images/ 下的文件；
- 拒绝 /d/storage/private/、/p/storage/private/、其它目录、其它挂载和未来新增的文件路径；
- OpenList 的 /api/、/dav/ 和后台管理不被此文件路由策略改变，原有认证仍需保持。

此策略默认关闭；更新插件本身不会更改已保护站点，也不会让 WordPress PHP 获得写 root 配置的权限。

### 从已有 protected 状态升级

1. 在 WordPress“下载页美化 → 交付高级设置”填写唯一公开图片目录并保存。例如 /d/storage/public-images/，必须与已授权付费目录处于相同挂载，并且二者不能有父子目录关系。
2. 返回“Nginx 自动部署”，复制 root-only 只读预览命令（preview-images-only），核对其只改变 OpenList 公网访问策略。
3. 预览正确后，由站长在服务器 root 终端执行 upgrade-images-only。升级程序核对现有 protected、已购验证、原备份与文件 SHA，然后完成 Nginx 语法检查/重载，更新只读状态与 root 管理 helper；失败会尝试自动恢复全部原文件。
4. 刷新后台并验证真实图片能够公开显示，其它文件目录返回 403，WordPress 已购买 ZIP 继续可以下载，WebDAV 与 API 按原有鉴权工作。

**注意**：root 已安装的旧版 helper 不会随着插件 ZIP 自动升级。此次为权限策略重大变化，必须重新使用明确的 root 授权升级命令；不能通过 WordPress 后台执行任意 root 命令。

### 安全回退

图片白名单升级后，后台提供 rollback-images-only 的 root-only 命令。它**只撤销图片白名单**，恢复之前已生效的付费目录封锁，WordPress 内部代理仍然存在，不会让全部付费 ZIP 重新公开。

普通的全量“恢复本次配置备份”可能移除完整的私有交付配置，切勿将其当作白名单回退操作。

### 验证

- 完整 PHP/Python/Nginx 辅助单测、权限与参数安全检查和 GitHub Actions CI。
- 使用真实 Nginx 1.24 临时站点验证中文图片目录（含百分号编码）返回允许、其它文件挂载返回 403、编码目录穿越返回 403，API 与 WebDAV 不被规则本身阻断。
- 本次仅改插件和 root 升级工具源码，不在更新时直接修改生产 Nginx、Docker、OpenList、支付订单或主题文件。
