(function () {
  'use strict';

  var cfg = window.XJPE_CONFIG || null;
  if (!cfg || !cfg.effects) return;

  var global = cfg.global || {};
  var compat = cfg.compat || {};
  var effects = cfg.effects || {};
  var z = parseInt(global.zIndex || 999999, 10);
  var bodyWait = !(compat.body_wait === false || compat.body_wait === 0 || compat.body_wait === '0');
  var rafLoops = [];
  var initialized = false;
  var mobile = isMobileDevice();
  var saveData = !!(navigator.connection && navigator.connection.saveData);
  var fallingBudget = (mobile || saveData) ? 180 : 600;
  var fallingAllocated = 0;

  function emitReady(disabled) {
    window.XJPE_DISABLED = !!disabled;
    window.XJPE_READY = true;
    try {
      document.dispatchEvent(new CustomEvent('xjpe:ready', { detail: { disabled: !!disabled } }));
    } catch (e) {
      var event = document.createEvent('Event');
      event.initEvent('xjpe:ready', false, false);
      event.detail = { disabled: !!disabled };
      document.dispatchEvent(event);
    }
  }

  function isMobileDevice() {
    var narrow = window.matchMedia && window.matchMedia('(max-width: 782px)').matches;
    var touchMobile = /Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
    return !!(narrow || touchMobile);
  }

  if (global.mobileEnabled === false && mobile) {
    emitReady(true);
    return;
  }

  function applyCustomCss() {
    var css = typeof global.customCss === 'string' ? global.customCss.trim() : '';
    if (!css || document.getElementById('xjpe-custom-css')) return;
    var style = document.createElement('style');
    style.id = 'xjpe-custom-css';
    style.textContent = css;
    (document.head || document.documentElement).appendChild(style);
  }
  applyCustomCss();

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, { once: true });
    else fn();
  }

  function startWhenBodyExists(fn) {
    var done = false;
    var observer = null;
    function run() {
      if (done || !document.body) return;
      done = true;
      if (observer) observer.disconnect();
      fn();
    }
    if (bodyWait) { ready(run); return; }
    if (document.body) { run(); return; }
    if (window.MutationObserver && document.documentElement) {
      observer = new MutationObserver(run);
      observer.observe(document.documentElement, { childList: true, subtree: true });
    }
    document.addEventListener('DOMContentLoaded', run, { once: true });
  }

  function enabled(name) { return effects[name] && !!effects[name].enabled; }
  function num(v, fallback) { var n = parseFloat(v); return isFinite(n) ? n : fallback; }
  function int(v, fallback) { var n = parseInt(v, 10); return isFinite(n) ? n : fallback; }
  function clamp(value, min, max) { return Math.max(min, Math.min(max, value)); }
  function editableTarget(target) {
    if (!target || !target.closest) return false;
    return !!target.closest('input, textarea, select, option, [contenteditable="true"], [contenteditable=""], .CodeMirror, .ace_editor');
  }
  function insideCodeOrPre(target) {
    return !!(target && target.closest && target.closest('pre, code'));
  }

  function layer(cls, extra) {
    var el = document.createElement('div');
    el.className = cls;
    el.style.zIndex = String(z + (extra || 0));
    // Full-screen visual layers must never intercept the page, regardless of legacy safe_mode.
    el.style.pointerEvents = 'none';
    document.body.appendChild(el);
    return el;
  }

  function canvasLayer(cls, extra) {
    var c = document.createElement('canvas');
    c.className = cls;
    c.style.zIndex = String(z + (extra || 0));
    c.style.pointerEvents = 'none';
    document.body.appendChild(c);

    function resize() {
      var width = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
      var height = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
      var dpr = clamp(window.devicePixelRatio || 1, 1, 2);
      c.xjpeWidth = width;
      c.xjpeHeight = height;
      c.xjpeDpr = dpr;
      c.style.width = width + 'px';
      c.style.height = height + 'px';
      if (c.width !== Math.round(width * dpr)) c.width = Math.round(width * dpr);
      if (c.height !== Math.round(height * dpr)) c.height = Math.round(height * dpr);
      var context = c.getContext('2d');
      if (context) context.setTransform(dpr, 0, 0, dpr, 0, 0);
      try { c.dispatchEvent(new Event('xjpe:resize')); } catch (e) {}
    }

    resize();
    window.addEventListener('resize', resize, { passive: true });
    return c;
  }

  function createRafLoop(step) {
    var loop = { id: 0, active: true };
    function frame(timestamp) {
      loop.id = 0;
      if (!loop.active || document.hidden) return;
      step(timestamp);
      schedule();
    }
    function schedule() {
      if (!loop.active || document.hidden || loop.id) return;
      loop.id = window.requestAnimationFrame(frame);
    }
    loop.pause = function () { if (loop.id) window.cancelAnimationFrame(loop.id); loop.id = 0; };
    loop.resume = schedule;
    loop.stop = function () { loop.active = false; loop.pause(); };
    rafLoops.push(loop);
    schedule();
    return loop;
  }

  document.addEventListener('visibilitychange', function () {
    rafLoops.forEach(function (loop) { document.hidden ? loop.pause() : loop.resume(); });
  });

  function toast(message, kind) {
    var old = document.querySelector('.xjpe-toast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'xjpe-toast' + (kind ? ' is-' + kind : '');
    t.setAttribute('role', 'status');
    t.textContent = message || '操作完成';
    t.style.zIndex = '2147483000';
    document.body.appendChild(t);
    window.setTimeout(function () { if (t.parentNode) t.remove(); }, 2600);
  }

  function performanceCount(requested, desktopMax, mobileMax) {
    var max = (mobile || saveData) ? mobileMax : desktopMax;
    return clamp(requested, 1, max);
  }

  function falling(name, characters, className, defaults, upward) {
    var e = effects[name] || {};
    var requested = Math.max(1, int(e.count, defaults.count));
    var count = Math.min(performanceCount(requested, 360, 120), Math.max(0, fallingBudget - fallingAllocated));
    if (count < 1) return;
    fallingAllocated += count;
    var size = clamp(int(e.size, defaults.size), 4, 72);
    var speed = clamp(num(e.speed, defaults.speed), 0.1, 5);
    var opacity = clamp(num(e.opacity, defaults.opacity), 0.05, 1);
    var wind = clamp(num(e.wind, defaults.wind || 0), -3, 3);
    var sway = clamp(num(e.sway, defaults.sway || 1), 0, 4);
    var wrap = layer('xjpe-layer xjpe-' + name + '-layer', 1);
    var items = [];
    var chars = Array.isArray(characters) ? characters : [characters];

    for (var i = 0; i < count; i++) {
      var el = document.createElement('span');
      el.className = className;
      el.textContent = chars[Math.floor(Math.random() * chars.length)];
      el.style.fontSize = (size * (0.52 + Math.random() * 1.05)) + 'px';
      el.style.opacity = String(opacity * (0.42 + Math.random() * 0.58));
      wrap.appendChild(el);
      var h = window.innerHeight;
      items.push({
        el: el,
        x: Math.random() * window.innerWidth,
        y: Math.random() * h,
        vy: (0.3 + Math.random() * 1.25) * speed * (upward ? -1 : 1),
        vx: wind + (-0.25 + Math.random() * 0.5) * speed,
        drift: (0.35 + Math.random() * 1.2) * sway,
        rot: Math.random() * 360,
        vr: (-1.4 + Math.random() * 2.8) * speed,
        phase: Math.random() * Math.PI * 2,
        scale: 0.7 + Math.random() * 0.6
      });
    }

    createRafLoop(function () {
      var w = window.innerWidth;
      var h = window.innerHeight;
      for (var i = 0; i < items.length; i++) {
        var p = items[i];
        p.y += p.vy;
        p.x += p.vx + Math.sin((p.y * 0.012) + p.phase) * p.drift;
        p.rot += p.vr;
        if (!upward && p.y > h + 80) { p.y = -80; p.x = Math.random() * w; }
        if (upward && p.y < -80) { p.y = h + 80; p.x = Math.random() * w; }
        if (p.x < -100) p.x = w + 60;
        if (p.x > w + 100) p.x = -60;
        p.el.style.transform = 'translate3d(' + p.x + 'px,' + p.y + 'px,0) rotate(' + p.rot + 'deg) scale(' + p.scale + ')';
      }
    });
  }

  function initLantern() {
    var e = effects.lantern || {};
    var count = clamp(int(e.quantity, 2), 1, 6);
    var size = clamp(int(e.size, 82), 36, 180);
    var text = String(e.text || '福').slice(0, 2);
    var wrap = layer('xjpe-lantern-wrap', 3);
    for (var i = 0; i < count; i++) {
      var l = document.createElement('div');
      l.className = 'xjpe-lantern';
      l.style.setProperty('--xjpe-lantern-size', size + 'px');
      var left = count === 1 ? 50 : (8 + i * (84 / (count - 1)));
      l.style.left = 'calc(' + left + '% - ' + (size / 2) + 'px)';
      l.style.animationDelay = (i * -0.45) + 's';
      l.innerHTML = '<div class="xjpe-lantern-line"></div><div class="xjpe-lantern-body"><span></span></div><div class="xjpe-lantern-tail"></div>';
      l.querySelector('span').textContent = text;
      wrap.appendChild(l);
    }
  }

  function initParticles() {
    var e = effects.particles || {};
    var requested = clamp(int(e.count, 70), 8, 200);
    var count = performanceCount(requested, 150, 70);
    var speed = Math.max(0.03, num(e.speed, 0.7));
    var opacity = clamp(num(e.opacity, 0.55), 0.05, 1);
    var maxDist = clamp(int(e.line_distance, 130), 40, 300);
    var c = canvasLayer('xjpe-canvas xjpe-particles-canvas', 0);
    var ctx = c.getContext('2d');
    if (!ctx) return;
    var pts = [];
    var lastFrame = 0;
    var frameInterval = count > 90 || mobile ? 1000 / 30 : 1000 / 50;

    function bounds() { return { w: c.xjpeWidth || window.innerWidth, h: c.xjpeHeight || window.innerHeight }; }
    var b = bounds();
    for (var i = 0; i < count; i++) {
      pts.push({ x: Math.random() * b.w, y: Math.random() * b.h, vx: (-0.6 + Math.random() * 1.2) * speed, vy: (-0.6 + Math.random() * 1.2) * speed });
    }

    createRafLoop(function (timestamp) {
      if (timestamp - lastFrame < frameInterval) return;
      lastFrame = timestamp;
      var boundsNow = bounds();
      var w = boundsNow.w, h = boundsNow.h;
      ctx.clearRect(0, 0, w, h);
      ctx.fillStyle = 'rgba(120,160,255,' + opacity + ')';
      var grid = Object.create(null);
      var cellSize = maxDist;

      for (var i = 0; i < pts.length; i++) {
        var p = pts[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0) { p.x = 0; p.vx = Math.abs(p.vx); }
        else if (p.x > w) { p.x = w; p.vx = -Math.abs(p.vx); }
        if (p.y < 0) { p.y = 0; p.vy = Math.abs(p.vy); }
        else if (p.y > h) { p.y = h; p.vy = -Math.abs(p.vy); }
        var gx = Math.floor(p.x / cellSize), gy = Math.floor(p.y / cellSize);
        var key = gx + ':' + gy;
        if (!grid[key]) grid[key] = [];
        grid[key].push(i);
        ctx.beginPath(); ctx.arc(p.x, p.y, 2, 0, Math.PI * 2); ctx.fill();
      }

      var maxDistSq = maxDist * maxDist;
      for (var a = 0; a < pts.length; a++) {
        var pa = pts[a];
        var cgx = Math.floor(pa.x / cellSize), cgy = Math.floor(pa.y / cellSize);
        for (var ox = -1; ox <= 1; ox++) for (var oy = -1; oy <= 1; oy++) {
          var candidates = grid[(cgx + ox) + ':' + (cgy + oy)] || [];
          for (var ci = 0; ci < candidates.length; ci++) {
            var bi = candidates[ci];
            if (bi <= a) continue;
            var pb = pts[bi];
            var dx = pa.x - pb.x, dy = pa.y - pb.y;
            var distanceSq = dx * dx + dy * dy;
            if (distanceSq < maxDistSq) {
              var ratio = 1 - Math.sqrt(distanceSq) / maxDist;
              ctx.strokeStyle = 'rgba(120,160,255,' + (ratio * opacity * 0.45) + ')';
              ctx.beginPath(); ctx.moveTo(pa.x, pa.y); ctx.lineTo(pb.x, pb.y); ctx.stroke();
            }
          }
        }
      }
    });
  }

  function initCursor() {
    var e = effects.cursor || {};
    if (mobile) return;
    var size = clamp(int(e.size, 13), 4, 48);
    var density = clamp(int(e.density, 1), 1, 6);
    var duration = clamp(int(e.duration, 760), 240, 2400);
    var preset = String(e.preset || 'star');
    var symbols = { star: ['✦', '✧', '★'], heart: ['❤', '♡'], firefly: ['•', '✦'], petal: ['🌸', '❀'], bubble: ['○', '◌'] };
    var custom = String(e.symbol || '✦').slice(0, 4);
    var list = preset === 'custom' ? [custom] : (symbols[preset] || symbols.star);
    var fixedColor = String(e.color || '#ff5ba7');
    var rainbow = String(e.color_mode || 'rainbow') !== 'fixed';
    var last = 0;

    document.addEventListener('mousemove', function (ev) {
      var now = Date.now();
      if (now - last < 30 / density) return;
      last = now;
      var s = document.createElement('span');
      s.className = 'xjpe-star-trail xjpe-cursor-' + preset;
      s.textContent = list[Math.floor(Math.random() * list.length)];
      s.style.left = ev.clientX + 'px'; s.style.top = ev.clientY + 'px';
      s.style.zIndex = String(z + 5); s.style.fontSize = size + 'px';
      s.style.color = rainbow ? 'hsl(' + Math.floor(Math.random() * 360) + ' 88% 62%)' : fixedColor;
      document.body.appendChild(s);
      var dx = -22 + Math.random() * 44;
      var dy = preset === 'bubble' ? (-8 - Math.random() * 40) : (-24 - Math.random() * 22);
      if (s.animate) {
        var animation = s.animate([
          { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
          { transform: 'translate3d(' + dx + 'px,' + dy + 'px,0) scale(.18) rotate(' + (-90 + Math.random() * 180) + 'deg)', opacity: 0 }
        ], { duration: duration, easing: 'cubic-bezier(.15,.7,.25,1)' });
        animation.onfinish = function () { if (s.parentNode) s.remove(); };
      } else window.setTimeout(function () { if (s.parentNode) s.remove(); }, duration);
    }, { passive: true });
  }

  function initWaves() {
    var e = effects.waves || {};
    var selector = String(e.footer_selector || 'footer.footer, #footer.footer, footer.site-footer, #colophon.site-footer, footer');
    var footer = null;
    try { footer = document.querySelector(selector); } catch (error) {}
    if (!footer || !footer.parentNode) return;

    var wrap = document.createElement('div');
    wrap.className = 'xjpe-waves';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.style.setProperty('--xjpe-wave-height', clamp(int(e.height, 72), 24, 220) + 'px');
    wrap.style.setProperty('--xjpe-wave-opacity', String(clamp(num(e.opacity, 0.48), 0.05, 1)));
    wrap.style.setProperty('--xjpe-wave-speed', clamp(int(e.speed, 12), 4, 40) + 's');
    wrap.style.setProperty('--xjpe-wave-color-1', String(e.color_1 || '#5b8cff'));
    wrap.style.setProperty('--xjpe-wave-color-2', String(e.color_2 || '#9b5cff'));
    wrap.innerHTML = '<div class="xjpe-wave xjpe-wave-b"><svg viewBox="0 0 2880 120" preserveAspectRatio="none"><path d="M0 74 C240 28 480 112 720 68 C960 24 1200 104 1440 62 L1440 120 L0 120 Z"></path><path transform="translate(1440 0)" d="M0 74 C240 28 480 112 720 68 C960 24 1200 104 1440 62 L1440 120 L0 120 Z"></path></svg></div><div class="xjpe-wave xjpe-wave-a"><svg viewBox="0 0 2880 120" preserveAspectRatio="none"><path d="M0 82 C180 44 360 38 540 74 C720 110 900 106 1080 66 C1260 26 1350 48 1440 70 L1440 120 L0 120 Z"></path><path transform="translate(1440 0)" d="M0 82 C180 44 360 38 540 74 C720 110 900 106 1080 66 C1260 26 1350 48 1440 70 L1440 120 L0 120 Z"></path></svg></div>';
    footer.parentNode.insertBefore(wrap, footer);
  }

  function initRibbon() {
    var e = effects.ribbon || {};
    var opacity = clamp(num(e.opacity, 0.42), 0.05, 1);
    var c = canvasLayer('xjpe-canvas xjpe-ribbon-canvas', -1);
    var ctx = c.getContext('2d');
    if (!ctx) return;
    function draw() {
      var w = c.xjpeWidth || window.innerWidth, h = c.xjpeHeight || window.innerHeight;
      ctx.clearRect(0, 0, w, h);
      for (var i = 0; i < (mobile ? 12 : 24); i++) {
        var x = Math.random() * w, y = Math.random() * h;
        var rw = 80 + Math.random() * 240, rh = 20 + Math.random() * 90;
        ctx.save(); ctx.translate(x, y); ctx.rotate(Math.random() * Math.PI);
        ctx.fillStyle = 'hsla(' + Math.floor(Math.random() * 360) + ',85%,65%,' + opacity + ')';
        ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(rw, rh * 0.2); ctx.lineTo(rw * 0.75, rh); ctx.lineTo(-rw * 0.1, rh * 0.75); ctx.closePath(); ctx.fill(); ctx.restore();
      }
    }
    draw();
    c.addEventListener('xjpe:resize', draw);
    if (e.click) document.addEventListener('click', function (ev) { if (!editableTarget(ev.target)) draw(); });
  }

  function initGrayscale() {
    var percent = clamp(int((effects.grayscale || {}).percent, 100), 1, 100);
    document.documentElement.style.setProperty('--xjpe-grayscale', percent + '%');
    document.documentElement.classList.add('xjpe-grayscale');
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (resolve, reject) {
      try {
        var textarea = document.createElement('textarea');
        textarea.value = text; textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed'; textarea.style.opacity = '0';
        document.body.appendChild(textarea); textarea.select();
        var copied = document.execCommand('copy'); textarea.remove();
        copied ? resolve() : reject(new Error('copy failed'));
      } catch (e) { reject(e); }
    });
  }

  function initContextMenu() {
    var e = effects.contextmenu || {};
    var m = document.createElement('div');
    m.className = 'xjpe-context-menu';
    m.innerHTML = '<div class="xjpe-context-title"></div><div class="xjpe-context-items"></div>';
    m.querySelector('.xjpe-context-title').textContent = e.title || '九流网站菜单';
    m.style.zIndex = '2147483000'; document.body.appendChild(m);
    var box = m.querySelector('.xjpe-context-items');
    var seen = {};
    function add(label, action, key) {
      key = String(key || label).toLowerCase(); if (seen[key]) return; seen[key] = true;
      var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
      b.addEventListener('click', function () { m.style.display = 'none'; action(); }); box.appendChild(b);
    }
    function copyCurrentLink() {
      copyText(location.href).then(function () { toast('链接已复制', 'success'); }).catch(function () { toast('复制失败，请手动复制地址栏链接', 'error'); });
    }
    if (e.show_copy) add('复制当前链接', copyCurrentLink, '#copy');
    if (e.show_refresh) add('刷新页面', function () { location.reload(); }, '#refresh');
    if (e.show_top) add('返回顶部', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); }, '#top');
    if (e.show_back) add('返回上一页', function () { history.back(); }, '#back');
    String(e.custom_items || '').split(/\r?\n/).forEach(function (line) {
      var parts = line.split('|'); if (parts.length < 2) return;
      var label = parts[0].trim(), url = parts.slice(1).join('|').trim(); if (!label || !url) return;
      add(label, function () {
        if (url === '#top') window.scrollTo({ top: 0, behavior: 'smooth' });
        else if (url === '#refresh') location.reload();
        else if (url === '#back') history.back();
        else if (url === '#copy') copyCurrentLink();
        else location.href = url;
      }, url);
    });
    document.addEventListener('contextmenu', function (ev) {
      if (editableTarget(ev.target)) return;
      ev.preventDefault(); m.style.display = 'block';
      var x = Math.min(ev.clientX, window.innerWidth - m.offsetWidth - 10);
      var y = Math.min(ev.clientY, window.innerHeight - m.offsetHeight - 10);
      m.style.left = Math.max(8, x) + 'px'; m.style.top = Math.max(8, y) + 'px';
    });
    document.addEventListener('click', function () { m.style.display = 'none'; });
    window.addEventListener('blur', function () { m.style.display = 'none'; });
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, function (ch) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch]; });
  }

  function initContentProtection() {
    var e = effects.nosource || {};
    if (e.admin_bypass && global.isAdmin) return;
    var message = e.message || '本站已开启内容保护，请尊重原创。';
    var contextMenuEnabled = enabled('contextmenu');

    if (e.block_selection) {
      document.documentElement.classList.add('xjpe-protect-selection');
      document.addEventListener('selectstart', function (ev) { if (!editableTarget(ev.target) && !insideCodeOrPre(ev.target)) ev.preventDefault(); }, true);
    }
    if (e.block_drag) document.addEventListener('dragstart', function (ev) { if (!editableTarget(ev.target)) { ev.preventDefault(); toast(message, 'warning'); } }, true);
    if (e.block_contextmenu && !contextMenuEnabled) document.addEventListener('contextmenu', function (ev) { if (!editableTarget(ev.target)) { ev.preventDefault(); toast(message, 'warning'); } });

    if (e.block_print) document.documentElement.classList.add('xjpe-block-print');

    if (e.block_shortcuts || e.block_copy || e.block_print) {
      var shortcutHandler = function (ev) {
        if (editableTarget(ev.target)) return;
        var code = ev.keyCode || ev.which || 0;
        var k = String(ev.key || '').toLowerCase();
        if (!k && code) k = String.fromCharCode(code).toLowerCase();
        var primary = !!(ev.ctrlKey || ev.metaKey);
        var macDev = !!(ev.metaKey && ev.altKey && (['i', 'j', 'c', 'u'].indexOf(k) >= 0 || [73, 74, 67, 85].indexOf(code) >= 0));
        var dev = e.block_shortcuts && (
          k === 'f12' || code === 123 ||
          (primary && (['u', 's'].indexOf(k) >= 0 || [85, 83].indexOf(code) >= 0)) ||
          (primary && ev.shiftKey && (['i', 'j', 'c', 'k'].indexOf(k) >= 0 || [73, 74, 67, 75].indexOf(code) >= 0)) ||
          macDev
        );
        var copying = e.block_copy && primary && (['c', 'x'].indexOf(k) >= 0 || [67, 88].indexOf(code) >= 0);
        var printing = e.block_print && primary && (k === 'p' || code === 80);
        if (dev || copying || printing) {
          if (ev.cancelable) ev.preventDefault();
          ev.stopPropagation();
          if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
          toast(message, 'warning');
          return false;
        }
      };
      window.addEventListener('keydown', shortcutHandler, true);
      document.addEventListener('keydown', shortcutHandler, true);
    }

    document.addEventListener('copy', function (ev) {
      if (editableTarget(ev.target)) return;
      var selection = window.getSelection ? String(window.getSelection()) : '';
      if (!selection) return;
      if (e.block_copy) { ev.preventDefault(); toast(message, 'warning'); return; }
      var minChars = clamp(int(e.copy_min_chars, 12), 0, 1000);
      if (selection.trim().length < minChars) return;
      var mode = String(e.copy_mode || 'append');
      if (mode === 'none') {
        if (e.copy_success_toast) toast(e.copy_toast_message || '复制成功，请保留文章版权。', 'success');
        return;
      }
      var source = e.copy_include_link ? location.href : '';
      var prefix = String(e.copy_prefix || '');
      var suffix = String(e.copy_suffix || '');
      if (source && prefix.indexOf(source) < 0 && suffix.indexOf(source) < 0) {
        if (mode === 'prepend') prefix += (prefix ? '\n' : '') + '原文链接：' + source;
        else suffix += (suffix ? '\n' : '') + '原文链接：' + source;
      }
      var plain = selection;
      if (mode === 'prepend' || mode === 'both') plain = prefix + (prefix ? '\n\n' : '') + plain;
      if (mode === 'append' || mode === 'both') plain = plain + (suffix ? '\n\n' + suffix : '');
      if (!ev.clipboardData) return;
      ev.preventDefault();
      ev.clipboardData.setData('text/plain', plain);
      var selectedHtml = escapeHtml(selection).replace(/\n/g, '<br>');
      var htmlPrefix = escapeHtml(prefix).replace(/\n/g, '<br>');
      var htmlSuffix = escapeHtml(suffix).replace(/\n/g, '<br>');
      if (source) htmlSuffix = htmlSuffix.replace(escapeHtml(source), '<a href="' + escapeHtml(source) + '">' + escapeHtml(source) + '</a>');
      var html = selectedHtml;
      if (mode === 'prepend' || mode === 'both') html = (htmlPrefix ? '<p>' + htmlPrefix + '</p>' : '') + html;
      if (mode === 'append' || mode === 'both') html += htmlSuffix ? '<p>' + htmlSuffix + '</p>' : '';
      ev.clipboardData.setData('text/html', html);
      if (e.copy_success_toast) toast(e.copy_toast_message || '复制成功，请保留文章版权与来源链接。', 'success');
    });
  }

  function initMusic() {
    var e = effects.bgmusic || {}; if (!e.url) return;
    var audio = new Audio(e.url); audio.loop = !!e.loop; audio.volume = clamp(num(e.volume, 0.35), 0, 1); audio.preload = 'metadata';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'xjpe-music-btn'; b.title = e.title || '背景音乐'; b.setAttribute('aria-label', e.title || '背景音乐'); b.textContent = '🎵'; b.style.zIndex = String(z + 10); document.body.appendChild(b);
    function play() { var result; try { result = audio.play(); } catch (error) { return Promise.reject(error); } return Promise.resolve(result).then(function () { b.classList.add('is-playing'); }); }
    function pause() { audio.pause(); b.classList.remove('is-playing'); }
    b.addEventListener('click', function () { if (audio.paused) play().catch(function () { toast('浏览器阻止了播放，请再次点击音乐按钮', 'warning'); }); else pause(); });
    audio.addEventListener('ended', function () { if (!audio.loop) b.classList.remove('is-playing'); });
    audio.addEventListener('pause', function () { b.classList.remove('is-playing'); });
    audio.addEventListener('error', function () { b.classList.remove('is-playing'); b.title = '背景音乐加载失败'; });
    if (e.autoplay) {
      var events = ['click', 'touchstart', 'keydown'];
      var attempt = function () { play().then(function () { events.forEach(function (name) { document.removeEventListener(name, attempt); }); }).catch(function () {}); };
      events.forEach(function (name) { document.addEventListener(name, attempt, { passive: name === 'touchstart' }); });
    }
  }

  function pad2(value) { return value < 10 ? '0' + value : String(value); }
  function localDateKey(date) { return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()); }
  function festivalGreeting(date) {
    var key = pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    var festivals = {
      '01-01': ['元旦快乐', '新的一年，愿你平安顺遂，所愿皆所得。'], '02-14': ['情人节快乐', '愿今天有爱、有花，也有值得珍惜的温柔。'],
      '03-08': ['妇女节快乐', '愿每一位女性都被尊重、被看见，自信而闪耀。'], '03-12': ['植树节快乐', '种下一点绿色，也种下一份对未来的期待。'],
      '05-01': ['劳动节快乐', '致敬每一份认真付出，愿劳动也带来收获与快乐。'], '06-01': ['儿童节快乐', '愿你永远保留好奇心，也保留心里的童真。'],
      '10-01': ['国庆节快乐', '祝愿祖国繁荣昌盛，也祝你假期平安愉快。'], '10-31': ['万圣夜快乐', '愿今晚有一点神秘、有一点惊喜，还有很多快乐。'],
      '12-24': ['平安夜快乐', '愿今夜平安温暖，身边有牵挂，也有陪伴。'], '12-25': ['圣诞快乐', '愿节日的灯光照亮好心情，祝你喜乐安康。']
    };
    return festivals[key] || null;
  }
  function storageGet(key) { try { return window.localStorage ? localStorage.getItem(key) : null; } catch (e) { return null; } }
  function storageSet(key, value) { try { if (window.localStorage) localStorage.setItem(key, value); } catch (e) {} }

  function initWelcome() {
    var e = effects.welcome || {}; var now = new Date(); var greeting = e.auto_festival ? festivalGreeting(now) : null;
    var title = greeting ? greeting[0] : (e.title || '欢迎访问'); var message = greeting ? greeting[1] : (e.message || '欢迎来到我的网站。');
    var key = 'xjpe_welcome_' + localDateKey(now) + '_' + (greeting ? pad2(now.getMonth() + 1) + pad2(now.getDate()) : 'default');
    if (e.once_per_day && !global.preview && storageGet(key)) return;
    var mask = document.createElement('div'); mask.className = 'xjpe-welcome-mask';
    mask.innerHTML = '<div class="xjpe-welcome-box" role="dialog" aria-modal="true" aria-labelledby="xjpe-welcome-title"><h3 id="xjpe-welcome-title"></h3><p></p><button type="button">知道了</button></div>';
    mask.querySelector('h3').textContent = title; mask.querySelector('p').textContent = message;
    function onKeydown(ev) { if (ev.key === 'Escape') close(); }
    function close() { document.removeEventListener('keydown', onKeydown); if (mask.parentNode) mask.remove(); }
    mask.querySelector('button').addEventListener('click', close); mask.addEventListener('click', function (ev) { if (ev.target === mask) close(); }); document.addEventListener('keydown', onKeydown);
    document.body.appendChild(mask); if (e.once_per_day && !global.preview) storageSet(key, '1');
  }

  function safeInit(name, fn) { try { fn(); } catch (error) { if (window.console && console.warn) console.warn('XJPE ' + name + ' init error:', error); } }

  function initialize() {
    if (initialized || !document.body) return; initialized = true;
    var reduceMotion = !!(global.respectReduceMotion && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if (reduceMotion) document.documentElement.classList.add('xjpe-respect-motion');
    else {
      if (enabled('particles')) safeInit('particles', initParticles);
      if (enabled('ribbon')) safeInit('ribbon', initRibbon);
      if (enabled('sakura')) safeInit('sakura', function () { falling('sakura', ['🌸', '❀', '✿'], 'xjpe-petal', { count: 56, size: 18, speed: 1, opacity: 0.85, wind: 0.45, sway: 1.25 }, false); });
      if (enabled('snow')) safeInit('snow', function () { falling('snow', ['❄', '❅', '•'], 'xjpe-snowflake', { count: 72, size: 13, speed: 1, opacity: 0.9, wind: 0.35, sway: 1 }, false); });
      if (enabled('leaves')) safeInit('leaves', function () { falling('leaves', ['🍂', '🍁', '❧'], 'xjpe-leaf', { count: 44, size: 18, speed: 0.85, opacity: 0.88, wind: 0.65, sway: 1.4 }, false); });
      if (enabled('bubbles')) safeInit('bubbles', function () { falling('bubbles', ['○', '◯', '◌'], 'xjpe-bubble', { count: 30, size: 18, speed: 0.65, opacity: 0.5, wind: 0.18, sway: 0.8 }, true); });
      if (enabled('lantern')) safeInit('lantern', initLantern);
      if (enabled('cursor')) safeInit('cursor', initCursor);
      if (enabled('waves')) safeInit('waves', initWaves);
    }
    if (enabled('grayscale')) safeInit('grayscale', initGrayscale);
    if (enabled('contextmenu')) safeInit('contextmenu', initContextMenu);
    if (enabled('nosource')) safeInit('content-protection', initContentProtection);
    if (enabled('bgmusic')) safeInit('bgmusic', initMusic);
    if (enabled('welcome')) safeInit('welcome', initWelcome);
    emitReady(false);
  }

  startWhenBodyExists(initialize);
})();
