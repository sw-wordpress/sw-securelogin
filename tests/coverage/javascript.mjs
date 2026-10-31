import { readdirSync, mkdirSync, readFileSync } from 'node:fs';
import { resolve, relative } from 'node:path';
import v8toIstanbul from 'v8-to-istanbul';
import coverage from 'istanbul-lib-coverage';
import reports from 'istanbul-reports';
import libReport from 'istanbul-lib-report';

// Always include every production script, even when the browser never requested it.
export class BrowserCoverage {
 constructor() { this.entries=[]; this.running=false; }
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
  const scripts=(directory)=>readdirSync(directory,{withFileTypes:true}).flatMap(entry=>entry.isDirectory() ? scripts(resolve(directory,entry.name)) : entry.name.endsWith('.js') ? [resolve(directory,entry.name)] : []);
  const paths=scripts('assets/js');
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
   const path=paths.find(file=>url.pathname.endsWith('/'+relative(process.cwd(),file)));
   if (!path) continue;
   if (entry.source!==readFileSync(path,'utf8')) throw new Error('Browser script differs from production source: '+path);
   const converter=v8toIstanbul(path,0,{source:entry.source});
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
