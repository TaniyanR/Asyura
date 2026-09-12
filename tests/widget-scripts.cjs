'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = name => fs.readFileSync(path.join(__dirname, '../assets/', name), 'utf8');
const listeners = {};
const docListeners = {};
const child = {postMessage() {}};
const frame = {src:'https://asyura.example/widgets/rss.php?id=test',contentWindow:child,style:{}};
const context = {
  window:{addEventListener(type,fn) {listeners[type]=fn;}},
  document:{querySelectorAll() {return [frame];},addEventListener(type,fn) {docListeners[type]=fn;}},
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
