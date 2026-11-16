'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('features/anti-debug/assets/js/frontend.js', 'utf8');

function protectedPage() {
  const listeners = {};
  const doc = {
    documentElement: {clientWidth: 1024, clientHeight: 768},
    readyState: 'complete', hidden: false,
    addEventListener(name, handler) { listeners['document:' + name] = handler; },
    querySelectorAll() { return []; }
  };
  const win = {
    JLWA_ANTI_DEBUG: {detectors: {shortcuts: 1, viewport: 0, console_getter: 0, debug_libraries: 0},
      decision: {threshold: 85, confirm_hits: 2}, response: {layers: {}}},
    matchMedia: () => ({matches: false}), outerWidth: 1024, outerHeight: 768, innerWidth: 1024, innerHeight: 768,
    addEventListener(name, handler) {listeners['window:' + name] = handler;},
    setTimeout() {return 1;},
    console: {log() {}, clear() {}}
  };
  const globals = {window: win, document: doc, screen: {width: 1280, height: 800},
    navigator: {userAgent: 'Desktop', platform: 'Linux', maxTouchPoints: 0},
    sessionStorage: {removeItem() {}, getItem() {return null;}},
    performance: {now: () => 1}, Date, Object, Function, console};
  vm.runInNewContext(source, globals);
  assert.equal(typeof listeners['window:keydown'], 'function');
  return listeners['window:keydown'];
}

function keyEvent(key, {ctrlKey = false, shiftKey = false, isEditing = false} = {}) {
  const record = {prevented: false, stopped: false};
  return {key, ctrlKey, shiftKey, metaKey: false, altKey: false, cancelable: true,
    target: {closest: () => isEditing ? {} : null},
    preventDefault() {record.prevented = true;},
    stopPropagation() {record.stopped = true;},
    stopImmediatePropagation() {}, record};
}

test('F12 remains handled while a form field has focus', () => {
  const handler = protectedPage();
  const event = keyEvent('F12', {isEditing: true});
  handler(event);
  assert.equal(event.record.prevented, true);
  assert.equal(event.record.stopped, true);
});

test('Ctrl+Shift+I remains handled while an input has focus', () => {
  const handler = protectedPage();
  const event = keyEvent('i', {ctrlKey: true, shiftKey: true, isEditing: true});
  handler(event);
  assert.equal(event.record.prevented, true);
});

test('ordinary editing shortcut is not hijacked', () => {
  const handler = protectedPage();
  const event = keyEvent('u', {ctrlKey: true, isEditing: true});
  handler(event);
  assert.equal(event.record.prevented, false);
});
