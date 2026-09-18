'use strict';
// Offline layout test: original row renderer + shipped styles, no live session.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const root=path.join(__dirname,'..'),source=fs.readFileSync(path.join(root,'assets/crm/crm.js'),'utf8');
const a=source.indexOf('    renderRows: function'),b=source.indexOf('\n    },',a);
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',args:['--disable-background-networking','--disable-sync']});
 try{
  const context=await browser.newContext({serviceWorkers:'block'});await context.setOffline(true);await context.route('**/*',r=>r.abort());
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><h2>四类渠道筛选 · 离线验收</h2><div class="crm-panel customer-list-panel"><div class="customer-table-wrap"><table class="crm-table customer-table is-compact"><colgroup><col style="width:92px"><col style="width:240px"></colgroup><thead><tr><th>代码</th><th>客户及分类依据</th></tr></thead><tbody data-customer-rows></tbody></table></div></div>');
  await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/crm.css'),'utf8')});
  await page.addStyleTag({content:'body{margin:16px;background:#f5f7fb;font-family:Arial,sans-serif}.customer-list-panel{width:100%;max-width:650px}.customer-table{min-width:0;width:100%}'});
  await page.addScriptTag({content:'window.esc=v=>String(v??" ").replace(/[&<>"\x27]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",\x27"\x27:"&quot;","\x27":"&#39;"}[c]));window.host={'+source.slice(a,b+7)+'};'});
  await page.evaluate(()=>{
   Object.assign(host,{visibleColumns:()=>[{key:'customer_code'},{key:'customer_name'}],syncPageSelectionState(){},total:4});
   host.renderRows(['微信个人','WhatsApp个人','微信群','WhatsApp群'].map((label,i)=>({id:i+1,customer_code:'TEST00'+i,customer_name:'验收客户 '+i,channel_match:{label,sources:['客户推广方式','联系人：张三、Alex'],notes:[i<2?'缺个人号码':'2个群 · 1个暂停 · 1个不用于推广']}})));
  });
  const artifacts=fs.mkdtempSync(path.join(os.tmpdir(),'crm-channel-browser-'));
  for(const width of [390,768,1280]){
   await page.setViewportSize({width,height:850});
   assert.equal(await page.locator('.customer-channel-match').count(),4);
   assert(await page.locator('.customer-channel-match').first().isVisible());
   assert(await page.locator('.customer-channel-match').evaluateAll(es=>es.every(e=>{
    const r=e.getBoundingClientRect(),p=e.parentElement.getBoundingClientRect();
    return e.scrollWidth<=e.clientWidth+1 && e.scrollHeight<=e.clientHeight+1 && r.bottom<=p.bottom+1 && r.left>=p.left && r.right<=p.right+1;
   })),'No note or parent-cell clipping');
   await page.screenshot({path:path.join(artifacts,width+'.png')});
  }
  assert.deepEqual(errors,[]);console.log(JSON.stringify({result:'PASS',widths:[390,768,1280],artifacts}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
