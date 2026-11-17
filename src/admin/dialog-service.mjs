/**
 * Central confirmation queue for all six WordPress admin modules.
 *
 * No synchronous window.confirm override. Old event handlers explicitly
 * request async confirmation, and dangerous forms submit only AFTER approval.
 * Native browser confirmation remains a conservative fallback if Vue fails.
 */
export function createDialogService(state) {
  const queue = []

  function showNext() {
    if (!state.active && queue.length) state.active = queue.shift()
  }

  function open(type, message, options = {}) {
    return new Promise(resolve => {
      queue.push({
        type,
        message: String(message || ''),
        title: String(options.title || ''),
        danger: !!options.danger,
        confirmLabel: String(options.confirmLabel || ''),
        cancelLabel: String(options.cancelLabel || ''),
        resolve,
      })
      showNext()
    })
  }

  function finish(approved) {
    const current = state.active
    if (!current) return
    state.active = null
    current.resolve(!!approved)
    showNext()
  }

  function confirmSubmit(event, form, message, options = {}) {
    if (!form) return false
    if (form.dataset.jlwaDialogConfirmed === '1') {
      delete form.dataset.jlwaDialogConfirmed
      return true
    }
    const submitter = event && event.submitter
    open('confirm', message, { danger: true, ...options }).then(approved => {
      if (!approved) return
      form.dataset.jlwaDialogConfirmed = '1'
      if (typeof form.requestSubmit === 'function') {
        // Re-enter the original DOM submit handler so existing WordPress
        // handlers, nonces and HTML5 validation are *not* bypassed.
        form.requestSubmit(submitter || undefined)
      } else {
        // Only for obsolete webviews; confirmation has already succeeded.
        HTMLFormElement.prototype.submit.call(form)
      }
    })
    return false // Inline onsubmit handler cancels first attempt.
  }

  return {
    state,
    confirm: (message, options = {}) => open('confirm', message, options),
    alert: (message, options = {}) => open('alert', message, options).then(() => {}),
    confirmSubmit,
    finish,
  }
}
