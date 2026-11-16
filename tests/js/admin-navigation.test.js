'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/admin-content.js', 'utf8');

function render(mocks) {
  const document = {readyState: 'complete',
    querySelectorAll(selector) {return mocks[selector] || [];},
    addEventListener() {throw new Error('Page should be ready already');}};
  const window = { /* CSS.escape deliberately absent */ };
  vm.runInNewContext(source, {document, window, console}, {filename:'admin-content.js'});
}

test('switching tabs scrolls only the tab strip and never jumps the document', () => {
  let globalScrollCalled = false;
  const active = {offsetLeft: 220, offsetWidth: 90, scrollIntoView() {globalScrollCalled = true;}};
  const tabs = {scrollWidth: 510, clientWidth: 200, offsetLeft: 10, scrollLeft: 0,
    querySelector() {return active;}};
  render({'.jlwa-feature-host .nav-tab-wrapper': [tabs]});
  assert.equal(globalScrollCalled, false);
  assert.ok(tabs.scrollLeft > 0);
});

test('selectable radio cards work with CSS.escape unavailable', () => {
  function card(checked) {
    const state = {active: false};
    const input = {type: 'radio', name: 'template[display]', checked,
      addEventListener(name, listener) {this.listener = listener;}, closest() {return state.card;}};
    state.card = {querySelector() {return input;}, classList: {toggle(name, value) {
      if (name === 'is-active') state.active = value;
    }}};
    return {state, input};
  }
  const first = card(true), second = card(false);
  render({'.jip-effect-card': [first.state.card, second.state.card],
    '.jip-effect-card input[type="radio"]': [first.input, second.input]});
  assert.equal(first.state.active, true);
  assert.equal(second.state.active, false);
  first.input.checked = false;
  second.input.checked = true;
  second.input.listener();
  assert.equal(first.state.active, false);
  assert.equal(second.state.active, true);
});
