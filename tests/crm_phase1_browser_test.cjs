'use strict';

// Controlled frontend integration, NOT whole-application end-to-end validation.
// Original candidate methods run in an ephemeral Chrome context with real DOM,
// native dialog/ESC and MutationObserver. Transport and role-contract responses
// are fixtures. No application bootstrap, real account, database, SMTP or user
// browser profile is loaded. The surrounding detail renderer is a minimal fixture.
// Run with Node; optionally set CRM_PLAYWRIGHT_MODULE or CRM_CHROME_PATH.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
const sourceHash = crypto.createHash('sha256').update(source).digest('hex');
let playwright;
try { playwright = require(process.env.CRM_PLAYWRIGHT_MODULE || 'playwright'); }
catch (error) {
  if (process.env.CRM_PLAYWRIGHT_MODULE) throw error;
  playwright = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'));
}
const chromePath = process.env.CRM_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
assert(fs.existsSync(chromePath), 'Set CRM_CHROME_PATH to an existing isolated-test browser executable');

function extract(module, method) {
  const moduleStart = source.indexOf('  var ' + module + ' = {');
  const moduleEnd = source.indexOf('\n  };', moduleStart);
  const start = source.indexOf('    ' + method + ': function', moduleStart);
  let end = source.indexOf('\n    },', start);
  if (end < 0 || moduleEnd < end) end = moduleEnd;
  else end += '\n    },'.length;
  assert(moduleStart >= 0 && start > moduleStart && start < moduleEnd && end > start, 'Missing original method: ' + module + '.' + method);
  return source.slice(start, end);
}
const customerMethods = ['loadDetail', 'ensureFullDetail', 'archiveAttributeData', 'saveArchiveAttribute',
  'openFollowupDialog', 'openReadOnlyDialog', 'openBusinessDialog', 'openDialog', 'closeDialog', 'showCustomerError'];
const taskMethods = ['requestToken', 'runBusy', 'actionPending', 'actionButton', 'openTaskFollowup', 'handleAction'];
const helperStart = source.indexOf('  function crmActionContract(');
const helperEnd = source.indexOf('  function hashParts(', helperStart);
assert(helperStart >= 0 && helperEnd > helperStart);

const html = `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; frame-src 'none'">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font:16px system-ui;margin:24px;color:#1c2938;background:#f4f6f8}h1{font-size:22px}button,input,select,textarea{font:inherit;padding:9px;margin:4px}button{cursor:pointer}button:disabled{cursor:default;opacity:.5}.active{outline:2px solid #1574aa}section,article{background:white;padding:16px;margin:12px 0;border:1px solid #d8dee7;border-radius:8px}label{display:block}.entity-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.wide{grid-column:1/-1}dialog{border:1px solid #aab8c8;border-radius:10px;width:min(760px,88vw);max-height:84vh}dialog::backdrop{background:#25354d66}[data-dialog-close]{float:right}[data-dialog-body]{max-height:60vh;overflow:auto}[data-feedback]{min-height:24px;color:#ad2f20}.scope{font-size:14px;color:#526274}textarea{width:90%}</style>
</head><body><h1>CRM 第一阶段 · 离线浏览器专项</h1>
<p class="scope">候选原方法 + 真实浏览器交互；周边界面为测试夹具，角色与数据均虚构。不是完整 CRM 页面验收。</p>
<section><label>角色夹具 <select id="role"><option value="full">操作角色</option><option value="task-editor">任务编辑角色</option><option value="followup">跟进角色</option><option value="readonly">只读角色</option></select></label><div id="actions"></div></section>
<section><button type="button" data-customer-row="101">客户 A</button><button type="button" data-customer-row="202">客户 B</button><button type="button" id="full-detail">读取完整资料</button><span data-customer-search-status></span></section>
<main data-customer-detail>请选择离线测试客户。</main><div data-feedback role="status"></div>
<dialog data-customer-dialog><button type="button" data-dialog-close aria-label="关闭弹窗">×</button><h2 data-dialog-title></h2><p data-dialog-description></p><form data-customer-form><div data-dialog-body></div><p data-dialog-hint></p><footer data-dialog-footer-actions></footer></form></dialog>
</body></html>`;

