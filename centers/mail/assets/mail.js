/* 邮箱系统：列表批量选择、正文高度、选客户、测试邮箱、邮箱类型预设。 */
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
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-row-check]'));
    function selected() {
      return rows.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }
    function refresh() {
      var ids = selected();
      bar.hidden = ids.length === 0;
      var allBox = bar.querySelector('[data-check-all]');
      if (allBox) { allBox.checked = ids.length === rows.length; allBox.indeterminate = ids.length > 0 && ids.length < rows.length; }
      bar.querySelectorAll('[data-bulk-count]').forEach(function (n) { n.textContent = String(ids.length); });
      bar.querySelectorAll('[data-bulk-ids]').forEach(function (holder) {
        holder.innerHTML = '';
        ids.forEach(function (id) {
          var input = el('input');
          input.type = 'hidden';
          input.name = 'ids[]';
          input.value = id;
          holder.appendChild(input);
        });
      });
    }
    rows.forEach(function (c) { c.addEventListener('change', refresh); });
    var all = bar.querySelector('[data-check-all]');
    if (all) all.addEventListener('change', function () { rows.forEach(function (c) { c.checked = all.checked; }); refresh(); });
    var clear = bar.querySelector('[data-bulk-clear]');
    if (clear) clear.addEventListener('click', function () { rows.forEach(function (c) { c.checked = false; }); refresh(); });
  }

  /* ---------- 选择框改变后自动提交（切换查看谁的邮件） ---------- */
  function initAutosubmit() {
    document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
      select.addEventListener('change', function () { if (select.form) select.form.submit(); });
    });
  }

  /* ---------- 正文 iframe 自动高度（同源、无脚本的沙箱） ---------- */
  function initFrame() {
    var frame = document.querySelector('[data-mail-frame]');
    if (!frame) return;
    function fit() {
      try {
        var doc = frame.contentDocument;
        if (!doc || !doc.body) return;
        var h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
        frame.style.height = Math.min(Math.max(h + 8, 160), 20000) + 'px';
      } catch (e) { /* 读不到就保持默认高度 */ }
    }
    frame.addEventListener('load', function () {
      fit();
      try {
        frame.contentDocument.querySelectorAll('img').forEach(function (img) {
          if (!img.complete) img.addEventListener('load', fit);
        });
      } catch (e) { /* ignore */ }
      setTimeout(fit, 400);
    });
    window.addEventListener('resize', fit);
  }

  /* ---------- 选客户 ---------- */
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
          window.Centers.api(picker.getAttribute('data-search') + '&q=' + encodeURIComponent(q), { method: 'GET' }).then(function (result) {
            if (mine !== seq) return;
            list.innerHTML = '';
            var items = (result.data && result.data.items) || [];
            if (!items.length) list.appendChild(el('li', 'picker-empty', '没有找到（只能选你看得到的客户）'));
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

  /* ---------- 接口成功后的特殊处理 ---------- */
  function initApiHooks() {
    document.addEventListener('centers:api-success', function (event) {
      var form = event.target;
      var data = event.detail.data || {};
      // 测试邮箱：在卡片里显示结果，不刷新页面
      if (form.hasAttribute('data-test-form')) {
        event.preventDefault();
        var box = document.querySelector('[data-test-result="' + form.getAttribute('data-test-form') + '"]');
        var kind = (form.querySelector('[name=type]') || {}).value === 'smtp' ? '发信' : '收信';
        if (box) {
          box.hidden = false;
          box.className = 'mail-test-result ' + (data.ok ? 'is-ok' : 'is-bad');
          box.textContent = (data.ok ? '✓ ' : '✗ 测试' + kind + '失败：') + (event.detail.message || '') + (data.ms ? '（' + (data.ms / 1000).toFixed(1) + ' 秒）' : '');
        }
        return;
      }
      // 星标：直接切换按钮状态
      if (form.hasAttribute('data-star-form')) {
        event.preventDefault();
        var flag = form.querySelector('[name=starred]');
        var star = form.querySelector('.mail-star');
        var on = flag.value === '1';
        star.classList.toggle('is-on', on);
        star.setAttribute('aria-pressed', on ? 'true' : 'false');
        star.setAttribute('aria-label', on ? '取消星标' : '加星标');
        flag.value = on ? '0' : '1';
        return;
      }
      var after = form.getAttribute('data-redirect-after');
      if (after) {
        event.preventDefault();
        window.location.href = after;
      }
    });
  }

  /* ---------- 邮箱设置：选邮箱类型时填好服务器 ---------- */
  function initPresets() {
    var form = document.querySelector('[data-account-form]');
    if (!form) return;
    var presets = {};
    try { presets = JSON.parse(form.getAttribute('data-presets') || '{}'); } catch (e) { presets = {}; }
    var select = form.querySelector('[data-preset]');
    select.addEventListener('change', function () {
      var p = presets[select.value];
      if (!p) return;
      ['imap_host', 'imap_port', 'imap_secure', 'smtp_host', 'smtp_port', 'smtp_secure'].forEach(function (k) {
        var input = form.querySelector('[name=' + k + ']');
        if (input) input.value = String(p[k]);
      });
    });
    var dialog = form.closest('dialog');
    var observer = new MutationObserver(function () {
      if (!dialog.open) return;
      var isNew = !form.querySelector('[name=id]').value;
      form.querySelectorAll('[data-new-only]').forEach(function (n) { n.hidden = !isNew; });
      form.querySelector('[name=mail_secret]').required = isNew;
    });
    observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
  }

  /* ---------- 打开的邮件在列表里滚动到可见 ---------- */
  function initOpenRow() {
    var row = document.querySelector('.mx-row.is-open');
    var list = document.querySelector('.mx-rows');
    if (row && list && list.scrollHeight > list.clientHeight) {
      list.scrollTop = Math.max(0, row.offsetTop - list.offsetTop - 80);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initOpenRow();
    initBulk();
    initAutosubmit();
    initFrame();
    initPickers();
    initApiHooks();
    initPresets();
  });
})();
