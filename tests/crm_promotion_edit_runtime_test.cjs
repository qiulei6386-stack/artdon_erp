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
  console.log('crm_promotion_edit_runtime_test: 7 scenarios passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
