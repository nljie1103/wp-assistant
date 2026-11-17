/**
 * DOM behavior adapter for legacy WordPress feature pages.
 *
 * Not a second settings engine. Six feature modules own their existing PHP
 * forms, WordPress nonces, AJAX, native tabs, modals and security prompts.
 * This adapter only normalizes accessible NOTICE semantics/dismiss controls
 * and respects dynamically appended notices without claiming a save succeeded.
 */
export function noticeKind(element) {
  if (!element || !element.classList) return ''
  const classes = element.classList
  if (classes.contains('notice-error') || classes.contains('error')) return 'error'
  if (classes.contains('notice-warning')) return 'warning'
  if (classes.contains('notice-success') || classes.contains('updated')) return 'success'
  if (classes.contains('notice-info') || classes.contains('notice')) return 'info'
  return ''
}

export function shouldAddDismiss(element, kind) {
  if (kind !== 'success' && kind !== 'info') return false
  if (element.classList.contains('is-dismissible')) return false
  if (element.hasAttribute('data-jlwa-persistent')) return false
  if (element.querySelector('.notice-dismiss, .jlwa-ui-dismiss, [aria-label="关闭提示"]')) return false
  return true
}

export function enhanceNotice(element) {
  const kind = noticeKind(element)
  if (!kind || element.hasAttribute('data-jlwa-notice-ready')) return false
  element.setAttribute('data-jlwa-notice-ready', 'true')
  // Don't replace attributes explicitly supplied by existing WordPress
  // / download operation logic. In particular retain role=alert.
  if (!element.hasAttribute('role')) {
    element.setAttribute('role', kind === 'error' || kind === 'warning' ? 'alert' : 'status')
  }
  if (!shouldAddDismiss(element, kind)) return true
  const owner = element.ownerDocument
  const button = owner.createElement('button')
  button.type = 'button'
  button.className = 'jlwa-ui-dismiss'
  button.setAttribute('aria-label', '关闭通知')
  button.setAttribute('title', '关闭通知')
  // Avoid injecting HTML content from third-party/legacy notice messages.
  const cross = owner.createElement('span')
  cross.setAttribute('aria-hidden', 'true')
  cross.textContent = '×'
  button.append(cross)
  button.addEventListener('click', () => element.remove())
  element.append(button)
  return true
}

export function enhanceNotices(root) {
  if (!root?.querySelectorAll) return 0
  let count = 0
  const nodes = root.querySelectorAll(
    '.jlwa-feature-host .notice, .jlwa-feature-host .jlwa-download-save-notice'
  )
  nodes.forEach(element => { if (enhanceNotice(element)) count++ })
  return count
}

export function watchNotices(root) {
  if (!root || typeof MutationObserver === 'undefined') return () => {}
  const observer = new MutationObserver(mutations => {
    for (const mutation of mutations) {
      if (mutation.type !== 'childList') continue
      for (const node of mutation.addedNodes) {
        if (node.nodeType !== 1) continue
        if (node.matches?.('.notice, .jlwa-download-save-notice') &&
            node.closest?.('.jlwa-feature-host')) enhanceNotice(node)
        if (node.querySelectorAll) {
          node.querySelectorAll('.jlwa-feature-host .notice, .jlwa-feature-host .jlwa-download-save-notice')
            .forEach(enhanceNotice)
          // Appends inside an existing .jlwa-feature-host subtree need a
          // separate match check, but never touch notices elsewhere in WP.
          if (node.closest?.('.jlwa-feature-host')) {
            node.querySelectorAll('.notice, .jlwa-download-save-notice').forEach(enhanceNotice)
          }
        }
      }
    }
  })
  observer.observe(root, { childList: true, subtree: true })
  return () => observer.disconnect()
}
