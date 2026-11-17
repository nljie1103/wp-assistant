(function () {
  'use strict';

  function syncCard(card) {
    var input = card.querySelector('input[type="checkbox"]');
    card.classList.toggle('is-checked', !!(input && input.checked));
  }

  function applyMode(mode, root) {
    var presets = {
      balanced: {
        shortcuts: true,
        console_getter: true,
        debug_libraries: true,
        viewport: false,
        debugger_timing: false,
        console_performance: false,
        focus_signal: false
      },
      strict: {
        shortcuts: true,
        console_getter: true,
        debug_libraries: true,
        viewport: true,
        debugger_timing: true,
        console_performance: true,
        focus_signal: true
      }
    };
    if (!presets[mode]) return;
    Object.keys(presets[mode]).forEach(function (key) {
      var card = root.querySelector('[data-detector="' + key + '"]');
      var input = card && card.querySelector('input[type="checkbox"]');
      if (input) input.checked = presets[mode][key];
      if (card) syncCard(card);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('.jlwa-ad-admin');
    if (!root) return;

    root.querySelectorAll('.jlwa-ad-option').forEach(function (card) {
      syncCard(card);
      var input = card.querySelector('input[type="checkbox"]');
      if (!input) return;
      input.addEventListener('change', function () {
        if (card.classList.contains('is-dangerous') && input.checked) {
          var accepted = window.confirm('这是高风险防御动作，可能造成循环刷新、持续断点或页面无法正常关闭。请确认管理员绕过和紧急旁路地址已经可用。');
          if (!accepted) input.checked = false;
        }
        syncCard(card);
      });
    });

    var tabs = root.querySelectorAll('[data-tab]');
    var panels = root.querySelectorAll('[data-panel]');
    function activateTab(tab) {
      if (!tab) return;
      tabs.forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle('is-active', selected);
        item.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      panels.forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-panel') === tab.getAttribute('data-tab')); });
      try { window.sessionStorage.setItem('jlwa-anti-debug-active-tab', tab.getAttribute('data-tab')); } catch (_) {}
    }
    tabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () { activateTab(tab); });
      tab.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        var next = tabs[(index + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
        activateTab(next);
        next.focus();
      });
    });
    try {
      var remembered = window.sessionStorage.getItem('jlwa-anti-debug-active-tab');
      tabs.forEach(function (tab) { if (tab.getAttribute('data-tab') === remembered) activateTab(tab); });
    } catch (_) {}

    var mode = root.querySelector('[data-jlwa-ad-mode]');
    if (mode) {
      mode.addEventListener('change', function () {
        applyMode(mode.value, root);
        if (mode.value === 'strict') {
          window.setTimeout(function () {
            window.alert('严格模式会启用窗口差值与 debugger 耗时探测。防御层仍由“分级防御链”单独决定，不会自动开启循环刷新或无限 debugger。');
          }, 10);
        }
      });
    }
  });
})();
