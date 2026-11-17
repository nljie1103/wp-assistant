'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function makeElement(name) {
  const classes = new Set(name === 'overview' ? ['is-active'] : []);
  const attributes = { 'data-tab': name, 'data-panel': name };
  const listeners = {};
  return {
    name,
    attributes,
    listeners,
    focused: false,
    classList: {
      contains: key => classes.has(key),
      toggle: (key, on) => { if (on) classes.add(key); else classes.delete(key); },
    },
    getAttribute: key => attributes[key] || null,
    setAttribute: (key, value) => { attributes[key] = value; },
    addEventListener: (type, handler) => { listeners[type] = handler; },
    focus() { this.focused = true; },
  };
}

test('anti-debug UI uses WordPress-like top tabs, persists selection, supports keyboard', () => {
  const tabNames = ['overview', 'detectors', 'decision', 'layers', 'logs'];
  const tabs = tabNames.map(makeElement);
  const panels = tabNames.map(makeElement);
  panels.forEach((p, i) => { p.attributes['data-panel'] = tabNames[i]; });
  const storage = new Map();
  const root = {
    querySelectorAll(query) {
      if (query === '.jlwa-ad-option') return [];
      if (query === '[data-tab]') return tabs;
      if (query === '[data-panel]') return panels;
      return [];
    },
    querySelector() { return null; },
  };
  let ready;
  const document = {
    addEventListener(event, handler) {
      if (event === 'DOMContentLoaded') ready = handler;
    },
    querySelector(selector) {
      return selector === '.jlwa-ad-admin' ? root : null;
    },
  };
  const window = { sessionStorage: {
    setItem: (key, value) => storage.set(key, value),
    getItem: key => storage.get(key) || null,
  } };
  const file = path.join(__dirname, '../../features/anti-debug/assets/js/admin.js');
  const code = fs.readFileSync(file, 'utf8');
  vm.runInNewContext(code, { document, window, console });
  ready();
  tabs[1].listeners.click();
  assert.equal(tabs[1].attributes['aria-selected'], 'true');
  assert.equal(tabs[0].attributes['aria-selected'], 'false');
  assert.equal(panels[1].classList.contains('is-active'), true);
  assert.equal(storage.get('jlwa-anti-debug-active-tab'), 'detectors');
  let prevented = false;
  tabs[1].listeners.keydown({ key: 'ArrowRight', preventDefault: () => { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(tabs[2].focused, true);
  assert.equal(tabs[2].attributes['aria-selected'], 'true');
});