const setup = `
var state = {action_permissions:{'编辑任务':false,'标记完成':false},action_contracts:{}};
var h = window.h = {requests:[],messages:[],routes:[],jobs:0,cancelEvents:0};
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function toast(message){h.messages.push(String(message));document.querySelector('[data-feedback]').textContent=String(message);}
function renderActions(){}
function post(action,payload){return new Promise(function(resolve,reject){h.requests.push({action:action,payload:payload,resolve:resolve,reject:reject,settled:false});});}
h.track=function(promise){h.jobs++;return Promise.resolve(promise).catch(function(e){toast('UNEXPECTED: '+e.message);}).finally(function(){h.jobs--;});};
${source.slice(helperStart, helperEnd)}
var CustomerModule = window.CustomerModule = {${customerMethods.map(name => extract('CustomerModule', name)).join('\n')}};
var TaskCenterModule = window.TaskCenterModule = {${taskMethods.map(name => extract('TaskCenterModule', name)).join('\n')}};
Object.assign(CustomerModule,{
  currentId:0,currentDetail:null,detailRequestSeq:0,detailSelectionSeq:0,layoutMode:'default',activeDetailTab:'overview',
  restoreDetailTab:function(){return 'overview';},applyLayoutMode:function(){},renderSelection:function(){},
  loadCustomerPromotionFeedback:function(){},loadList:function(){return Promise.resolve();},selectedOwnerIdsFromForm:function(){return [];},
  bindEntrySystem:function(){},bindOwnerSelection:function(){},bindCountryRegionLink:function(){},renderEntryContacts:function(){},bindPhoneDialSearch:function(){},switchDetailTab:function(){},
  renderDetail:function(data){
    var box=document.querySelector('[data-customer-detail]');
    box.innerHTML='<article data-rendered-customer="'+esc(data.customer.id)+'"><h2>'+esc(data.customer.customer_name)+'</h2><p>当前实体 ID：'+esc(data.customer.id)+'</p><form data-archive-attribute-form><input type="hidden" name="customer_id" value="'+esc(data.customer.id)+'"><label>客户名称 <input name="customer_name" value="'+esc(data.customer.customer_name)+'" disabled></label><button type="button" data-archive-edit>编辑测试档案</button><button type="button" data-archive-save disabled>保存测试档案</button></form><div data-detail-panel="history"></div></article>';
    this.archiveEditMode=false;
    box.querySelector('[data-archive-edit]').onclick=function(){CustomerModule.archiveEditMode=true;box.querySelector('[name="customer_name"]').disabled=false;box.querySelector('[data-archive-save]').disabled=false;};
    box.querySelector('[data-archive-save]').onclick=function(){h.track(CustomerModule.saveArchiveAttribute());};
    box.querySelector('form').onsubmit=function(e){e.preventDefault();};
  }
});
Object.assign(TaskCenterModule,{
  row:null,view:'my',selectedType:'task',selected:function(){return this.row;},isViewAction:function(){return false;},quoteFlowFilterKeyFromLabel:function(){return '';},
  isFollowupTask:function(){return false;},isSampleTask:function(){return false;},
  openTaskDialog:function(){h.routes.push('tasks.edit');},updateTaskStatus:function(){h.routes.push('tasks.complete');}
});
h.role=function(role){
  var edit=role==='full'||role==='task-editor',followup=role==='full'||role==='followup';
  state.action_contracts={tasks:{'编辑任务':{id:'tasks.edit',allowed:edit},'标记完成':{id:'tasks.complete',allowed:role==='full'},'新建跟进':{id:'tasks.create_followup',allowed:followup}},promotion:{'编辑任务':{id:'promotion.edit',allowed:false},'标记完成':{id:'promotion.manual_complete',allowed:false}}};
  document.querySelector('#actions').innerHTML=['编辑任务','标记完成','新建跟进','查询物流'].map(function(label){return TaskCenterModule.actionButton(label);}).join('');
};
document.querySelector('#role').onchange=function(){h.role(this.value);};
document.querySelector('#actions').onclick=function(event){var button=event.target.closest('[data-task-detail-action]');if(button&&!button.disabled)h.track(TaskCenterModule.handleAction(button.dataset.taskDetailAction));};
document.querySelectorAll('[data-customer-row]').forEach(function(button){button.onclick=function(){h.track(CustomerModule.loadDetail(Number(button.dataset.customerRow)));};});
document.querySelector('#full-detail').onclick=function(){h.track(CustomerModule.ensureFullDetail('history'));};
document.querySelector('[data-customer-form]').onsubmit=function(event){event.preventDefault();};
document.querySelector('[data-customer-dialog]').addEventListener('cancel',function(){h.cancelEvents++;});
h.role('full');
`;

