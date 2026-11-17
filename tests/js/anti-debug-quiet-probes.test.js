'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('features/anti-debug/assets/js/frontend.js','utf8');
const php = fs.readFileSync('features/anti-debug/bootstrap.php','utf8');

function runClient(extraFlags={}) {
  const calls={logs:[],tables:[],debuggers:[],timers:[]};
  const detectors=Object.assign({
    shortcuts:1,viewport:1,debugger_timing:1,console_getter:1,
    console_performance:1,debug_libraries:1,focus_signal:1,
    interval_ms:1100,viewport_threshold:220,viewport_ratio:18,
    debugger_threshold:180,performance_threshold:110,
    active_console_probes:0,active_debugger_probe:0
  },extraFlags);
  const root={add(){},remove(){}};
  const document={
    documentElement:{classList:root,clientWidth:1120,clientHeight:740},
    readyState:'complete',hidden:false,title:'test',
    addEventListener(){},querySelectorAll(){return []}
  };
  const window={
    JLWA_ANTI_DEBUG:{
      detectors,
      decision:{threshold:85,confirm_hits:2,hit_window_ms:4200,score_decay:12,detector_cooldown_ms:1800},
      response:{layers:{},auto_recover:0},logging:{enabled:false}
    },
    outerWidth:1120,outerHeight:790,innerWidth:1120,innerHeight:740,
    console:{log(...args){calls.logs.push(args)},table(rows){calls.tables.push(rows)},clear(){}},
    setTimeout(fn,ms){calls.timers.push({fn,ms});return calls.timers.length},
    addEventListener(){},
    matchMedia(){return {matches:false}}
  };
  const DateMock=class extends Date {static now(){return 1710000000000}};
  const FunctionSpy=function(...args){
    calls.debuggers.push(args);
    return function(){};
  };
  const context={
    document,window,Function:FunctionSpy,Date:DateMock,
    navigator:{userAgent:'Chrome Desktop',platform:'Win32',maxTouchPoints:0},
    screen:{width:1120,height:740},
    performance:{now(){return 1234}},
    sessionStorage:{getItem(){return null},removeItem(){}}
  };
  vm.runInNewContext(source, context, {filename:'anti-debug/frontend.js'});
  const init=calls.timers.find(t=>t.ms===900);
  assert.ok(init,'normal frontend polling initialization is scheduled');
  init.fn(); // executes the first tick including all detectors
  return calls;
}

test('strict flags never create console output or debugger breaks without separately opted-in probes',()=>{
  const calls=runClient();
  assert.equal(calls.logs.length,0);
  assert.equal(calls.tables.length,0);
  assert.equal(calls.debuggers.length,0);
});
test('explicit console opt-in is needed for both getter and 90-row performance probe',()=>{
  const calls=runClient({active_console_probes:1});
  assert.equal(calls.logs.length,1);
  assert.equal(calls.tables.length,1);
  assert.equal(calls.tables[0].length,90);
  assert.equal(calls.debuggers.length,0);
});
test('intentional debugger statement requires separate explicit opt-in',()=>{
  const calls=runClient({active_debugger_probe:1});
  assert.equal(calls.logs.length,0);
  assert.equal(calls.tables.length,0);
  assert.deepEqual(Array.from(calls.debuggers[0]||[]),['debugger']);
});
test('backend exposes both opt-in switches with OFF defaults for existing sites',()=>{
  for(const key of ['active_console_probes','active_debugger_probe']){
    assert.match(php,new RegExp("'"+key+"'\\s*=> 0"));
    assert.ok(php.includes("detectors["+key+"]"),key+' admin checkbox missing');
    assert.ok(php.includes("'focus_signal', 'active_console_probes', 'active_debugger_probe'"),key+' save sanitizer missing');
  }
  assert.ok(php.includes("'close_loop' => 0") || php.includes("'close_loop'         => 0"),'close loop remains opt-in only');
});
