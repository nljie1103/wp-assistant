'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('features/anti-debug/assets/js/frontend.js', 'utf8');

function simulate(options = {}) {
  let now = 1720000000000;
  const tasks = [];
  const listeners = {};
  const classes = new Set();
  const storage = options.storage || new Map();
  const jumps = [];
  const navType = options.navigationType || 'navigate';
  let visiblePassword = false;
  const current = options.url || 'https://blog.jiuliu.org/post/test';
  const location = {
    href: current, pathname: new URL(current).pathname,
    replace(url) { jumps.push(url); },
    reload() { jumps.push('RELOAD'); },
  };
  const layers = options.layers || {
    level1: { enabled: 1, delay_ms: 0, overlay: 0 },
    level2: { enabled: 0 },
    level3: { enabled: 1, delay_ms: 0, redirect: 1 },
    level4: { enabled: 0 }
  };
  const doc = {
    readyState: 'complete',
    hidden: false,
    title: '测试页',
    documentElement: {
      clientWidth: 1000, clientHeight: 700,
      classList: { add(...args) { args.forEach(x => classes.add(x)); },
        remove(...args) { args.forEach(x => classes.delete(x)); } }
    },
    addEventListener(name, listener) { listeners['doc:' + name] = listener; },
    querySelectorAll(selector) {
      if (selector.includes('input[type="password"]') && visiblePassword) {
        return [{ disabled: false, getClientRects() {return [1]}, closest() {return {tagName: 'FORM'} } }];
      }
      return [];
    },
  };
  const win = {
    location,
    outerWidth: 1030, innerWidth: 1000, outerHeight: 760, innerHeight: 700,
    performance: { now: () => now, getEntriesByType() {return [{type:navType}]} },
    JLWA_ANTI_DEBUG: {
      detectors: {
        shortcuts: 1, viewport: 1, debugger_timing: 0, console_getter: 0,
        console_performance: 0, debug_libraries: 1, focus_signal: 1,
        viewport_threshold: 220, viewport_ratio: 18, interval_ms: 1100
      },
      decision: {
        threshold: 80, confirm_hits: 2, hit_window_ms: 4200,
        score_decay: 1, detector_cooldown_ms: 1800
      },
      response: { layers, redirect_url: options.redirectUrl || 'https://blog.jiuliu.org/user-sign?tab=signin', auto_recover: 0 },
      logging: {enabled: false},
    },
    matchMedia() { return {matches: false} },
    setTimeout(fn, ms) { const task = {fn, ms, run: false}; tasks.push(task); return tasks.length; },
    clearTimeout() {},
    setInterval(fn, ms) {const task = {fn, ms, repeat:true, run:false}; tasks.push(task); return tasks.length;},
    clearInterval() {},
    addEventListener(name, fn) {listeners['win:'+name] = fn;},
    console: {log(){},table(){},clear(){}}
  };
  const fakeStorage = {
    getItem(key) {return storage.has(key)?storage.get(key):null},
    setItem(key,value){storage.set(key,value)},
    removeItem(key){storage.delete(key)},
  };
  class FakeDate extends Date {static now() {return now}}
  vm.runInNewContext(source, {
    window: win, document: doc, navigator:{userAgent:'Desktop Chrome',platform:'Win32',maxTouchPoints:0},
    screen:{width:1030,height:760}, performance:win.performance, sessionStorage:fakeStorage,
    Date:FakeDate, URL,
  });
  function advance(ms) {now+=ms}
  function runNext(ms) {
    const task=tasks.find(task=>!task.run && task.ms===ms);
    assert.ok(task, 'expected scheduled '+ms+'ms task');
    task.run=true;
    task.fn();
    return task;
  }
  function runAll(ms) {
    while (tasks.some(task => !task.run && task.ms === ms && !task.repeat)) runNext(ms);
  }
  function f12() {
    const event={key:'F12',cancelable:true,target:{closest(){return null}},
      preventDefault(){},stopPropagation(){},stopImmediatePropagation(){} };
    listeners['win:keydown'](event);
  }
  function loginClick() {
    listeners['doc:click']({target:{closest(selector){return selector.includes('.signin-loader') ? {className:'signin-loader'}:null}}});
  }
  return {
    tasks,listeners,storage,jumps,classes,location,win,doc,runNext,runAll,advance,f12,loginClick,
    setVisiblePassword(v){visiblePassword=v}, setInnerWidth(w){win.innerWidth=w},
  };
}

