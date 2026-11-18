'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../features/immersive-preloader/assets/js/preloader.js'), 'utf8');

test('skip gestures attach to the owned loading layer, never to document-capture login/payment events', () => {
  const attached = [];
  const detached = [];
  const ownHandlers = {};
  const classes = new Set(['jip-loading']);
  const overlay = {
    hasAttribute() { return false; },
    setAttribute(name, value) { attached.push(['attribute', name, value]); },
    addEventListener(name, handler) { ownHandlers[name] = handler; attached.push(['overlay', name]); },
    removeEventListener(name) { detached.push(['overlay', name]); },
    classList: { add() {}, remove() {} },
    querySelector() { return null; },
    parentNode: { removeChild() {} },
  };
  const docEvents = [];
  const document = {
    readyState: 'loading',
    documentElement: { classList: { contains(name) { return classes.has(name); }, add() {}, remove() {} } },
    getElementById(id) { return id === 'jip-preloader' ? overlay : null; },
    addEventListener(name) { docEvents.push(name); },
  };
  const window = {
    JIP_CFG: { allowSkip: 1, effect: 'logo3d', completion: 'dom', maxDuration: 2, minDuration: 0 },
    __JIP_START__: Date.now(), __JIP_VISUAL_START__: Date.now(),
    addEventListener() {}, dispatchEvent() {},
  };
  vm.runInNewContext(source, { document, window, Date, setTimeout() { return 1; }, clearTimeout() {}, CustomEvent: class {} });
  assert.deepEqual(attached.filter(v => v[0] === 'overlay').map(v => v[1]).sort(), ['click', 'keydown', 'pointerdown', 'touchstart']);
  assert.equal(docEvents.includes('click'), false);
  assert.equal(docEvents.includes('pointerdown'), false);
  assert.equal(docEvents.includes('touchstart'), false);
  assert.equal(docEvents.includes('keydown'), false);
  let consumed = 0;
  ownHandlers.pointerdown({
    type: 'pointerdown',
    cancelable: true,
    preventDefault() { consumed++; },
    stopPropagation() {},
    stopImmediatePropagation() {},
  });
  assert.equal(consumed, 1);
  assert.ok(detached.some(v => v[1] === 'click'));
});

test('skip code has no document-level 900ms blocking guard', () => {
  assert.doesNotMatch(source, /document\.addEventListener\('click', guard/i);
  assert.doesNotMatch(source, /installFollowupGuard|guardTimer/);
});
