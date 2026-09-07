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
  console.log('crm_promotion_edit_runtime_test: 7 scenarios passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
