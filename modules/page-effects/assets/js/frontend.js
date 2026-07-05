(function () {
  'use strict';

  var cfg = window.XJPE_CONFIG || null;
  if (!cfg || !cfg.effects) return;

  var global = cfg.global || {};
  var effects = cfg.effects || {};
  var z = parseInt(global.zIndex || 999999, 10);
  var timers = [];
  var rafs = [];

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
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
      c.width = window.innerWidth;
      c.height = window.innerHeight;
    }
    resize();
    window.addEventListener('resize', resize);
    return c;
  }

  function toast(message) {
    var old = document.querySelector('.xjpe-toast');
    if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'xjpe-toast';
    t.textContent = message || '操作已拦截';
    t.style.zIndex = '2147483000';
    document.body.appendChild(t);
    window.setTimeout(function () { if (t.parentNode) t.remove(); }, 1800);
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

    function tick() {
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
      rafs.push(requestAnimationFrame(tick));
    }
    tick();
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
    var count = Math.max(8, Math.min(220, int(e.count, 70)));
    var speed = Math.max(0.03, num(e.speed, 0.7));
    var opacity = Math.max(0.05, Math.min(1, num(e.opacity, 0.55)));
    var maxDist = Math.max(40, Math.min(320, int(e.line_distance, 130)));
    var c = canvasLayer('xjpe-canvas xjpe-particles-canvas', 0);
    var ctx = c.getContext('2d');
    var pts = [];
    for (var i = 0; i < count; i++) {
      pts.push({ x: Math.random() * c.width, y: Math.random() * c.height, vx: (-0.6 + Math.random() * 1.2) * speed, vy: (-0.6 + Math.random() * 1.2) * speed });
    }
    function tick() {
      ctx.clearRect(0, 0, c.width, c.height);
      ctx.fillStyle = 'rgba(120,160,255,' + opacity + ')';
      for (var i = 0; i < pts.length; i++) {
        var p = pts[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > c.width) p.vx *= -1;
        if (p.y < 0 || p.y > c.height) p.vy *= -1;
        ctx.beginPath(); ctx.arc(p.x, p.y, 2, 0, Math.PI * 2); ctx.fill();
        for (var j = i + 1; j < pts.length; j++) {
          var q = pts[j];
          var dx = p.x - q.x, dy = p.y - q.y, d = Math.sqrt(dx * dx + dy * dy);
          if (d < maxDist) {
            ctx.strokeStyle = 'rgba(120,160,255,' + ((1 - d / maxDist) * opacity * 0.45) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
      }
      rafs.push(requestAnimationFrame(tick));
    }
    tick();
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
      var dx = (-18 + Math.random() * 36), dy = (-24 - Math.random() * 18);
      s.animate([
        { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
        { transform: 'translate3d(' + dx + 'px,' + dy + 'px,0) scale(.2)', opacity: 0 }
      ], { duration: 760, easing: 'ease-out' }).onfinish = function () { if (s.parentNode) s.remove(); };
    });
  }

  function initRibbon() {
    var e = effects.ribbon || {};
    var opacity = Math.max(0.05, Math.min(1, num(e.opacity, 0.42)));
    var c = canvasLayer('xjpe-canvas xjpe-ribbon-canvas', -1);
    var ctx = c.getContext('2d');
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
        ctx.moveTo(0, 0); ctx.lineTo(w, h * .2); ctx.lineTo(w * .75, h); ctx.lineTo(-w * .1, h * .75); ctx.closePath();
        ctx.fill();
        ctx.restore();
      }
    }
    draw();
    window.addEventListener('resize', draw);
    if (e.click) document.addEventListener('click', draw);
  }

  function initGrayscale() {
    var percent = Math.max(1, Math.min(100, int((effects.grayscale || {}).percent, 100)));
    document.documentElement.style.setProperty('--xjpe-grayscale', percent + '%');
    document.documentElement.classList.add('xjpe-grayscale');
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
    function add(label, action) {
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = label; b.addEventListener('click', function(){ m.style.display='none'; action(); });
      box.appendChild(b);
    }
    if (e.show_copy) add('复制当前链接', function(){ navigator.clipboard && navigator.clipboard.writeText(location.href); toast('链接已复制'); });
    if (e.show_refresh) add('刷新页面', function(){ location.reload(); });
    if (e.show_top) add('返回顶部', function(){ window.scrollTo({ top: 0, behavior: 'smooth' }); });
    if (e.show_back) add('返回上一页', function(){ history.back(); });
    String(e.custom_items || '').split(/\r?\n/).forEach(function(line){
      var parts = line.split('|');
      if (parts.length < 2) return;
      var label = parts[0].trim(), url = parts.slice(1).join('|').trim();
      if (!label || !url) return;
      add(label, function(){
        if (url === '#top') window.scrollTo({ top: 0, behavior: 'smooth' });
        else if (url === '#refresh') location.reload();
        else if (url === '#back') history.back();
        else if (url === '#copy') { navigator.clipboard && navigator.clipboard.writeText(location.href); toast('链接已复制'); }
        else location.href = url;
      });
    });
    document.addEventListener('contextmenu', function(ev){
      ev.preventDefault();
      m.style.display = 'block';
      var x = Math.min(ev.clientX, window.innerWidth - m.offsetWidth - 10);
      var y = Math.min(ev.clientY, window.innerHeight - m.offsetHeight - 10);
      m.style.left = Math.max(8, x) + 'px'; m.style.top = Math.max(8, y) + 'px';
    });
    document.addEventListener('click', function(){ m.style.display = 'none'; });
  }

  function initNoSource() {
    var message = (effects.nosource || {}).message || '本站已开启基础防复制保护。';
    document.addEventListener('contextmenu', function(ev){ ev.preventDefault(); toast(message); });
    document.addEventListener('keydown', function(ev){
      var k = String(ev.key || '').toLowerCase();
      var blocked = k === 'f12' || (ev.ctrlKey && ['u','s','p'].indexOf(k) >= 0) || (ev.ctrlKey && ev.shiftKey && ['i','j','c'].indexOf(k) >= 0);
      if (blocked) { ev.preventDefault(); ev.stopPropagation(); toast(message); }
    }, true);
  }

  function initMusic() {
    var e = effects.bgmusic || {};
    if (!e.url) return;
    var audio = new Audio(e.url);
    audio.loop = !!e.loop;
    audio.volume = Math.max(0, Math.min(1, num(e.volume, 0.35)));
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'xjpe-music-btn'; b.title = e.title || '背景音乐'; b.textContent = '🎵'; b.style.zIndex = String(z + 10);
    document.body.appendChild(b);
    function play(){ audio.play().then(function(){ b.classList.add('is-playing'); }).catch(function(){}); }
    function pause(){ audio.pause(); b.classList.remove('is-playing'); }
    b.addEventListener('click', function(){ audio.paused ? play() : pause(); });
    if (e.autoplay) {
      var once = function(){ play(); document.removeEventListener('click', once); document.removeEventListener('touchstart', once); };
      document.addEventListener('click', once); document.addEventListener('touchstart', once);
    }
  }

  function initWelcome() {
    var e = effects.welcome || {};
    var key = 'xjpe_welcome_' + (new Date()).toISOString().slice(0,10);
    if (e.once_per_day && !global.preview && localStorage.getItem(key)) return;
    if (e.once_per_day && !global.preview) localStorage.setItem(key, '1');
    var mask = document.createElement('div');
    mask.className = 'xjpe-welcome-mask';
    mask.innerHTML = '<div class="xjpe-welcome-box"><h3></h3><p></p><button type="button">知道了</button></div>';
    mask.querySelector('h3').textContent = e.title || '欢迎访问';
    mask.querySelector('p').textContent = e.message || '欢迎来到我的网站。';
    mask.querySelector('button').addEventListener('click', function(){ mask.remove(); });
    mask.addEventListener('click', function(ev){ if (ev.target === mask) mask.remove(); });
    document.body.appendChild(mask);
  }

  ready(function () {
    if (!document.body) return;
    if (global.respectReduceMotion && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      document.documentElement.classList.add('xjpe-respect-motion');
      return;
    }
    if (enabled('particles')) initParticles();
    if (enabled('ribbon')) initRibbon();
    if (enabled('sakura')) falling('sakura', '🌸', 'xjpe-petal', { count: 28, size: 18, speed: 1, opacity: 0.85 });
    if (enabled('snow')) falling('snow', '❄', 'xjpe-snowflake', { count: 48, size: 13, speed: 1, opacity: 0.9 });
    if (enabled('lantern')) initLantern();
    if (enabled('cursor')) initCursor();
    if (enabled('grayscale')) initGrayscale();
    if (enabled('contextmenu')) initContextMenu();
    if (enabled('nosource')) initNoSource();
    if (enabled('bgmusic')) initMusic();
    if (enabled('welcome')) initWelcome();
    window.XJPE_READY = true;
  });
})();
