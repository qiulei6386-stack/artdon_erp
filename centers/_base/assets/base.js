/*
 * Artdon 公共底座脚本（四个中心共用）
 *   Centers.api(url, { method, body })  调用接口，自动带 CSRF 令牌，返回 { success, message, data, error_code }
 *   Centers.toast(message, type)        右下角提示
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
      document.querySelectorAll('details.usermenu[open]').forEach(function (menu) {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
      });
    });
  }

  /* 表单提交时禁用提交按钮，避免重复提交 */
  function initSubmitGuard() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-guard')) return;
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        setTimeout(function () { button.setAttribute('aria-busy', 'true'); button.disabled = true; }, 0);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSideToggle();
    initUserMenu();
    initSubmitGuard();
  });

  window.Centers = { api: api, toast: toast, csrfToken: csrfToken };
})();
