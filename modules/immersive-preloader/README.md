<p align="center">
  <img src="https://img.shields.io/badge/WordPress-5.8+-21759B?style=flat-square&logo=wordpress&logoColor=white" alt="WordPress 5.8+">
  <img src="https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/Version-1.0.6-blue?style=flat-square" alt="Version 1.0.6">
  <img src="https://img.shields.io/badge/License-GPLv2%2B-green?style=flat-square" alt="GPLv2 or later License">
  <img src="https://img.shields.io/badge/Theme-Compatible-success?style=flat-square" alt="Theme Compatible">
</p>

<h1 align="center">九流沉浸式预加载</h1>
<p align="center"><strong>WordPress 沉浸式预加载插件 · 零代码侵入 · 开门式进入体验</strong></p>
<p align="center">完全掩盖页面加载过程，以霓虹立体动画 + 资源就绪联动方式，实现真正的"沉浸式开门"</p>

---

## 🌟 它和"普通开屏插件"的本质区别

| 对比项 | 普通开屏插件 | 九流沉浸式预加载 |
|:---|:---|:---|
| 触发逻辑 | 固定时长（例如 3 秒）就消失 | 等待 `window.load` 触发后才淡出 |
| 加载过程 | 用户能看到加载中的页面闪烁 | **完全掩盖**，看不到一帧空白 / 闪现 |
| 关键 CSS | 入队加载，可能 FOUC | `wp_head` 优先级 1 内联输出，零闪烁 |
| 资源就绪 | 不感知，固定切走 | 真正等 HTML / CSS / JS / 首屏图片就绪 |
| 超时保护 | 无 | 用户配置最大等待时长，超时强制结束 |
| 主题兼容 | 仅依赖 `wp_body_open` | **PHP 主路径 + JS 兜底**，Zibll/Divi/Astra 全兼容 |
| 跳过机制 | 无 | 点击 / 触摸 / ESC 都可立即跳过 |

## ✨ 功能一览

<table>
<tr>
<td width="50%">

**🎨 5 种内置精美效果**
- 🥇 **立体 Logo 开场（核心）** — 蓝→紫→玫红→酒红霓虹边框 + 蒙版 Logo + 文字弹入 + 荧光扫描
- 粒子汇聚 Logo 入场 — Canvas 2D 粒子从屏幕四周汇聚成 Logo 形状
- 渐变色彩穿梭开屏 — 多层径向渐变光团流动 + 文字渐变流光
- 简约线条勾勒 Logo — SVG `stroke-dashoffset` 描边逐步绘制
- 玻璃质感边框旋转 — `backdrop-filter` 毛玻璃方块 360° 旋转

**🔌 零代码侵入**
- 一键安装即用，**绝不要求**修改主题任何文件
- 通过 `wp_head` + `wp_body_open` 标准钩子注入
- 主题不支持 `wp_body_open` 时 JS 自动兜底
- 卸载后自动清理所有数据，不留垃圾

**⚡ 真正的沉浸式逻辑**
- 关键 CSS 内联在 `<head>` 最早期输出，零 FOUC
- 监听 `window.load` 事件，资源真正就绪后才淡出
- 自定义最小展示时长（避免一闪而过）
- 自定义最大等待时长（超时保护）
- 淡出动画完成后自动从 DOM 移除，释放内存

</td>
<td width="50%">

**🎛 完整后台管理**
- WordPress 原生风格设置页（外观与插件菜单之间）
- 启用 / 禁用单选切换
- 5 种效果可视化卡片选择（120×80 预览图）
- 仅核心效果显示 Logo 设置（智能切换）
- WordPress 原生**媒体上传器**（选择 Logo）
- WordPress 原生**颜色选择器**（背景色）
- 数字 / 复选框 / 单选高级选项
- GitHub 在线检查更新与一键覆盖升级

**🛡 容错与兼容**
- Zibll / Astra / Divi / GeneratePress / Hello / 经典主题全兼容
- 不在后台、Ajax、Feed、REST、Customizer 中显示
- 自定义 Logo 跨域加载失败时自动用默认 Logo
- 粒子采样失败时回退到圆形粒子降级方案
- 不支持 `backdrop-filter` 浏览器自动降级
- `prefers-reduced-motion` 用户偏好自动禁用动画

**📱 全设备 60fps 流畅**
- 全程 GPU 加速（`will-change` + `transform`）
- 响应式 `clamp()` + `vmin` 单位自适应
- 移动端断点优化文字与间距
- z-index 999999 确保覆盖一切内容
- 总资源不到 30KB（含 CSS + JS + 图）

</td>
</tr>
</table>

## 🛠 技术栈

