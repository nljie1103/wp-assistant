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
      if (input) input.addEventListener('change', function () { syncCard(card); });
    });

    var tabs = root.querySelectorAll('[data-tab]');
    var panels = root.querySelectorAll('[data-panel]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (item) { item.classList.toggle('is-active', item === tab); });
        panels.forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-panel') === tab.getAttribute('data-tab')); });
      });
    });

    var mode = root.querySelector('[data-jlwa-ad-mode]');
    if (mode) {
      mode.addEventListener('change', function () {
        applyMode(mode.value, root);
        if (mode.value === 'strict') {
          window.setTimeout(function () {
            window.alert('严格模式会启用窗口差值与 debugger 耗时探测。请先使用“只记录”动作测试日志，再切换遮罩或跳转。');
          }, 10);
        }
      });
    }
  });
})();
