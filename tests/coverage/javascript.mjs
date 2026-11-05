import { readdirSync, mkdirSync, readFileSync } from 'node:fs';
import { resolve, relative } from 'node:path';
import v8toIstanbul from 'v8-to-istanbul';
import coverage from 'istanbul-lib-coverage';
import reports from 'istanbul-reports';
import libReport from 'istanbul-lib-report';

// Never substitute repository contents for missing browser evidence.
export function verifiedScriptSource(entry,expected,responseSource) {
 if (typeof entry.source==='string' && entry.source!==expected) throw new Error('Browser script differs from production source: '+entry.url);
 if (typeof responseSource==='string' && responseSource!==expected) throw new Error('Browser script response differs from production source: '+entry.url);
 const source=entry.source ?? responseSource;
 if (typeof source!=='string') throw new Error('Browser script source is unavailable: '+entry.url);
 return source;
}

const scripts=(directory)=>readdirSync(directory,{withFileTypes:true}).flatMap(entry=>entry.isDirectory() ? scripts(resolve(directory,entry.name)) : entry.name.endsWith('.js') ? [resolve(directory,entry.name)] : []);
const productionPath=(url,paths)=>paths.find(file=>url.pathname.endsWith('/'+relative(process.cwd(),file)));

// Always include every production script, even when the browser never requested it.
export class BrowserCoverage {
 constructor() { this.entries=[]; this.running=false; this.responseSources=new Map(); this.paths=scripts('assets/js'); }
 async captureScript(route) {
  const url=route.request().url();
  const path=productionPath(new URL(url),this.paths);
  if (!path) return false;
  // Buffer the actual response before the browser can execute it and navigate away.
  // Reading a response event later has the same document-lifetime race as V8 source.
  const response=await route.fetch();
  const source=await response.text();
  verifiedScriptSource({url},readFileSync(path,'utf8'),source);
  this.responseSources.set(url,source);
  await route.fulfill({response});
  return true;
 }
 async start(page) {
  this.page=page;
  await page.coverage.startJSCoverage({resetOnNavigation:false});
  this.running=true;
 }
 async checkpoint() {
  if (!this.running) return;
  this.running=false;
  this.entries.push(...await this.page.coverage.stopJSCoverage());
  await this.start(this.page);
 }
 async report() {
  await this.checkpoint();
  const map=coverage.createCoverageMap({});
  const paths=this.paths;
  for (const path of paths) {
   const converter=v8toIstanbul(path);
   await converter.load();
   converter.applyCoverage([{functionName:'',ranges:[{startOffset:0,endOffset:readFileSync(path,'utf8').length,count:0}],isBlockCoverage:true}]);
   map.merge(converter.toIstanbul());
  }
  for (const entry of this.entries) {
   if (!entry.url) continue;
   let url;
   try { url=new URL(entry.url); } catch { continue; }
   const path=productionPath(url,paths);
   if (!path) continue;
   const expected=readFileSync(path,'utf8');
   const source=verifiedScriptSource(entry,expected,this.responseSources.get(entry.url));
   const converter=v8toIstanbul(path,0,{source});
   await converter.load();
   converter.applyCoverage(entry.functions);
   map.merge(converter.toIstanbul());
  }
  mkdirSync('coverage/js',{recursive:true});
  const context=libReport.createContext({dir:'coverage/js',coverageMap:map});
  for (const kind of ['lcovonly','json','json-summary','text']) reports.create(kind).execute(context);
  const {covered,total}=map.getCoverageSummary().lines;
  if (!total || covered*100<=total*80) throw new Error(`JavaScript line coverage must exceed 80% (${covered}/${total}).`);
 }
}