| 组件 | 技术 |
|:---|:---|
| 后端 | PHP 7.4+ / WordPress 5.8+（标准 Settings API） |
| 前端 | 原生 CSS3 + 原生 ES5 JS（零外部依赖） |
| 渐变 | `conic-gradient` + `mask-composite` 实现霓虹边框 |
| 立体 | 多层 `drop-shadow` filter 叠加营造 3D 浮雕 |
| 粒子 | Canvas 2D + `getImageData` 采样 Logo 像素 |
| 玻璃 | `backdrop-filter: blur() saturate()` |
| 兜底 | `wp_json_encode` + JS `DOMContentLoaded` 注入 |
| 后台 | WordPress 原生 `wp.media` + `wp-color-picker` |

## 🚀 快速开始

### 环境要求

- WordPress 5.8 及以上版本
- PHP 7.4 及以上版本
- 现代浏览器（Chrome 88+ / Firefox 87+ / Safari 14+ / Edge 88+）
- **无需** Composer / Node.js / npm
- **无需** 修改任何主题文件

### 安装方式

**方式一：上传 ZIP（推荐）**

1. 下载本仓库的 [Release ZIP 压缩包](https://github.com/nljie1103/wp-immersive-preloader/releases)
2. WordPress 后台 → **插件** → **安装插件** → **上传插件**
3. 选择 `jiuliu-immersive-preloader.zip` → **现在安装** → **启用插件**

**方式二：手动复制**

```bash
cd wp-content/plugins
git clone https://github.com/nljie1103/wp-immersive-preloader.git jiuliu-immersive-preloader
```

然后 WordPress 后台 → 插件 → 启用 **九流沉浸式预加载**。

### 开始配置

1. WordPress 后台左侧菜单（外观与插件之间）→ **沉浸式预加载**
2. 启用插件、选择心仪的效果、上传自定义 Logo、调整时长
3. 保存设置 → 强制刷新（Ctrl+F5）首页查看效果

> 💡 默认仅在首页显示。如需所有页面都显示，取消勾选「仅在首页显示」即可。

## 🎬 5 种效果详解

### 🥇 立体 Logo 开场（核心，推荐）

最高优先级、最精致的核心效果，完整复刻"霓虹立体片头"质感：

| 时间 | 动画 |
|:---|:---|
| 0 ~ 0.5 秒 | 圆角矩形 3D 立体霓虹边框淡入 + `rotateX(-14°)` → `0°` 入场 |
| 全程 | 边框 `conic-gradient` 蓝→紫→玫红→酒红 8 秒一圈匀速顺时针自转 |
| 0.5 ~ 1.5 秒 | Logo 通过竖向蒙版从上到下擦开 + 轻微上移 5px |
| 1.5 ~ 2.5 秒 | 站点名称从下方 20px 处弹性淡入 + 0.8 → 1.0 倍缩放 |
| 2 秒后 | 白色荧光扫光线从上到下匀速划过，带 4 层 box-shadow 柔光拖尾，2.4 秒一次循环 |
| 加载完成 | 1 秒淡出 + 1.1 倍放大，淡出后自动从 DOM 移除 |

**技术亮点：**
- **3 层边框叠加**：主体 conic-gradient + 外圈柔光辉光（blur 14px）+ 上表面高光（135° linear + screen 混合）
- **5 层霓虹光晕**：drop-shadow 6/18/36/60px 蓝紫玫红渐扩 + 18/50px 黑色底投影
- **5 层 Logo 立体感**：顶部 1px 白色高光 + 1px 黑色压暗 + 投影 + 蓝光晕 + 紫光辉光
- **4 层扫光拖尾**：18/36/60/100px 白蓝渐扩光晕 + blur(3.5px) + saturate(1.2)

### 🌌 粒子汇聚 Logo 入场

- Canvas 2D + `getImageData` 采样 Logo 像素，每 3px 一个粒子
- 粒子从屏幕四周随机位置弹性汇聚到目标点
- 物理速度叠加 + 阻尼系数实现自然减速
- 采样失败（跨域）时自动回退为圆形粒子分布

### 🌈 渐变色彩穿梭开屏

- 3 层径向渐变光团（蓝 / 玫红 / 紫）`mix-blend-mode: screen` 叠加
- 6 / 7 / 5 秒不同周期 `ease-in-out` 缓动游走 + 缩放
- 站点名称 `background-clip: text` 渐变流光，4 秒一圈

### ✏️ 简约线条勾勒 Logo

- SVG `stroke-dasharray` + `stroke-dashoffset` 描边动画
- 圆形 / 矩形 / 三角折线依次绘制（0 / 0.3 / 0.6 秒延迟）
- 2.4 秒一周期无限循环，发光描边带蓝色辉光

### 🧊 玻璃质感边框旋转

- `backdrop-filter: blur(20px) saturate(160%)` 真正毛玻璃
- 1px 半透明高光描边 + inset 顶部反光 + 外部投影
- 内嵌方块反向旋转，形成视觉错位
- 不支持 backdrop-filter 时自动降级为半透明灰底

## 📂 项目结构

```
jiuliu-immersive-preloader/
├── jiuliu-immersive-preloader.php   # 主插件文件（插件头、单例、钩子）
├── uninstall.php                    # 卸载脚本（清理选项）
├── readme.txt                       # WordPress 标准 readme
├── README.md                        # GitHub 项目说明（本文件）
├── LICENSE                          # GPLv2+ 协议
├── index.php                        # 防目录列出（每个目录都有）
│
├── includes/                        # PHP 类文件
│   ├── class-jip-settings.php       #   设置类（默认值、读取、清洗）
│   ├── class-jip-admin.php          #   后台菜单 + 设置页面 + 资源入队
│   ├── class-jip-frontend.php       #   前台预加载注入（双路径）
│   └── class-jip-updater.php        #   GitHub 在线更新
│
└── assets/                          # 静态资源
    ├── css/
    │   ├── preloader.css            #     前台样式（5 种效果 + 响应式 + 降级）
    │   └── admin.css                #     后台样式（卡片网格 + Logo 上传器）
    ├── js/
    │   ├── preloader.js             #     前台生命周期 + Canvas 粒子
    │   └── admin.js                 #     媒体上传 + 颜色选择 + 卡片交互
    ├── images/
    │   └── default-logo.svg         #     内置默认 Logo（带 feSpecularLighting 立体浮雕）
    └── previews/                    # 5 张 120×80 效果预览图（SVG）
        ├── preview-logo3d.svg
        ├── preview-particles.svg
        ├── preview-gradient.svg
        ├── preview-lines.svg
        └── preview-glass.svg
```

## 🎛 后台配置项

| 板块 | 配置项 | 类型 | 默认 | 说明 |
|:---|:---|:---|:---|:---|
| **基础设置** | 启用沉浸式预加载 | 单选 | 启用 | 总开关 |
| | 最小展示时长 | 数字 (秒) | 1 | 推荐 0~3 秒，可填任意正数 |
| | 最大等待时长 | 数字 (秒) | 8 | 推荐 5~15 秒，可填任意正数 |
| **效果选择** | 预加载效果 | 卡片单选 | 立体 Logo | 5 种内置效果带预览 |
| **Logo 设置** | 中心 Logo 图 | 媒体上传 | 内置默认 | 仅核心效果使用，PNG/JPG/WebP，推荐 512×512 |
| | Logo 显示尺寸 | 数字 (%) | 80 | 30~100，相对边框内部占比 |
| **高级设置** | 预加载背景色 | 颜色选择 | `#000000` | 任意颜色，默认纯黑 |
| | 显示站点名称 | 复选框 | 勾选 | 动画中显示 WordPress 站点名 |
| | 允许点击跳过 | 复选框 | 勾选 | 点击 / 触摸 / ESC 立即结束 |
| | 仅在首页显示 | 复选框 | 勾选 | 取消则所有页面显示 |

## 🔌 兼容性

### 主题兼容

✅ **完全兼容**（已通过 `wp_body_open` + JS 兜底双路径保证）：

- Zibll（子比主题）
- Astra
- Divi
- GeneratePress
- Kadence
- Hello Elementor
- WordPress 默认主题（Twenty Twenty-One/Two/Three/Four/Five）
- 任何调用了 `wp_head()` 的主题（绝大多数现代主题）

### 浏览器兼容

| 浏览器 | 最低版本 | 备注 |
|:---|:---|:---|
| Chrome / Edge | 88+ | 完全支持 |
| Firefox | 87+ | 完全支持 |
| Safari | 14+ | 完全支持 |
| iOS Safari | 14+ | `-webkit-mask-composite: xor` 已加 |
| Android Chrome | 88+ | 完全支持 |
| IE 11 | ❌ | 不支持 conic-gradient，自动降级为纯黑 |

### 第三方插件兼容

- ✅ **WP Rocket / WP Super Cache / W3 Total Cache**：清缓存后正常
- ✅ **Cloudflare CDN**：清 CDN 缓存后正常
- ✅ **Autoptimize / WP Fastest Cache**：兼容（关键 CSS 是内联输出）
- ✅ **WooCommerce / Elementor / Yoast SEO**：无冲突
- ⚠️ **极少数会强制移除 `wp_body_open` 的特殊插件**：JS 兜底路径会自动接管

## 💡 常见问题 FAQ

<details>
<summary><strong>Q1: 启用插件后首页没有效果？</strong></summary>

请按顺序排查：

1. 确认插件已启用（插件页能看到"九流沉浸式预加载"且为已启用状态）
2. 进入"沉浸式预加载" 检查"启用沉浸式预加载"是否为「启用」
3. 默认仅在首页显示，请访问首页 URL（不是 /about 等子页面）
4. 强制刷新（Ctrl+F5）跳过浏览器缓存
5. 如装有缓存插件（WP Rocket / WP Super Cache）请清空缓存
6. 如使用 Cloudflare 等 CDN，请清空 CDN 缓存
7. F12 打开浏览器开发者工具 → Console，看是否有 JS 报错
8. F12 → Elements，搜索 `jip-preloader` 看 DOM 是否被注入

</details>

<details>
<summary><strong>Q2: 我想在所有页面都显示预加载，怎么办？</strong></summary>

进入"沉浸式预加载" → 高级设置 → 取消勾选「仅在首页显示」→ 保存。

</details>

<details>
<summary><strong>Q3: 自定义 Logo 上传后没有显示？</strong></summary>

请确认：
- 当前选中的效果是「立体 Logo 开场」（只有此效果使用 Logo）
- Logo 文件格式是 PNG / JPG / WebP
- 文件大小未超过 WordPress 默认上传限制（通常 2MB）
- 浏览器强制刷新（Ctrl+F5）

</details>

<details>
<summary><strong>Q4: 如何让预加载时间更短 / 更长？</strong></summary>

进入"沉浸式预加载" → 基础设置：

- 想要更短：把"最小展示时长"调小（例如 0.3 秒）
- 想要更长：把"最小展示时长"调大（例如 3 秒）
- 避免无限等待：调整"最大等待时长"作为超时保护

时长可填任意正数，不再有硬性上限。

</details>

<details>
<summary><strong>Q5: 怎样彻底关闭预加载？</strong></summary>

两种方式任选其一：

- **临时关闭**：进入"沉浸式预加载" → 启用沉浸式预加载 → 选「禁用」→ 保存
- **永久卸载**：插件页 → 找到"九流沉浸式预加载" → 停用 → 删除（卸载时自动清理所有数据）

</details>

<details>
<summary><strong>Q6: 会影响 SEO 或 Lighthouse 评分吗？</strong></summary>

不会影响 SEO：
- 预加载层只是视觉层，HTML 实际内容完整存在
- 搜索引擎爬虫不执行 JS，看到的是原始 HTML
- `wp_body_open` 注入位置在 SEO 主内容之外

Lighthouse 评分：
- LCP（最大内容渲染）可能因隐藏 body 内容而略受影响
- 可以设置较小的"最小展示时长"（如 0.5 秒）减少影响
- 或仅在首页显示，子页面（SEO 重要页面）不受影响

</details>

## 🧪 开发与调试

### 监听预加载结束事件

```javascript
window.addEventListener('jip:ended', function () {
  console.log('预加载已结束，主页面已就绪');
  // 在这里启动你的页面入场动画等
});
```

### 检查注入的关键变量

打开浏览器控制台输入：

```javascript
window.JIP_CFG    // 当前生效的配置
window.JIP_HTML   // 预加载 HTML 字符串（用于 JS 兜底）
document.documentElement.classList.contains('jip-loading')  // 加载中？
```

### 强制结束预加载

```javascript
document.getElementById('jip-preloader').click();
```

## 📦 打包发布

如需将插件打包成可上传的 ZIP（带顶级目录、正斜杠分隔符）：

```bash
python -c "import os, zipfile; src='jiuliu-immersive-preloader'; zp='jiuliu-immersive-preloader.zip'; base=os.path.dirname(os.path.abspath(src)); zf=zipfile.ZipFile(zp,'w',zipfile.ZIP_DEFLATED); [zf.write(os.path.join(r,f), os.path.relpath(os.path.join(r,f), base).replace(os.sep,'/')) for r,d,fs in os.walk(src) for f in fs]; zf.close()"
```

> ⚠️ Windows 下不要用 PowerShell 的 `Compress-Archive`，它会使用反斜杠分隔符导致 WordPress 提示"插件文件不存在"。

## 📜 开源协议

[GPLv2 or later](LICENSE) — © 2026 [九流](https://www.jiuliu.org)

## 🙋 作者

**九流** · [https://www.jiuliu.org](https://www.jiuliu.org)

如有 Bug 反馈、功能建议或定制需求，欢迎在 [Issues](https://github.com/nljie1103/wp-immersive-preloader/issues) 提交。
