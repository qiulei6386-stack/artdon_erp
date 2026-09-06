'use strict';

// Runs extracted production methods with fake DOM/network only. No live API calls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/crm/crm.js'), 'utf8');
const names = ['loadDetail', 'ensureFullDetail', 'renderDetail', 'renderArchiveAttributePanel',
  'archiveAttributeData', 'saveArchiveAttribute', 'openCustomerAttributeView',
  'renderCustomerAttributeView', 'customerAttributeSnapshot', 'collectCustomerAttributeData',
  'validateCustomerAttributeData', 'saveCustomerAttribute', 'loadCustomerPromotionFeedback',
  'showCustomerAttributeMissing', 'showCustomerAttributeLogs'];

function extract(name) {
  const start = source.indexOf('    ' + name + ': function');
  const end = source.indexOf('\n    },', start);
  assert(start >= 0 && end > start, 'Missing method: ' + name);
  return source.slice(start, end + '\n    },'.length);
}

function data(id, lazy = false) {
  return { customer: { id, customer_name: 'Fake customer ' + id }, _lazy_detail: lazy ? 1 : 0 };
}

function form(id) {
  const fields = [
    { name: 'customer_id', value: String(id), type: 'hidden', dataset: {} },
    { name: 'customer_name', value: 'Fake customer ' + id, dataset: {} },
  ];
  const saveButton = { disabled: false };
  return {
    dataset: {}, fields, saveButton,
    querySelector(selector) {
      if (selector === '[name="customer_id"]') return fields[0];
      if (selector === '[data-archive-save]') return saveButton;
      return null;
    },
    querySelectorAll() { return fields; },
  };
}

function harness() {
  const calls = [];
  const ui = { renders: [], attributeRenders: [], errors: [], toasts: [], tabs: [], actions: 0, lists: 0 };
  let archiveForm = null;
  let attributeForm = null;
  let html = '';
  const detailBox = {
    get innerHTML() { return html; },
    set innerHTML(value) { html = value; archiveForm = null; attributeForm = null; },
  };
  const status = { textContent: '' };
  const context = vm.createContext({
    post(action, payload) {
      return new Promise((resolve, reject) => calls.push({ action, payload, resolve, reject, settled: false }));
    },
    document: {
      querySelector(selector) {
        if (selector === '[data-customer-detail]') return detailBox;
        if (selector === '[data-archive-attribute-form]') return archiveForm;
        if (selector === '[data-customer-attribute-form]') return attributeForm;
        if (selector === '[data-customer-search-status]') return status;
        return null;
      },
      querySelectorAll() { return []; },
    },
    window: { confirm() { return true; } },
    esc(value) { return String(value == null ? '' : value); },
    toast(message) { ui.toasts.push(message); },
    renderActions() { ui.actions += 1; },
  });
  const methods = vm.runInContext('({' + names.map(extract).join('\n') + '})', context);
  const m = Object.assign({
    currentId: 0, currentDetail: null, layoutMode: 'default', activeDetailTab: 'overview',
    detailRequestSeq: 0, detailSelectionSeq: 0,
    restoreDetailTab() { return 'overview'; },
    applyLayoutMode() {}, renderSelection() {}, loadCustomerPromotionFeedback() {},
    selectedOwnerIdsFromForm() { return []; }, ownerDisplayText() { return ''; },
    customerOwnerChecks() { return ''; },
    showCustomerError(message) { ui.errors.push(message); },
    loadList() { ui.lists += 1; return Promise.resolve(); },
    showCustomerAttributeLogs() {},
    switchDetailTab(tab) { ui.tabs.push(tab); },
  }, methods);
  m.renderDetail = function (detail) {
    ui.renders.push(detail.customer.id);
    archiveForm = form(detail.customer.id);
    attributeForm = null;
    this.attributeViewMode = false;
    this.attributeEditMode = false;
  };
  m.renderCustomerAttributeView = function () {
    ui.attributeRenders.push(this.attributeData.customer.id);
    attributeForm = form(this.attributeData.customer.id);
    archiveForm = null;
    this.attributeOriginalSnapshot = this.customerAttributeSnapshot(attributeForm);
  };
  // Keep auxiliary reads explicit in tests instead of triggering more fake requests.
  m.loadCustomerPromotionFeedback = function () {};
  m.showCustomerAttributeLogs = function () {};
  function pending(action, id, detail) {
    const call = calls.find((item) => !item.settled && item.action === action &&
      (id === undefined || Number(item.payload.customer_id) === id) &&
      (detail === undefined || item.payload.detail === detail));
    assert(call, 'Missing fake request: ' + [action, id, detail].join('/'));
    call.settled = true;
    return call;
  }
  async function load(id, lazy = false) {
    const promise = m.loadDetail(id);
    pending('customer_get', id).resolve({ success: true, data: data(id, lazy) });
    await promise;
  }
  async function attribute(id) {
    if (m.currentId !== id) await load(id);
    const promise = m.openCustomerAttributeView(true, false);
    pending('customer_attribute_get', id).resolve({ success: true, data: data(id) });
    await promise;
    attributeForm.fields[1].value += ' edited';
  }
  return { m, methods, calls, ui, status, pending, load, attribute,
    get archiveForm() { return archiveForm; },
    get attributeForm() { return attributeForm; },
    setArchiveForm(value) { archiveForm = value; },
  };
}

