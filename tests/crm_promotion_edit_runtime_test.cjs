'use strict';
// Execute the original editor entry with fake transport and UI, never a real API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
const start = source.indexOf('    openEditTaskDialog: function (taskId) {');
const end = source.indexOf('\n    },', start) + '\n    },'.length;
assert(start > 0 && end > start);
function harness(queueCount = 0) {
  const messages = [], requests = [];
  const task = {id: 11, task_status: 'draft', queue_count: queueCount};
  const context = vm.createContext({Number, Set, Array, toast: m => messages.push(m),
    post(action, input) { requests.push({action, input}); return context.transport(); },
    transport: () => Promise.resolve({success: true, data: {targets: [{customer_id: 3, contact_id: 5}]}})});
  const module = vm.runInContext('({' + source.slice(start, end) + '})', context);
  let opened = 0;
  Object.assign(module, {taskById: () => task, ensureTaskDetail: () => Promise.resolve(task),
    closeDialog() {}, closeWizard() {}, openWizard() {opened++;}, taskToWizardDraft() {return {};}, showError: m => messages.push(m)});
  return {module, context, task, messages, requests, opened: () => opened};
}
(async () => {
  let h = harness(4);
  await h.module.openEditTaskDialog(11);
  assert.equal(h.opened(), 0); assert.equal(h.requests.length, 0); assert.match(h.messages[0], /复制/);
  h = harness(); h.module.ensureTaskDetail = () => Promise.resolve({...h.task, queue_count: 1});
  await h.module.openEditTaskDialog(11);
  assert.equal(h.opened(), 0); assert.equal(h.requests.length, 0);
  for (const result of [{success: false, message: '名单读取失败'}, {success: true, data: {}}, {success: true, data: {targets: null}}]) {
    h = harness(); h.context.transport = () => Promise.resolve(result);
    await h.module.openEditTaskDialog(11);
    assert.equal(h.opened(), 0, 'Failed/malformed targets must never open an empty destructive editor');
    assert(h.messages.length > 0);
  }
  h = harness(); h.context.transport = () => Promise.reject(new Error('Network fixture'));
  await h.module.openEditTaskDialog(11); assert.equal(h.opened(), 0);
  h = harness(); await h.module.openEditTaskDialog(11);
  assert.equal(h.opened(), 1); assert.deepEqual(Array.from(h.module.selectedCustomerIds), [3]);
  assert.deepEqual(Array.from(h.module.selectedContactIds), [5]); assert.equal(h.module.wizardDraft.task_id, 11);
  h=harness();h.task.audience_config_json=JSON.stringify({group_mode:'selected',excluded_customers:[{id:9,name:'Suppressed'}]});
  h.context.transport=()=>Promise.resolve({success:true,data:{targets:[]}});
  await h.module.openEditTaskDialog(11);
  assert.deepEqual(Array.from(h.module.wizardDraft.customer_ids),[9],'Legacy excluded selection must remain visible on reopen');
  h=harness();h.task.audience_config_json=JSON.stringify({group_mode:'group',selection:{customer_ids:[3,9],contact_ids:[]}});
  h.module.taskToWizardDraft=()=>({group_mode:'group',contact_filter:'all_valid',customer_ids:[3,9],contact_ids:[]});
  await h.module.openEditTaskDialog(11);
  assert.deepEqual(Array.from(h.module.wizardDraft.customer_ids),[3,9]);
  assert.deepEqual(Array.from(h.module.selectedContactIds),[],'Generated target contacts must not become an explicit selection');
  assert.equal(h.module.wizardDraft.group_mode,'group');assert.equal(h.module.wizardDraft.contact_filter,'all_valid');
  const refreshStart=source.indexOf("if (label === '刷新推广中心'");
  const refreshEnd=source.indexOf("if (label === '查看我的待执行'",refreshStart);
  assert(!source.slice(refreshStart,refreshEnd).includes("toast('推广中心已刷新')"),'Do not announce success before the load completes');
  assert(source.slice(refreshStart,refreshEnd).includes('notify: true'));
  const switchStart=source.indexOf('switchView: function (view) {',source.indexOf('  var PromotionModule = {'));
  const switchEnd=source.indexOf('\n    poolFilterPayload:',switchStart);
  assert(switchStart>0 && switchEnd>switchStart);
  const node={classList:{toggle(){}},getAttribute(){return 'campaigns';}};
  const loads=[];
  const ctx=vm.createContext({document:{querySelector(){return {querySelector(){return node;},querySelectorAll(){return [];}};}},
    current:'promotion',window:{location:{hash:'#promotion:customer_pool'}},history:{replaceState(){}},renderActions(){}});
  const switched=vm.runInContext('({'+source.slice(switchStart,switchEnd)+'})',ctx);
  ctx.PromotionModule=switched;
  Object.assign(switched,{data:{loaded_view:'customer_pool'},saveState(){},syncSidebarCard(){},renderPoolFilters(){},load(o){loads.push(o);return Promise.resolve();}});
  switched.switchView('campaigns');switched.switchView('campaigns');
  assert.equal(loads.length,1,'Entering campaigns from a pool-only bootstrap must load once');
  assert.equal(loads[0].view,'campaigns');assert.equal(loads[0].noSwitch,true);
  await Promise.resolve();switched.data.loaded_view='campaigns';switched.switchView('campaigns');
  assert.equal(loads.length,1,'Already loaded campaigns must not refetch on each render');
  const progressStart=source.indexOf('      var hasMailBody =',source.indexOf('    renderTaskProperties:'));
  const progressEnd=source.indexOf('      var stepHtml =',progressStart);
  const progressContext=vm.createContext({task:{task_name:'Test',channel_key:'email',campaign_type:'email',customer_count:1,contact_count:0,mail_body_html:'<p>Test</p>',task_status:'draft'},audience:{},schedule:{},failure:{},sendRule:{delivery_version:2},manualTargets:[],confirmedFlow:true});
  const progress=vm.runInContext(source.slice(progressStart,progressEnd)+';stepDefs',progressContext);
  assert.equal(progress.length,5);assert.equal(progress[1][1],true,'Customer public email does not require a fabricated contact');
  assert.equal(progress[4][1],false,'Saved preview is not final execution confirmation');
  const metricsStart=source.indexOf('        var metrics = cnChannel(row.channel_key)');
  const metricsEnd=source.indexOf('\n',metricsStart);
  const metrics=vm.runInContext(source.slice(metricsStart,metricsEnd)+';metrics',vm.createContext({cnChannel:x=>x,row:{channel_key:'email',manual_pending_count:1},isDraft:true}));
  assert.match(metrics,/人工待办 0/,'Draft targets are not executable manual work');
  console.log('crm_promotion_edit_runtime_test: 7 scenarios passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
