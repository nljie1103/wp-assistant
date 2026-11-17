<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription } from 'reka-ui'
import { Menu, Search, Moon, Sun, Command, ArrowRight, RefreshCw, ShieldCheck, Check, CircleAlert, X, Keyboard, LayoutDashboard, Settings, Sparkles, Bot, Images, Download } from 'lucide-vue-next'
import { collectPageHeadings, searchItems } from '../registry.mjs'

const props = defineProps<{ entries: any[]; state: any; active: string; title: string; subtitle: string; version: string }>()
const query = ref('')
const input = ref<HTMLInputElement>()
const position = ref(0)
const sections = ref<any[]>([])
const found = computed(() => searchItems([
  ...props.entries.map(e => ({ ...e, group: '导航', description: e.description || '' })),
  ...sections.value
], query.value))
watch(() => props.state.commandOpen, async open => {
  if (open) {
    query.value = ''
    position.value = 0
    sections.value = collectPageHeadings(document.querySelector('.jlwa-v3'))
    await nextTick()
    input.value?.focus()
  }
})
watch(query, () => { position.value = 0 })
function select(item: any) {
  if (!item) return
  if (item.target) {
    props.state.commandOpen = false
    nextTick(() => {
      item.target.scrollIntoView({ behavior: 'smooth', block: 'start' })
      item.target.setAttribute('tabindex', '-1')
      item.target.focus({ preventScroll: true })
    })
    return
  }
  if (props.state.dirtyCount > 0 && !window.confirm('存在未保存的修改。确定离开当前页面吗？')) return
  props.state.commandOpen = false
  window.location.assign(item.href)
}
function keys(event: KeyboardEvent) {
  if (event.key === 'ArrowDown') { event.preventDefault(); position.value = Math.min(position.value + 1, found.value.length - 1) }
  else if (event.key === 'ArrowUp') { event.preventDefault(); position.value = Math.max(position.value - 1, 0) }
  else if (event.key === 'Enter' && found.value.length) { event.preventDefault(); select(found.value[position.value]) }
}
function refreshPage() {
  if (props.state.dirtyCount > 0 && !window.confirm('页面上存在未保存的修改。确定刷新吗？')) return
  window.location.reload()
}
function toggleTheme() {
  props.state.theme = props.state.theme === 'dark' ? 'light' : 'dark'
  document.documentElement.dataset.jlwaTheme = props.state.theme
  document.querySelector('.jlwa-app')?.setAttribute('data-jlwa-theme', props.state.theme)
  try { localStorage.setItem('jlwa-admin-theme', props.state.theme) } catch (_) {}
}
</script>

<template>
  <header class="jlwa-ui-topbar">
    <div class="jlwa-ui-topbar-left">
      <button type="button" class="jlwa-ui-icon-button jlwa-ui-menu-button" @click="state.sidebarOpen = true" aria-label="打开功能导航"><Menu :size="19"/></button>
      <div class="jlwa-ui-breadcrumbs" aria-label="页面位置">
        <span>工作台</span><span class="jlwa-ui-breadcrumb-sep">/</span><strong>{{ title }}</strong>
      </div>
    </div>
    <div class="jlwa-ui-topbar-right">
      <span v-if="state.dirtyCount" class="jlwa-ui-unsaved" role="status"><CircleAlert :size="15"/> {{ state.dirtyCount }} 项表单未保存</span>
      <span v-else class="jlwa-ui-ready-status"><span></span> 已就绪</span>
      <button class="jlwa-ui-icon-button" type="button" @click="state.commandOpen = true" title="搜索功能与设置 (Ctrl+K)" aria-label="打开搜索"><Search :size="18"/></button>
      <button class="jlwa-ui-icon-button" type="button" @click="toggleTheme" :title="state.theme === 'dark' ? '切换浅色模式' : '切换深色模式'" :aria-label="state.theme === 'dark' ? '切换浅色模式' : '切换深色模式'"><Sun v-if="state.theme === 'dark'" :size="18"/><Moon v-else :size="18"/></button>
      <span class="jlwa-ui-user-avatar" title="WordPress 管理后台">JL</span>
    </div>
  </header>
  <section class="jlwa-ui-page-head">
    <div class="jlwa-ui-page-head-main">
      <span class="jlwa-ui-eyebrow"><span></span> JIULIU WORKSPACE <span class="jlwa-ui-eyebrow-separator">/</span> VERSION {{ version }}</span>
      <h1>{{ title }}</h1>
      <p>{{ subtitle }}</p>
    </div>
    <div class="jlwa-ui-page-head-actions">
      <button type="button" class="jlwa-ui-outline-button" @click="state.commandOpen = true"><Command :size="15"/> 快速定位 <kbd>Ctrl K</kbd></button>
      <button type="button" class="jlwa-ui-icon-button" title="刷新当前页面" aria-label="刷新当前页面" @click="refreshPage"><RefreshCw :size="16"/></button>
    </div>
  </section>
  <DialogRoot v-model:open="state.commandOpen">
    <DialogPortal>
      <DialogOverlay class="jlwa-ui-dialog-overlay" />
      <DialogContent class="jlwa-ui-dialog" aria-describedby="jlwa-ui-search-description">
        <DialogTitle class="jlwa-ui-dialog-title"><Search :size="19" /> 查找功能与设置</DialogTitle>
        <DialogDescription id="jlwa-ui-search-description" class="jlwa-ui-dialog-description">搜索六大功能、系统页面，以及当前页面的设置区域</DialogDescription>
        <div class="jlwa-ui-dialog-input-wrap"><Search :size="18" /><input ref="input" v-model="query" type="search" placeholder="输入功能、设置或关键词..." autocomplete="off" @keydown="keys" aria-label="搜索功能与设置"/><button type="button" @click="state.commandOpen = false" aria-label="关闭搜索"><X :size="16" /></button></div>
        <div class="jlwa-ui-dialog-results" role="listbox" aria-label="搜索结果">
          <div v-if="!found.length" class="jlwa-ui-command-empty">没有找到匹配项。换个关键词试试。</div>
          <button v-for="(item, index) in found" v-else :key="item.key" type="button" :class="['jlwa-ui-command-item', { 'is-selected': position === index }]" role="option" :aria-selected="position === index" @mouseenter="position = index" @click="select(item)">
            <span class="jlwa-ui-command-icon"><Settings v-if="item.key === 'system' || item.group === '页面内设置'" :size="17" /><LayoutDashboard v-else-if="item.key === 'dashboard'" :size="17"/><Sparkles v-else-if="item.key === 'page-effects'" :size="17"/><Bot v-else-if="item.key === 'ai-summary'" :size="17"/><Images v-else-if="item.key === 'media-urls'" :size="17"/><Download v-else-if="item.key === 'download-page'" :size="17"/><ShieldCheck v-else :size="17"/></span>
            <span class="jlwa-ui-command-copy"><strong>{{ item.label }}</strong><small>{{ item.description || item.group }}</small></span>
            <span class="jlwa-ui-command-category">{{ item.group }}</span><ArrowRight :size="15" class="jlwa-ui-command-arrow"/>
          </button>
        </div>
        <div class="jlwa-ui-dialog-footer"><span><Keyboard :size="14" /> ↑↓ 选择 · Enter 打开</span><span>ESC 关闭</span></div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>