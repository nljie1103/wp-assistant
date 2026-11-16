'use strict';
const { test }=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const code=fs.readFileSync('features/immersive-preloader/assets/js/preloader.js','utf8');
function run({ min=0, strategy='dom', max=0.5 }={}) {
 const classes=new Set(['jip-loading']);
 let intervals=0;
 const text={textContent:'页面准备中'};
 const element={
   classList:{ add(x){element.classes.add(x)}, remove(x){element.classes.delete(x)}, contains(x){return element.classes.has(x)} },
   classes:new Set(),
   parentNode:{removeChild(){element.removed=true}},
   removed:false,
   addEventListener(){},
   querySelector(q){return q==='.jip-progress em'?text:null},
 };
 const html={classList:{add(x){classes.add(x)},remove(x){classes.delete(x)},contains(x){return classes.has(x)}}};
 const document={documentElement:html,readyState:'complete',hidden:false,images:[],
   getElementById(id){return id==='jip-preloader'&&!element.removed?element:null},
   addEventListener(){},};
 const events=[];
 const window={JIP_CFG:{minDuration:min,maxDuration:max,completion:strategy,allowSkip:0,effect:'editorial'},
   __JIP_START__:Date.now(),dispatchEvent(e){events.push(e.type)},requestAnimationFrame(cb){setImmediate(cb)}};
 const sandbox={window,document,Date,CustomEvent:class{constructor(type){this.type=type}},
   setTimeout,clearTimeout,clearInterval,
   setInterval(){intervals++;throw Error('fake progress interval created')},
   requestAnimationFrame:cb=>setImmediate(cb),cancelAnimationFrame(){},
   MutationObserver:class{},console};
 vm.runInNewContext(code,sandbox,{filename:'preloader.js'});
 return {classes,element,events,text,get intervals(){return intervals}};
}
test('actual DOM readiness starts exit immediately for minDuration 0 (never forced to 0.45s)',async()=>{
 const lab=run();
 await new Promise(r=>setTimeout(r,55));
 assert.equal(lab.element.classes.has('jip-hide'),true);
 assert.equal(lab.intervals,0);
 assert.equal(lab.text.textContent,'页面已就绪');
 await new Promise(r=>setTimeout(r,450));
 assert.equal(lab.element.removed,true);
 assert.equal(lab.classes.has('jip-loading'),false);
 assert.ok(lab.events.includes('jip:ended'));
});
test('minDuration remains honored when configured on purpose',async()=>{
 const lab=run({min:0.27,max:1});
 await new Promise(r=>setTimeout(r,75));
 assert.equal(lab.element.classes.has('jip-hide'),false);
 await new Promise(r=>setTimeout(r,260));
 assert.equal(lab.element.classes.has('jip-hide'),true);
 assert.equal(lab.intervals,0);
});