test('strict viewports resizing and focus blips alone never trigger redirect defense',()=>{
  const sim=simulate();
  sim.runNext(900);
  sim.setInnerWidth(630); // Common browser layout change: 370px inner gap.
  for(let i=0;i<10;i++){
    sim.advance(1900);
    sim.runNext(1100);
  }
  assert.equal(sim.jumps.length,0,'layout changes must not redirect a genuine visitor');
  assert.equal(sim.classes.has('jlwa-ad-active'),false);
});

test('Zibll signin-loader click pauses existing defense and cancels pending redirect',()=>{
  const sim=simulate();
  sim.f12();
  sim.advance(2000);
  sim.f12(); // Two deliberate DevTools shortcut presses.
  assert.equal(sim.classes.has('jlwa-ad-active'),true,'defense activated before login');
  sim.loginClick();
  assert.equal(sim.classes.has('jlwa-ad-active'),false,'login click clears block');
  sim.runAll(0); // Pending level3 redirect must not execute.
  assert.equal(sim.jumps.length,0);
  assert.equal(sim.storage.has('jlwa_ad_lock_v3'),false);
});

test('visible authentication dialog suppresses defense even on a protected article',()=>{
  const sim=simulate();
  sim.setVisiblePassword(true);
  sim.f12();
  sim.advance(2000);
  sim.f12();
  sim.runNext(900);
  assert.equal(sim.classes.has('jlwa-ad-active'),false);
  assert.equal(sim.jumps.length,0);
});

test('normal navigation after login clears same-page persisted protection',()=>{
  const storage=new Map();
  storage.set('jlwa_ad_lock_v2', JSON.stringify({expires:1720000999999,source:'reload_loop'}));
  storage.set('jlwa_ad_lock_v3', JSON.stringify({expires:1720000999999,source:'reload_loop',path:'/post/test'}));
  const sim=simulate({storage,navigationType:'navigate'});
  assert.equal(storage.has('jlwa_ad_lock_v2'),false,'old cross-page lock removed on update');
  assert.equal(storage.has('jlwa_ad_lock_v3'),false,'fresh navigation is not a refresh');
  sim.runNext(900);
  assert.equal(sim.classes.has('jlwa-ad-active'),false);
  assert.equal(sim.jumps.length,0);
});

test('an actual refresh may resume a same-page persisted lock',()=>{
  const storage=new Map();
  storage.set('jlwa_ad_lock_v3', JSON.stringify({expires:1720000999999,source:'level3',path:'/post/test'}));
  const sim=simulate({storage,navigationType:'reload'});
  assert.equal(sim.classes.has('jlwa-ad-active'),true);
});

test('redirect action never redirects to its own URL',()=>{
  const url='https://blog.jiuliu.org/post/test';
  const sim=simulate({url,redirectUrl:url});
  sim.f12();sim.advance(2000);sim.f12();sim.runAll(0);
  assert.deepEqual(sim.jumps,[]);
});

test('redirect guard caps chained automatic redirects within a browser session',()=>{
  const storage=new Map();
  const first=simulate({storage});
  first.f12();first.advance(2000);first.f12();first.runAll(0);
  assert.equal(first.jumps.length,1);
  const second=simulate({storage,url:'https://blog.jiuliu.org/post/other'});
  second.f12();second.advance(2000);second.f12();second.runAll(0);
  assert.equal(second.jumps.length,0,'no loop if another page re-triggers a redirect');
});
