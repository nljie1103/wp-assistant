<!-- release-title: 反调试安全审查与默认静默探针 -->

### v3.0.4：反调试安全边界核查，默认静默

针对浏览器禁用 JavaScript、Chrome 菜单打开开发者工具、主动 debugger 暂停、尝试关闭用户标签页和反复输出 90 项数组的问题进行了完整源码审查与修正。

- **正常浏览不再主动输出调试探针**：旧版 Console 求值会反复打印 /jlwa/；严格模式还可能打印 90 行数组、执行 debugger，甚至跳转到 Chrome Sources。这些实验性检测现在都要求单独开启授权开关，**新安装与既有严格模式配置默认关闭主动探针**。
- **明确控制两个实验能力**：在「反调试 → 探测器」中新增「主动 Console 探针（可见输出）」和「Debugger 暂停探针（可能中断）」。默认关闭；只有管理员充分知晓探针会暴露、误报和干扰访问之后才可开启。
- **其它能力不丢失**：F12、视口检测、调试库检测、得分/确认窗口、分级遮罩和现有跳转等继续由原保存设置决定；站长单独选中的第四级无限 Debugger 循环继续按原设置执行。
- **不夸大安全能力**：Chrome 可以从浏览器菜单打开 DevTools 或关闭 JavaScript，页面无法完全阻止；浏览器通常不允许网站自动关闭普通标签页。反调试只能阻碍普通复制，真正的付费文章和 ZIP 必须由 PHP + Zibll 订单 + Nginx OpenList 在服务器端验证。
- **保留现有私有交付**：本次不会修改站点支付/下载权限、OpenList Docker 端口、Nginx root 受限助手、付费文件白名单或数据库结构；不会自动开启任何强力锁死选项。

### 验证

- 新增测试覆盖默认严格模式无 console.log、console.table 和 Function(debugger) 调用，单独 opt-in 的探针仍能按需要运行。
- 完整 PHPUnit、JavaScript、Nginx 辅助程序与 WordPress 打包检查继续执行。
- 详见 docs/anti-debug-security.md 的禁用 JS、DevTools、服务端授权及 CDN 缓存核查步骤。

**升级提示**：如果此前手动启用了第四级无限 Debugger 循环，它依旧会触发 debugger；请在「分级防御链」中自行检查。正式 WordPress 生产页面与 CDN 缓存仍需站点更新后验证。
