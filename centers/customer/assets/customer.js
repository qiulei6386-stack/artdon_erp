/* 客户中心：列表批量选择；新建客户时的疑似重复确认。 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  /* ---------- 列表批量选择 ---------- */
  function initBulk() {
    var bar = document.querySelector('[data-bulkbar]');
    if (!bar) return;
    var all = document.querySelector('[data-check-all]');
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-row-check]'));

    function selected() {
      return rows.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }
    function refresh() {
      var ids = selected();
      bar.hidden = ids.length === 0;
      document.querySelectorAll('[data-bulk-count]').forEach(function (n) { n.textContent = String(ids.length); });
      if (all) {
        all.checked = ids.length > 0 && ids.length === rows.length;
        all.indeterminate = ids.length > 0 && ids.length < rows.length;
      }
    }
    rows.forEach(function (c) { c.addEventListener('change', refresh); });
    if (all) {
      all.addEventListener('change', function () {
        rows.forEach(function (c) { c.checked = all.checked; });
        refresh();
      });
    }
    var clear = bar.querySelector('[data-bulk-clear]');
    if (clear) clear.addEventListener('click', function () { rows.forEach(function (c) { c.checked = false; }); refresh(); });

    document.querySelectorAll('[data-bulk-open]').forEach(function (button) {
      button.addEventListener('click', function () {
        var dialog = document.getElementById(button.getAttribute('data-bulk-open'));
        if (!dialog) return;
        var count = selected().length;
        var min = parseInt(button.getAttribute('data-min') || '1', 10);
        var max = parseInt(button.getAttribute('data-max') || '500', 10);
        if (count < min || count > max) {
          window.Centers.toast(count < min ? '请至少选 ' + min + ' 个客户' : '一次最多 ' + max + ' 个客户', 'error');
          return;
        }
        var form = dialog.querySelector('form');
        if (form) form.reset();
        dialog.querySelectorAll('.field-error[data-js]').forEach(function (n) { n.remove(); });
        var holder = dialog.querySelector('[data-bulk-ids]');
        holder.innerHTML = '';
        selected().forEach(function (id) {
          var input = el('input');
          input.type = 'hidden';
          input.name = 'ids[]';
          input.value = id;
          holder.appendChild(input);
        });
        var mergeList = dialog.querySelector('[data-merge-list]');
        if (mergeList) {
          mergeList.querySelectorAll('label').forEach(function (n) { n.remove(); });
          rows.filter(function (c) { return c.checked; }).forEach(function (c, i) {
            var label = el('label', 'check');
            var radio = el('input');
            radio.type = 'radio';
            radio.name = 'master_id';
            radio.value = c.value;
            radio.required = true;
            if (i === 0) radio.checked = true;
            label.appendChild(radio);
            label.appendChild(el('span', '', c.getAttribute('data-name') + '（#' + c.value + '）'));
            mergeList.appendChild(label);
          });
        }
        refresh();
        dialog.showModal();
      });
    });
    refresh();
  }

  /* ---------- 新建客户：疑似重复 ---------- */
  function initCreate() {
    var form = document.querySelector('[data-create-form]');
    if (!form) return;
    var fields = form.querySelector('[data-create-fields]');
    var panel = form.querySelector('[data-dup-panel]');
    var list = form.querySelector('[data-dup-list]');
    var confirm = form.querySelector('[data-dup-confirm]');
    var back = form.querySelector('[data-dup-back]');
    var submit = form.querySelector('[data-create-submit]');

    function showFields() {
      fields.hidden = false;
      panel.hidden = true;
      back.hidden = true;
      confirm.value = '';
      submit.textContent = '保存';
    }
    back.addEventListener('click', showFields);
    form.closest('dialog').addEventListener('close', showFields);
    // 改了内容就要重新查重
    fields.addEventListener('input', function () { confirm.value = ''; });

    form.addEventListener('centers:api-success', function (event) {
      var data = event.detail.data || {};
      if (data.status !== 'duplicates') return;
      event.preventDefault();
      list.innerHTML = '';
      (data.matches || []).forEach(function (m) {
        var li = el('li', 'dup-item');
        var body = el('div', 'dup-item-body');
        var link = el('a', 'row-link', m.name);
        link.href = m.url;
        link.target = '_blank';
        link.rel = 'noopener';
        body.appendChild(link);
        body.appendChild(el('small', '', m.country + ' · 负责人：' + m.owner + ' · ' + m.reasons));
        li.appendChild(body);
        li.appendChild(el('span', 'dup-score', m.similarity + '%'));
        list.appendChild(li);
      });
      fields.hidden = true;
      panel.hidden = false;
      back.hidden = false;
      confirm.value = '1';
      submit.textContent = '不是同一个，继续保存';
      submit.focus();
    });
  }

  /* ---------- 弹窗里的多选框：data-check-values='{"contact_ids[]":[1,2]}'（在基础脚本填好表单后执行） ---------- */
  function initCheckValues() {
    document.addEventListener('click', function (event) {
      var opener = event.target.closest('[data-open-dialog][data-check-values]');
      if (!opener) return;
      var dialog = document.getElementById(opener.getAttribute('data-open-dialog'));
      var values = {};
      try { values = JSON.parse(opener.getAttribute('data-check-values') || '{}'); } catch (e) { values = {}; }
      Object.keys(values).forEach(function (name) {
        var wanted = (values[name] || []).map(String);
        dialog.querySelectorAll('input[type=checkbox][name="' + name + '"]').forEach(function (box) {
          box.checked = wanted.indexOf(box.value) !== -1;
        });
      });
    });
  }

  /* ---------- 选客户：输入后查找自己能看到的客户 ---------- */
  function initPickers() {
    document.querySelectorAll('[data-customer-picker]').forEach(function (picker) {
      var input = picker.querySelector('[data-picker-input]');
      var value = picker.querySelector('[data-picker-value]');
      var list = picker.querySelector('[data-picker-list]');
      var timer = null;
      var seq = 0;
      function close() { list.hidden = true; list.innerHTML = ''; }
      input.addEventListener('input', function () {
        value.value = '';
        clearTimeout(timer);
        var q = input.value.trim();
        if (q === '') { close(); return; }
        timer = setTimeout(function () {
          var mine = ++seq;
          var url = picker.getAttribute('data-search') + '&q=' + encodeURIComponent(q) + '&exclude=' + encodeURIComponent(picker.getAttribute('data-exclude') || '0');
          window.Centers.api(url, { method: 'GET' }).then(function (result) {
            if (mine !== seq) return;
            list.innerHTML = '';
            var items = (result.data && result.data.items) || [];
            if (!items.length) {
              list.appendChild(el('li', 'picker-empty', '没有找到（只能选你看得到的客户）'));
            }
            items.forEach(function (item) {
              var li = el('li');
              var button = el('button', 'picker-item');
              button.type = 'button';
              button.appendChild(el('span', '', item.name));
              button.appendChild(el('small', '', '#' + item.id + ' · ' + item.country + (item.city ? ' ' + item.city : '')));
              button.addEventListener('click', function () {
                value.value = String(item.id);
                input.value = item.name;
                close();
              });
              li.appendChild(button);
              list.appendChild(li);
            });
            list.hidden = false;
          });
        }, 250);
      });
      var dialog = picker.closest('dialog');
      if (dialog) dialog.addEventListener('close', function () { input.value = ''; value.value = ''; close(); });
    });
  }

  /* ---------- 新建、编辑共用的弹窗：有编号（name=id）时提交到 data-switch-action ---------- */
  function initSwitchAction() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-switch-action')) return;
      if (!form.hasAttribute('data-create-action')) form.setAttribute('data-create-action', form.getAttribute('action'));
      var id = form.querySelector('[name=id]');
      form.setAttribute('action', id && id.value ? form.getAttribute('data-switch-action') : form.getAttribute('data-create-action'));
    }, true);
  }

  /* ---------- 新建客户：识别名片后填表 ---------- */
  function initCardScan() {
    var box = document.querySelector('[data-card-scan]');
    if (!box) return;
    var input = box.querySelector('[data-card-file]');
    var status = box.querySelector('[data-card-status]');
    var token = box.querySelector('[data-card-token]');
    var form = box.closest('form');
    function set(name, value) {
      if (!value) return;
      var el = form.querySelector('[name="' + name + '"]');
      if (!el) return;
      if (el.tagName === 'SELECT') {
        var has = Array.prototype.some.call(el.options, function (o) { return o.value === value; });
        if (!has) return;
      }
      el.value = value;
    }
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;
      status.textContent = '正在识别……';
      var data = new FormData();
      data.append('card', file);
      fetch(box.getAttribute('data-api-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.Centers.csrfToken() } })
        .then(function (r) { return r.json().catch(function () { return { success: false, message: '服务器返回格式错误' }; }); })
        .then(function (result) {
          input.value = '';
          if (!result.success) {
            var errors = (result.data && result.data.errors) || {};
            status.textContent = errors.card || result.message || '识别失败';
            return;
          }
          var c = result.data.customer || {};
          var p = result.data.contact || {};
          set('customer_name', c.customer_name); set('customer_name_en', c.customer_name_en); set('country', c.country);
          set('address', c.address); set('website', c.website); set('email', c.email); set('phone', c.phone);
          set('contact_name', p.name); set('contact_position', p.position); set('contact_email', p.email); set('contact_phone', p.phone);
          token.value = result.data.token || '';
          status.textContent = '已识别并填好，请核对。' + (c.country ? '' : '国家没有认出来，请手动选择。');
        })
        .catch(function () { status.textContent = '网络错误，请重试'; });
    });
    form.closest('dialog').addEventListener('close', function () { token.value = ''; status.textContent = '拍一张名片照片，自动填好客户和联系人'; });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSwitchAction();
    initCardScan();
    initBulk();
    initCreate();
    initCheckValues();
    initPickers();
  });
})();
