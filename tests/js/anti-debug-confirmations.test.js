'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('features/anti-debug/assets/js/frontend.js', 'utf8');

function testClient() {
  const rootClasses = new Set();
  const listeners = Object.create(null);
  let current = 1700000000000;
  const callbacks = [];
  const FakeDate = class extends Date { static now() { return current; } };
  const classes = {add(name) {rootClasses.add(name);}, remove(name) {rootClasses.delete(name);}};
  const document = {
    documentElement: {classList: classes,clientWidth:1080,clientHeight:760}, readyState:'complete',hidden:false,
    addEventListener(name,fn) {listeners['document:'+name]=fn;},querySelectorAll() {return [];}
  };
  const window = {
    JLWA_ANTI_DEBUG:{detectors:{shortcuts:1,viewport:0,debugger_timing:0,console_getter:0,console_performance:0,debug_libraries:0,focus_signal:0,interval_ms:1100},
      decision:{threshold:85,confirm_hits:2,hit_window_ms:4200,score_decay:12,detector_cooldown_ms:1800},
      response:{layers:{},auto_recover:0}, logging:{enabled:false}},
    matchMedia() {return {matches:false}},
    outerWidth:1080,outerHeight:800,innerWidth:1080,innerHeight:760,
    addEventListener(name,fn) {listeners['window:'+name]=fn;},
    setTimeout(fn) {callbacks.push(fn);return callbacks.length;},clearTimeout(){},
    console:{log(){},table(){},clear(){}}
  };
  const sandbox={window,document,navigator:{userAgent:'Desktop',platform:'Linux',maxTouchPoints:0},
    screen:{width:1080,height:800},sessionStorage:{getItem(){return null},removeItem(){}},
    performance:{now(){return current}},Date:FakeDate,Function,console};
  vm.runInNewContext(source,sandbox);
  const key = () => listeners['window:keydown']({key:'F12',cancelable:true,ctrlKey:false,metaKey:false,shiftKey:false,
      target:{closest(){return null}},preventDefault(){},stopPropagation(){},stopImmediatePropagation(){}});
  return {key,tick(){const cb=callbacks.shift();if(cb)cb()},advance(ms){current+=ms},rootClasses};
}
test('a single suspicious shortcut cannot become two confirmations from background polling',()=>{
  const c=testClient();
  c.key();
  c.advance(1100);c.tick();
  assert.equal(c.rootClasses.has('jlwa-ad-active'),false);
});
test('two fresh suspicious signals within the configured interval can confirm',()=>{
  const c=testClient();
  c.key();
  c.advance(1900);c.key();
  assert.equal(c.rootClasses.has('jlwa-ad-active'),true);
});
