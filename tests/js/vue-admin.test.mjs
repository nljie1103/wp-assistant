import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { safeNavigationEntries, searchItems, collectPageHeadings, PAGE_ICONS } from '../../src/admin/registry.mjs'

test('Vue navigation preserves safe WordPress admin URLs and six module keys', () => {
  const entries = [
    { key: 'dashboard', label: '控制台', href: 'https://example.org/wp-admin/admin.php?page=jiuliu-wp-assistant' },
    { key: 'ai-summary', label: 'AI 文章摘要', href: '/wp-admin/admin.php?page=jlwa-ai-summary' },
    { key: 'invalid', label: '危险链接', href: 'javascript:alert(1)' },
    { key: 1, label: 'wrong', href: '/wp-admin/' },
  ]
  const valid = safeNavigationEntries(entries)
  assert.deepEqual(valid.map(item => item.key), ['dashboard', 'ai-summary'])
  assert.equal(valid[0].href, entries[0].href)
  assert.equal(valid[1].href, entries[1].href)
  assert.equal(Object.keys(PAGE_ICONS).length, 8)
})

test('functional search filters Chinese content and description without deleting controls', () => {
  const modules = [
    { key: 'a', label: '页面特效', description: '视觉特效和鼠标交互', group: '功能' },
    { key: 'b', label: '私有下载', description: 'WordPress 订单与 OpenList Nginx 代理', group: '功能' },
    { key: 'c', label: 'AI 摘要', description: '自动总结', group: '功能' },
  ]
  assert.deepEqual(searchItems(modules, '下载').map(x => x.key), ['b'])
  assert.deepEqual(searchItems(modules, 'nginx').map(x => x.key), ['b'])
  assert.deepEqual(searchItems(modules, '特效').map(x => x.key), ['a'])
  assert.deepEqual(searchItems(modules, '  ').map(x => x.key), ['a', 'b', 'c'])
  assert.deepEqual(searchItems(modules, '不存在'), [])
})

test('in-page search produces links to existing heading DOM objects without moving forms', () => {
  const headings = [
    { textContent: 'API 设置', closest() { return null } },
    { textContent: '  Nginx   自动部署  ', closest() { return null } },
    { textContent: '隐藏', closest() { return {} } },
  ]
  const page = { querySelectorAll(selector) {
    assert.match(selector, /jlwa-feature-host/)
    return headings
  }}
  const results = collectPageHeadings(page)
  assert.equal(results.length, 2)
  assert.equal(results[1].label, 'Nginx 自动部署')
  assert.equal(results[1].target, headings[1])
  assert.equal(results[1].group, '页面内设置')
  assert.deepEqual(collectPageHeadings(null), [])
})

test('top navigation is horizontal, complete, scrollable and has an accessible active tab', () => {
  const css = readFileSync(new URL('../../src/admin/style.css', import.meta.url), 'utf8')
  const vue = readFileSync(new URL('../../src/admin/components/TopNavigation.vue', import.meta.url), 'utf8')
  const app = readFileSync(new URL('../../src/admin/main.ts', import.meta.url), 'utf8')
  for (const key of ['dashboard', 'page-effects', 'anti-debug', 'ai-summary', 'preloader', 'media-urls', 'download-page', 'system']) {
    assert.ok(vue.includes(key), 'Navigation includes ' + key)
  }
  assert.match(vue, /jlwa-ui-top-navigation/)
  assert.match(vue, /jlwa-ui-horizontal-nav/)
  assert.match(vue, /aria-current/)
  assert.match(vue, /aria-label="功能导航"/)
  assert.match(css, /overflow-x:auto/)
  assert.match(css, /grid-template-rows: auto minmax\(0, 1fr\)/)
  assert.ok(!vue.includes('sidebarOpen'), 'No mobile drawer or hidden links')
  assert.ok(app.includes('createApp(TopNavigation'), 'Vue mounts horizontal navigation in existing header root')
})
