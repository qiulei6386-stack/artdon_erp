'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(path.join(root,'dispatch_next.php'),'utf8');
assert(source.includes('DispatchDaily.install({api,openTask:openDetail})'));
assert(/async function openDetail\(/.test(source));
// Compile the real inline page scripts too: adapter integration must not break the workbench.
const vm=require('node:vm');for(const m of source.replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g,'null').matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)){if(!m[1].trim())continue;new vm.Script(m[1]);}
(async()=>{
 const output=fs.mkdtempSync(path.join(os.tmpdir(),'dispatch-daily-ui-'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BIN||undefined});
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',r=>r.abort());
 await page.setContent('<!doctype html><html lang="zh-CN"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><section class="toolbar"><button id="dailyReportBtn">今日总结</button></section><dialog id="detail"><h2>任务详情</h2><button id="closeDetail">关闭详情</button></dialog></body></html>');
 for(const m of source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g))await page.addStyleTag({content:m[1]});
 await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/dispatch/daily-report.css'),'utf8')});
 await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/dispatch/daily-report.js'),'utf8')});
 await page.evaluate(()=>{
  window.calls=[];window.confirm=()=>false;
  window.reply=p=>{const id=p.user_id??1,section=p.section||'completed';return {date:p.date||'2026-09-08',today:'2026-09-08',historical:p.date==='2026-09-07',started_at:'2026-09-07 09:00:00',partial:false,user_id:id,user_name:id===0?'全员汇总':id===1?'示例人员甲':'示例人员乙',is_admin:!window.normalUser,users:[{id:1,name:'示例人员甲'},{id:2,name:'示例人员乙 · 名称很长也不能挤在一起'}],counts:{completed:23,pending:4,overdue:2,changes:8,tomorrow:3,active:5},items:Array.from({length:section==='changes'?3:5},(_,i)=>({id:i+1,title:'产品目录修订与客户跟进 · 很长的待办标题检查自然换行 '+i,task_no:'DN-SYNTHETIC-'+i,task_type:'personal',owner_name:'示例人员甲',status:section==='completed'?'done':'in_progress',due_at:'2026-09-08 18:00:00',completed_at:'2026-09-08 10:25:00',progress:65,time:'14:20:00',actor_name:'示例人员乙',changes:section==='changes'?[{field:'due_at',label:'截止时间',before:'2026-09-08 18:00:00',after:'2026-09-10 18:00:00'},{field:'status',label:'状态',before:'in_progress',after:'done'}]:[]})),section,page:p.page||1,pages:2,team:[{id:1,name:'示例人员甲',counts:{completed:12,pending:3,overdue:2,changes:4}},{id:2,name:'示例人员乙 · 名称很长也不能挤在一起',counts:{completed:11,pending:1,overdue:0,changes:4}}],note:{note:'',version:0},can_edit_note:id===1&&p.date!=='2026-09-07',csrf:'synthetic'};};
  window.testDaily=DispatchDaily.install({api:async(action,p)=>{calls.push({action,p});if(window.defer&&action==='daily_report')return new Promise(resolve=>window.deferred.push({p,resolve}));if(action==='daily_note_save'){if(window.failSave)throw Error('模拟保存失败');return {note:p.note,version:p.version+1};}return reply(p);},openTask:async id=>{window.openedTask=id;document.querySelector('#detail').showModal();}});
  document.querySelector('#closeDetail').onclick=()=>document.querySelector('#detail').close();
 });
 assert.equal(await page.evaluate(()=>calls.length),0,'No query on page startup');
 await page.locator('#dailyReportBtn').click();await page.locator('.dd-summary').waitFor();
 const layouts=[];
 for(const size of [{width:360,height:640},{width:390,height:844},{width:768,height:900},{width:1280,height:800},{width:1440,height:900}]){
  await page.setViewportSize(size);
  for(const section of ['completed','changes']){
   await page.locator(`[data-dd-section="${section}"]`).click();await page.waitForFunction(s=>document.querySelector(`[data-dd-section="${s}"]`).getAttribute('aria-pressed')==='true',section);
   await page.waitForTimeout(180);
   const m=await page.evaluate(()=>{const d=document.querySelector('.dd-dialog'),b=document.querySelector('.dd-body'),f=document.querySelector('.dd-foot').getBoundingClientRect();return {width:d.clientWidth,scroll:d.scrollWidth,bodyWidth:b.clientWidth,bodyScroll:b.scrollWidth,bodyHeight:b.clientHeight,foot:f.bottom};});
   assert(m.scroll<=m.width+1&&m.bodyScroll<=m.bodyWidth+1,`No horizontal overflow ${JSON.stringify({size,m})}`);assert(m.bodyHeight>200&&m.foot<=size.height+1,'Body and footer visible');
   layouts.push({size,section,m});await page.screenshot({path:path.join(output,`${size.width}-${section}.png`)});
  }
 }
 await page.setViewportSize({width:1280,height:800});
 await page.locator('[data-dd-user]').selectOption('0');await page.locator('.dd-team').waitFor();
 await page.screenshot({path:path.join(output,'team.png')});
 await page.locator('[data-dd-person-id="2"]').click();await page.waitForFunction(()=>document.querySelector('#dd-title').textContent.includes('人员乙'));
 assert.equal(await page.locator('[data-dd-note]').count(),0,'Admin cannot rewrite another person supplement');
 await page.locator('[data-dd-user]').selectOption('1');await page.locator('[data-dd-note]').waitFor();
 await page.locator('[data-dd-note]').fill('需要工程部提供图片');
 await page.locator('[data-dd-user]').selectOption('2');assert.equal(await page.locator('[data-dd-user]').inputValue(),'1','Unsaved-note scope switch cancelled');
 await page.locator('[data-dd-close]').click();assert(await page.locator('.dd-dialog').evaluate(d=>d.open),'Unsaved note protects close');
 await page.evaluate(()=>window.failSave=true);await page.locator('[data-dd-save]').click();
 assert.equal(await page.locator('[data-dd-note]').inputValue(),'需要工程部提供图片','Failure preserves input');
 await page.evaluate(()=>window.failSave=false);await page.locator('[data-dd-save]').click();await page.waitForFunction(()=>document.querySelector('[data-dd-note-status]').textContent==='已保存');
 const before=await page.locator('.dd-body').evaluate(el=>el.scrollTop);
 await page.locator('[data-dd-task]').first().click();assert.equal(await page.evaluate(()=>openedTask),1);await page.locator('#closeDetail').click();
 assert(await page.locator('.dd-dialog').evaluate(d=>d.open),'Underlying summary survives task dialog');
 // Delayed first result must never overwrite a more recently selected report.
 await page.evaluate(()=>{window.defer=true;window.deferred=[];testDaily.load({date:'2026-09-07',user_id:1});testDaily.load({date:'2026-09-08',user_id:2});});
 await page.evaluate(()=>deferred[1].resolve(reply(deferred[1].p)));await page.waitForFunction(()=>document.querySelector('#dd-title').textContent.includes('人员乙'));
 await page.evaluate(()=>deferred[0].resolve(reply(deferred[0].p)));await page.waitForTimeout(50);assert((await page.locator('#dd-title').innerText()).includes('人员乙'));
 await page.evaluate(()=>{window.defer=false;window.normalUser=true;testDaily.load({date:'2026-09-08',user_id:1});});await page.waitForFunction(()=>document.querySelector('[data-dd-person]').hidden);
 assert.equal(await page.locator('[data-dd-user]:visible').count(),0,'Normal user gets no people switch');
 await page.locator('[data-dd-close]').click();assert(!(await page.locator('.dd-dialog').evaluate(d=>d.open)));
 assert.equal(errors.length,0,errors.join('\n'));console.log(JSON.stringify({passed:true,layouts:layouts.length,output,network:'offline fixtures only'}));await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
