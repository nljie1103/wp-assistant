import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createDialogService } from '../../src/admin/dialog-service.mjs'
import { noticeKind, shouldAddDismiss, enhanceNotice } from '../../src/admin/unified-notices.mjs'

test('one global confirm queue never authorizes on dismissal or cancellation', async () => {
  const state = { active: null }
  const dialog = createDialogService(state)
  const first = dialog.confirm('确认删除全部缓存?', { danger: true })
  const second = dialog.confirm('确认执行下一项操作?')
  assert.equal(state.active?.danger, true)
  assert.equal(state.active?.message, '确认删除全部缓存?')
  dialog.finish(false)
  assert.equal(await first, false)
  assert.equal(state.active?.message, '确认执行下一项操作?')
  dialog.finish(true)
  assert.equal(await second, true)
  assert.equal(state.active, null)
  dialog.finish(true) // no-op if no request is pending
})

test('dangerous form confirmation is fail-closed and retains native submit/nonce handlers', async () => {
  const state = { active: null }
  const dialog = createDialogService(state)
  const submitter = { name: 'submit', value: '执行' }
  let submits = 0
  let reentered = null
  const form = {
    dataset: {},
    requestSubmit(which) {
      submits += 1
      assert.equal(which, submitter)
      reentered = dialog.confirmSubmit({ submitter }, form, '危险操作')
    }
  }
  assert.equal(dialog.confirmSubmit({ submitter }, form, '危险操作'), false)
  assert.equal(submits, 0)
  dialog.finish(false)
  await Promise.resolve()
  assert.equal(submits, 0)
  assert.equal(form.dataset.jlwaDialogConfirmed, undefined)
  assert.equal(dialog.confirmSubmit({ submitter }, form, '危险操作'), false)
  dialog.finish(true)
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(submits, 1)
  assert.equal(reentered, true)
  assert.equal(form.dataset.jlwaDialogConfirmed, undefined)
})

test('alerts never authorize a pending confirm', async () => {
  const state = { active: null }
  const dialog = createDialogService(state)
  const alert = dialog.alert('发生了错误')
  const confirm = dialog.confirm('允许更新?')
  assert.equal(state.active.type, 'alert')
  dialog.finish(false)
  await alert
  assert.equal(state.active.type, 'confirm')
  dialog.finish(false)
  assert.equal(await confirm, false)
})

function fakeNotice(classes) {
  const attrs = new Map()
  const children = []
  let removed = false
  const createNode = () => ({
    attrs: new Map(), children: [],
    setAttribute(key, value) { this.attrs.set(key, value) },
    append(child) { this.children.push(child) },
    addEventListener(key, callback) { this.onClick = callback },
  })
  const element = {
    classList: { contains(key) { return classes.includes(key) } },
    hasAttribute(key) { return attrs.has(key) },
    getAttribute(key) { return attrs.get(key) },
    setAttribute(key, value) { attrs.set(key, value) },
    querySelector() { return null },
    ownerDocument: { createElement: createNode },
    append(node) { children.push(node) },
    remove() { removed = true },
    get removed() { return removed },
    get children() { return children },
  }
  return element
}

test('notices share semantic roles; only transient info/success get close controls', () => {
  const success = fakeNotice(['notice', 'notice-success'])
  assert.equal(noticeKind(success), 'success')
  assert.equal(enhanceNotice(success), true)
  assert.equal(success.getAttribute('role'), 'status')
  assert.equal(success.children.length, 1)
  assert.equal(success.children[0].attrs.get('aria-label'), '关闭通知')
  assert.equal(enhanceNotice(success), false)
  success.children[0].onClick()
  assert.equal(success.removed, true)

  const error = fakeNotice(['notice', 'notice-error'])
  assert.equal(noticeKind(error), 'error')
  enhanceNotice(error)
  assert.equal(error.getAttribute('role'), 'alert')
  assert.equal(error.children.length, 0)
  const warning = fakeNotice(['notice', 'notice-warning'])
  assert.equal(shouldAddDismiss(warning, 'warning'), false)
  assert.equal(noticeKind(warning), 'warning')
  const nativeDismiss = fakeNotice(['notice', 'notice-success', 'is-dismissible'])
  enhanceNotice(nativeDismiss)
  assert.equal(nativeDismiss.children.length, 0)
  const marked = fakeNotice(['notice', 'notice-info'])
  marked.setAttribute('role', 'log')
  enhanceNotice(marked)
  assert.equal(marked.getAttribute('role'), 'log')
})

test('one visual interaction policy spans all six module types', () => {
  const css = readFileSync(new URL('../../src/admin/unified-components.css', import.meta.url), 'utf8')
  const tokens = [
    '.xjpe-card', '.jlwa-ad-option', '.wpaias-style-card', '.jip-effect-card',
    '.jrmu-panel', '.jlwa-template-card', '.jlwa-download-section',
    '.jlwa-ad-tabs', '.jlwa-ui-confirm-dialog', '.notice-warning', '.notice-success',
    '.notice-error', '.widefat', '.wpaias-switch',
  ]
  for (const selector of tokens) assert.ok(css.includes(selector), selector)
  assert.ok(css.includes('transform: none !important;'))
  assert.ok(!css.includes('translateY(-'), 'No lifted cards in the shared component layer')
  assert.ok(css.includes('prefers-reduced-motion'))
  const moduleSources = [
    'features/anti-debug/assets/js/admin.js',
    'features/ai-article-summary/assets/js/admin.js',
    'features/relative-media-urls/includes/class-jrmu-admin.php',
    'assets/js/admin.js',
  ]
  for (const source of moduleSources) {
    const code = readFileSync(new URL('../../' + source, import.meta.url), 'utf8')
    assert.ok(code.includes('JLWA_DIALOG'), source)
  }
})
