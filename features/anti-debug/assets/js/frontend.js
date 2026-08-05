(function () {
  'use strict';

  var cfg = window.JLWA_ANTI_DEBUG || null;
  if (!cfg || !cfg.detectors || !cfg.decision || !cfg.response) return;

  var detectors = cfg.detectors;
  var decision = cfg.decision;
  var response = cfg.response;
  var score = 0;
  var reasons = Object.create(null);
  var lastSignal = Object.create(null);
  var thresholdHits = [];
  var active = false;
  var destroyed = false;
  var activatedAt = 0;
  var lastSuspiciousAt = 0;
  var overlay = null;
  var replaceSnapshots = [];
  var baseline = null;
  var viewportHits = 0;
  var focusLostAt = 0;
  var logSent = false;
  var timer = 0;
  var consoleProbeToken = 0;
  var lastConsoleProbeAt = 0;
  var lastPerformanceProbeAt = 0;
  var safeConsole = window.console || { log: function () {}, clear: function () {}, table: function () {} };

  function now() {
    return window.performance && performance.now ? performance.now() : Date.now();
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function signal(name, points) {
    if (destroyed) return;
    var time = Date.now();
    var cooldown = parseInt(decision.detector_cooldown_ms || 1800, 10);
    if (lastSignal[name] && time - lastSignal[name] < cooldown) return;
    lastSignal[name] = time;
    score = clamp(score + points, 0, 500);
    reasons[name] = time;
    lastSuspiciousAt = time;
    evaluate(time);
  }

  function recentReasons(time) {
    var windowMs = parseInt(decision.hit_window_ms || 4200, 10);
    return Object.keys(reasons).filter(function (name) {
      return time - reasons[name] <= windowMs;
    });
  }

  function evaluate(time) {
    var threshold = parseInt(decision.threshold || 85, 10);
    var windowMs = parseInt(decision.hit_window_ms || 4200, 10);
    thresholdHits = thresholdHits.filter(function (stamp) { return time - stamp <= windowMs; });
    if (score >= threshold) {
      if (!thresholdHits.length || time - thresholdHits[thresholdHits.length - 1] > 250) thresholdHits.push(time);
      if (thresholdHits.length >= parseInt(decision.confirm_hits || 2, 10)) activate(recentReasons(time));
    }
  }

  function contentNodes() {
    var selector = String(response.content_selector || 'article, main, .entry-content, .post-content');
    try {
      return Array.prototype.slice.call(document.querySelectorAll(selector));
    } catch (error) {
      return document.body ? [document.body] : [];
    }
  }

  function createOverlay(triggerReasons) {
    if (overlay || !document.body) return;
    overlay = document.createElement('div');
    overlay.className = 'jlwa-ad-overlay';
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = '<div class="jlwa-ad-overlay__card"><span class="jlwa-ad-overlay__shield">🛡️</span><h2></h2><p></p><div class="jlwa-ad-overlay__status"><span></span><strong>等待调试环境关闭</strong></div><small></small></div>';
    overlay.querySelector('h2').textContent = response.message || '检测到调试环境';
    overlay.querySelector('p').textContent = response.detail || '请关闭开发者工具后继续访问。';
    overlay.querySelector('small').textContent = triggerReasons.length ? '检测信号：' + triggerReasons.join('、') : '';
    document.body.appendChild(overlay);
    window.requestAnimationFrame(function () { if (overlay) overlay.classList.add('is-visible'); });
  }

  function applyBlur() {
    document.documentElement.style.setProperty('--jlwa-ad-blur', clamp(parseInt(response.blur_px || 16, 10), 0, 40) + 'px');
    contentNodes().forEach(function (node) { node.classList.add('jlwa-ad-protected-content'); });
  }

  function replaceContent() {
    if (replaceSnapshots.length) return;
    contentNodes().forEach(function (node) {
      replaceSnapshots.push({ node: node, html: node.innerHTML });
      node.innerHTML = '<div class="jlwa-ad-replaced"><strong>🛡️ 内容保护已触发</strong><p>' + escapeHtml(response.message || '请关闭开发者工具后刷新页面。') + '</p></div>';
    });
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  function activate(triggerReasons) {
    if (active || destroyed) return;
    active = true;
    activatedAt = Date.now();
    document.documentElement.classList.add('jlwa-ad-active');
    logEvent(triggerReasons);

    var action = String(response.action || 'overlay');
    if (action === 'observe') return;
    if (action === 'overlay') {
      applyBlur();
      createOverlay(triggerReasons);
    } else if (action === 'replace') {
      replaceContent();
      createOverlay(triggerReasons);
    } else if (action === 'redirect') {
      redirect();
    } else if (action === 'close') {
      closeOrRedirect();
    }
  }

  function redirect() {
    var url = String(response.redirect_url || '');
    if (url) window.location.replace(url);
    else {
      applyBlur();
      createOverlay(recentReasons(Date.now()));
    }
  }

  function closeOrRedirect() {
    if (response.close_attempt) {
      try {
        window.open('', '_self');
        window.close();
      } catch (error) {}
    }
    window.setTimeout(function () {
      if (!document.hidden) redirect();
    }, 180);
  }

  function recoverIfSafe() {
    if (!active || !response.auto_recover) return;
    var delay = parseInt(response.recover_delay_ms || 1800, 10);
    if (Date.now() - lastSuspiciousAt < delay || score >= parseInt(decision.threshold || 85, 10) * 0.45) return;
    active = false;
    thresholdHits = [];
    logSent = false;
    document.documentElement.classList.remove('jlwa-ad-active');
    contentNodes().forEach(function (node) { node.classList.remove('jlwa-ad-protected-content'); });
    replaceSnapshots.forEach(function (snapshot) {
      if (snapshot.node && snapshot.node.isConnected) snapshot.node.innerHTML = snapshot.html;
    });
    replaceSnapshots = [];
    if (overlay) {
      overlay.classList.remove('is-visible');
      var old = overlay;
      overlay = null;
      window.setTimeout(function () { if (old.parentNode) old.remove(); }, 240);
    }
  }

  function logEvent(triggerReasons) {
    if (logSent || !cfg.logging || !cfg.logging.enabled || !cfg.logging.url) return;
    logSent = true;
    var form = new URLSearchParams();
    form.append('action', 'jlwa_anti_debug_event');
    form.append('nonce', cfg.logging.nonce || '');
    form.append('score', String(Math.round(score)));
    form.append('url', window.location.href);
    triggerReasons.slice(0, 8).forEach(function (reason) { form.append('reasons[]', reason); });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(cfg.logging.url, new Blob([form.toString()], { type: 'application/x-www-form-urlencoded;charset=UTF-8' }));
      } else {
        fetch(cfg.logging.url, { method: 'POST', body: form, credentials: 'same-origin', keepalive: true }).catch(function () {});
      }
    } catch (error) {}
  }

  function updateBaseline(force) {
    var current = {
      outerW: window.outerWidth || 0,
      outerH: window.outerHeight || 0,
      innerW: window.innerWidth || document.documentElement.clientWidth || 0,
      innerH: window.innerHeight || document.documentElement.clientHeight || 0
    };
    current.gapW = Math.max(0, current.outerW - current.innerW);
    current.gapH = Math.max(0, current.outerH - current.innerH);
    if (!baseline || force) baseline = current;
    return current;
  }

  function detectViewport() {
    if (!detectors.viewport) return;
    var current = updateBaseline(false);
    if (!baseline || !current.outerW || !current.innerW) return;
    var addedW = current.gapW - baseline.gapW;
    var addedH = current.gapH - baseline.gapH;
    var absThreshold = parseInt(detectors.viewport_threshold || 220, 10);
    var ratio = parseInt(detectors.viewport_ratio || 18, 10) / 100;
    var suspiciousW = addedW > absThreshold && addedW > current.innerW * ratio && Math.abs(current.outerW - baseline.outerW) < 70;
    var suspiciousH = addedH > absThreshold && addedH > current.innerH * ratio && Math.abs(current.outerH - baseline.outerH) < 70;

    if (suspiciousW || suspiciousH) {
      viewportHits += 1;
      if (viewportHits >= 2) signal('viewport', 62);
    } else {
      viewportHits = Math.max(0, viewportHits - 1);
      var outerChange = Math.max(Math.abs(current.outerW - baseline.outerW), Math.abs(current.outerH - baseline.outerH));
      var gapChange = Math.max(Math.abs(current.gapW - baseline.gapW), Math.abs(current.gapH - baseline.gapH));
      if (!active && outerChange > 90 && gapChange < 80) baseline = current;
    }
  }

  function detectDebuggerTiming() {
    if (!detectors.debugger_timing || document.hidden) return;
    var start = now();
    try {
      Function('debugger')(); // Deliberately low-frequency and optional.
    } catch (error) {}
    var elapsed = now() - start;
    if (elapsed > parseInt(detectors.debugger_threshold || 180, 10)) signal('debugger_timing', 58);
  }

  function detectConsoleGetter() {
    if (!detectors.console_getter || document.hidden || Date.now() - lastConsoleProbeAt < 3800) return;
    lastConsoleProbeAt = Date.now();
    var token = ++consoleProbeToken;
    var touched = false;
    var probe = /jlwa/;
    try {
      probe.toString = function () {
        if (!touched && token === consoleProbeToken) {
          touched = true;
          window.setTimeout(function () { signal('console_getter', 52); }, 0);
        }
        return '/jlwa/';
      };
      safeConsole.log(probe);
    } catch (error) {}
  }

  function detectConsolePerformance() {
    if (!detectors.console_performance || document.hidden || !safeConsole.table || Date.now() - lastPerformanceProbeAt < 6500) return;
    lastPerformanceProbeAt = Date.now();
    var rows = [];
    for (var i = 0; i < 90; i++) rows.push({ i: i, k: 'jlwa-' + i, v: i * 3 });
    var start = now();
    try { safeConsole.table(rows); } catch (error) { return; }
    var elapsed = now() - start;
    if (elapsed > parseInt(detectors.performance_threshold || 110, 10)) signal('console_performance', 46);
  }

  function detectDebugLibraries() {
    if (!detectors.debug_libraries) return;
    var names = ['eruda', 'VConsole', 'vConsole', 'Firebug', '__VCONSOLE_INSTANCE__'];
    for (var i = 0; i < names.length; i++) {
      if (window[names[i]]) {
        signal('debug_library', 85);
        return;
      }
    }
  }

  function detectFocusSignal() {
    if (!detectors.focus_signal || !focusLostAt) return;
    if (!document.hidden && Date.now() - focusLostAt < 1600) signal('focus_change', 12);
    focusLostAt = 0;
  }

  function tick() {
    if (destroyed) return;
    if (!document.hidden) {
      detectViewport();
      detectDebugLibraries();
      detectConsoleGetter();
      detectDebuggerTiming();
      detectConsolePerformance();
      detectFocusSignal();
      score = Math.max(0, score - parseInt(decision.score_decay || 12, 10));
      Object.keys(reasons).forEach(function (name) {
        if (Date.now() - reasons[name] > parseInt(decision.hit_window_ms || 4200, 10) * 2) delete reasons[name];
      });
      evaluate(Date.now());
      recoverIfSafe();
      if (active && response.action === 'overlay' && Date.now() - activatedAt > parseInt(response.escalate_after_ms || 5500, 10)) {
        if (String(response.redirect_url || '')) redirect();
      }
    }
    timer = window.setTimeout(tick, clamp(parseInt(detectors.interval_ms || 1100, 10), 450, 5000));
  }

  function shortcutHandler(event) {
    if (!detectors.shortcuts) return;
    var target = event.target;
    if (target && target.closest && target.closest('input, textarea, select, [contenteditable="true"]')) return;
    var code = event.keyCode || event.which || 0;
    var key = String(event.key || '').toLowerCase();
    if (!key && code) key = String.fromCharCode(code).toLowerCase();
    var primary = !!(event.ctrlKey || event.metaKey);
    var devShortcut = key === 'f12' || code === 123 ||
      (primary && (key === 'u' || code === 85)) ||
      (primary && event.shiftKey && ['i', 'j', 'c', 'k'].indexOf(key) >= 0) ||
      (event.metaKey && event.altKey && ['i', 'j', 'c', 'u'].indexOf(key) >= 0);
    if (!devShortcut) return;
    if (event.cancelable) event.preventDefault();
    event.stopPropagation();
    if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    signal('shortcut', 100);
    return false;
  }

  function init() {
    if (!document.documentElement) return;
    updateBaseline(true);
    window.addEventListener('keydown', shortcutHandler, true);
    document.addEventListener('keydown', shortcutHandler, true);
    window.addEventListener('blur', function () { focusLostAt = Date.now(); }, true);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !active) window.setTimeout(function () { updateBaseline(true); }, 350);
    });
    window.addEventListener('resize', function () {
      if (detectors.viewport) window.setTimeout(detectViewport, 80);
    }, { passive: true });
    window.setTimeout(function () {
      updateBaseline(true);
      tick();
    }, 900);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