function customer(id, lazy = false) {
  return {success:true,data:{customer:{id,customer_name:'离线测试客户 '+(id===101?'A':'B')},contacts:[],_lazy_detail:lazy?1:0}};
}
async function settle(page, index, response, reject = false) {
  await page.evaluate(async ({index,response,reject}) => {
    const request=window.h.requests[index];
    if(!request || request.settled)throw new Error('Missing/unexpected fixture request '+index);
    request.settled=true;
    if(reject)request.reject(new Error(response));else request.resolve(response);
    await new Promise(requestAnimationFrame);
  }, {index,response,reject});
}
async function load(page, id, lazy = false) {
  await page.locator('[data-customer-row="'+id+'"]').click();
  const index=await page.evaluate(()=>h.requests.length-1);
  await settle(page,index,customer(id,lazy));
  await page.waitForFunction(id=>CustomerModule.currentId===id && Number(document.querySelector('[data-rendered-customer]')?.dataset.renderedCustomer)===id,id);
}
async function pickerSelection(page, id = 101) {
  await page.getByRole('button',{name:'新建跟进',exact:true}).click();
  await page.locator('[data-task-followup-search]').fill('离线测试');
  await page.getByRole('button',{name:'搜索客户',exact:true}).click();
  const search=await page.evaluate(()=>h.requests.length-1);
  await settle(page,search,{success:true,data:{rows:[{id,customer_name:'选择离线客户 '+id}]}});
  await page.locator('[data-task-followup-customer="'+id+'"]').click();
  return page.evaluate(()=>h.requests.length-1);
}
async function entitySnapshot(page) {
  return page.evaluate(()=>({selected:CustomerModule.currentId,detail:CustomerModule.currentDetail?.customer.id,
    form:Number(document.querySelector('[data-archive-attribute-form] [name="customer_id"]')?.value||0),
    rendered:Number(document.querySelector('[data-rendered-customer]')?.dataset.renderedCustomer||0),messages:h.messages.slice()}));
}

