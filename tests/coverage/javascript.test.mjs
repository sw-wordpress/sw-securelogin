import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { BrowserCoverage, verifiedScriptSource } from './javascript.mjs';

const url='https://localhost:8443/wp-content/plugins/sw-securelogin/assets/js/settings.js?ver=0.3.0';
const expected='window.example = true;\n';

test('missing V8 source can use an exact browser response without changing executed ranges',()=>{
 const functions=[{functionName:'',ranges:[{startOffset:0,endOffset:expected.length,count:1}]}];
 const entry={url,functions};
 assert.equal(verifiedScriptSource(entry,expected,expected),expected);
 assert.deepEqual(entry,{url,functions});
 assert.equal(Object.hasOwn(entry,'source'),false);
});

test('a missing V8 source and missing response fail instead of trusting repository contents',()=>{
 assert.throws(()=>verifiedScriptSource({url},expected,undefined),/source is unavailable/);
});

test('a changed browser response fails even when V8 has matching source',()=>{
 assert.throws(()=>verifiedScriptSource({url,source:expected},expected,'window.example = false;\n'),/response differs/);
});

test('a changed V8 source fails even when the HTTP response matches',()=>{
 assert.throws(()=>verifiedScriptSource({url,source:'window.example = false;\n'},expected,expected),/script differs/);
});

test('a changed response cannot replace missing V8 source',()=>{
 assert.throws(()=>verifiedScriptSource({url},expected,'window.example = false;\n'),/response differs/);
});

test('matching V8 source can be verified without a new HTTP response',()=>{
 assert.equal(verifiedScriptSource({url,source:expected},expected,undefined),expected);
});

test('script bytes are captured and verified before delivery can trigger navigation',async()=>{
 const measured=new BrowserCoverage();
 const source=readFileSync('assets/js/settings.js','utf8');
 const events=[];
 const response={text:async()=>{events.push('read');return source;}};
 const route={
  request:()=>({url:()=>url}),
  fetch:async()=>{events.push('fetch');return response;},
  fulfill:async args=>{
   assert.equal(args.response,response);
   assert.equal(measured.responseSources.get(url),source);
   events.push('deliver');
  },
 };
 assert.equal(await measured.captureScript(route),true);
 assert.deepEqual(events,['fetch','read','deliver']);
});

test('changed responses are rejected before delivery, including repeat loads of the same URL',async()=>{
 const measured=new BrowserCoverage();
 const source=readFileSync('assets/js/settings.js','utf8');
 let served=source;
 let delivered=0;
 const route={
  request:()=>({url:()=>url}),
  fetch:async()=>({text:async()=>served}),
  fulfill:async()=>{delivered++;},
 };
 assert.equal(await measured.captureScript(route),true);
 served+='\nwindow.changed = true;';
 await assert.rejects(measured.captureScript(route),/response differs/);
 assert.equal(delivered,1);
 assert.equal(measured.responseSources.get(url),source);
});

test('unrelated browser requests are left to their existing route handler',async()=>{
 const measured=new BrowserCoverage();
 assert.equal(await measured.captureScript({request:()=>({url:()=> 'https://localhost:8443/wp-admin/profile.php'})}),false);
});
