(function () {
  'use strict';
  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, { once: true });
    else fn();
  }
  ready(function () {
    document.querySelectorAll('[data-xjpe-card]').forEach(function (card) {
      var cb = card.querySelector('.xjpe-toggle');
      var st = card.querySelector('.xjpe-status');
      if (!cb) return;
      function sync() {
        card.classList.toggle('is-enabled', cb.checked);
        if (st) st.textContent = cb.checked ? '● 已启用' : '○ 未启用';
      }
      cb.addEventListener('change', sync);
      sync();
    });
    var enableAll = document.querySelector('[data-xjpe-enable-all]');
    var disableAll = document.querySelector('[data-xjpe-disable-all]');
    function setAll(v) {
      document.querySelectorAll('.xjpe-toggle').forEach(function (cb) {
        cb.checked = v;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
    if (enableAll) enableAll.addEventListener('click', function () { setAll(true); });
    if (disableAll) disableAll.addEventListener('click', function () { setAll(false); });

    var form = document.getElementById('xjpe-settings-form');
    if (form) {
      form.addEventListener('submit', function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) {
          b.disabled = true;
          b.textContent = '正在保存...';
        });
      });
    }

    var checkBtn = document.getElementById('xjpe-check-update');
    var updateBtn = document.getElementById('xjpe-do-update');
    var result = document.getElementById('xjpe-update-result');
    var log = document.getElementById('xjpe-update-log');
    function ajax(action, cb) {
      if (!window.XJPE_ADMIN || !XJPE_ADMIN.ajax_url) return;
      var data = new FormData();
      data.append('action', action);
      data.append('nonce', XJPE_ADMIN.nonce);
      data.append('force', '1');
      fetch(XJPE_ADMIN.ajax_url, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function (r) { return r.json(); })
        .then(cb)
        .catch(function (e) { if (result) result.textContent = '请求失败：' + e.message; });
    }
    if (checkBtn) {
      checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        if (result) result.textContent = '正在检查远程版本...';
        ajax('xjpe_check_update', function (json) {
          checkBtn.disabled = false;
          if (!json || !json.success) {
            if (result) result.textContent = '检查失败：' + ((json && json.data && json.data.message) || '未知错误');
            return;
          }
          if (result) result.textContent = json.data.message + ' 当前版本 v' + json.data.current_version + '，远程版本 v' + json.data.latest_version + '。';
          if (log) {
            log.hidden = !json.data.changelog;
            log.textContent = json.data.changelog || '';
          }
          if (updateBtn) updateBtn.disabled = !json.data.has_update;
        });
      });
    }
    if (updateBtn) {
      updateBtn.addEventListener('click', function () {
        if (!confirm('确定要从 GitHub 更新插件吗？更新前会保留当前配置。')) return;
        updateBtn.disabled = true;
        if (result) result.textContent = '正在下载并更新，请不要关闭页面...';
        ajax('xjpe_do_update', function (json) {
          if (!json || !json.success) {
            updateBtn.disabled = false;
            if (result) result.textContent = '更新失败：' + ((json && json.data && json.data.message) || '未知错误');
            return;
          }
          if (result) result.textContent = json.data.message + ' 页面即将刷新。';
          setTimeout(function () { location.reload(); }, 1500);
        });
      });
    }
  });
})();
