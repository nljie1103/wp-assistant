# 九流 AI 文章摘要 (WP AI Article Summary)

> 作者：[九流](https://www.jiuliu.org) · 版本：1.0.9 · License：GPLv2+

一款高质量、高性能、可商用的 WordPress 插件。自动在文章顶部（标题下方、正文上方）插入 **AI 智能摘要**，支持 **16 家主流厂商**、**三级联动模型选择**、**10 种文字动画特效**、**完整缓存系统** 与 **暗黑极简卡片风格**。

---

## ✨ 核心特性

### 🤖 多厂商 AI 接口（三级联动）

预设服务商（按字母排序的中英文）：

| 服务商 | 内置模型示例 |
| --- | --- |
| OpenAI | gpt-5.4-nano / gpt-5.4-mini / gpt-5.5 / chat-latest |
| Gemini (Google) | gemini-3-flash-preview / gemini-3-pro-preview / gemini-2.5-flash |
| DeepSeek | deepseek-v4-flash / deepseek-v4-pro / deepseek-chat / deepseek-reasoner |
| 火山方舟 (字节) | doubao-seed-2-0-lite-260215 / doubao-seed-2-0-pro-260215 |
| Kimi (月之暗面) | kimi-k2.6 / kimi-k2.5 / kimi-k2-thinking / moonshot-v1 |
| OpenRouter | gpt-5.5 / claude-opus-4.8 / gemini-3-flash-preview ... |
| Claude (Anthropic) | claude-opus-4-8 / claude-sonnet-4-6 / claude-haiku-4-5 |
| 通义千问 (阿里) | qwen3.7-max / qwen3.6-plus / qwen3.5-flash |
| 讯飞星火 | spark-lite / spark-pro / spark-max / 4.0Ultra |
| 智谱 GLM | glm-5.1 / glm-5 / glm-5-flash / glm-4.5 |
| 360 智脑 | 360gpt2-pro / 360gpt-pro / deepseek-v3-360gpt-pro |
| 百度文心一言 | ernie-4.5-turbo-128k / ernie-x1-turbo-32k / ernie-speed-128k |
| 豆包 (字节) | doubao-seed-2-0-lite-260215 / doubao-seed-2-0-pro-260215 |
| Mistral | mistral-medium-3.5 / mistral-small-latest / mistral-large-latest |
| Grok (xAI) | grok-4.3 / grok-4.20 / grok-3 |
| 自定义接口 | 任意 OpenAI 兼容端点 |

选择「自定义接口」时自动出现 **接口地址** + **模型名** 双输入框；API Key 会按 **服务商 + 模型** 独立绑定，切换模型时自动切换对应凭证。

### 🧪 一键测试连通性

后台直接点击「一键测试连通性」按钮即可，前端实时反馈成功 / 失败 / 错误信息。

### 🎬 10 种文字入场动画

1. 普通直接显示
2. 打印机逐字效果（光标闪烁）
3. 打字完成后光标自动消失
4. 全局淡入渐变
5. 由下向上滑入
6. 由上向下滑入
7. 缩放淡入
8. 轻微弹跳入场
9. 逐行渐入
10. 霓虹微光呼吸渐变

可配置：动画时长 / 打字速度 / 光标颜色 / 动画延迟 / 自定义 CSS。

### 💾 缓存系统（闭环）

- 每篇文章独立缓存 (`post_id` 唯一缓存键)
- 首访调用 AI，后续全部读缓存
- 文章保存 / 删除自动清缓存
- 后台手动：单篇缓存 / 全部缓存
- 过期时间：永久 / 1 天 / 7 天 / 30 天
- AI 调用失败不缓存，下次重试
- 全部基于 WP Transient 标准

### 🎨 暗黑极简卡片样式

- 深色 `#1a1a1a` + 细边框 `#333` + 圆角 `8px`
- 柔和浅色文字 `#ccc`，标题高亮白色
- 完全自适应移动端
- 加载时显示 `AI 摘要生成中…` 优雅占位

### ⚙️ 后台四 Tab 设置页

- **基础设置**：全局开关 / 摘要标题 / 字数限制 / 显示位置 / 应用文章类型 / 排除分类 / 排除文章 ID / 移动端开关
- **AI 接口设置**：服务商 / 模型 / 自定义端点 / 自定义模型名 / 按模型独立绑定 API Key / 温度 / 最大 Token / 自定义 Prompt / 一键测试
- **动画特效**：10 种动画选择 + 时长 / 速度 / 光标色 / 自定义 CSS
- **缓存管理**：当前缓存数 / 清空全部 / 按 ID 清空 / 过期时间

菜单图标：`dashicons-admin-customizer`，位于「外观」与「插件」之间。

### 📝 编辑器侧边栏

- 手动生成 AI 摘要
- 重新生成（强制）
- 清除当前文章缓存
- 文章列表显示「是否已有 AI 摘要」标记

### 🔒 安全 & 规范

- 全部输入过滤（`sanitize_*`、`absint`、`esc_url_raw` …）
- 全部输出转义（`esc_html`、`esc_attr`、`esc_url`、`wp_kses_post` …）
- Nonce 校验（`check_ajax_referer`）
- 权限校验（`current_user_can`）
- 禁止直接访问 PHP 文件
- 所有 API 请求使用 `wp_remote_post`
- 异常静默捕获，不崩页面
- 卸载脚本 `uninstall.php` 自动清空所有数据库残留 / 缓存 / 设置（支持 multisite）

---

## 📦 安装

仓库本身就是插件根目录，直接：

```bash
git clone https://github.com/nljie1103/WP-AI-Article-Summary.git wp-ai-article-summary
```

或下载 ZIP，把整个仓库内容放进 `/wp-content/plugins/wp-ai-article-summary/`（目录名可自定义），然后：

1. 后台「插件」中启用
2. 左侧菜单「AI 文章摘要」中配置 API、动画、缓存

兼容：WordPress **5.8+** · PHP **7.4+** · 现代浏览器全兼容。

---

## 🗂️ 目录结构（仓库根 = 插件根）

```
.
├── wp-ai-article-summary.php   # 主入口
├── uninstall.php               # 卸载清理
├── readme.txt                  # WP.org 风格说明
├── index.php                   # 防直接访问占位
├── includes/
│   ├── class-wpaias-plugin.php     # 主类 & 单例
│   ├── class-wpaias-providers.php  # 厂商 + 模型预设
│   ├── class-wpaias-api.php        # AI 调用（OpenAI/Gemini/Claude 三格式）
│   ├── class-wpaias-cache.php      # 缓存管理（Transient + 索引）
│   ├── class-wpaias-admin.php      # 后台菜单、Tab、Ajax、Meta Box
│   └── class-wpaias-frontend.php   # 前端注入、卡片渲染、首访 Ajax
├── assets/
│   ├── css/admin.css       # 后台样式
│   ├── css/frontend.css    # 前端暗黑卡片 + 10 套动画
│   ├── js/admin.js         # 三级联动 / 测试 / Meta Box
│   └── js/frontend.js      # 打字机 / 逐行渐入 / Ajax
└── languages/              # 翻译占位
```

---

## 📄 License

GPLv2 or later — © 2024-2026 [九流](https://www.jiuliu.org)。详见 [LICENSE](LICENSE)。
