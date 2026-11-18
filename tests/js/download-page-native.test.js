'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(
  path.join(__dirname, '../../assets/js/download-page-native.js'),
  'utf8'
);

test('native-download helper moves only its own support panel', () => {
  const actions = [];
  const owned = {
    querySelector(selector) {
      actions.push(['own-selector', selector]);
      return selector === '.jld-help-panel' ? {} : null;
    },
    remove() { actions.push(['remove-own']); },
  };
  const article = {
    contains(node) { assert.equal(node, owned); return false; },
    appendChild(node) { assert.equal(node, owned); actions.push(['append-own']); },
  };
  const document = {
    readyState: 'complete',
    getElementById(id) { assert.equal(id, 'jlwa-native-support'); return owned; },
    querySelector(selector) {
      assert.equal(selector, 'body.jlwa-native-download .page-article');
      return article;
    },
    addEventListener() { throw new Error('No deferred listener required'); },
  };
  vm.runInNewContext(script, { document });
  assert.deepEqual(actions, [
    ['own-selector', '.jld-help-panel'],
    ['append-own'],
  ]);
});

test('no configured contact links removes only the empty owned placeholder', () => {
  const actions = [];
  const owned = {
    querySelector(selector) { assert.equal(selector, '.jld-help-panel'); return null; },
    remove() { actions.push('remove-own'); },
  };
  const document = {
    readyState: 'complete',
    getElementById(id) { assert.equal(id, 'jlwa-native-support'); return owned; },
    querySelector() { throw new Error('No native node should be touched'); },
  };
  vm.runInNewContext(script, { document });
  assert.deepEqual(actions, ['remove-own']);
});

test('never monkeypatches fetch, XMLHttpRequest, Zibll buttons, or event propagation', () => {
  assert.doesNotMatch(script, /XMLHttpRequest|fetch\s*=|\.ajax(?:Setup|Prefilter)|preventDefault|stopPropagation|stopImmediatePropagation|\.innerHTML\s*=/i);
  assert.doesNotMatch(script, /window\.location|form\.submit|history\.pushState|document\.cookie/i);
});
