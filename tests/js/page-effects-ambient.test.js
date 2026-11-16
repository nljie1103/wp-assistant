'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('features/page-effects/assets/js/frontend.js', 'utf8');

function runAmbient({ mobile = false, reduced = false } = {}) {
  const layers = [];
  function node(tag) {
    return {
      tag, children: [], style: { setProperty(key,value) { this[key]=value; } },
      appendChild(child) { this.children.push(...(child.fragment ? child.children : [child])); return child; },
      setAttribute(k,v) {this[k]=v;},
      classList: { add() {}, remove() {} }
    };
  }
  const body = node('body'); body.appendChild = child => { layers.push(child); return child; };
  const document = {
    readyState: 'complete', body, documentElement: node('html'), hidden: false,
    getElementById() { return null; },
    createElement: node,
    createDocumentFragment() { const el=node('fragment');el.fragment=true;return el; },
    addEventListener() {}, dispatchEvent() {}, querySelector() { return null; }
  };
  const window = {
    XJPE_CONFIG: {global:{mobileEnabled:true,respectReduceMotion:true}, compat:{body_wait:1},effects:{
      fireflies:{enabled:1,count:32,speed:1,opacity:0.75},
      meteors:{enabled:1,count:24,speed:1,opacity:0.8}}},
    innerWidth: mobile?360:1440, innerHeight:mobile?780:900,
    matchMedia: q => ({matches:q.includes('max-width')?mobile:q.includes('prefers-reduced-motion')?reduced:false}),
    requestAnimationFrame() {throw Error('ambient effects should need no frame loop');}
  };
  vm.runInNewContext(code, {window,document,navigator:{userAgent:'Desktop'},CustomEvent:function(){},console,Math,Event:function(){}});
  return layers;
}

test('two ambient effects are really initialized as CSS-only layers',()=>{
  const layers=runAmbient();
  assert.equal(layers.length,2);
  assert.ok(layers.some(x=>x.className.includes('fireflies')));
  assert.ok(layers.some(x=>x.className.includes('meteors')));
  assert.equal(layers[0].style.pointerEvents,'none');
  assert.equal(layers[0].children.length,26); // capped desktop fireflies
  assert.equal(layers[1].children.length,12); // capped desktop meteors
});

test('mobile budget caps animation nodes',()=>{
  const layers=runAmbient({mobile:true});
  assert.equal(layers[0].children.length,10);
  assert.equal(layers[1].children.length,4);
});

test('prefers-reduced-motion prevents ambient animation initialization',()=>{
  assert.equal(runAmbient({reduced:true}).length,0);
});