(async()=>{
  const artifactDir=fs.mkdtempSync(path.join(os.tmpdir(),'crm-phase1-browser-'));
  const browser=await playwright.chromium.launch({headless:true,executablePath:chromePath,
    args:['--disable-background-networking','--disable-component-update','--disable-sync','--no-first-run']});
  const context=await browser.newContext({viewport:{width:1120,height:940},serviceWorkers:'block',acceptDownloads:false});
  const blocked=[];
  await context.route('**/*',route=>{blocked.push(route.request().url());return route.abort();});
  await context.setOffline(true);
  const results=[],screenshots=[];
  async function test(name,run) {
    const page=await context.newPage(),errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.setContent(html);
    await page.addScriptTag({content:setup});
    try {
      await run(page);
      assert.deepEqual(errors,[],'Unexpected page errors');
      assert.deepEqual(await page.evaluate(()=>h.messages.filter(x=>x.startsWith('UNEXPECTED:'))),[]);
      results.push(name);console.log('PASS '+name);
    }catch(error){
      const shot=path.join(artifactDir,'failure-'+(results.length+1)+'.png');
      await page.screenshot({path:shot,fullPage:true});
      console.error('Failure screenshot: '+shot);throw error;
    }finally{await page.close();}
  }
  try {
    await test('role-contract fixture controls native buttons and guards a stale action',async page=>{
      await page.locator('#role').selectOption('task-editor');
      assert.equal(await page.locator('[data-action-id="tasks.edit"]').count(),1);
      assert.equal(await page.locator('[data-action-id="tasks.complete"]').count(),0);
      assert.equal(await page.locator('[data-action-id="tasks.create_followup"]').count(),0);
      assert(await page.getByRole('button',{name:'查询物流（待接入）',exact:true}).isDisabled());
      await page.getByRole('button',{name:'编辑任务',exact:true}).click();
      assert.deepEqual(await page.evaluate(()=>h.routes),['tasks.edit']);
      await page.locator('#role').selectOption('readonly');
      assert.equal(await page.locator('[data-action-id="tasks.edit"]').count(),0);
      await page.evaluate(()=>TaskCenterModule.handleAction('编辑任务'));
      assert.deepEqual(await page.evaluate(()=>h.routes),['tasks.edit']);
      assert.equal(await page.evaluate(()=>h.requests.length),0);
      assert.match(await page.locator('[data-feedback]').textContent(),/没有.*权限/);
      await page.locator('#role').selectOption('followup');
      await page.getByRole('button',{name:'新建跟进',exact:true}).click();
      assert(await page.locator('[data-customer-dialog]').isVisible());
    });
    await test('A slow / B fast leaves selected, detail, rendered and form IDs equal',async page=>{
      await page.locator('[data-customer-row="101"]').click();await page.locator('[data-customer-row="202"]').click();
      await settle(page,1,customer(202));await settle(page,0,customer(101));
      assert.deepEqual(await entitySnapshot(page),{selected:202,detail:202,form:202,rendered:202,messages:[]});
      assert(await page.locator('[data-customer-row="202"]').evaluate(el=>el.classList.contains('active')));
      const shot=path.join(artifactDir,'customer-B-race-protected.png');await page.screenshot({path:shot,fullPage:true});screenshots.push(shot);
    });
    await test('an old customer failure does not replace the current DOM or feedback',async page=>{
      await page.locator('[data-customer-row="101"]').click();await page.locator('[data-customer-row="202"]').click();
      await settle(page,1,customer(202));await settle(page,0,'Old offline fixture error',true);
      assert.deepEqual(await entitySnapshot(page),{selected:202,detail:202,form:202,rendered:202,messages:[]});
    });
    await test('wrong response entity cannot render a saveable form',async page=>{
      await page.locator('[data-customer-row="101"]').click();await settle(page,0,customer(202));
      assert.equal(await page.locator('[data-archive-save]').count(),0);
      assert.match(await page.locator('[data-feedback]').textContent(),/不一致/);
    });
    await test('full-detail native double-click deduplicates and stays customer-scoped',async page=>{
      await load(page,101,true);await page.locator('#full-detail').dblclick();
      assert.equal(await page.evaluate(()=>h.requests.filter(r=>r.payload.detail==='full').length),1);
      await load(page,202,true);await page.locator('#full-detail').click();
      await settle(page,1,customer(101));
      assert.equal(await page.evaluate(()=>CustomerModule.detailFullLoading.customerId),202);
      await settle(page,3,customer(202));
      assert.deepEqual(await entitySnapshot(page),{selected:202,detail:202,form:202,rendered:202,messages:[]});
    });
    await test('a mismatched native archive form is rejected before fake save transport',async page=>{
      await load(page,101);await page.locator('[data-archive-edit]').click();
      await page.locator('[data-archive-attribute-form] [name="customer_id"]').evaluate(el=>{el.value='202';});
      await page.locator('[data-archive-save]').click();
      assert.equal(await page.evaluate(()=>h.requests.length),1);assert.match(await page.locator('[data-feedback]').textContent(),/不一致/);
    });
    await test('native archive double-click sends one save and failure preserves input',async page=>{
      await load(page,101);await page.locator('[data-archive-edit]').click();
      await page.locator('[data-archive-attribute-form] [name="customer_name"]').fill('未保存的离线测试编辑');
      await page.locator('[data-archive-save]').dblclick();
      assert(await page.locator('[data-archive-save]').isDisabled());
      assert.equal(await page.evaluate(()=>h.requests.filter(r=>r.action==='customer_attribute_save').length),1);
      await settle(page,1,{success:false,message:'离线保存拒绝'});
      assert.equal(await page.locator('[name="customer_name"]').inputValue(),'未保存的离线测试编辑');
      assert(await page.locator('[data-archive-save]').isEnabled());
    });
    await test('saving A then editing B keeps the new native form untouched',async page=>{
      await load(page,101);await page.locator('[data-archive-edit]').click();await page.locator('[data-archive-save]').click();
      await load(page,202);await page.locator('[data-archive-edit]').click();await page.locator('[name="customer_name"]').fill('B 新编辑');
      await settle(page,1,{success:true,data:{}});
      assert.equal(await page.locator('[name="customer_name"]').inputValue(),'B 新编辑');
      assert(await page.locator('[data-archive-save]').isEnabled());assert.equal(await page.evaluate(()=>CustomerModule.archiveEditMode),true);
      assert.equal(await page.evaluate(()=>h.requests.length),3);
    });
    await test('successful picker opens the original follow-up form for the correct customer',async page=>{
      const index=await pickerSelection(page);await settle(page,index,customer(101));
      assert.equal(await page.locator('[data-customer-form]').getAttribute('data-action'),'followup_create');
      assert.equal(await page.locator('[data-customer-form] [name="customer_id"]').inputValue(),'101');
      assert.match(await page.locator('[data-dialog-body]').textContent(),/离线测试客户 A/);
      const shot=path.join(artifactDir,'native-followup-dialog.png');await page.screenshot({path:shot,fullPage:true});screenshots.push(shot);
    });
    for(const close of ['cancel-button','right-close','Escape'])await test('native '+close+' blocks delayed follow-up reopening',async page=>{
      const index=await pickerSelection(page);
      if(close==='cancel-button')await page.locator('[data-business-cancel]').click();
      else if(close==='right-close')await page.getByRole('button',{name:'关闭弹窗',exact:true}).click();
      else await page.keyboard.press('Escape');
      await page.waitForFunction(()=>!document.querySelector('[data-customer-dialog]').open);
      await settle(page,index,customer(101));
      assert.equal(await page.locator('[data-customer-dialog]').evaluate(el=>el.open),false);
      assert.notEqual(await page.locator('[data-customer-form]').getAttribute('data-action'),'followup_create');
      if(close==='Escape')assert.equal(await page.evaluate(()=>h.cancelEvents),1);
    });
    await test('native dialog body replacement keeps the replacement form and draft',async page=>{
      const index=await pickerSelection(page);
      await page.evaluate(()=>CustomerModule.openBusinessDialog('另一个离线表单','<label>新草稿<input id="replacement-draft"></label>','替换测试'));
      await page.locator('#replacement-draft').fill('保留此草稿');await settle(page,index,customer(101));
      assert.equal(await page.locator('[data-dialog-title]').textContent(),'另一个离线表单');
      assert.equal(await page.locator('#replacement-draft').inputValue(),'保留此草稿');
    });
    await test('same-customer later entry wins even when the old detail resolves first',async page=>{
      await page.evaluate(()=>{TaskCenterModule.row={customer_id:101};});
      await page.getByRole('button',{name:'新建跟进',exact:true}).click();await page.getByRole('button',{name:'新建跟进',exact:true}).click();
      await settle(page,0,customer(101));assert.equal(await page.locator('[data-customer-dialog]').evaluate(el=>el.open),false);
      await settle(page,1,customer(101));assert.equal(await page.locator('[data-customer-form]').getAttribute('data-action'),'followup_create');
      assert.equal(await page.locator('[data-customer-form] [name="customer_id"]').inputValue(),'101');
    });
    await test('same-customer later entry stays open when an older detail resolves last',async page=>{
      await page.evaluate(()=>{TaskCenterModule.row={customer_id:101};});
      await page.getByRole('button',{name:'新建跟进',exact:true}).click();await page.getByRole('button',{name:'新建跟进',exact:true}).click();
      await settle(page,1,customer(101));await page.locator('[name="content"]').fill('新表单草稿');
      await settle(page,0,customer(101));assert.equal(await page.locator('[name="content"]').inputValue(),'新表单草稿');
    });
    await test('real MutationObserver invalidates close/reopen with unchanged dialog content',async page=>{
      const index=await pickerSelection(page);
      await page.evaluate(()=>{const dialog=document.querySelector('[data-customer-dialog]');dialog.close();dialog.showModal();});
      await settle(page,index,customer(101));
      assert.equal(await page.locator('[data-customer-dialog]').evaluate(el=>el.open),true);
      assert.equal(await page.locator('[data-task-followup-picker]').count(),1);
      assert.notEqual(await page.locator('[data-customer-form]').getAttribute('data-action'),'followup_create');
    });
    await test('native ESC ignores a delayed picker search response',async page=>{
      await page.getByRole('button',{name:'新建跟进',exact:true}).click();await page.locator('[data-task-followup-search]').fill('离线测试');
      await page.getByRole('button',{name:'搜索客户',exact:true}).click();await page.keyboard.press('Escape');
      await settle(page,0,{success:true,data:{rows:[{id:101,customer_name:'不应出现'}]}});
      assert.equal(await page.locator('[data-customer-dialog]').evaluate(el=>el.open),false);
      assert.equal(await page.locator('[data-task-followup-customer]').count(),0);
    });
    assert.deepEqual(blocked,[],'Unexpected renderer network attempt was blocked');
    console.log(JSON.stringify({passed:results.length,source_sha256:sourceHash,browser:await browser.version(),
      network_attempts:blocked.length,transport:'in-memory fixtures only',role_scope:'frontend action-contract fixtures, not server authentication',
      ui_scope:'original methods with minimal surrounding DOM; not the whole CRM app',screenshots},null,2));
  }finally{await context.close();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
