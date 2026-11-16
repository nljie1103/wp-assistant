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
    var cards = [];
    var activeFilter = 'all';
    var currentOpen = null;
    var counter = document.querySelector('.xjpe-effect-count');
    function closeCard(card) {
      if (!card) return;
      card.classList.remove('is-expanded');
      var body = card.querySelector('.xjpe-card-body');
      var button = card.querySelector('.xjpe-config-trigger');
      if (body) body.hidden = true;
      if (button) button.setAttribute('aria-expanded', 'false');
      if (currentOpen === card) currentOpen = null;
    }
    function applyFilter() {
      var visible = 0;
      var enabled = 0;
      cards.forEach(function (card) {
        var cb = card.querySelector('.xjpe-toggle');
        if (cb && cb.checked) enabled++;
        var show = activeFilter === 'all' || (activeFilter === 'enabled' && cb && cb.checked) || card.dataset.group === activeFilter;
        card.hidden = !show;
        if (!show && currentOpen === card) closeCard(card);
        if (show) visible++;
      });
      if (counter) counter.textContent = '显示 ' + visible + ' 项 · 已启用 ' + enabled + ' 项';
    }
    function attachCard(card) {
      if (card.__xjpeBound) return;
      card.__xjpeBound = true;
      cards.push(card);
      var cb = card.querySelector('.xjpe-toggle');
      var st = card.querySelector('.xjpe-status');
      var trigger = card.querySelector('.xjpe-config-trigger');
      if (!cb) return;
      function sync() {
        card.classList.toggle('is-enabled', cb.checked);
        if (st) st.textContent = cb.checked ? '已启用' : '未启用';
        applyFilter();
      }
      cb.addEventListener('change', sync);
      if (trigger) trigger.addEventListener('click', function () {
        var expanding = currentOpen !== card;
        closeCard(currentOpen);
        if (expanding) {
          var body = card.querySelector('.xjpe-card-body');
          if (body) body.hidden = false;
          trigger.setAttribute('aria-expanded', 'true');
          card.classList.add('is-expanded');
          currentOpen = card;
        }
      });
      sync();
    }
    document.querySelectorAll('[data-xjpe-card]').forEach(attachCard);
    document.addEventListener('xjpe:cards-added', function () {
      document.querySelectorAll('[data-xjpe-card]').forEach(attachCard);
      applyFilter();
    });
    document.querySelectorAll('[data-xjpe-filter]').forEach(function (button) {
      button.addEventListener('click', function () {
        activeFilter = button.getAttribute('data-xjpe-filter');
        document.querySelectorAll('[data-xjpe-filter]').forEach(function (item) {
          var selected = item === button;
          item.classList.toggle('is-active', selected);
          item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        applyFilter();
      });
    });

    var enableAll = document.querySelector('[data-xjpe-enable-all]');
    var disableAll = document.querySelector('[data-xjpe-disable-all]');

    function setAll(value) {
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
  });
})();
