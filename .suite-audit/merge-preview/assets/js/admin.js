(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  ready(function () {
    document.querySelectorAll('[data-xjpe-card]').forEach(function (card) {
      var cb = card.querySelector('.xjpe-toggle');
      var st = card.querySelector('.xjpe-status');
      if (!cb) return;

      function sync() {
        card.classList.toggle('is-enabled', cb.checked);
        if (st) st.textContent = cb.checked ? '已启用' : '未启用';
      }

      cb.addEventListener('change', sync);
      sync();
    });

    var enableAll = document.querySelector('[data-xjpe-enable-all]');
    var disableAll = document.querySelector('[data-xjpe-disable-all]');

<<<<<<< modules/page-effects/assets/js/admin.js
    function setAll(v) {
=======
    function setAll(value) {
>>>>>>> /tmp/merge-theirs/assets/js/admin.js
      document.querySelectorAll('.xjpe-toggle').forEach(function (cb) {
        cb.checked = value;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }

    if (enableAll) enableAll.addEventListener('click', function () { setAll(true); });
    if (disableAll) disableAll.addEventListener('click', function () { setAll(false); });

    var contextToggle = document.querySelector('input[name="xjpe_options[effects][contextmenu][enabled]"]');
    var noSourceToggle = document.querySelector('input[name="xjpe_options[effects][nosource][enabled]"]');
    var conflictNotice = document.getElementById('xjpe-context-conflict');

    function syncConflictNotice() {
      if (!conflictNotice || !contextToggle || !noSourceToggle) return;
      conflictNotice.hidden = !(contextToggle.checked && noSourceToggle.checked);
    }

    if (contextToggle) contextToggle.addEventListener('change', syncConflictNotice);
    if (noSourceToggle) noSourceToggle.addEventListener('change', syncConflictNotice);
    syncConflictNotice();

    var form = document.getElementById('xjpe-settings-form');
    if (form) {
      form.addEventListener('submit', function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
          button.disabled = true;
          button.textContent = '正在保存...';
        });
      });
    }
<<<<<<< modules/page-effects/assets/js/admin.js
=======

    var checkBtn = document.getElementById('xjpe-check-update');
    var updateBtn = document.getElementById('xjpe-do-update');
    var result = document.getElementById('xjpe-update-result');
    var log = document.getElementById('xjpe-update-log');

    function setResult(message) {
      if (result) result.textContent = message;
    }

    function ajax(action) {
      if (!window.XJPE_ADMIN || !XJPE_ADMIN.ajax_url) {
        return Promise.reject(new Error('后台更新配置未加载，请刷新页面后重试。'));
      }

      var data = new FormData();
      data.append('action', action);
      data.append('nonce', XJPE_ADMIN.nonce);
      data.append('force', '1');

      return fetch(XJPE_ADMIN.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        body: data,
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        return response.text().then(function (text) {
          var json;
          try {
            json = JSON.parse(text);
          } catch (error) {
            throw new Error('服务器返回的不是有效 JSON（HTTP ' + response.status + '）。');
          }
          if (!response.ok) {
            throw new Error((json && json.data && json.data.message) || ('HTTP ' + response.status));
          }
          return json;
        });
      });
    }

    if (checkBtn) {
      checkBtn.addEventListener('click', function () {
        checkBtn.disabled = true;
        setResult('正在检查远程版本...');

        ajax('xjpe_check_update').then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || '未知错误');
          }

          setResult(json.data.message + ' 当前版本 v' + json.data.current_version + '，远程版本 v' + json.data.latest_version + '。');
          if (log) {
            log.hidden = !json.data.changelog;
            log.textContent = json.data.changelog || '';
          }
          if (updateBtn) updateBtn.disabled = !json.data.has_update;
        }).catch(function (error) {
          setResult('检查失败：' + error.message);
          if (updateBtn) updateBtn.disabled = true;
        }).then(function () {
          checkBtn.disabled = false;
        });
      });
    }

    if (updateBtn) {
      updateBtn.addEventListener('click', function () {
        if (!window.confirm('确定要从 GitHub 更新插件吗？更新器会先完整备份插件文件和当前配置，失败时自动回滚。')) return;

        updateBtn.disabled = true;
        setResult('正在下载、校验并更新，请不要关闭页面...');
        var succeeded = false;

        ajax('xjpe_do_update').then(function (json) {
          if (!json || !json.success) {
            throw new Error((json && json.data && json.data.message) || '未知错误');
          }
          succeeded = true;
          setResult(json.data.message + ' 页面即将刷新。');
          window.setTimeout(function () { window.location.reload(); }, 1500);
        }).catch(function (error) {
          setResult('更新失败：' + error.message);
        }).then(function () {
          if (!succeeded) updateBtn.disabled = false;
        });
      });
    }
>>>>>>> /tmp/merge-theirs/assets/js/admin.js
  });
})();
