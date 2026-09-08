'use strict';
// Synthetic cases only. Optional browser checks have all external requests blocked.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),os=require('node:os');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(path.join(root,'assets/crm/crm.js'),'utf8');
function method(name){const start=source.indexOf('    '+name+': function (');assert(start>=0,name);const tail=source.slice(start+1),next=tail.search(/\n    \w+: function \(/);assert(next>=0,name+' boundary');return source.slice(start,start+1+next);}
const names=['resultReferenceText','resultReferenceEntry','resultReferenceHtml','openResultDialog','copyResultToDialog','formSection','followupOffsetChecks','openResultFromAction','collectForm','submitResult'];
// collectForm exists in several modules: select the visit implementation explicitly.
function visitCollect(){const start=source.indexOf('    collectForm: function (',source.indexOf('    openResultDialog: function ('));assert(start>0);const end=source.indexOf('\n    submitVisit:',start);assert(end>start);return source.slice(start,end);}
const methods=names.map(n=>n==='collectForm'?visitCollect():method(n)).join('\n');
const bootstrap=`var esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
var notices=[],toast=t=>notices.push(t),captured=[],posted=[];
var CustomerModule={currentId:0,openBusinessDialog:(title,html,subtitle,bind)=>{captured.push(html);if(typeof document!=='undefined'){var dialog=document.querySelector('[data-customer-dialog]');dialog.innerHTML='<div class="customer-dialog-box crm-modal-panel"><header class="crm-modal-header">'+esc(title)+'</header><main class="customer-dialog-body crm-modal-body">'+html+'</main></div>';dialog.showModal();bind(dialog);}},closeDialog:()=>{if(typeof document!=='undefined')document.querySelector('[data-customer-dialog]').close();}};
var post=async(a,p)=>{posted.push({a,p});return {success:true,data:{record:{id:p.visit_id}}};};
var V={${methods}
options:(values,selected)=>values.map(v=>'<option'+(v===selected?' selected':'')+'>'+esc(v)+'</option>').join(''),
needChecks:()=>'',fileUploadBlock:()=>'',bindFileInputs:()=>{},loadVisitFiles:()=>{},uploadQueuedFiles:async()=>{},load:()=>{},selected:()=>null,detailCache:{},formError:()=>{}};`;
const prior={id:1,result:'需要报价',result_note:'上一条总结',customer_feedback:'旧反馈',customer_needs:'旧需求',products_discussed:'旧产品',next_action:'旧计划',actual_time:'2026-09-01 10:00',created_at:'2026-09-01 11:00',created_by_name:'验收人员'};
(async()=>{
 const ctx=vm.createContext({});vm.runInContext(bootstrap,ctx);const V=ctx.V;
 const row={id:10,planned_note:'原计划 <img src=x onerror=alert(1)>',result_history:[]};
 let html=V.resultReferenceHtml(row);assert(html.includes('原拜访备注（计划内容）'));assert(html.includes(' open'));assert(html.includes('&lt;img'));assert(!html.includes('<img'));
 assert(!html.includes('data-visit-copy-last-result'));assert(V.resultReferenceHtml({}).includes('暂无历史结果'));
 const historyRow={id:10,planned_note:'计划内容',result_history:[prior,{...prior,id:2,result_note:'更早结果'}]};
 const before=JSON.stringify(historyRow);V.openResultDialog(historyRow);assert.equal(JSON.stringify(historyRow),before);
 html=ctx.captured.at(-1);assert(html.includes('查看更早结果（1 条）'));assert(html.includes('更早结果'));assert(html.includes('客户反馈：旧反馈'));assert(html.includes('name="result_note" rows="4"></textarea>'),'New result remains empty');
 assert(V.resultReferenceText('长'.repeat(900)).includes('展开全文'));assert(V.resultReferenceText('短').includes('<p>短</p>'));
 V.detailCache[10]={id:10,result_history:[]};let reads=0,opened=[];V.loadDetail=async()=>{reads++;return historyRow;};V.openResultDialog=r=>opened.push(r);
 await V.openResultFromAction(V.detailCache[10]);assert.equal(reads,1);assert.equal(opened[0],historyRow,'Ignore stale empty cached history');
 V.loadDetail=async()=>null;await V.openResultFromAction(row);assert.equal(opened.length,1,'Failed history fetch must not open blank form');
 V.loadDetail=async()=>({id:999,result_history:[]});await V.openResultFromAction(row);assert.equal(opened.length,1,'Wrong record rejected');
 const pending={};V.loadDetail=id=>new Promise(resolve=>pending[id]=resolve);const a=V.openResultFromAction({id:1}),b=V.openResultFromAction({id:2});pending[2]({id:2,result_history:[]});await b;pending[1]({id:1,result_history:[]});await a;assert.equal(opened.at(-1).id,2);assert.equal(opened.length,2,'Older click cannot replace latest form');
 console.log('Visit result reference runtime: plan fallback, history, text safety, empty new fields, fresh load, failures and out-of-order clicks passed.');
 if(process.env.VISIT_BROWSER_TEST!=='1')return;
 const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN||undefined});
 try{
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>r.abort());
  await page.setContent('<!doctype html><dialog class="customer-dialog crm-modal" data-customer-dialog></dialog>');
  for(const file of ['themes.css','crm.css'])await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm',file),'utf8')});await page.addScriptTag({content:bootstrap});
  const output=fs.mkdtempSync(path.join(os.tmpdir(),'visit-result-reference-'));
  for(const width of [390,768,1280]){
   await page.setViewportSize({width,height:900});
   await page.evaluate(row=>V.openResultDialog(row),{id:10,planned_note:'原计划内容。'.repeat(150)+'计划尾部',result_history:[]});
   assert(await page.locator('.visit-reference-plan').getAttribute('open')!==null);
   await page.locator('.visit-reference-full > summary').click();assert(await page.getByText('计划尾部',{exact:false}).last().isVisible());
   assert.equal(await page.locator('textarea[name="result_note"]').inputValue(),'');
   assert(await page.evaluate(()=>document.querySelector('[data-customer-dialog]').scrollWidth<=innerWidth));
   await page.screenshot({path:path.join(output,'plan-'+width+'.png')});await page.evaluate(()=>CustomerModule.closeDialog());
   await page.evaluate(row=>V.openResultDialog(row),{...historyRow,result_history:[{...prior,result_note:'新近总结。'.repeat(180)+'最新尾部'}, {...prior,result_note:'更早完整结果'}]});
   await page.locator('.visit-reference-full > summary').click();assert(await page.getByText('最新尾部',{exact:false}).last().isVisible());
   await page.locator('.visit-reference-history > summary').click();assert(await page.getByText('更早完整结果',{exact:false}).isVisible());
   const content=page.locator('.visit-reference-entry p').first();assert(await content.evaluate(el=>getComputedStyle(el).overflowY==='visible'));
   assert.equal(await page.locator('textarea[name="result_note"]').inputValue(),'');
   await page.locator('[data-visit-copy-last-result]').click();assert((await page.locator('textarea[name="result_note"]').inputValue()).includes('最新尾部'));
   await page.locator('textarea[name="result_note"]').fill('本次新增测试总结');
   assert((await page.locator('.visit-reference-entry').first().textContent()).includes('最新尾部'),'Editing does not mutate reference');
   await page.locator('.visit-result-reference').evaluate(el=>el.scrollIntoView({block:'start'}));
   await page.screenshot({path:path.join(output,'history-'+width+'.png')});
   await page.locator('[data-visit-result-save]').click();await page.waitForFunction(()=>!document.querySelector('[data-customer-dialog]').open);
   const saved=await page.evaluate(()=>posted.at(-1));assert.equal(saved.a,'visit_result_save');assert.equal(saved.p.result_note,'本次新增测试总结');assert.equal(saved.p.visit_id,'10');assert(!('planned_note'in saved.p));assert(!('result_history'in saved.p));
  }
  assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,output,checks:'3 widths x plan/history; full text; older history; explicit copy; separate edit and fake save'}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
