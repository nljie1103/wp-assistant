(function () {
  'use strict';

  var cfg = window.JLWA_ANTI_DEBUG || null;
  if (!cfg || !cfg.detectors || !cfg.decision || !cfg.response) return;

  var runtime = cfg.runtime || {};
  var ua = String(navigator.userAgent || '');
  var isIPadDesktop = navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1;
  var isCoarseSmallScreen = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches && Math.min(screen.width || 9999, screen.height || 9999) < 1100);
  var isMobileRuntime = /Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i.test(ua) || isIPadDesktop || isCoarseSmallScreen;
  var allowMobile = runtime.allow_mobile === true || runtime.allow_mobile === 1 || runtime.allow_mobile === '1';
  var allowMobileRisky = runtime.allow_mobile_risky === true || runtime.allow_mobile_risky === 1 || runtime.allow_mobile_risky === '1';
  var storageKey = 'jlwa_ad_lock_v2';

  // Server-side wp_is_mobile() cannot reliably identify iPadOS desktop mode.
  // Apply a second browser-side gate and clear stale false-positive locks.
  if (isMobileRuntime && !allowMobile) {
    try { sessionStorage.removeItem(storageKey); } catch (error) {}
    return;
  }

  var detectors = Object.assign({}, cfg.detectors);
  if (isMobileRuntime && !allowMobileRisky) {
    detectors.viewport = 0;
    detectors.debugger_timing = 0;
    detectors.console_getter = 0;
    detectors.console_performance = 0;
    detectors.focus_signal = 0;
    try { sessionStorage.removeItem(storageKey); } catch (error) {}
  }
  var decision = cfg.decision;
  var response = cfg.response;
  var layers = response.layers || {};
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
  var layerTimers = [];
  var loopTimers = [];
  var cleanupHandlers = [];
  var historyLocked = false;
  var clipboardLocked = false;
  var safeConsole = window.console || { log: function () {}, clear: function () {}, table: function () {} };

  function now() {
    return window.performance && performance.now ? performance.now() : Date.now();
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function int(value, fallback) {
    var parsed = parseInt(value, 10);
    return isFinite(parsed) ? parsed : fallback;
  }

  function bool(value) {
    return value === true || value === 1 || value === '1';
  }

  function layer(name) {
    return layers[name] || {};
  }

  function signal(name, points) {
    if (destroyed) return;
    var time = Date.now();
    var cooldown = int(decision.detector_cooldown_ms, 1800);
    if (lastSignal[name] && time - lastSignal[name] < cooldown) return;
    lastSignal[name] = time;
    score = clamp(score + points, 0, 500);
    reasons[name] = time;
    lastSuspiciousAt = time;
    evaluate(time);
  }

  function recentReasons(time) {
    var windowMs = int(decision.hit_window_ms, 4200);
    return Object.keys(reasons).filter(function (name) {
      return time - reasons[name] <= windowMs;
    });
  }

  function evaluate(time) {
    var threshold = int(decision.threshold, 85);
    var windowMs = int(decision.hit_window_ms, 4200);
    thresholdHits = thresholdHits.filter(function (stamp) { return time - stamp <= windowMs; });
    if (score >= threshold) {
      if (!thresholdHits.length || time - thresholdHits[thresholdHits.length - 1] > 250) thresholdHits.push(time);
      if (thresholdHits.length >= int(decision.confirm_hits, 2)) activate(recentReasons(time), false);
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
    overlay.innerHTML = '<div class="jlwa-ad-overlay__card"><span class="jlwa-ad-overlay__shield">🛡️</span><h2></h2><p></p><div class="jlwa-ad-overlay__status"><span></span><strong>防御层已启用</strong></div><small></small></div>';
    overlay.querySelector('h2').textContent = response.message || '检测到调试环境';
    overlay.querySelector('p').textContent = response.detail || '请关闭开发者工具后继续访问。';
    overlay.querySelector('small').textContent = triggerReasons.length ? '检测信号：' + triggerReasons.join('、') : '';
    document.body.appendChild(overlay);
    window.requestAnimationFrame(function () { if (overlay) overlay.classList.add('is-visible'); });
  }

  function applyBlur() {
    document.documentElement.style.setProperty('--jlwa-ad-blur', clamp(int(response.blur_px, 16), 0, 60) + 'px');
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

  function schedule(fn, delay) {
    var id = window.setTimeout(function () {
      layerTimers = layerTimers.filter(function (item) { return item !== id; });
      if (active && !destroyed) fn();
    }, Math.max(0, int(delay, 0)));
    layerTimers.push(id);
  }

  function repeat(fn, interval) {
    var id = window.setInterval(function () {
      if (!active || destroyed) return;
      fn();
    }, clamp(int(interval, 500), 80, 10000));
    loopTimers.push(id);
    return id;
  }

  function addCleanup(fn) {
    cleanupHandlers.push(fn);
  }

  function applyInteractionLock() {
    document.documentElement.classList.add('jlwa-ad-block-interaction');
    contentNodes().forEach(function (node) { node.classList.add('jlwa-ad-no-interaction'); });
  }

  function applySelectionLock() {
    document.documentElement.classList.add('jlwa-ad-block-selection');
  }

  function installClipboardGuard() {
    if (clipboardLocked) return;
    clipboardLocked = true;
    var handler = function (event) {
      if (!active) return;
      if (event.cancelable) event.preventDefault();
      event.stopPropagation();
      if (event.stopImmediatePropagation) event.stopImmediatePropagation();
      return false;
    };
    document.addEventListener('copy', handler, true);
    document.addEventListener('cut', handler, true);
    addCleanup(function () {
      document.removeEventListener('copy', handler, true);
      document.removeEventListener('cut', handler, true);
      clipboardLocked = false;
    });
  }

  function installHistoryLock() {
    if (historyLocked || !window.history || !history.pushState) return;
    historyLocked = true;
    try { history.pushState({ jlwaAd: 1 }, document.title, window.location.href); } catch (error) {}
    var handler = function () {
      if (!active) return;
      try { history.pushState({ jlwaAd: 1 }, document.title, window.location.href); } catch (error) {}
    };
    window.addEventListener('popstate', handler, true);
    addCleanup(function () {
      window.removeEventListener('popstate', handler, true);
      historyLocked = false;
    });
  }

  function clearConsoleLoop(interval) {
    try { safeConsole.clear(); } catch (error) {}
    repeat(function () {
      try { safeConsole.clear(); } catch (error) {}
    }, interval);
  }

  function setPersistentLock(minutes, source) {
    try {
      var expires = Date.now() + clamp(int(minutes, 10), 1, 1440) * 60000;
      sessionStorage.setItem(storageKey, JSON.stringify({ expires: expires, source: source || 'layer' }));
    } catch (error) {}
  }

  function clearPersistentLock() {
    try { sessionStorage.removeItem(storageKey); } catch (error) {}
  }

  function getPersistentLock() {
    try {
      var raw = sessionStorage.getItem(storageKey);
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || !parsed.expires || parsed.expires < Date.now()) {
        sessionStorage.removeItem(storageKey);
        return null;
      }
      return parsed;
    } catch (error) {
      return null;
    }
  }

  function startReloadLoop(interval, persistMinutes) {
    setPersistentLock(persistMinutes, 'reload_loop');
    repeat(function () {
      try { window.location.reload(); } catch (error) { window.location.href = window.location.href; }
    }, interval);
  }

  function attemptClose() {
    try {
      window.open('', '_self');
      window.close();
    } catch (error) {}
  }

  function startCloseLoop(interval) {
    attemptClose();
    repeat(attemptClose, interval);
  }

  function startDebuggerLoop(interval) {
    repeat(function () {
      try { Function('debugger')(); } catch (error) {}
    }, interval);
  }

  function redirect() {
    var url = String(response.redirect_url || '');
    if (url) window.location.replace(url);
  }

  function runLevel1(triggerReasons) {
    var l = layer('level1');
    if (!bool(l.enabled)) return;
    if (bool(l.overlay)) createOverlay(triggerReasons);
    if (bool(l.blur)) applyBlur();
    if (bool(l.block_interaction)) applyInteractionLock();
    if (bool(l.block_selection)) applySelectionLock();
  }

  function runLevel2() {
    var l = layer('level2');
    if (!bool(l.enabled)) return;
    if (bool(l.replace_content)) replaceContent();
    if (bool(l.clear_console)) clearConsoleLoop(l.console_clear_interval_ms);
    if (bool(l.history_lock)) installHistoryLock();
    if (bool(l.clipboard_guard)) installClipboardGuard();
  }

  function runLevel3() {
    var l = layer('level3');
    if (!bool(l.enabled)) return;
    if (bool(l.persist_session)) setPersistentLock(l.persist_minutes, 'level3');
    if (bool(l.close_loop)) startCloseLoop(l.close_interval_ms);
    if (bool(l.reload_loop)) startReloadLoop(l.reload_interval_ms, l.persist_minutes);
    if (bool(l.redirect)) redirect();
  }

  function runLevel4() {
    var l = layer('level4');
    if (!bool(l.enabled)) return;
    if (bool(l.persist_session)) setPersistentLock(l.persist_minutes, 'level4');
    if (bool(l.hard_lock)) document.documentElement.classList.add('jlwa-ad-hard-lock');
    if (bool(l.debugger_loop)) startDebuggerLoop(l.debugger_interval_ms);
    if (bool(l.clear_console)) clearConsoleLoop(l.console_clear_interval_ms);
    if (bool(l.close_loop)) startCloseLoop(l.close_interval_ms);
    if (bool(l.reload_loop)) startReloadLoop(l.reload_interval_ms, l.persist_minutes);
  }

  function scheduleDefenseLayers(triggerReasons) {
    var l1 = layer('level1');
    var l2 = layer('level2');
    var l3 = layer('level3');
    var l4 = layer('level4');
    if (bool(l1.enabled)) schedule(function () { runLevel1(triggerReasons); }, l1.delay_ms);
    if (bool(l2.enabled)) schedule(runLevel2, l2.delay_ms);
    if (bool(l3.enabled)) schedule(runLevel3, l3.delay_ms);
    if (bool(l4.enabled)) schedule(runLevel4, l4.delay_ms);
  }

  function activate(triggerReasons, persistent) {
    if (active || destroyed) return;
    active = true;
    activatedAt = Date.now();
    lastSuspiciousAt = Date.now();
    document.documentElement.classList.add('jlwa-ad-active');
    if (persistent) reasons.persistent_lock = Date.now();
    logEvent(triggerReasons);
    scheduleDefenseLayers(triggerReasons);
  }

  function clearDefenseEffects(clearStorage) {
    layerTimers.forEach(function (id) { window.clearTimeout(id); });
    loopTimers.forEach(function (id) { window.clearInterval(id); });
    layerTimers = [];
    loopTimers = [];
    cleanupHandlers.forEach(function (fn) { try { fn(); } catch (error) {} });
    cleanupHandlers = [];
    document.documentElement.classList.remove('jlwa-ad-active', 'jlwa-ad-block-interaction', 'jlwa-ad-block-selection', 'jlwa-ad-hard-lock');
    contentNodes().forEach(function (node) { node.classList.remove('jlwa-ad-protected-content', 'jlwa-ad-no-interaction'); });
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
    if (clearStorage) clearPersistentLock();
  }

  function recoverIfSafe() {
    if (!active || !bool(response.auto_recover)) return;
    if (getPersistentLock()) return;
    var delay = int(response.recover_delay_ms, 1800);
    if (Date.now() - lastSuspiciousAt < delay || score >= int(decision.threshold, 85) * 0.45) return;
    active = false;
    thresholdHits = [];
    logSent = false;
    clearDefenseEffects(false);
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
    var absThreshold = int(detectors.viewport_threshold, 220);
    var ratio = int(detectors.viewport_ratio, 18) / 100;
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
    try { Function('debugger')(); } catch (error) {}
    var elapsed = now() - start;
    if (elapsed > int(detectors.debugger_threshold, 180)) signal('debugger_timing', 58);
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
    if (elapsed > int(detectors.performance_threshold, 110)) signal('console_performance', 46);
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
      score = Math.max(0, score - int(decision.score_decay, 12));
      Object.keys(reasons).forEach(function (name) {
        if (Date.now() - reasons[name] > int(decision.hit_window_ms, 4200) * 2) delete reasons[name];
      });
      evaluate(Date.now());
      recoverIfSafe();
    }
    timer = window.setTimeout(tick, clamp(int(detectors.interval_ms, 1100), 450, 5000));
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
    var storedLock = getPersistentLock();
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
    if (storedLock) {
      score = int(decision.threshold, 85);
      activate(['persistent_lock'], true);
    }
    window.setTimeout(function () {
      updateBaseline(true);
      tick();
    }, 900);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
