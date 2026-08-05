/** 九流WP助手：AI 摘要动画可靠播放与文字可见性兜底 */
(function () {
  'use strict';
  var S = '.wpaias-summary';

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, { once: true });
    else fn();
  }

  function textOf(box) { return box && box.querySelector('.wpaias-summary__text'); }
  function reduceMotion() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function num(box, name, fallback, min, max) {
    var value = parseInt(box.getAttribute(name), 10);
    return Math.max(min, Math.min(max, isFinite(value) ? value : fallback));
  }

  function show(box, text) {
    box.classList.remove('wpaias-anim-prepared');
    box.classList.add('wpaias-animation-safe');
    box.removeAttribute('data-jlwa-animation-running');
    if (!text) return;
    text.style.opacity = '1';
    text.style.transform = 'none';
    text.style.visibility = 'visible';
    text.classList.remove('wpaias-with-cursor', 'wpaias-typing');
    text.querySelectorAll('.jlwa-wpaias-line').forEach(function (line) {
      line.style.opacity = '1';
      line.style.transform = 'none';
    });
  }

  function takeOver(box) {
    var saved = box.getAttribute('data-jlwa-original-anim');
    if (saved) return saved;
    var anim = (box.getAttribute('data-anim') || 'none').toLowerCase();
    box.setAttribute('data-jlwa-original-anim', anim);
    box.setAttribute('data-jlwa-animation-managed', '1');
    box.setAttribute('data-anim', 'none');
    box.classList.remove('wpaias-anim-prepared', 'wpaias-anim-active');
    return anim;
  }

  function frames(anim) {
    if (anim === 'fade' || anim === 'neon') return [{ opacity: 0 }, { opacity: 1 }];
    if (anim === 'slide-up') return [{ opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'translateY(0)' }];
    if (anim === 'slide-down') return [{ opacity: 0, transform: 'translateY(-14px)' }, { opacity: 1, transform: 'translateY(0)' }];
    if (anim === 'zoom') return [{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'scale(1)' }];
    if (anim === 'bounce') return [
      { opacity: 0, transform: 'translateY(10px) scale(.96)' },
      { opacity: 1, transform: 'translateY(-4px) scale(1.02)', offset: .6 },
      { opacity: 1, transform: 'translateY(0) scale(1)' }
    ];
    return null;
  }

  function standard(box, text, anim, delay, duration) {
    var keyframes = frames(anim);
    if (!keyframes || typeof text.animate !== 'function') return show(box, text);
    box.classList.remove('wpaias-animation-safe');
    box.setAttribute('data-jlwa-animation-running', '1');
    var player;
    try {
      player = text.animate(keyframes, {
        duration: duration,
        delay: delay,
        easing: anim === 'bounce' ? 'cubic-bezier(.2,.9,.3,1.25)' : 'cubic-bezier(.16,.68,.3,1)',
        fill: 'both'
      });
    } catch (e) { return show(box, text); }
    var done = function () { try { player.cancel(); } catch (e) {} show(box, text); };
    player.addEventListener('finish', done, { once: true });
    window.setTimeout(done, delay + duration + 1200);
  }

  function typewriter(box, text, content, delay) {
    var chars = Array.from(content);
    var speed = num(box, 'data-speed', 35, 5, 300);
    var token = String(Date.now()) + Math.random();
    box.setAttribute('data-jlwa-animation-running', '1');
    box.setAttribute('data-jlwa-type-token', token);
    window.setTimeout(function () {
      if (box.getAttribute('data-jlwa-type-token') !== token) return;
      box.classList.remove('wpaias-animation-safe');
      text.textContent = '';
      text.style.opacity = '1';
      text.style.visibility = 'visible';
      if (parseInt(box.getAttribute('data-cursor'), 10) === 1) text.classList.add('wpaias-with-cursor');
      var i = 0;
      function finish() {
        if (box.getAttribute('data-jlwa-type-token') !== token) return;
        text.textContent = content;
        text.classList.add('wpaias-typed');
        show(box, text);
      }
      function step() {
        if (box.getAttribute('data-jlwa-type-token') !== token) return;
        if (i >= chars.length) return finish();
        text.textContent += chars[i++];
        window.setTimeout(step, speed);
      }
      step();
      window.setTimeout(finish, chars.length * speed + 1500);
    }, delay);
  }

  function lineFade(box, text, content, delay, duration) {
    var parts = content.match(/[^。！？!?\n]+[。！？!?]?|\n+/g) || [content];
    if (typeof text.animate !== 'function') return show(box, text);
    box.classList.remove('wpaias-animation-safe');
    box.setAttribute('data-jlwa-animation-running', '1');
    text.textContent = '';
    var players = [];
    parts.forEach(function (part, i) {
      var span = document.createElement('span');
      span.className = 'jlwa-wpaias-line';
      span.textContent = part;
      text.appendChild(span);
      try {
        players.push(span.animate(
          [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'translateY(0)' }],
          { duration: duration, delay: delay + i * 120, easing: 'ease', fill: 'both' }
        ));
      } catch (e) { span.style.opacity = '1'; }
    });
    window.setTimeout(function () {
      players.forEach(function (p) { try { p.cancel(); } catch (e) {} });
      show(box, text);
    }, delay + duration + Math.max(0, parts.length - 1) * 120 + 1000);
  }

  function run(box) {
    if (!box || !box.matches(S)) return;
    var anim = takeOver(box);
    if (box.getAttribute('data-jlwa-animation-running') === '1') return;
    var text = textOf(box);
    var state = (box.getAttribute('data-state') || '').toLowerCase();
    if (state !== 'ready' || !text || text.hasAttribute('data-pending')) {
      box.classList.remove('wpaias-anim-prepared');
      return;
    }
    var content = text.textContent || '';
    if (!content.trim()) return show(box, text);
    if (box.getAttribute('data-jlwa-animated-text') === content) return;
    box.setAttribute('data-jlwa-animated-text', content);
    var delay = num(box, 'data-delay', 0, 0, 5000);
    var duration = num(box, 'data-duration', 800, 100, 5000);
    if (anim === 'none' || reduceMotion()) return show(box, text);
    if (anim === 'typewriter') return typewriter(box, text, content, delay);
    if (anim === 'line-fade') return lineFade(box, text, content, delay, duration);
    standard(box, text, anim, delay, duration);
  }

  function scan(root) {
    if (root.nodeType === 1 && root.matches && root.matches(S)) run(root);
    if (root.querySelectorAll) root.querySelectorAll(S).forEach(run);
  }

  ready(function () {
    scan(document);
    if (!window.MutationObserver || !document.body) return;
    new MutationObserver(function (mutations) {
      var boxes = [];
      mutations.forEach(function (m) {
        var node = m.target.nodeType === 1 ? m.target : m.target.parentElement;
        var box = node && node.closest ? node.closest(S) : null;
        if (box && boxes.indexOf(box) < 0) boxes.push(box);
        m.addedNodes.forEach(function (added) {
          if (added.nodeType !== 1) return;
          if (added.matches(S) && boxes.indexOf(added) < 0) boxes.push(added);
          if (added.querySelectorAll) added.querySelectorAll(S).forEach(function (nested) {
            if (boxes.indexOf(nested) < 0) boxes.push(nested);
          });
        });
      });
      boxes.forEach(run);
    }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['data-state'] });
  });
})();
