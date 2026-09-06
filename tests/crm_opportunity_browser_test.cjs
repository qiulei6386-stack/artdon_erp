'use strict';
// Offline original opportunity methods with native forms/dialogs and fixture transport.
// This is a component check, not production-account or database acceptance.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(process.env.CRM_PLAYWRIGHT_MODULE || path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
const root=path.join(__dirname,'..'),source=fs.readFileSync(path.join(root,'assets/crm/crm.js'),'utf8');
function method(module,name) {
  const base=source.indexOf('  var '+module+' = {'),start=source.indexOf('    '+name+': function',base),end=source.indexOf('\n    },',start)+7;
  assert(start>base && end>start,name);return source.slice(start,end);
}
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:process.env.CRM_CHROME_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',args:['--disable-background-networking','--disable-sync']});
  try {
    const context=await browser.newContext({serviceWorkers:'block'}),requests=[],errors=[];
    await context.route('**/*',r=>{requests.push(r.request().url());return r.abort();});await context.setOffline(true);
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    await page.setContent('<!doctype html><html lang="zh-CN" data-crm-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'"><body class="crm-app"><h1>商机关联任务 · 离线验收</h1><p id="feedback" role="status"></p><dialog class="customer-dialog crm-modal"><form method="dialog" class="customer-dialog-box crm-modal-panel"><header class="crm-modal-header"><h2 class="crm-modal-title"></h2></header><div class="customer-dialog-body crm-modal-body" data-body></div><footer class="crm-modal-footer"><span data-hint></span><div data-actions></div></footer></form></dialog></body></html>');
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/themes.css'),'utf8')});
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/crm.css'),'utf8')});
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/crm/workspace.css'),'utf8')});
    await page.addScriptTag({content:`
      var state={csrf:'fixture'},jobs=[],messages=[];
      function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
      function toast(m){messages.push(m);document.querySelector('#feedback').textContent=m;}
      function post(action,data){return new Promise((resolve,reject)=>jobs.push({action,data,resolve,reject}));}
      var CustomerModule={currentId:0,closeDialog(){document.querySelector('dialog').close();},openBusinessDialog(title,html,hint,binder){const d=document.querySelector('dialog');d.querySelector('h2').textContent=title;d.querySelector('[data-hint]').textContent=hint;d.querySelector('[data-body]').innerHTML='<div class="customer-business-dialog">'+html+'</div>';const actions=d.querySelector('.business-dialog-actions');d.querySelector('[data-actions]').innerHTML=actions?actions.innerHTML:'';if(actions)actions.remove();if(!d.open)d.showModal();binder(d);}};
      var TaskCenterModule={${['requestToken','runBusy'].map(n=>method('TaskCenterModule',n)).join('\n')}};
      var OpportunityModule={${['openLinkedTask','saveLinkedTask','collect','ownsDialogForm','userOptions','priorityOptions','save','uploadQueuedFiles','uploadFileInput'].map(n=>method('OpportunityModule',n)).join('\n')}};
      OpportunityModule.users=[{id:9,display_name:'测试负责人'}];OpportunityModule.load=()=>{};
      function openTask(type='sample_task'){OpportunityModule.openLinkedTask({id:51,customer_id:101,opportunity_name:'离线样品项目',customer_name:'测试客户',priority:'normal'},type);}
      function openSave(){CustomerModule.openBusinessDialog('附件重试检查','<div data-opportunity-form><input name="opportunity_id" value=""><input type="checkbox" name="create_followup" checked><input type="file" data-opportunity-image-input><input type="file" data-opportunity-attachment-input><p data-opportunity-error></p></div><button data-opportunity-save>保存商机</button>','仅测试夹具',d=>{d.querySelector('[data-opportunity-save]').onclick=()=>OpportunityModule.save(d);});}
      var uploads=[];window.fetch=(url,options)=>new Promise(resolve=>uploads.push({body:options.body,resolve}));
    `});
    const artifacts=fs.mkdtempSync(path.join(os.tmpdir(),'crm-opportunity-browser-'));
    for(const width of [390,1200]) {
      await page.setViewportSize({width,height:850});await page.evaluate(()=>openTask());
      await page.locator('[name="title"]').fill('样品准备：离线项目');
      assert.equal(await page.locator('[name="opportunity_id"]').inputValue(),'51');
      assert.equal(await page.evaluate(()=>document.querySelector('dialog').scrollWidth<=document.querySelector('dialog').clientWidth),true,'Dialog horizontal overflow');
      await page.screenshot({path:path.join(artifacts,'task-'+width+'.png')});
      await page.keyboard.press('Escape');assert(!await page.locator('dialog').evaluate(d=>d.open));
    }
    await page.evaluate(()=>openTask('material_task'));
    await page.locator('[data-opportunity-task-save]').click();
    assert(await page.locator('[data-opportunity-task-save]').isDisabled());
    await page.evaluate(()=>OpportunityModule.saveLinkedTask(document.querySelector('dialog')));
    assert.equal(await page.evaluate(()=>jobs.length),1);
    await page.evaluate(()=>jobs[0].resolve({success:false,message:'模拟断网，可重试'}));
    await page.waitForFunction(()=>!document.querySelector('[data-opportunity-task-save]').disabled);
    await page.locator('[data-opportunity-task-save]').click();
    assert.equal(await page.evaluate(()=>jobs[0].data.request_token===jobs[1].data.request_token),true);
    await page.evaluate(()=>{openTask();jobs[1].resolve({success:true,data:{task:{id:71},reused:true}});});
    await page.waitForFunction(()=>messages.some(m=>m.includes('#71')));
    assert(await page.locator('dialog').evaluate(d=>d.open));
    assert.equal(await page.locator('[name="task_type"]').inputValue(),'sample_task');
    await page.evaluate(()=>openSave());
    await page.locator('[data-opportunity-image-input]').setInputFiles({name:'fixture.png',mimeType:'image/png',buffer:Buffer.from('fake offline fixture')});
    await page.locator('[data-opportunity-attachment-input]').setInputFiles({name:'fixture.txt',mimeType:'text/plain',buffer:Buffer.from('fixture')});
    await page.locator('[data-opportunity-save]').click();
    await page.evaluate(()=>jobs[2].resolve({success:true,data:{opportunity:{id:91}}}));
    await page.waitForFunction(()=>uploads.length===2);
    await page.evaluate(()=>uploads[0].resolve({json:async()=>({success:true})}));
    await page.evaluate(()=>uploads[1].resolve({json:async()=>({success:false,message:'fixture failure'})}));
    await page.waitForFunction(()=>!document.querySelector('[data-opportunity-save]').disabled);
    assert.equal(await page.locator('[name="opportunity_id"]').inputValue(),'91');
    assert.equal(await page.locator('[data-opportunity-image-input]').inputValue(),'');
    assert((await page.locator('[data-opportunity-attachment-input]').inputValue()).includes('fixture.txt'));
    await page.locator('[data-opportunity-save]').click();
    assert.equal(await page.evaluate(()=>jobs[3].data.opportunity_id),'91');
    await page.evaluate(()=>jobs[3].resolve({success:true,data:{opportunity:{id:91}}}));
    await page.waitForFunction(()=>uploads.length===3);
    assert.equal(await page.evaluate(()=>uploads[2].body.get('file_type')),'attachment');
    await page.evaluate(()=>uploads[2].resolve({json:async()=>({success:true})}));
    await page.waitForFunction(()=>!document.querySelector('dialog').open);
    assert.deepEqual(errors,[]);assert.deepEqual(requests,[]);
    console.log(JSON.stringify({result:'PASS',checks:['390/1200 native dialog','escape','business IDs','duplicate click lock','retry same token','old response keeps new dialog','saved ID retained','successful upload cleared','failed attachment only retried'],artifacts,network_attempts:requests.length},null,2));
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
