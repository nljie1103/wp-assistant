<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import {
  LayoutDashboard, Sparkles, ShieldCheck, Bot, Clapperboard,
  Images, Download, Settings, Shield, Search, ExternalLink, CircleHelp
} from 'lucide-vue-next'

const props = defineProps<{
  entries: any[]
  active: string
  state: any
  version: string
  homeUrl: string
}>()

// Visible names are deliberately short enough to fit on one horizontal row.
// Accessible labels and tooltips continue to use the complete feature names.
const shortNames: Record<string, string> = {
  dashboard: '控制台',
  'page-effects': '页面特效',
  'anti-debug': '反调试',
  'login-security': '登录安全',
  'ai-summary': 'AI 摘要',
  preloader: '预加载',
  'media-urls': '媒体链接',
  'download-page': '下载管理',
  system: '系统与更新'
}
const icons: Record<string, any> = {
  dashboard: LayoutDashboard,
  'page-effects': Sparkles,
  'anti-debug': ShieldCheck,
  'login-security': Shield,
  'ai-summary': Bot,
  preloader: Clapperboard,
  'media-urls': Images,
  'download-page': Download,
  system: Settings
}
const nav = ref<HTMLElement | null>(null)
const links = computed(() => props.entries.map(entry => ({
  ...entry,
  shortLabel: shortNames[entry.key] || entry.label
})))

function openCommand() { props.state.commandOpen = true }

function follow(event: MouseEvent) {
  // Reuse the existing dirty-form protection. Navigation must not discard
  // unsaved WordPress changes without the operator's permission.
  if (props.state.dirtyCount > 0 && !window.confirm('存在未保存的修改。确定离开当前页面吗？')) {
    event.preventDefault()
  }
}

onMounted(async () => {
  await nextTick()
  const container = nav.value
  const current = container?.querySelector<HTMLElement>('[aria-current="page"]')
  if (!container || !current) return
  // Reveal active tab on a narrow viewport without horizontally scrolling
  // the entire WordPress page or jumping the page vertically.
  const left = current.getBoundingClientRect().left - container.getBoundingClientRect().left + container.scrollLeft
  container.scrollLeft = Math.max(0, left - (container.clientWidth - current.offsetWidth) / 2)
})
</script>

<template>
  <header class="jlwa-ui-top-navigation" aria-label="九流 WP 助手顶部导航">
    <div class="jlwa-ui-top-navigation-header">
      <a class="jlwa-ui-top-brand" :href="homeUrl" @click="follow">
        <span class="jlwa-ui-brand-symbol"><Sparkles :size="20" :stroke-width="2.1"/></span>
        <span class="jlwa-ui-top-brand-copy">
          <strong>九流 WP 助手</strong>
          <small>WORDPRESS WORKSPACE</small>
        </span>
      </a>
      <div class="jlwa-ui-top-navigation-actions">
        <span class="jlwa-ui-top-version" :title="'九流 WP 助手 v' + version">v{{ version }}</span>
        <a class="jlwa-ui-top-docs" href="https://github.com/nljie1103/wp-assistant"
          target="_blank" rel="noopener noreferrer" aria-label="使用文档（新窗口打开）">
          <CircleHelp :size="15"/><span>使用文档</span><ExternalLink :size="13"/>
        </a>
        <button type="button" class="jlwa-ui-top-search" aria-label="搜索功能与设置"
          aria-keyshortcuts="Control+K Meta+K" @click="openCommand">
          <Search :size="16"/><span>搜索功能或设置</span><kbd>Ctrl K</kbd>
        </button>
      </div>
    </div>
    <nav ref="nav" class="jlwa-ui-horizontal-nav" aria-label="功能导航" tabindex="0" title="可以横向滚动查看全部功能">
      <a v-for="item in links" :key="item.key" :href="item.href"
        class="jlwa-ui-horizontal-link" :class="{ 'is-active': active === item.key }"
        :aria-label="item.label" :title="item.label"
        :aria-current="active === item.key ? 'page' : undefined"
        @click="follow">
        <component :is="icons[item.key] || CircleHelp" :size="16" :stroke-width="1.9" aria-hidden="true"/>
        <span>{{ item.shortLabel }}</span>
        <span v-if="item.key !== 'dashboard' && item.key !== 'system'"
          class="jlwa-ui-horizontal-status"
          :class="item.ready === false ? 'is-warning' : item.enabled ? 'is-on' : 'is-off'"
          :title="item.status || ''" aria-hidden="true"></span>
      </a>
    </nav>
  </header>
</template>
