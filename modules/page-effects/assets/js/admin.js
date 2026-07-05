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
  });
})();
