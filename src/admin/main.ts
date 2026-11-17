import { createApp, reactive } from 'vue'
import Sidebar from './components/Sidebar.vue'
import Toolbar from './components/Toolbar.vue'
import Dashboard from './components/Dashboard.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { createDialogService } from './dialog-service.mjs'
import { safeNavigationEntries } from './registry.mjs'
import { enhanceNotices, watchNotices } from './unified-notices.mjs'
import './style.css'
import './unified-components.css'

// Vite bundles Vue, Reka and Lucide into assets/vue/admin.js. Existing PHP
// forms are intentionally *not* mounted inside Vue; they keep their original
// nonces, nested controls, request endpoints and front-end feature behavior.
interface Bridge {
  active: string
  title: string
  subtitle: string
  version: string
  homeUrl: string
  updateUrl: string
  entries: any[]
  modules: any[]
}
declare global { interface Window { JLWA_V3?: Bridge; JLWA_DIALOG?: any } }

function boot() {
  const config = window.JLWA_V3
  const sidebarNode = document.getElementById('jlwa-vue-sidebar')
  const toolbarNode = document.getElementById('jlwa-vue-toolbar')
  if (!config || !sidebarNode || !toolbarNode) return

  let savedTheme = 'light'
  try {
    const stored = localStorage.getItem('jlwa-admin-theme')
    if (stored === 'dark' || stored === 'light') savedTheme = stored
  } catch (_) {}
  const appRoot = document.querySelector('.jlwa-v3')
  if (!appRoot) return
  appRoot.setAttribute('data-jlwa-theme', savedTheme)
  document.documentElement.dataset.jlwaTheme = savedTheme
  const state = reactive({ theme: savedTheme, commandOpen: false, sidebarOpen: false, dirtyCount: 0 })
  // Keep notice behavior coherent even when a legacy AJAX callback inserts it.
  enhanceNotices(appRoot)
  watchNotices(appRoot)
  const entries = safeNavigationEntries(config.entries)
  const modules = safeNavigationEntries(config.modules)
  const common = { state, entries, active: config.active, version: config.version }

  try {
    // Two isolated Vue roots prevent the framework from destroying legacy PHP
    // admin forms. Their state is shared through a single reactive object.
    createApp(Sidebar, { ...common, homeUrl: config.homeUrl }).mount(sidebarNode)
    createApp(Toolbar, { ...common, title: config.title, subtitle: config.subtitle }).mount(toolbarNode)
    const confirmationNode = document.getElementById('jlwa-vue-dialog-host')
    if (confirmationNode) {
      const service = reactive({ active: null as any })
      const dialog = createDialogService(service)
      createApp(ConfirmDialog, { service, finish: dialog.finish }).mount(confirmationNode)
      window.JLWA_DIALOG = dialog
    }
    const dashboard = document.getElementById('jlwa-vue-dashboard')
    if (dashboard) {
      createApp(Dashboard, { ...common, modules, updateUrl: config.updateUrl }).mount(dashboard)
      appRoot.classList.add('jlwa-dashboard-ready')
    }
    appRoot.classList.add('jlwa-vue-ready')
  } catch (error) {
    // Keep WordPress's original nav and forms available if Vue fails.
    console.error('[JLWA] Vue admin shell did not mount; original admin forms remain available.', error)
    return
  }

  // Only mark changed forms for the toolbar. Native form submits and the
  // feature modules' original JS AJAX handlers stay fully in charge.
  const changed = new Set<HTMLFormElement>()
  const sync = () => { state.dirtyCount = changed.size }
  document.querySelectorAll<HTMLFormElement>('.jlwa-feature-host form').forEach(form => {
    const dirty = () => { if (!changed.has(form)) { changed.add(form); sync() } }
    const clear = () => { changed.delete(form); sync() }
    form.addEventListener('input', dirty)
    form.addEventListener('change', dirty)
    form.addEventListener('submit', event => {
      // Inline high-risk confirmation cancels the first submit event.
      // Leave the dirty indicator intact until the confirmed resubmit.
      if (!event.defaultPrevented) clear()
    })
    form.addEventListener('reset', clear)
  })

  document.addEventListener('keydown', (event) => {
    const key = event.key?.toLowerCase()
    if ((event.metaKey || event.ctrlKey) && key === 'k') {
      event.preventDefault()
      state.sidebarOpen = false
      state.commandOpen = !state.commandOpen
    }
    if (event.key === 'Escape') state.sidebarOpen = false
  })
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true })
} else {
  boot()
}
