'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('features/page-effects/assets/js/admin.js', 'utf8');

function element(attributes = {}) {
  const listeners = {};
  const classes = new Set();
  return {
    hidden: false, isConnected: true,
    dataset: attributes.dataset || {},
    attributes: attributes.attrs || {},
    classList: {
      add(n) { classes.add(n); }, remove(n) { classes.delete(n); },
      contains(n) { return classes.has(n); },
      toggle(n, on) { if (on) classes.add(n); else classes.delete(n); return Boolean(on); }
    },
    addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
    dispatchEvent(ev) { (listeners[ev.type] || []).forEach(fn => fn(ev)); },
    click() { this.dispatchEvent({type:'click'}); },
    getAttribute(name) { return this.attributes[name] || null; },
    setAttribute(name, value) { this.attributes[name] = value; },
    listeners
  };
}
function fixture() {
  let cards = [];
  const eventListeners = {};
  const counter = {textContent:''};
  const kinds = ['all','enabled','氛围特效','交互增强','特殊模式','内容保护','节日与音乐'];
  const filters = kinds.map(id => Object.assign(element({attrs:{'data-xjpe-filter':id}}), {id}));
  function makeCard(group, enabled = false) {
    const card = element({dataset:{group}}), cb = element(), st = {textContent:''}, details = element();
    cb.checked = enabled;
    details.open = false;
    card.querySelector = key => ({'.xjpe-toggle':cb,'.xjpe-status':st,'.xjpe-config-details':details})[key] || null;
    card.cb = cb;card.details = details;card.st = st;
    return card;
  }
  const groups = ['氛围特效','氛围特效','交互增强','特殊模式','内容保护','节日与音乐'];
  cards = groups.map((g,i) => makeCard(g, i===0 || i===4));
  const enableAll = element(),disableAll=element();
  const document = {
    readyState:'complete',
    querySelector(query) { return ({'.xjpe-effect-count':counter, '[data-xjpe-enable-all]':enableAll, '[data-xjpe-disable-all]':disableAll})[query] || null; },
    querySelectorAll(query) {
      if (query === '[data-xjpe-card]') return cards;
      if (query === '[data-xjpe-filter]') return filters;
      if (query === '.xjpe-toggle') return cards.map(c=>c.cb);
      return [];
    },
    getElementById() {return null;},
    addEventListener(name,fn) {(eventListeners[name] ||= []).push(fn);},
    dispatchEvent(ev) {(eventListeners[ev.type]||[]).forEach(fn=>fn(ev));}
  };
  vm.runInNewContext(source,{document, Event:class{constructor(type){this.type=type}}});
  return {cards, filters, counter, enableAll, disableAll, makeCard, document,appendCard(card){cards.push(card);document.dispatchEvent({type:'xjpe:cards-added'});}};
}
test('all seven filters really show/hide cards, including the enabled-only view',()=>{
  const x=fixture();
  assert.equal(x.counter.textContent,'显示 6 项 · 已启用 2 项');
  const expected={'all':6,'enabled':2,'氛围特效':2,'交互增强':1,'特殊模式':1,'内容保护':1,'节日与音乐':1};
  for(const button of x.filters){
    button.click();
    assert.equal(x.cards.filter(card=>!card.hidden).length,expected[button.id], button.id);
    assert.equal(button.attributes['aria-pressed'],'true', button.id);
    assert.ok(x.counter.textContent.includes('显示 '+expected[button.id]+' 项'),button.id);
  }
});
test('config details are exclusive and filtered-out configuration is closed',()=>{
  const x=fixture();
  const [a,b] = x.cards;
  a.details.open=true;a.details.dispatchEvent({type:'toggle'});
  assert.equal(a.classList.contains('is-expanded'),true);
  b.details.open=true;b.details.dispatchEvent({type:'toggle'});
  assert.equal(a.details.open,false);
  assert.equal(b.classList.contains('is-expanded'),true);
  x.filters.find(f=>f.id==='特殊模式').click();
  assert.equal(b.details.open,false);
});
test('replaced legacy cursor card is excluded from category and total counts',()=>{
  const x=fixture();
  const removed = x.cards[2]; // Legacy cursor card that the mouse extension replaces.
  removed.isConnected = false;
  x.appendCard(x.makeCard('交互增强'));
  assert.equal(x.counter.textContent,'显示 6 项 · 已启用 2 项');
  x.filters.find(f=>f.id==='交互增强').click();
  assert.equal(x.counter.textContent,'显示 1 项 · 已启用 2 项');
});
test('dynamically appended mouse cards get filtering, toggles, and exclusive configuration',()=>{
  const x=fixture();
  x.appendCard(x.makeCard('交互增强'));
  assert.equal(x.counter.textContent,'显示 7 项 · 已启用 2 项');
  x.filters.find(f=>f.id==='交互增强').click();
  assert.equal(x.cards.filter(c=>!c.hidden).length,2);
  const dynamic=x.cards.at(-1);
  dynamic.cb.checked=true;dynamic.cb.dispatchEvent({type:'change'});
  assert.equal(dynamic.st.textContent,'已启用');
  assert.ok(x.counter.textContent.includes('已启用 3 项'));
  dynamic.details.open=true;dynamic.details.dispatchEvent({type:'toggle'});
  assert.equal(dynamic.classList.contains('is-expanded'),true);
  x.filters.find(f=>f.id==='all').click();
  x.disableAll.click();
  assert.equal(x.cards.filter(c=>c.cb.checked).length,0);
  x.enableAll.click();
  assert.equal(x.cards.filter(c=>c.cb.checked).length,7);
});
