'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = name => fs.readFileSync(path.join(__dirname, '../assets/', name), 'utf8');
const listeners = {};
const docListeners = {};
const child = {postMessage() {}};
const frame = {src:'https://asyura.example/widgets/rss.php?id=test',contentWindow:child,style:{},matches(selector){return selector==='iframe[src]';}};
const context = {
  window:{addEventListener(type,fn) {listeners[type]=fn;}},
  document:{currentScript:{src:'https://asyura.example/assets/widget-embed.js'},readyState:'loading',querySelectorAll() {return [frame];},addEventListener(type,fn) {docListeners[type]=fn;}},
  location:{href:'https://partner.example/'},URL,Number,Math
};
vm.runInNewContext(script('widget-embed.js'),context);
listeners.message({data:{type:'asyura-widget-size',height:420},source:child,origin:'https://asyura.example'});
assert.equal(frame.style.height,'420px');
listeners.message({data:{type:'asyura-widget-size',height:1000},source:child,origin:'https://attacker.example'});
assert.equal(frame.style.height,'420px');
listeners.message({data:{type:'asyura-widget-size',height:1000},source:{},origin:'https://asyura.example'});
assert.equal(frame.style.height,'420px');
for (const height of [-1,Infinity,NaN,100001]) listeners.message({data:{type:'asyura-widget-size',height},source:child,origin:'https://asyura.example'});
assert.equal(frame.style.height,'420px');
listeners.message({data:{type:'asyura-widget-size',height:80},source:child,origin:'https://asyura.example'});
assert.equal(frame.style.height,'80px');
const before=frame.style.height;
frame.src='https://foreign.example/widgets/rss.php?id=test';
listeners.message({data:{type:'asyura-widget-size',height:999},source:child,origin:'https://foreign.example'});
assert.equal(frame.style.height,before);
frame.src='https://asyura.example/other/rss.php?id=test';
listeners.message({data:{type:'asyura-widget-size',height:999},source:child,origin:'https://asyura.example'});
assert.equal(frame.style.height,before);
frame.src='https://asyura.example/widgets/links.php?id=test';
let requests=0;child.postMessage=()=>requests++;
docListeners.DOMContentLoaded();
docListeners.load({target:frame});
assert.equal(requests,2);
console.log('OK legacy tags, late loading and unrelated frame isolation');
console.log('OK iframe grows/shrinks only for valid messages from its own origin and window');
const sent=[];let boxHeight=220;
const parent={postMessage(value) {sent.push(value);}};
const childListeners={};
const box={getBoundingClientRect(){return {bottom:boxHeight};}};
const resizeContext={
  window:{parent,addEventListener(type,fn){childListeners[type]=fn;}},
  document:{querySelector(){return box;}},
  getComputedStyle(){return {marginBottom:'10px'};},Math,parseFloat
};
vm.runInNewContext(script('widget-resize.js'),resizeContext);
assert.equal(sent.at(-1).height,230);
boxHeight=70;childListeners.resize();
assert.equal(sent.at(-1).height,80);
const count=sent.length;
childListeners.message({source:{},data:{type:'asyura-widget-measure'}});
assert.equal(sent.length,count);
childListeners.message({source:parent,data:{type:'asyura-widget-measure'}});
assert.equal(sent.length,count+1);
console.log('OK content height is measured independently of previous iframe height');
const appended=[];
const trackerContext={
  window:{},URL,
  document:{currentScript:{src:'https://asyura.example/sub/assets/tracker.js',dataset:{siteId:'test',siteKey:'test'}},createElement(){return {};},head:{appendChild(node){appended.push(node);}}}
};
// Execute tracker initialization up to the unchanged analytics implementation.
const boot=script('tracker.js').split('  var randomId=function()')[0]+'})();';
vm.runInNewContext(boot,trackerContext);
assert.equal(appended.length,1);
assert.equal(appended[0].src,'https://asyura.example/sub/assets/widget-embed.js');
vm.runInNewContext(boot,trackerContext);
assert.equal(appended.length,1);
console.log('OK existing tracker loads resize helper once and preserves installation subdirectory');
