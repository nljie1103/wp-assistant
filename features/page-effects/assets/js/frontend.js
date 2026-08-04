(function () {
  'use strict';

  var cfg = window.XJPE_CONFIG || null;
  if (!cfg || !cfg.effects) return;

  var global = cfg.global || {};
  var compat = cfg.compat || {};
  var effects = cfg.effects || {};
  var z = parseInt(global.zIndex || 999999, 10);
  var safeMode = !(compat.safe_mode === false || compat.safe_mode === 0 || compat.safe_mode === '0');
  var bodyWait = !(compat.body_wait === false || compat.body_wait === 0 || compat.body_wait === '0');
  var rafLoops = [];
  var initialized = false;

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

  if (global.mobileEnabled === false && isMobileDevice()) {
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
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
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

    if (bodyWait) {
      ready(run);
      return;
    }

    if (document.body) {
      run();
      return;
    }

    if (window.MutationObserver && document.documentElement) {
      observer = new MutationObserver(run);
      observer.observe(document.documentElement, { childList: true, subtree: true });
    }
    document.addEventListener('DOMContentLoaded', run, { once: true });
  }

  function enabled(name) {
    return effects[name] && !!effects[name].enabled;
  }

  function num(v, fallback) {
    var n = parseFloat(v);
    return isFinite(n) ? n : fallback;
  }

  function int(v, fallback) {
    var n = parseInt(v, 10);
    return isFinite(n) ? n : fallback;
  }

  function layer(cls, extra) {
    var el = document.createElement('div');
    el.className = cls;
    el.style.zIndex = String(z + (extra || 0));
    el.style.pointerEvents = safeMode ? 'none' : 'auto';
    document.body.appendChild(el);
    return el;
  }

  function canvasLayer(cls, extra) {
    var c = document.createElement('canvas');
    c.className = cls;
    c.style.zIndex = String(z + (extra || 0));
    c.style.pointerEvents = safeMode ? 'none' : 'auto';
    document.body.appendChild(c);

    function resize() {
      var width = Math.max(1, window.innerWidth || document.documentElement.clientWidth || 1);
      var height = Math.max(1, window.innerHeight || document.documentElement.clientHeight || 1);
      if (c.width !== width) c.width = width;
      if (c.height !== height) c.height = height;
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

    loop.pause = function () {
      if (loop.id) window.cancelAnimationFrame(loop.id);
      loop.id = 0;
    };
    loop.resume = schedule;
    loop.stop = function () {
      loop.active = false;
      loop.pause();
    };

    rafLoops.push(loop);
    schedule();
    return loop;
  }

  document.addEventListener('visibilitychange', function () {
    rafLoops.forEach(function (loop) {
      if (document.hidden) loop.pause();
      else loop.resume();
    });
  });

  function toast(message) {
    var old = document.querySelector('.xjpe-toast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'xjpe-toast';
    t.textContent = message || '操作已拦截';
    t.style.zIndex = '2147483000';
    document.body.appendChild(t);
    window.setTimeout(function () {
      if (t.parentNode) t.remove();
    }, 1800);
  }

  function falling(name, char, className, defaults) {
    var e = effects[name] || {};
    var count = Math.max(1, int(e.count, defaults.count));
    var size = Math.max(4, int(e.size, defaults.size));
    var speed = Math.max(0.1, num(e.speed, defaults.speed));
    var opacity = Math.max(0.05, Math.min(1, num(e.opacity, defaults.opacity)));
    var wrap = layer('xjpe-layer xjpe-' + name + '-layer', 1);
    var items = [];

    for (var i = 0; i < count; i++) {
      var el = document.createElement('span');
      el.className = className;
      el.textContent = char;
      el.style.fontSize = (size * (0.55 + Math.random() * 0.9)) + 'px';
      el.style.opacity = String(opacity * (0.45 + Math.random() * 0.55));
      wrap.appendChild(el);
      items.push({
        el: el,
        x: Math.random() * window.innerWidth,
        y: Math.random() * window.innerHeight,
        vy: (0.35 + Math.random() * 1.25) * speed,
        vx: (-0.35 + Math.random() * 0.7) * speed,
        drift: 0.5 + Math.random() * 1.5,
        rot: Math.random() * 360,
        vr: (-1.2 + Math.random() * 2.4) * speed,
        phase: Math.random() * Math.PI * 2
      });
    }

    createRafLoop(function () {
      var w = window.innerWidth;
      var h = window.innerHeight;
      for (var i = 0; i < items.length; i++) {
        var p = items[i];
        p.y += p.vy;
        p.x += p.vx + Math.sin((p.y * 0.01) + p.phase) * p.drift;
        p.rot += p.vr;
        if (p.y > h + 60) {
          p.y = -60;
          p.x = Math.random() * w;
        }
        if (p.x < -80) p.x = w + 40;
        if (p.x > w + 80) p.x = -40;
        p.el.style.transform = 'translate3d(' + p.x + 'px,' + p.y + 'px,0) rotate(' + p.rot + 'deg)';
      }
    });
  }

  function initLantern() {
    var e = effects.lantern || {};
    var count = Math.max(1, Math.min(6, int(e.quantity, 2)));
    var size = Math.max(36, Math.min(180, int(e.size, 82)));
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
    var count = Math.max(8, Math.min(200, int(e.count, 70)));
    var speed = Math.max(0.03, num(e.speed, 0.7));
    var opacity = Math.max(0.05, Math.min(1, num(e.opacity, 0.55)));
    var maxDist = Math.max(40, Math.min(300, int(e.line_distance, 130)));
    var maxDistSq = maxDist * maxDist;
    var c = canvasLayer('xjpe-canvas xjpe-particles-canvas', 0);
    var ctx = c.getContext('2d');
    if (!ctx) return;

    var pts = [];
    var lastFrame = 0;
    var frameInterval = count > 120 ? 1000 / 30 : 1000 / 60;

    for (var i = 0; i < count; i++) {
      pts.push({
        x: Math.random() * c.width,
        y: Math.random() * c.height,
        vx: (-0.6 + Math.random() * 1.2) * speed,
        vy: (-0.6 + Math.random() * 1.2) * speed
      });
    }

    createRafLoop(function (timestamp) {
      if (timestamp - lastFrame < frameInterval) return;
      lastFrame = timestamp;
      ctx.clearRect(0, 0, c.width, c.height);
      ctx.fillStyle = 'rgba(120,160,255,' + opacity + ')';

      for (var i = 0; i < pts.length; i++) {
        var p = pts[i];
        p.x += p.vx;
        p.y += p.vy;
        if (p.x < 0) { p.x = 0; p.vx = Math.abs(p.vx); }
        else if (p.x > c.width) { p.x = c.width; p.vx = -Math.abs(p.vx); }
        if (p.y < 0) { p.y = 0; p.vy = Math.abs(p.vy); }
        else if (p.y > c.height) { p.y = c.height; p.vy = -Math.abs(p.vy); }

        ctx.beginPath();
        ctx.arc(p.x, p.y, 2, 0, Math.PI * 2);
        ctx.fill();

        for (var j = i + 1; j < pts.length; j++) {
          var q = pts[j];
          var dx = p.x - q.x;
          var dy = p.y - q.y;
          var distanceSq = dx * dx + dy * dy;
          if (distanceSq < maxDistSq) {
            var ratio = 1 - Math.sqrt(distanceSq) / maxDist;
            ctx.strokeStyle = 'rgba(120,160,255,' + (ratio * opacity * 0.45) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(q.x, q.y);
            ctx.stroke();
          }
        }
      }
    });
  }

  function initCursor() {
    var e = effects.cursor || {};
    var size = Math.max(4, Math.min(40, int(e.size, 13)));
    var density = Math.max(1, Math.min(5, int(e.density, 1)));
    var symbol = String(e.symbol || '✦').slice(0, 2);
    var last = 0;

    document.addEventListener('mousemove', function (ev) {
      var now = Date.now();
      if (now - last < 26 / density) return;
      last = now;

      var s = document.createElement('span');
      s.className = 'xjpe-star-trail';
      s.textContent = symbol;
      s.style.left = ev.clientX + 'px';
      s.style.top = ev.clientY + 'px';
      s.style.zIndex = String(z + 5);
      s.style.fontSize = size + 'px';
      s.style.color = 'hsl(' + Math.floor(Math.random() * 360) + ' 88% 62%)';
      document.body.appendChild(s);

      var dx = -18 + Math.random() * 36;
      var dy = -24 - Math.random() * 18;
      if (s.animate) {
        var animation = s.animate([
          { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
          { transform: 'translate3d(' + dx + 'px,' + dy + 'px,0) scale(.2)', opacity: 0 }
        ], { duration: 760, easing: 'ease-out' });
        animation.onfinish = function () {
          if (s.parentNode) s.remove();
        };
      } else {
        window.setTimeout(function () {
          if (s.parentNode) s.remove();
        }, 760);
      }
    }, { passive: true });
  }

  function initRibbon() {
    var e = effects.ribbon || {};
    var opacity = Math.max(0.05, Math.min(1, num(e.opacity, 0.42)));
    var c = canvasLayer('xjpe-canvas xjpe-ribbon-canvas', -1);
    var ctx = c.getContext('2d');
    if (!ctx) return;

    function draw() {
      ctx.clearRect(0, 0, c.width, c.height);
      for (var i = 0; i < 24; i++) {
        var x = Math.random() * c.width;
        var y = Math.random() * c.height;
        var w = 80 + Math.random() * 240;
        var h = 20 + Math.random() * 90;
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(Math.random() * Math.PI);
        ctx.fillStyle = 'hsla(' + Math.floor(Math.random() * 360) + ', 85%, 65%, ' + opacity + ')';
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(w, h * 0.2);
        ctx.lineTo(w * 0.75, h);
        ctx.lineTo(-w * 0.1, h * 0.75);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
      }
    }

    draw();
    window.addEventListener('resize', draw, { passive: true });
    if (e.click) document.addEventListener('click', draw);
  }

  function initGrayscale() {
    var percent = Math.max(1, Math.min(100, int((effects.grayscale || {}).percent, 100)));
    document.documentElement.style.setProperty('--xjpe-grayscale', percent + '%');
    document.documentElement.classList.add('xjpe-grayscale');
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      try {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        var copied = document.execCommand('copy');
        textarea.remove();
        copied ? resolve() : reject(new Error('copy failed'));
      } catch (e) {
        reject(e);
      }
    });
  }

  function initContextMenu() {
    if (enabled('nosource')) return;
    var e = effects.contextmenu || {};
    var m = document.createElement('div');
    m.className = 'xjpe-context-menu';
    m.innerHTML = '<div class="xjpe-context-title"></div><div class="xjpe-context-items"></div>';
    m.querySelector('.xjpe-context-title').textContent = e.title || '九流网站菜单';
    m.style.zIndex = '2147483000';
    document.body.appendChild(m);

    var box = m.querySelector('.xjpe-context-items');
    var seen = {};

    function add(label, action, key) {
      key = String(key || label).toLowerCase();
      if (seen[key]) return;
      seen[key] = true;
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.addEventListener('click', function () {
        m.style.display = 'none';
        action();
      });
      box.appendChild(b);
    }

    function copyCurrentLink() {
      copyText(location.href).then(function () {
        toast('链接已复制');
      }).catch(function () {
        toast('复制失败，请手动复制地址栏链接');
      });
    }

    if (e.show_copy) add('复制当前链接', copyCurrentLink, '#copy');
    if (e.show_refresh) add('刷新页面', function () { location.reload(); }, '#refresh');
    if (e.show_top) add('返回顶部', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); }, '#top');
    if (e.show_back) add('返回上一页', function () { history.back(); }, '#back');

    String(e.custom_items || '').split(/\r?\n/).forEach(function (line) {
      var parts = line.split('|');
      if (parts.length < 2) return;
      var label = parts[0].trim();
      var url = parts.slice(1).join('|').trim();
      if (!label || !url) return;

      add(label, function () {
        if (url === '#top') window.scrollTo({ top: 0, behavior: 'smooth' });
        else if (url === '#refresh') location.reload();
        else if (url === '#back') history.back();
        else if (url === '#copy') copyCurrentLink();
        else location.href = url;
      }, url);
    });

    document.addEventListener('contextmenu', function (ev) {
      ev.preventDefault();
      m.style.display = 'block';
      var x = Math.min(ev.clientX, window.innerWidth - m.offsetWidth - 10);
      var y = Math.min(ev.clientY, window.innerHeight - m.offsetHeight - 10);
      m.style.left = Math.max(8, x) + 'px';
      m.style.top = Math.max(8, y) + 'px';
    });
    document.addEventListener('click', function () {
      m.style.display = 'none';
    });
    window.addEventListener('blur', function () {
      m.style.display = 'none';
    });
  }

  function initNoSource() {
    var message = (effects.nosource || {}).message || '本站已开启基础防复制保护。';
    document.addEventListener('contextmenu', function (ev) {
      ev.preventDefault();
      toast(message);
    });
    document.addEventListener('keydown', function (ev) {
      var k = String(ev.key || '').toLowerCase();
      var blocked = k === 'f12' ||
        (ev.ctrlKey && ['u', 's', 'p'].indexOf(k) >= 0) ||
        (ev.ctrlKey && ev.shiftKey && ['i', 'j', 'c', 'k'].indexOf(k) >= 0);
      if (blocked) {
        ev.preventDefault();
        ev.stopPropagation();
        toast(message);
      }
    }, true);
  }

  function initMusic() {
    var e = effects.bgmusic || {};
    if (!e.url) return;

    var audio = new Audio(e.url);
    audio.loop = !!e.loop;
    audio.volume = Math.max(0, Math.min(1, num(e.volume, 0.35)));
    audio.preload = 'metadata';

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'xjpe-music-btn';
    b.title = e.title || '背景音乐';
    b.setAttribute('aria-label', e.title || '背景音乐');
    b.textContent = '🎵';
    b.style.zIndex = String(z + 10);
    document.body.appendChild(b);

    function play() {
      var result;
      try {
        result = audio.play();
      } catch (error) {
        return Promise.reject(error);
      }
      return Promise.resolve(result).then(function () {
        b.classList.add('is-playing');
      });
    }

    function pause() {
      audio.pause();
      b.classList.remove('is-playing');
    }

    b.addEventListener('click', function () {
      if (audio.paused) {
        play().catch(function () {
          toast('浏览器阻止了播放，请再次点击音乐按钮');
        });
      } else {
        pause();
      }
    });

    audio.addEventListener('ended', function () {
      if (!audio.loop) b.classList.remove('is-playing');
    });
    audio.addEventListener('pause', function () {
      b.classList.remove('is-playing');
    });
    audio.addEventListener('error', function () {
      b.classList.remove('is-playing');
      b.title = '背景音乐加载失败';
    });

    if (e.autoplay) {
      var events = ['click', 'touchstart', 'keydown'];
      var attempt = function () {
        play().then(function () {
          events.forEach(function (name) {
            document.removeEventListener(name, attempt);
          });
        }).catch(function () {
          // 保留监听器，等待下一次真实用户交互再次尝试。
        });
      };
      events.forEach(function (name) {
        document.addEventListener(name, attempt, { passive: name === 'touchstart' });
      });
    }
  }

  function pad2(value) {
    return value < 10 ? '0' + value : String(value);
  }

  function localDateKey(date) {
    return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
  }

  function festivalGreeting(date) {
    var key = pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    var festivals = {
      '01-01': ['元旦快乐', '新的一年，愿你平安顺遂，所愿皆所得。'],
      '02-14': ['情人节快乐', '愿今天有爱、有花，也有值得珍惜的温柔。'],
      '03-08': ['妇女节快乐', '愿每一位女性都被尊重、被看见，自信而闪耀。'],
      '03-12': ['植树节快乐', '种下一点绿色，也种下一份对未来的期待。'],
      '05-01': ['劳动节快乐', '致敬每一份认真付出，愿劳动也带来收获与快乐。'],
      '06-01': ['儿童节快乐', '愿你永远保留好奇心，也保留心里的童真。'],
      '10-01': ['国庆节快乐', '祝愿祖国繁荣昌盛，也祝你假期平安愉快。'],
      '10-31': ['万圣夜快乐', '愿今晚有一点神秘、有一点惊喜，还有很多快乐。'],
      '12-24': ['平安夜快乐', '愿今夜平安温暖，身边有牵挂，也有陪伴。'],
      '12-25': ['圣诞快乐', '愿节日的灯光照亮好心情，祝你喜乐安康。']
    };
    return festivals[key] || null;
  }

  function storageGet(key) {
    try {
      return window.localStorage ? localStorage.getItem(key) : null;
    } catch (e) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      if (window.localStorage) localStorage.setItem(key, value);
    } catch (e) {
      // 禁用存储时仅退化为每次显示，不中断其他特效。
    }
  }

  function initWelcome() {
    var e = effects.welcome || {};
    var now = new Date();
    var greeting = e.auto_festival ? festivalGreeting(now) : null;
    var title = greeting ? greeting[0] : (e.title || '欢迎访问');
    var message = greeting ? greeting[1] : (e.message || '欢迎来到我的网站。');
    var key = 'xjpe_welcome_' + localDateKey(now) + '_' + (greeting ? pad2(now.getMonth() + 1) + pad2(now.getDate()) : 'default');

    if (e.once_per_day && !global.preview && storageGet(key)) return;

    var mask = document.createElement('div');
    mask.className = 'xjpe-welcome-mask';
    mask.innerHTML = '<div class="xjpe-welcome-box" role="dialog" aria-modal="true" aria-labelledby="xjpe-welcome-title"><h3 id="xjpe-welcome-title"></h3><p></p><button type="button">知道了</button></div>';
    mask.querySelector('h3').textContent = title;
    mask.querySelector('p').textContent = message;

    function onKeydown(ev) {
      if (ev.key === 'Escape') close();
    }

    function close() {
      document.removeEventListener('keydown', onKeydown);
      if (mask.parentNode) mask.remove();
    }

    mask.querySelector('button').addEventListener('click', close);
    mask.addEventListener('click', function (ev) {
      if (ev.target === mask) close();
    });
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(mask);
    if (e.once_per_day && !global.preview) storageSet(key, '1');
  }

  function safeInit(name, fn) {
    try {
      fn();
    } catch (error) {
      if (window.console && console.warn) console.warn('XJPE ' + name + ' init error:', error);
    }
  }

  function initialize() {
    if (initialized || !document.body) return;
    initialized = true;

    var reduceMotion = !!(
      global.respectReduceMotion &&
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );

    if (reduceMotion) {
      document.documentElement.classList.add('xjpe-respect-motion');
    } else {
      if (enabled('particles')) safeInit('particles', initParticles);
      if (enabled('ribbon')) safeInit('ribbon', initRibbon);
      if (enabled('sakura')) safeInit('sakura', function () { falling('sakura', '🌸', 'xjpe-petal', { count: 28, size: 18, speed: 1, opacity: 0.85 }); });
      if (enabled('snow')) safeInit('snow', function () { falling('snow', '❄', 'xjpe-snowflake', { count: 48, size: 13, speed: 1, opacity: 0.9 }); });
      if (enabled('lantern')) safeInit('lantern', initLantern);
      if (enabled('cursor')) safeInit('cursor', initCursor);
    }

    if (enabled('grayscale')) safeInit('grayscale', initGrayscale);
    if (enabled('contextmenu')) safeInit('contextmenu', initContextMenu);
    if (enabled('nosource')) safeInit('nosource', initNoSource);
    if (enabled('bgmusic')) safeInit('bgmusic', initMusic);
    if (enabled('welcome')) safeInit('welcome', initWelcome);
    emitReady(false);
  }

  startWhenBodyExists(initialize);
})();
