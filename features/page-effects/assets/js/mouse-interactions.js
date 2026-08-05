/** 九流WP助手：鼠标交互中心 */
(function () {
  'use strict';

  var cfg = window.JLWA_MOUSE || {};
  var shapeCfg = cfg.cursor_shape || {};
  var trailCfg = cfg.trail || {};
  var burstCfg = cfg.click_burst || {};
  var isTouchOnly = !!(window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches);
  var activeTrail = [];
  var activeBurst = [];

  function num(value, fallback) {
    var parsed = parseFloat(value);
    return isFinite(parsed) ? parsed : fallback;
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function enabled(value) {
    return value === true || value === 1 || value === '1';
  }

  function randomColor() {
    return 'hsl(' + Math.floor(Math.random() * 360) + ' 88% 62%)';
  }

  function svgData(svg) {
    return 'data:image/svg+xml,' + encodeURIComponent(svg.replace(/\s+/g, ' ').trim());
  }

  function cursorSvgs(preset, size, pointer) {
    var s = clamp(Math.round(size), 20, 48);
    var base = '';
    var hotX = pointer ? Math.round(s * 0.48) : 3;
    var hotY = pointer ? Math.round(s * 0.2) : 3;
    var ring = pointer ? '<circle cx="' + (s * .5) + '" cy="' + (s * .5) + '" r="' + (s * .42) + '" fill="none" stroke="#ffffff" stroke-width="2" opacity=".9"/>' : '';

    if (preset === 'web_hero') {
      hotX = Math.round(s * .5); hotY = Math.round(s * .5);
      base = '<circle cx="' + (s/2) + '" cy="' + (s/2) + '" r="' + (s*.43) + '" fill="#e11d48" stroke="#111827" stroke-width="2"/>' +
        '<path d="M' + (s*.18) + ' ' + (s*.5) + 'H' + (s*.82) + 'M' + (s*.5) + ' ' + (s*.18) + 'V' + (s*.82) + 'M' + (s*.28) + ' ' + (s*.28) + 'L' + (s*.72) + ' ' + (s*.72) + 'M' + (s*.72) + ' ' + (s*.28) + 'L' + (s*.28) + ' ' + (s*.72) + '" stroke="#f8fafc" stroke-width="1.4"/>' +
        '<ellipse cx="' + (s*.37) + '" cy="' + (s*.43) + '" rx="' + (s*.08) + '" ry="' + (s*.15) + '" fill="#f8fafc"/><ellipse cx="' + (s*.63) + '" cy="' + (s*.43) + '" rx="' + (s*.08) + '" ry="' + (s*.15) + '" fill="#f8fafc"/>' + ring;
    } else if (preset === 'pixel_plumber') {
      hotX = Math.round(s * .5); hotY = Math.round(s * .28);
      base = '<rect x="' + (s*.18) + '" y="' + (s*.22) + '" width="' + (s*.64) + '" height="' + (s*.18) + '" rx="2" fill="#dc2626"/>' +
        '<rect x="' + (s*.28) + '" y="' + (s*.12) + '" width="' + (s*.44) + '" height="' + (s*.18) + '" rx="2" fill="#ef4444"/>' +
        '<rect x="' + (s*.24) + '" y="' + (s*.4) + '" width="' + (s*.52) + '" height="' + (s*.36) + '" rx="5" fill="#f4b183" stroke="#713f12" stroke-width="1.5"/>' +
        '<rect x="' + (s*.22) + '" y="' + (s*.7) + '" width="' + (s*.56) + '" height="' + (s*.18) + '" rx="3" fill="#2563eb"/><circle cx="' + (s*.38) + '" cy="' + (s*.52) + '" r="2" fill="#111827"/><circle cx="' + (s*.62) + '" cy="' + (s*.52) + '" r="2" fill="#111827"/>' + ring;
    } else if (preset === 'cat_paw') {
      hotX = Math.round(s * .5); hotY = Math.round(s * .6);
      base = '<ellipse cx="' + (s*.5) + '" cy="' + (s*.62) + '" rx="' + (s*.22) + '" ry="' + (s*.2) + '" fill="#fb7185" stroke="#7c2d12" stroke-width="1.5"/>' +
        '<circle cx="' + (s*.27) + '" cy="' + (s*.35) + '" r="' + (s*.09) + '" fill="#fda4af"/><circle cx="' + (s*.43) + '" cy="' + (s*.25) + '" r="' + (s*.09) + '" fill="#fda4af"/><circle cx="' + (s*.59) + '" cy="' + (s*.25) + '" r="' + (s*.09) + '" fill="#fda4af"/><circle cx="' + (s*.75) + '" cy="' + (s*.35) + '" r="' + (s*.09) + '" fill="#fda4af"/>' + ring;
    } else if (preset === 'magic_wand') {
      hotX = Math.round(s * .18); hotY = Math.round(s * .82);
      base = '<path d="M' + (s*.18) + ' ' + (s*.82) + 'L' + (s*.74) + ' ' + (s*.26) + '" stroke="#7c3aed" stroke-width="' + (s*.12) + '" stroke-linecap="round"/>' +
        '<path d="M' + (s*.78) + ' ' + (s*.08) + 'l' + (s*.06) + ' ' + (s*.14) + ' ' + (s*.15) + ' ' + (s*.02) + '-' + (s*.12) + ' ' + (s*.1) + ' ' + (s*.04) + ' ' + (s*.15) + '-' + (s*.13) + '-' + (s*.08) + '-' + (s*.13) + ' ' + (s*.08) + ' ' + (s*.04) + '-' + (s*.15) + '-' + (s*.12) + '-' + (s*.1) + ' ' + (s*.15) + '-' + (s*.02) + 'z" fill="#facc15"/>' + ring;
    } else if (preset === 'rocket') {
      hotX = Math.round(s * .2); hotY = Math.round(s * .8);
      base = '<path d="M' + (s*.22) + ' ' + (s*.76) + 'C' + (s*.28) + ' ' + (s*.42) + ' ' + (s*.52) + ' ' + (s*.18) + ' ' + (s*.82) + ' ' + (s*.12) + 'C' + (s*.78) + ' ' + (s*.44) + ' ' + (s*.55) + ' ' + (s*.7) + ' ' + (s*.22) + ' ' + (s*.76) + 'Z" fill="#e2e8f0" stroke="#334155" stroke-width="1.5"/>' +
        '<circle cx="' + (s*.62) + '" cy="' + (s*.34) + '" r="' + (s*.09) + '" fill="#38bdf8"/><path d="M' + (s*.26) + ' ' + (s*.7) + 'L' + (s*.08) + ' ' + (s*.92) + 'L' + (s*.3) + ' ' + (s*.78) + 'Z" fill="#fb923c"/>' + ring;
    } else if (preset === 'ghost') {
      hotX = Math.round(s * .5); hotY = Math.round(s * .5);
      base = '<path d="M' + (s*.2) + ' ' + (s*.78) + 'V' + (s*.42) + 'A' + (s*.3) + ' ' + (s*.3) + ' 0 0 1 ' + (s*.8) + ' ' + (s*.42) + 'V' + (s*.82) + 'L' + (s*.67) + ' ' + (s*.72) + ' ' + (s*.55) + ' ' + (s*.84) + ' ' + (s*.43) + ' ' + (s*.72) + ' ' + (s*.31) + ' ' + (s*.84) + 'Z" fill="#f8fafc" stroke="#64748b" stroke-width="1.5"/><circle cx="' + (s*.4) + '" cy="' + (s*.45) + '" r="2" fill="#0f172a"/><circle cx="' + (s*.6) + '" cy="' + (s*.45) + '" r="2" fill="#0f172a"/>' + ring;
    } else if (preset === 'rainbow') {
      hotX = 4; hotY = 4;
      base = '<path d="M3 3L' + (s*.76) + ' ' + (s*.58) + 'L' + (s*.48) + ' ' + (s*.62) + 'L' + (s*.62) + ' ' + (s*.88) + 'L' + (s*.5) + ' ' + (s*.94) + 'L' + (s*.36) + ' ' + (s*.67) + 'L' + (s*.18) + ' ' + (s*.86) + 'Z" fill="url(#g)" stroke="#fff" stroke-width="1.5"/><defs><linearGradient id="g"><stop stop-color="#ef4444"/><stop offset=".25" stop-color="#f59e0b"/><stop offset=".5" stop-color="#22c55e"/><stop offset=".75" stop-color="#3b82f6"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs>' + ring;
    } else {
      hotX = 3; hotY = 3;
      base = '<path d="M3 3L' + (s*.76) + ' ' + (s*.58) + 'L' + (s*.48) + ' ' + (s*.62) + 'L' + (s*.62) + ' ' + (s*.88) + 'L' + (s*.5) + ' ' + (s*.94) + 'L' + (s*.36) + ' ' + (s*.67) + 'L' + (s*.18) + ' ' + (s*.86) + 'Z" fill="#38bdf8" stroke="#ffffff" stroke-width="2" filter="url(#f)"/><defs><filter id="f"><feGaussianBlur stdDeviation="1" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>' + ring;
    }

    return {
      url: svgData('<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 ' + s + ' ' + s + '">' + base + '</svg>'),
      x: hotX,
      y: hotY
    };
  }

  function applyCursorShape() {
    if (!enabled(shapeCfg.enabled) || isTouchOnly) return;
    var preset = String(shapeCfg.preset || 'neon_arrow');
    var size = clamp(num(shapeCfg.size, 32), 20, 48);
    var normal = cursorSvgs(preset, size, false);
    var pointer = enabled(shapeCfg.link_variant) ? cursorSvgs(preset, size, true) : normal;
    var style = document.createElement('style');
    style.id = 'jlwa-custom-cursor-style';
    style.textContent = 'html,body,body *{cursor:url("' + normal.url + '") ' + normal.x + ' ' + normal.y + ',auto!important}' +
      'a,button,[role="button"],input[type="button"],input[type="submit"],input[type="reset"],label,select,summary,.clickable{cursor:url("' + pointer.url + '") ' + pointer.x + ' ' + pointer.y + ',pointer!important}' +
      'input[type="text"],input[type="search"],input[type="email"],input[type="url"],input[type="password"],textarea,[contenteditable="true"]{cursor:text!important}';
    (document.head || document.documentElement).appendChild(style);
  }

  function createParticle(className, text, x, y, size, color, zIndex) {
    var el = document.createElement('span');
    el.className = className;
    el.textContent = text;
    el.style.left = x + 'px';
    el.style.top = y + 'px';
    el.style.fontSize = size + 'px';
    el.style.color = color;
    el.style.zIndex = String(zIndex || 2147482000);
    document.body.appendChild(el);
    return el;
  }

  function removeFrom(list, el) {
    var index = list.indexOf(el);
    if (index >= 0) list.splice(index, 1);
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function trimActive(list, max) {
    while (list.length > max) removeFrom(list, list[0]);
  }

  function trailSymbols(preset, custom) {
    var map = {
      star: ['✦', '✧', '★'], heart: ['❤', '♡'], firefly: ['•', '✦'], petal: ['🌸', '❀'], bubble: ['○', '◌'],
      comet: ['☄', '✦'], snow: ['❄', '❅'], music: ['♪', '♫', '♬'], rainbow: ['◆', '✦', '●'], pixel: ['■', '▪'], paw: ['🐾'], web: ['✣', '⊛']
    };
    return preset === 'custom' ? [custom || '✦'] : (map[preset] || map.star);
  }

  function initTrail() {
    if (!enabled(trailCfg.enabled) || isTouchOnly) return;
    var preset = String(trailCfg.preset || 'star');
    var list = trailSymbols(preset, String(trailCfg.symbol || '✦').slice(0, 4));
    var density = clamp(num(trailCfg.density, 2), 1, 8);
    var size = clamp(num(trailCfg.size, 14), 6, 48);
    var duration = clamp(num(trailCfg.duration, 760), 200, 2400);
    var rainbow = String(trailCfg.color_mode || 'rainbow') !== 'fixed';
    var fixed = String(trailCfg.color || '#ff5ba7');
    var last = 0;

    document.addEventListener('pointermove', function (ev) {
      if (ev.pointerType && ev.pointerType !== 'mouse' && ev.pointerType !== 'pen') return;
      var now = performance.now ? performance.now() : Date.now();
      if (now - last < 38 / density) return;
      last = now;
      var symbol = list[Math.floor(Math.random() * list.length)];
      var color = rainbow ? randomColor() : fixed;
      var el = createParticle('jlwa-mouse-particle jlwa-trail jlwa-trail-' + preset, symbol, ev.clientX, ev.clientY, size * (0.72 + Math.random() * 0.55), color);
      activeTrail.push(el);
      trimActive(activeTrail, 120);
      var dx = -24 + Math.random() * 48;
      var dy = preset === 'bubble' ? -18 - Math.random() * 54 : -20 - Math.random() * 34;
      var rotate = -120 + Math.random() * 240;
      var frames = [
        { transform: 'translate3d(-50%,-50%,0) scale(1)', opacity: 1 },
        { transform: 'translate3d(calc(-50% + ' + dx + 'px),calc(-50% + ' + dy + 'px),0) scale(.12) rotate(' + rotate + 'deg)', opacity: 0 }
      ];
      if (preset === 'comet') frames[0].filter = 'drop-shadow(0 0 7px currentColor)';
      if (el.animate) {
        var animation = el.animate(frames, { duration: duration, easing: 'cubic-bezier(.15,.72,.25,1)' });
        animation.onfinish = function () { removeFrom(activeTrail, el); };
      } else {
        setTimeout(function () { removeFrom(activeTrail, el); }, duration);
      }
    }, { passive: true });
  }

  function burstSymbols(preset, custom) {
    var map = {
      stars: ['✦', '✧', '★'], hearts: ['❤', '♡'], sparks: ['✦', '·', '×'], petals: ['🌸', '❀'], snow: ['❄', '❅'],
      confetti: ['■', '▲', '●'], bubbles: ['○', '◌'], music: ['♪', '♫'], paw: ['🐾'], web: ['✣', '⊛'], pixel: ['■', '▪']
    };
    return preset === 'custom' ? [custom || '✦'] : (map[preset] || map.stars);
  }

  function initClickBurst() {
    if (!enabled(burstCfg.enabled)) return;
    if (isTouchOnly && !enabled(burstCfg.mobile)) return;
    var preset = String(burstCfg.preset || 'stars');
    var count = clamp(num(burstCfg.count, 14), 4, 36);
    var size = clamp(num(burstCfg.size, 18), 8, 44);
    var spread = clamp(num(burstCfg.spread, 92), 24, 180);
    var duration = clamp(num(burstCfg.duration, 780), 280, 1800);
    var gravity = clamp(num(burstCfg.gravity, 0.55), -1.5, 2.5);
    var rainbow = String(burstCfg.color_mode || 'rainbow') !== 'fixed';
    var fixed = String(burstCfg.color || '#ffd166');
    var symbols = burstSymbols(preset, String(burstCfg.symbol || '✦').slice(0, 4));

    document.addEventListener('pointerdown', function (ev) {
      if (ev.button !== undefined && ev.button !== 0) return;
      if (preset === 'ripple') {
        var ripple = document.createElement('span');
        ripple.className = 'jlwa-click-ripple';
        ripple.style.left = ev.clientX + 'px';
        ripple.style.top = ev.clientY + 'px';
        ripple.style.width = spread + 'px';
        ripple.style.height = spread + 'px';
        ripple.style.borderColor = rainbow ? randomColor() : fixed;
        document.body.appendChild(ripple);
        activeBurst.push(ripple);
        trimActive(activeBurst, 180);
        if (ripple.animate) {
          var ra = ripple.animate([
            { transform: 'translate3d(-50%,-50%,0) scale(.08)', opacity: .95 },
            { transform: 'translate3d(-50%,-50%,0) scale(1)', opacity: 0 }
          ], { duration: duration, easing: 'cubic-bezier(.1,.7,.2,1)' });
          ra.onfinish = function () { removeFrom(activeBurst, ripple); };
        } else setTimeout(function () { removeFrom(activeBurst, ripple); }, duration);
        return;
      }

      for (var i = 0; i < count; i++) {
        (function (index) {
          var angle = (Math.PI * 2 * index / count) + (-0.22 + Math.random() * 0.44);
          var distance = spread * (0.46 + Math.random() * 0.62);
          var dx = Math.cos(angle) * distance;
          var dy = Math.sin(angle) * distance;
          var fall = gravity * spread * (0.26 + Math.random() * 0.42);
          var symbol = symbols[Math.floor(Math.random() * symbols.length)];
          var color = rainbow ? randomColor() : fixed;
          var el = createParticle('jlwa-mouse-particle jlwa-click-particle jlwa-burst-' + preset, symbol, ev.clientX, ev.clientY, size * (0.7 + Math.random() * 0.7), color);
          activeBurst.push(el);
          trimActive(activeBurst, 180);
          var rotate = -220 + Math.random() * 440;
          var frames = [
            { transform: 'translate3d(-50%,-50%,0) scale(.45)', opacity: 1 },
            { transform: 'translate3d(calc(-50% + ' + (dx * .72) + 'px),calc(-50% + ' + (dy * .72) + 'px),0) scale(1.05) rotate(' + (rotate * .6) + 'deg)', opacity: .92, offset: .58 },
            { transform: 'translate3d(calc(-50% + ' + dx + 'px),calc(-50% + ' + (dy + fall) + 'px),0) scale(.12) rotate(' + rotate + 'deg)', opacity: 0 }
          ];
          if (el.animate) {
            var animation = el.animate(frames, { duration: duration * (0.84 + Math.random() * 0.32), easing: 'cubic-bezier(.12,.72,.2,1)' });
            animation.onfinish = function () { removeFrom(activeBurst, el); };
          } else setTimeout(function () { removeFrom(activeBurst, el); }, duration + 120);
        })(i);
      }
    }, { passive: true });
  }

  function init() {
    applyCursorShape();
    initTrail();
    initClickBurst();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
