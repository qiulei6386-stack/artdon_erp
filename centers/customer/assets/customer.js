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

  document.addEventListener('DOMContentLoaded', function () {
    initBulk();
    initCreate();
  });
})();
