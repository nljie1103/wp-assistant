<script setup lang="ts">
import { computed } from 'vue'
import { LayoutDashboard, Sparkles, ShieldCheck, Bot, Clapperboard, Images, Download, Settings, Search, ChevronsUpDown, PanelLeftClose, X, ExternalLink, CircleHelp } from 'lucide-vue-next'
const props = defineProps<{ entries: any[]; active: string; state: any; version: string; homeUrl: string }>()
const icons: Record<string, any> = { dashboard: LayoutDashboard, 'page-effects': Sparkles, 'anti-debug': ShieldCheck, 'ai-summary': Bot, preloader: Clapperboard, 'media-urls': Images, 'download-page': Download, system: Settings }
const groups = computed(() => [
  { label: '概览', entries: props.entries.filter(e => e.key === 'dashboard') },
  { label: '功能管理', entries: props.entries.filter(e => !['dashboard', 'system'].includes(e.key)) },
  { label: '系统', entries: props.entries.filter(e => e.key === 'system') }
])
function openCommand() {
  // On mobile the sidebar is above the dialog; close it before opening
  // the accessible search overlay, otherwise the dialog becomes unclickable.
  props.state.sidebarOpen = false
  props.state.commandOpen = true
}
function follow(event: MouseEvent, item: any) {
  if (props.state.dirtyCount > 0 && !window.confirm('存在未保存的修改。确定离开当前页面吗？')) {
    event.preventDefault()
    return
  }
  props.state.sidebarOpen = false
}
</script>
<template>
  <div class="jlwa-ui-sidebar-overlay" :class="{ 'is-visible': state.sidebarOpen }" @click="state.sidebarOpen = false" aria-hidden="true"></div>
  <aside class="jlwa-ui-sidebar" :class="{ 'is-open': state.sidebarOpen }" aria-label="九流 WP 助手导航">
    <div class="jlwa-ui-brand">
      <a class="jlwa-ui-brand-link" :href="homeUrl" @click="follow($event, {})">
        <span class="jlwa-ui-brand-symbol"><Sparkles :size="21" :stroke-width="2.1" /></span>
        <span class="jlwa-ui-brand-name"><strong>九流 WP 助手</strong><small>WORKSPACE</small></span>
      </a>
      <button type="button" class="jlwa-ui-icon-button jlwa-ui-close-sidebar" aria-label="关闭导航" @click="state.sidebarOpen = false"><X :size="18" /></button>
    </div>
    <div class="jlwa-ui-sidebar-top">
      <button class="jlwa-ui-site-switch" type="button" @click="openCommand" aria-label="查找插件功能">
        <span class="jlwa-ui-site-avatar">J</span>
        <span><strong>工作台</strong><small>WordPress 插件管理</small></span>
        <ChevronsUpDown class="jlwa-ui-site-chevron" :size="15" />
      </button>
      <button type="button" class="jlwa-ui-search-trigger" @click="openCommand">
        <Search :size="15" /><span>搜索功能或设置...</span><kbd>⌘ K</kbd>
      </button>
    </div>
    <div class="jlwa-ui-sidebar-scroll">
      <section v-for="group in groups" :key="group.label" class="jlwa-ui-nav-group">
        <h2 class="jlwa-ui-nav-caption">{{ group.label }}</h2>
        <nav class="jlwa-ui-nav-items" :aria-label="group.label">
          <a v-for="item in group.entries" :key="item.key" :href="item.href" class="jlwa-ui-nav-link" :class="{ 'is-active': active === item.key }" :aria-current="active === item.key ? 'page' : undefined" @click="follow($event, item)">
            <component :is="icons[item.key] || CircleHelp" :size="17" :stroke-width="1.85"/>
            <span class="jlwa-ui-nav-label">{{ item.label }}</span>
            <span v-if="item.key !== 'dashboard' && item.key !== 'system'" class="jlwa-ui-nav-status" :class="item.ready === false ? 'is-warning' : item.enabled ? 'is-on' : 'is-off'" :title="item.status || ''"></span>
          </a>
        </nav>
      </section>
    </div>
    <div class="jlwa-ui-sidebar-footer">
      <div class="jlwa-ui-sidebar-version">
        <span class="jlwa-ui-version-icon"><ShieldCheck :size="17" /></span>
        <span><strong>九流 WP 助手</strong><small>v{{ version }} · 统一管理后台</small></span>
      </div>
      <a href="https://github.com/nljie1103/wp-assistant" target="_blank" rel="noopener noreferrer" class="jlwa-ui-docs-link">
        <span><CircleHelp :size="15" /> 使用文档</span><ExternalLink :size="13"/>
      </a>
    </div>
  </aside>
</template>