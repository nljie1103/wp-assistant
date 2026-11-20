export const PAGE_ICONS = {
  dashboard: 'layout-dashboard',
  'page-effects': 'sparkles',
  'anti-debug': 'shield-check',
  'login-security': 'shield',
  'ai-summary': 'bot',
  preloader: 'clapperboard',
  'media-urls': 'images',
  'download-page': 'download',
  system: 'settings',
}

export function safeNavigationEntries(entries) {
  if (!Array.isArray(entries)) return []
  return entries
    .filter(item => item && typeof item.key === 'string' && typeof item.label === 'string' && typeof item.href === 'string')
    .filter(item => item.href.startsWith('/') || /^https?:\/\/[^/]+\//i.test(item.href))
    .map(item => ({ ...item, key: item.key.slice(0, 48), label: item.label.slice(0, 64) }))
}

export function searchItems(entries, query) {
  const text = String(query || '').trim().toLocaleLowerCase()
  if (!text) return entries
  return entries.filter(item => {
    const haystack = [item.label, item.description, item.group, item.shortcut].filter(Boolean).join(' ').toLocaleLowerCase()
    return haystack.includes(text)
  })
}

// No data writes and no dynamic link construction: navigation hrefs only come
// from PHP's WordPress admin_url() and are otherwise left untouched.
export function collectPageHeadings(scope) {
  if (!scope || typeof scope.querySelectorAll !== 'function') return []
  return Array.from(scope.querySelectorAll('.jlwa-feature-host h2, .jlwa-feature-host h3, .jlwa-system-grid h2'))
    .filter(el => el.textContent && !el.closest('[hidden], [aria-hidden="true"]'))
    .slice(0, 65)
    .map((element, index) => ({
      key: 'section-' + index,
      label: element.textContent.trim().replace(/\s+/g, ' ').slice(0, 72),
      description: '跳转到当前页面设置区域',
      group: '页面内设置',
      target: element,
    }))
    .filter(item => item.label.length > 1)
}
