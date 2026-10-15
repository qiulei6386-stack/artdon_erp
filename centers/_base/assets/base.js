/*
 * Artdon 公共底座脚本（四个中心共用）
 *   Centers.api(url, { method, body })  调用接口，自动带 CSRF 令牌，返回 { success, message, data, error_code }
 *   Centers.toast(message, type)        右下角提示
 *   弹窗：<button data-open-dialog="d-edit" data-fill='{"id":1,"real_name":"王磊"}'> 打开 <dialog id="d-edit">，
 *         并把 data-fill 里的值填进弹窗表单里同名的输入框、以及 [data-text="键名"] 元素
 *   接口表单：<form data-api action="api.php?action=xxx">，提交时用接口发送，
 *         成功时先在表单上触发可取消的 centers:api-success 事件（detail 为接口结果），
 *         成功后刷新页面（返回 data.password 时先弹出密码），失败时把错误显示在对应输入框下
 * 不依赖任何框架。
 */
(function () {
  'use strict';

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') || '' : '';
  }

  function toast(message, type) {
    var area = document.querySelector('.toast-area');
    if (!area) {
      area = document.createElement('div');
      area.className = 'toast-area';
      area.setAttribute('role', 'status');
      area.setAttribute('aria-live', 'polite');
      document.body.appendChild(area);
    }
    var item = document.createElement('div');
    item.className = 'toast' + (type === 'error' ? ' toast-error' : '');
    item.textContent = message;
    area.appendChild(item);
    setTimeout(function () { item.remove(); }, type === 'error' ? 6000 : 3000);
  }

  function api(url, options) {
    options = options || {};
    var method = (options.method || (options.body ? 'POST' : 'GET')).toUpperCase();
    var headers = { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() };
    var body;
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }
    return fetch(url, { method: method, headers: headers, body: body, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().catch(function () {
          return { success: false, message: '服务器返回格式错误（' + response.status + '）', data: {}, error_code: 'SERVER_ERROR' };
        });
      })
      .then(function (result) {
        if (!result.success && result.error_code === 'AUTH_REQUIRED') {
          var login = document.querySelector('meta[name="centers-login"]');
          if (login) {
            window.location.href = login.getAttribute('content') + '?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
          }
        }
        return result;
      })
      .catch(function () {
        return { success: false, message: '网络连接失败，请稍后重试', data: {}, error_code: 'NETWORK_ERROR' };
      });
  }

  /* 小屏导航开关 */
  function initSideToggle() {
    var button = document.querySelector('[data-toggle-side]');
    var side = document.getElementById('side');
    if (!button || !side) return;
    button.addEventListener('click', function () {
      var open = side.classList.toggle('is-open');
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (event) {
      if (!side.classList.contains('is-open')) return;
      if (side.contains(event.target) || button.contains(event.target)) return;
      side.classList.remove('is-open');
      button.setAttribute('aria-expanded', 'false');
    });
  }

  /* 点击外部关闭用户菜单 */
  function initUserMenu() {
    document.addEventListener('click', function (event) {
      document.querySelectorAll('details.usermenu[open], details.menu[open]').forEach(function (menu) {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
      });
    });
  }

  /* 表单提交时禁用提交按钮，避免重复提交 */
  function initSubmitGuard() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-guard') || form.hasAttribute('data-api')) return;
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        setTimeout(function () { button.setAttribute('aria-busy', 'true'); button.disabled = true; }, 0);
      }
    });
  }

  /* ---------- 弹窗 ---------- */
  function clearErrors(form) {
    form.querySelectorAll('.field.has-error').forEach(function (field) { field.classList.remove('has-error'); });
    form.querySelectorAll('.field-error[data-js]').forEach(function (el) { el.remove(); });
    form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    var box = form.querySelector('[data-form-error]');
    if (box) { box.hidden = true; box.textContent = ''; }
  }

  function showErrors(form, errors, message) {
    var first = null;
    Object.keys(errors || {}).forEach(function (name) {
      var control = form.querySelector('[name="' + name + '"]');
      if (!control) return;
      var field = control.closest('.field') || control.parentNode;
      field.classList.add('has-error');
      control.setAttribute('aria-invalid', 'true');
      var p = document.createElement('p');
      p.className = 'field-error';
      p.setAttribute('data-js', '1');
      p.textContent = errors[name];
      field.appendChild(p);
      if (!first) first = control;
    });
    var box = form.querySelector('[data-form-error]');
    if (box && (!first || !errors)) { box.textContent = message; box.hidden = false; }
    if (first) first.focus(); else if (!box) toast(message, 'error');
  }

  function fillForm(dialog, data) {
    var form = dialog.querySelector('form');
    if (form) {
      form.reset();
      clearErrors(form);
    }
    Object.keys(data || {}).forEach(function (key) {
      var value = data[key] === null || data[key] === undefined ? '' : String(data[key]);
      dialog.querySelectorAll('[name="' + key + '"]').forEach(function (control) {
        if (control.type === 'checkbox' || control.type === 'radio') control.checked = control.value === value;
        else control.value = value;
      });
      dialog.querySelectorAll('[data-text="' + key + '"]').forEach(function (el) { el.textContent = value; });
    });
  }

  function initDialogs() {
    document.addEventListener('click', function (event) {
      var opener = event.target.closest('[data-open-dialog]');
      if (opener) {
        var dialog = document.getElementById(opener.getAttribute('data-open-dialog'));
        if (!dialog) return;
        var data = {};
        try { data = JSON.parse(opener.getAttribute('data-fill') || '{}'); } catch (e) { data = {}; }
        fillForm(dialog, data);
        var menu = opener.closest('details');
        if (menu) menu.removeAttribute('open');
        dialog.showModal();
        var focus = dialog.querySelector('[autofocus], input:not([type=hidden]):not([readonly]), select, textarea');
        if (focus) focus.focus();
        return;
      }
      var closer = event.target.closest('[data-close-dialog]');
      if (closer) {
        var d = closer.closest('dialog');
        if (d) d.close();
      }
    });
  }

  /* 显示一次性的密码（新建账号、重置密码后），关闭后刷新页面 */
  function showSecret(title, secret, note) {
    var dialog = document.createElement('dialog');
    dialog.className = 'dialog dialog-sm';
    dialog.innerHTML = '<div class="dialog-head"><h2 class="dialog-title"></h2></div><div class="dialog-body stack"><p class="muted secret-note"></p><div class="secret"><code class="secret-value"></code><button type="button" class="btn btn-sm" data-copy>复制</button></div></div><div class="dialog-foot"><button type="button" class="btn btn-primary" data-done>我已记下</button></div>';
    dialog.querySelector('.dialog-title').textContent = title;
    dialog.querySelector('.secret-note').textContent = note;
    dialog.querySelector('.secret-value').textContent = secret;
    dialog.querySelector('[data-copy]').addEventListener('click', function () {
      var done = function () { toast('已复制'); };
      if (navigator.clipboard) navigator.clipboard.writeText(secret).then(done, function () {});
    });
    dialog.addEventListener('close', function () { window.location.reload(); });
    dialog.querySelector('[data-done]').addEventListener('click', function () { dialog.close(); });
    document.body.appendChild(dialog);
    dialog.showModal();
  }

  function initApiForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-api')) return;
      event.preventDefault();
      clearErrors(form);
      var body = {};
      new FormData(form).forEach(function (value, key) {
        if (key.slice(-2) === '[]') {
          var name = key.slice(0, -2);
          (body[name] = body[name] || []).push(value);
        } else {
          body[key] = value;
        }
      });
      form.querySelectorAll('[data-array]').forEach(function (el) {
        var name = el.getAttribute('data-array');
        if (!body[name]) body[name] = [];
      });
      var button = form.querySelector('button[type="submit"]');
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
      api(form.getAttribute('action'), { method: 'POST', body: body }).then(function (result) {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
        if (!result.success) {
          showErrors(form, (result.data || {}).errors, result.message || '操作失败');
          return;
        }
        // 页面脚本可以监听 centers:api-success 自己处理结果（调用 preventDefault 后不再刷新页面）
        var hook = new CustomEvent('centers:api-success', { bubbles: true, cancelable: true, detail: result });
        if (!form.dispatchEvent(hook)) return;
        var dialog = form.closest('dialog');
        if (result.data && result.data.password) {
          if (dialog) dialog.close();
          showSecret(result.message || '操作成功', result.data.password, '这是新密码，只显示这一次。请复制后发给对方，对方首次登录时需要修改。');
          return;
        }
        var redirect = result.data && result.data.redirect;
        if (redirect) window.location.href = redirect; else window.location.reload();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSideToggle();
    initUserMenu();
    initSubmitGuard();
    initDialogs();
    initApiForms();
  });

  window.Centers = { api: api, toast: toast, csrfToken: csrfToken, showSecret: showSecret };
})();