let count = 0;
async function test(name, run) {
  await run();
  count += 1;
  console.log('PASS ' + name);
}

(async () => {
  await test('on-demand sections merge without losing basic identity or loaded sections', async () => {
    const h=harness(); await h.load(101,true);
    h.m.currentDetail._loaded_tabs=['overview','contacts']; h.m.currentDetail.linkage={quote:{total:3}};
    const basic=h.m.currentDetail.customer;
    const p=h.m.ensureFullDetail('orders');
    h.pending('customer_get',101,'tab:orders').resolve({success:true,data:{customer:{id:101},_partial_detail:1,_loaded_tabs:['orders'],linkage:{orders:{total:2}}}});
    await p;
    assert.equal(h.m.currentDetail.customer,basic);
    assert.equal(h.m.currentDetail.linkage.quote.total,3);
    assert.equal(h.m.currentDetail.linkage.orders.total,2);
    const calls=h.calls.length; await h.m.ensureFullDetail('orders'); assert.equal(h.calls.length,calls);
  });
  await test('quick section switching discards old section data and errors', async () => {
    const h=harness(); await h.load(101,true); h.m.currentDetail._loaded_tabs=['overview'];
    const old=h.m.ensureFullDetail('orders'), latest=h.m.ensureFullDetail('mail');
    h.pending('customer_get',101,'tab:mail').resolve({success:true,data:{customer:{id:101},_partial_detail:1,_loaded_tabs:['mail'],mail_rows:[{id:8}]}});
    await latest;
    h.pending('customer_get',101,'tab:orders').reject(new Error('old failure'));
    assert.equal(await old,null); assert.equal(h.m.currentDetail.mail_rows[0].id,8); assert.deepEqual(h.ui.toasts,[]);
  });
  await test('A slow / B fast discards A and returns no entity', async () => {
    const h = harness();
    const a = h.m.loadDetail(101);
    const b = h.m.loadDetail(202);
    assert.equal(h.m.currentDetail, null);
    h.pending('customer_get', 202).resolve({ success: true, data: data(202) });
    await b;
    h.pending('customer_get', 101).resolve({ success: true, data: data(101) });
    assert.equal(await a, null);
    assert.equal(h.m.currentId, 202);
    assert.equal(h.m.currentDetail.customer.id, 202);
    assert.deepEqual(h.ui.renders, [202]);
  });
  await test('same customer requests also obey the latest request sequence', async () => {
    const h = harness();
    const first = h.m.loadDetail(101);
    const old = h.pending('customer_get', 101);
    const second = h.m.loadDetail(101);
    const latest = data(101); latest.customer.customer_name = 'Newest';
    h.pending('customer_get', 101).resolve({ success: true, data: latest });
    await second;
    old.resolve({ success: true, data: data(101) });
    assert.equal(await first, null);
    assert.equal(h.m.currentDetail.customer.customer_name, 'Newest');
  });
  for (const failure of ['api', 'network', 'silent']) {
    await test('old ' + failure + ' failure cannot replace B or show errors', async () => {
      const h = harness();
      const old = h.m.loadDetail(101, { silent: failure === 'silent' });
      await h.load(202);
      const call = h.pending('customer_get', 101);
      if (failure === 'api') call.resolve({ success: false, message: 'old error' });
      else call.reject(new Error('old error'));
      assert.equal(await old, null);
      assert.deepEqual(h.ui.errors, []);
      assert.equal(h.status.textContent, '');
      assert.equal(h.m.currentDetail.customer.id, 202);
    });
  }
  await test('wrong response entity is rejected; current failures remain visible', async () => {
    const h = harness();
    const promise = h.m.loadDetail(101);
    h.pending('customer_get', 101).resolve({ success: true, data: data(202) });
    await promise;
    assert.equal(h.m.currentDetail, null);
    assert.equal(h.ui.errors.length, 1);
    assert.deepEqual(h.ui.renders, []);
    const failed = h.m.loadDetail(101);
    h.pending('customer_get', 101).reject(new Error('current error'));
    await failed;
    assert.equal(h.ui.errors.at(-1), 'current error');
  });
  await test('in-flight silent reload cannot erase editing started afterward', async () => {
    const h = harness(); await h.load(101);
    const savedDetail = h.m.currentDetail;
    const promise = h.m.loadDetail(101, { silent: true });
    h.m.archiveEditMode = true;
    h.archiveForm.fields[1].value = 'Unsaved edit';
    h.pending('customer_get', 101).resolve({ success: true, data: data(101) });
    assert.equal(await promise, null);
    assert.equal(h.m.currentDetail, savedDetail);
    assert.equal(h.archiveForm.fields[1].value, 'Unsaved edit');
  });
  for (const rejectOld of [false, true]) {
    await test('full-detail requests are customer-scoped; old ' + (rejectOld ? 'error' : 'success') + ' cannot clear B promise', async () => {
      const h = harness(); await h.load(101, true);
      const a = h.m.ensureFullDetail('contacts');
      await h.load(202, true);
      const b = h.m.ensureFullDetail('contacts');
      const contextB = h.m.detailFullLoading;
      assert.notEqual(a, b);
      assert.equal(h.m.ensureFullDetail('orders'), b);
      const call = h.pending('customer_get', 101, 'full');
      if (rejectOld) call.reject(new Error('old full error'));
      else call.resolve({ success: true, data: data(101) });
      assert.equal(await a, null);
      assert.equal(h.m.detailFullLoading, contextB);
      h.pending('customer_get', 202, 'full').resolve({ success: true, data: data(202) });
      await b;
      assert.equal(h.m.currentDetail.customer.id, 202);
      assert.equal(h.m.detailFullLoading, null);
      assert.equal(h.ui.tabs.at(-1), 'orders');
      assert.deepEqual(h.ui.toasts, []);
    });
  }
  await test('full detail refuses mismatched local or response entities', async () => {
    const h = harness(); await h.load(101, true);
    h.m.currentId = 202;
    assert.equal(await h.m.ensureFullDetail('contacts'), null);
    assert.equal(h.calls.length, 1);
    h.m.currentId = 101;
    const full = h.m.ensureFullDetail('contacts');
    h.pending('customer_get', 101, 'full').resolve({ success: true, data: data(202) });
    await assert.rejects(full, /不一致/);
    assert.equal(h.m.currentDetail.customer.id, 101);
    assert.equal(h.ui.toasts.length, 1);
  });
  await test('archive form ID comes from the rendered entity; invalid render is ignored', async () => {
    const h = harness(); h.m.currentId = 202;
    const html = h.methods.renderArchiveAttributePanel.call(h.m, data(101));
    assert.match(html, /name="customer_id" value="101"/);
    assert.doesNotMatch(html, /name="customer_id" value="202"/);
    h.methods.renderDetail.call(h.m, data(101));
    assert.equal(h.archiveForm, null);
    h.m.attributeData = data(101);
    h.methods.renderCustomerAttributeView.call(h.m);
    assert.equal(h.attributeForm, null);
  });
  await test('archive saves require matching selected, detail and form IDs', async () => {
    const h = harness(); await h.load(101);
    h.m.currentId = 202;
    assert.throws(() => h.m.archiveAttributeData(), /不一致/);
    await h.m.saveArchiveAttribute();
    h.m.currentId = 101;
    h.setArchiveForm(form(202));
    assert.throws(() => h.m.archiveAttributeData(), /不一致/);
    assert.equal(h.calls.filter((c) => c.action === 'customer_attribute_save').length, 0);
  });
  await test('archive save locks duplicate submit and refreshes the same customer', async () => {
    const h = harness(); await h.load(101); h.m.archiveEditMode = true;
    const save = h.m.saveArchiveAttribute();
    assert.equal(h.archiveForm.saveButton.disabled, true);
    await h.m.saveArchiveAttribute();
    assert.equal(h.calls.filter((c) => c.action === 'customer_attribute_save').length, 1);
    h.pending('customer_attribute_save', 101).resolve({ success: true });
    await Promise.resolve();
    h.pending('customer_get', 101).resolve({ success: true, data: data(101) });
    await save;
    assert.equal(h.m.archiveEditMode, false);
    assert.equal(h.ui.lists, 1);
  });
  for (const fail of [false, true]) {
    await test('archive save ' + (fail ? 'error' : 'success') + ' after switch cannot reset B editing', async () => {
      const h = harness(); await h.load(101); h.m.archiveEditMode = true;
      const save = h.m.saveArchiveAttribute();
      await h.load(202); h.m.archiveEditMode = true;
      const formB = h.archiveForm;
      h.pending('customer_attribute_save', 101).resolve({ success: !fail, message: 'old save result' });
      await save;
      assert.equal(h.m.currentDetail.customer.id, 202);
      assert.equal(h.m.archiveEditMode, true);
      assert.equal(h.archiveForm, formB);
      assert.equal(h.ui.lists, 0);
      assert.deepEqual(h.ui.errors, []);
      assert.deepEqual(h.ui.toasts, []);
    });
  }
  await test('switching during post-save reload cannot redraw or reset B', async () => {
    const h = harness(); await h.load(101); h.m.archiveEditMode = true;
    const save = h.m.saveArchiveAttribute();
    h.pending('customer_attribute_save', 101).resolve({ success: true });
    await Promise.resolve();
    const reloadA = h.pending('customer_get', 101);
    await h.load(202); h.m.archiveEditMode = true;
    reloadA.resolve({ success: true, data: data(101) });
    await save;
    assert.equal(h.m.currentDetail.customer.id, 202);
    assert.equal(h.m.archiveEditMode, true);
    assert.equal(h.ui.lists, 0);
  });
  for (const fail of [false, true]) {
    await test('attribute-view old ' + (fail ? 'error' : 'success') + ' does not replace B', async () => {
      const h = harness(); await h.load(101);
      const old = h.m.openCustomerAttributeView(true, false);
      await h.attribute(202);
      const formB = h.attributeForm;
      h.pending('customer_attribute_get', 101).resolve({ success: !fail, data: data(101), message: 'old attribute error' });
      assert.equal(await old, null);
      assert.equal(h.m.attributeData.customer.id, 202);
      assert.equal(h.attributeForm, formB);
      assert.equal(h.m.attributeEditMode, true);
      assert.deepEqual(h.ui.errors, []);
    });
  }
  await test('attribute reads and saves reject wrong entity or form ID', async () => {
    const h = harness(); await h.load(101);
    const read = h.m.openCustomerAttributeView(true, false);
    h.pending('customer_attribute_get', 101).resolve({ success: true, data: data(202) });
    await read;
    assert.equal(h.m.attributeData, null);
    assert.equal(h.ui.errors.length, 1);
    await h.attribute(101);
    h.attributeForm.fields[0].value = '202';
    assert.throws(() => h.m.collectCustomerAttributeData(), /不一致/);
    await h.m.saveCustomerAttribute();
    assert.equal(h.calls.filter((c) => c.action === 'customer_attribute_save').length, 0);
  });
  await test('attribute save refreshes data without replacing its view or duplicate submit', async () => {
    const h = harness(); await h.attribute(101);
    const rendersBefore = h.ui.renders.length;
    const save = h.m.saveCustomerAttribute();
    await h.m.saveCustomerAttribute();
    assert.equal(h.calls.filter((c) => c.action === 'customer_attribute_save').length, 1);
    h.pending('customer_attribute_save', 101).resolve({ success: true, data: { attribute: data(101) } });
    await Promise.resolve();
    h.pending('customer_get', 101).resolve({ success: true, data: data(101) });
    await save;
    assert.equal(h.m.attributeViewMode, true);
    assert.equal(h.m.attributeEditMode, false);
    assert.equal(h.ui.renders.length, rendersBefore);
    assert.equal(h.ui.attributeRenders.length, 2);
  });
  for (const fail of [false, true]) {
    await test('attribute save ' + (fail ? 'error' : 'success') + ' after switching preserves B form and edits', async () => {
      const h = harness(); await h.attribute(101);
      const save = h.m.saveCustomerAttribute();
      await h.attribute(202);
      const formB = h.attributeForm;
      h.pending('customer_attribute_save', 101).resolve({ success: !fail, data: { attribute: data(101) }, message: 'old save' });
      await save;
      assert.equal(h.m.attributeData.customer.id, 202);
      assert.equal(h.attributeForm, formB);
      assert.equal(h.m.attributeEditMode, true);
      assert.equal(h.ui.lists, 0);
      assert.deepEqual(h.ui.toasts, []);
      assert.deepEqual(h.ui.errors, []);
    });
  }
  await test('attribute edits started during save-refresh remain untouched', async () => {
    const h = harness(); await h.attribute(101);
    const save = h.m.saveCustomerAttribute();
    h.pending('customer_attribute_save', 101).resolve({ success: true, data: { attribute: data(101) } });
    await Promise.resolve();
    h.m.attributeEditMode = true;
    h.attributeForm.fields[1].value = 'New unsaved edit';
    h.pending('customer_get', 101).resolve({ success: true, data: data(101) });
    await save;
    assert.equal(h.m.attributeEditMode, true);
    assert.equal(h.attributeForm.fields[1].value, 'New unsaved edit');
    assert.equal(h.ui.attributeRenders.length, 1);
  });
  for (const [method, action] of [['loadCustomerPromotionFeedback', 'marketing_feedback_list'],
    ['showCustomerAttributeMissing', 'customer_attribute_missing'], ['showCustomerAttributeLogs', 'customer_attribute_logs']]) {
    for (const fail of [false, true]) {
      await test(method + ' old ' + (fail ? 'failure' : 'success') + ' cannot mutate the next customer', async () => {
        const h = harness(); await h.attribute(101);
        const old = h.methods[method].call(h.m);
        await h.attribute(202);
        const feedback = h.m.attributePromotionFeedback;
        const formB = h.attributeForm;
        h.pending(action, 101).resolve({ success: !fail, data: { rows: [], missing: [] }, message: 'old auxiliary error' });
        assert.equal(await old, null);
        assert.equal(h.attributeForm, formB);
        assert.equal(h.m.attributePromotionFeedback, feedback);
        assert.equal(h.m.attributeEditMode, true);
        assert.deepEqual(h.ui.errors, []);
        assert.deepEqual(h.ui.toasts, []);
      });
    }
  }
  console.log('CRM customer detail race runtime: ' + count + ' passed; fake network only.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
