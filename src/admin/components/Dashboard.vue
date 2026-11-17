<script setup lang="ts">
import { computed, ref } from 'vue'
import { Activity, ArrowUpRight, Bot, CheckCircle2, Clapperboard, Download, Images, LayoutDashboard, Search, Settings2, ShieldCheck, Sparkles, AlertTriangle, ChevronRight, Boxes } from 'lucide-vue-next'
import { searchItems } from '../registry.mjs'
const props = defineProps<{ modules: any[]; version: string; updateUrl: string; state: any }>()
const filter = ref('')
const cards = computed(() => searchItems(props.modules, filter.value))
const enabled = computed(() => props.modules.filter(item => item.enabled && item.ready).length)
const ready = computed(() => props.modules.filter(item => item.ready).length)
const attention = computed(() => props.modules.filter(item => !item.ready))
const icons: Record<string,any> = { 'page-effects': Sparkles, 'anti-debug': ShieldCheck, 'ai-summary': Bot, preloader: Clapperboard, 'media-urls': Images, 'download-page': Download }
function follow(event: MouseEvent) {
  if (props.state.dirtyCount > 0 && !window.confirm('当前页面有未保存的更改。仍要离开吗？')) event.preventDefault()
}
</script>
<template>
  <div class="jlwa-ui-dashboard">
    <div class="jlwa-ui-overview-heading">
      <div><span class="jlwa-ui-label-muted">概览 / OVERVIEW</span><h2>管理站点的每一个细节</h2><p>在一个工作台中查看功能状态、管理配置与发现需要处理的问题。</p></div>
      <a class="jlwa-ui-primary-button" :href="updateUrl" @click="follow"><Settings2 :size="16"/> 检查更新 <ArrowUpRight :size="15"/></a>
    </div>
    <div class="jlwa-ui-stat-grid">
      <article class="jlwa-ui-stat-card">
        <div class="jlwa-ui-stat-heading"><span>已正常启用</span><span class="jlwa-ui-stat-icon is-green"><CheckCircle2 :size="19"/></span></div>
        <div class="jlwa-ui-stat-number">{{ enabled }}<span class="jlwa-ui-stat-denom">/ {{ modules.length }}</span></div>
        <p>正在为站点提供服务的功能</p>
        <span class="jlwa-ui-stat-foot is-positive"><CheckCircle2 :size="13"/> 状态来自实际配置</span>
      </article>
      <article class="jlwa-ui-stat-card">
        <div class="jlwa-ui-stat-heading"><span>模块已加载</span><span class="jlwa-ui-stat-icon is-blue"><Boxes :size="19"/></span></div>
        <div class="jlwa-ui-stat-number">{{ ready }}<span class="jlwa-ui-stat-denom">/ {{ modules.length }}</span></div>
        <p>依赖满足且可正常进入设置</p>
        <span class="jlwa-ui-stat-foot"><Activity :size="13"/> 兼容性与依赖检查</span>
      </article>
      <article class="jlwa-ui-stat-card">
        <div class="jlwa-ui-stat-heading"><span>需要关注</span><span class="jlwa-ui-stat-icon is-amber"><AlertTriangle :size="19"/></span></div>
        <div class="jlwa-ui-stat-number">{{ attention.length }}<span class="jlwa-ui-stat-denom">项</span></div>
        <p>依赖或设置尚未满足的功能</p>
        <span class="jlwa-ui-stat-foot" :class="attention.length ? 'is-alert' : 'is-positive'"><CheckCircle2 v-if="!attention.length" :size="13"/><AlertTriangle v-else :size="13"/>{{ attention.length ? '建议检查下方模块' : '未发现阻断问题' }}</span>
      </article>
      <article class="jlwa-ui-stat-card">
        <div class="jlwa-ui-stat-heading"><span>当前插件版本</span><span class="jlwa-ui-stat-icon is-purple"><Sparkles :size="19"/></span></div>
        <div class="jlwa-ui-stat-number is-version">v{{ version }}</div>
        <p>Vue 3 统一管理界面</p>
        <span class="jlwa-ui-stat-foot"><ShieldCheck :size="13"/> 原有功能与数据保留</span>
      </article>
    </div>

    <section v-if="attention.length" class="jlwa-ui-attention-panel">
      <span class="jlwa-ui-attention-marker"><AlertTriangle :size="18"/></span>
      <div><strong>{{ attention.length }} 个模块需要关注</strong><p>检查对应模块的依赖或缺失配置，当前其它功能仍保持独立运行。</p></div>
      <a :href="attention[0].href" @click="follow">立即查看 <ChevronRight :size="16"/></a>
    </section>
    <section v-else class="jlwa-ui-attention-panel is-clear">
      <span class="jlwa-ui-attention-marker"><CheckCircle2 :size="18"/></span>
      <div><strong>所有功能模块均已准备就绪</strong><p>此处显示模块依赖状态；未启用的功能不会被自动开启。</p></div>
    </section>

    <section class="jlwa-ui-feature-section" id="jlwa-feature-list">
      <div class="jlwa-ui-section-heading">
        <div><span class="jlwa-ui-label-muted">FEATURES</span><h2>功能模块</h2><p>独立配置六大功能，保存原有 WordPress 数据和前台逻辑。</p></div>
        <label class="jlwa-ui-feature-search"><Search :size="17"/><input v-model="filter" type="search" placeholder="筛选模块..." aria-label="筛选功能模块"/><span>{{ cards.length }} 项</span></label>
      </div>
      <div v-if="cards.length" class="jlwa-ui-module-grid">
        <article v-for="module in cards" :key="module.key" class="jlwa-ui-module-card">
          <div class="jlwa-ui-module-card-top">
            <span class="jlwa-ui-module-icon" :class="'is-' + module.key"><component :is="icons[module.key] || LayoutDashboard" :size="21" :stroke-width="1.7"/></span>
            <span class="jlwa-ui-module-status" :class="module.ready ? module.enabled ? 'is-success' : 'is-neutral' : 'is-warning'"><span></span>{{ module.status }}</span>
          </div>
          <span class="jlwa-ui-module-eyebrow">{{ module.eyebrow || '功能模块' }}</span>
          <h3>{{ module.label }}</h3>
          <p>{{ module.description }}</p>
          <div class="jlwa-ui-module-card-bottom"><span>{{ module.ready ? '可配置' : '请检查依赖' }}</span><a :href="module.href" @click="follow">进入管理 <ArrowUpRight :size="16"/></a></div>
        </article>
      </div>
      <div v-else class="jlwa-ui-feature-empty"><Search :size="22"/><strong>没有匹配的模块</strong><p>尝试缩短关键词或清除筛选。</p><button type="button" @click="filter = ''">清除筛选</button></div>
    </section>
    <footer class="jlwa-ui-overview-footer"><ShieldCheck :size="16"/> 设置由 WordPress 保存；前台特效、子比鉴权及 OpenList 受限 Nginx 助手继续在原有服务端运行。</footer>
  </div>
</template>