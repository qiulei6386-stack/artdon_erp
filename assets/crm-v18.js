(function () {
  var THEME_KEY = 'artdon.theme';
  var DENSITY_KEY = 'artdon.density';
  var VIEW_KEY = 'artdon.portalView';

  function applyPreference(key, value) {
    if (!value) return;
    if (key === THEME_KEY) {
      document.documentElement.dataset.theme = value;
    }
    if (key === DENSITY_KEY) {
      document.documentElement.dataset.density = value;
    }
    try {
      window.localStorage.setItem(key, value);
    } catch (error) {}
  }

  function syncControls() {
    var theme = document.documentElement.dataset.theme || 'office-light';
    var density = document.documentElement.dataset.density || 'standard';
    document.querySelectorAll('[data-theme-picker]').forEach(function (select) {
      select.value = theme;
      select.addEventListener('change', function () {
        applyPreference(THEME_KEY, select.value);
      });
    });
    document.querySelectorAll('[data-density-picker]').forEach(function (select) {
      select.value = density;
      select.addEventListener('change', function () {
        applyPreference(DENSITY_KEY, select.value);
      });
    });
  }

  function tickTime() {
    var node = document.querySelector('[data-live-time]');
    var now = new Date();
    var hours = String(now.getHours()).padStart(2, '0');
    var minutes = String(now.getMinutes()).padStart(2, '0');
    if (node) node.textContent = hours + ':' + minutes;
    document.querySelectorAll('[data-world-time]').forEach(function (item) {
      var timeZone = item.getAttribute('data-world-time');
      try {
        item.textContent = new Intl.DateTimeFormat('en-GB', {
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          hour12: false,
          timeZone: timeZone
        }).format(now);
      } catch (error) {
        item.textContent = '--:--:--';
      }
    });
  }

  function sendHomepageEvent(type, moduleKey, targetUrl, payload) {
    if (!document.body.classList.contains('portal-body')) return;
    var data = JSON.stringify({
      event_type: type,
      module_key: moduleKey || '',
      target_url: targetUrl || '',
      payload: payload || null
    });
    if (navigator.sendBeacon) {
      navigator.sendBeacon('api.php?action=homepage_log', new Blob([data], { type: 'application/json' }));
      return;
    }
    fetch('api.php?action=homepage_log', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: data, keepalive: true }).catch(function () {});
  }

  function syncPortalView() {
    var page = document.querySelector('[data-portal-view-root]');
    if (!page) return;
    var view = 'cards';
    try {
      view = window.localStorage.getItem(VIEW_KEY) || 'cards';
    } catch (error) {}
    page.dataset.portalView = view;
    document.querySelectorAll('[data-portal-view]').forEach(function (button) {
      button.classList.toggle('active', button.getAttribute('data-portal-view') === view);
      button.addEventListener('click', function () {
        view = button.getAttribute('data-portal-view');
        page.dataset.portalView = view;
        try { window.localStorage.setItem(VIEW_KEY, view); } catch (error) {}
        document.querySelectorAll('[data-portal-view]').forEach(function (item) {
          item.classList.toggle('active', item === button);
        });
      });
    });
  }

  syncControls();
  syncPortalView();
  tickTime();
  window.setInterval(tickTime, 1000);

  document.querySelectorAll('[data-acl-tabs]').forEach(function (tabbar) {
    tabbar.addEventListener('click', function (event) {
      var button = event.target.closest('[data-acl-tab]');
      if (!button) return;
      var name = button.getAttribute('data-acl-tab');
      tabbar.querySelectorAll('[data-acl-tab]').forEach(function (item) {
        item.classList.toggle('active', item === button);
      });
      document.querySelectorAll('[data-acl-panel]').forEach(function (panel) {
        panel.classList.toggle('active', panel.getAttribute('data-acl-panel') === name);
      });
    });
  });

  document.querySelectorAll('[data-employee-search]').forEach(function (input) {
    input.addEventListener('input', function () {
      var keyword = input.value.trim().toLowerCase();
      document.querySelectorAll('[data-employee-card]').forEach(function (card) {
        var haystack = card.getAttribute('data-search') || '';
        card.hidden = keyword && haystack.indexOf(keyword) === -1;
      });
    });
  });

  document.querySelectorAll('[data-role-select]').forEach(function (select) {
    var form = select.closest('form');
    if (!form) return;
    var radios = form.querySelectorAll('input[name="department_id"][data-default-role]');
    var departmentInput = form.querySelector('[data-department-input]');
    function findDepartmentOption(input) {
      var listId = input ? input.getAttribute('list') : '';
      var list = listId ? document.getElementById(listId) : null;
      if (!list) return null;
      return Array.prototype.find.call(list.options, function (option) {
        return option.value === (input.value || '');
      }) || null;
    }
    function syncRoleFromDepartment(force) {
      if (departmentInput) {
        var option = findDepartmentOption(departmentInput);
        var inputRoleId = option ? (option.getAttribute('data-default-role') || '') : '';
        if (inputRoleId && (force || !select.value)) select.value = inputRoleId;
        return;
      }
      var checked = form.querySelector('input[name="department_id"][data-default-role]:checked');
      if (!checked) return;
      var roleId = checked.getAttribute('data-default-role') || '';
      if (roleId && (force || !select.value)) select.value = roleId;
    }
    if (departmentInput) {
      departmentInput.addEventListener('change', function () { syncRoleFromDepartment(true); });
      departmentInput.addEventListener('input', function () { syncRoleFromDepartment(false); });
    }
    radios.forEach(function (radio) {
      radio.addEventListener('change', function () { syncRoleFromDepartment(true); });
    });
    syncRoleFromDepartment(false);
  });

  document.querySelectorAll('[data-account-department]').forEach(function (select) {
    var form = select.closest('form');
    if (!form) return;
    var roleSelect = form.querySelector('[data-account-role]');
    if (!roleSelect) return;
    function findDepartmentOption(input) {
      var listId = input ? input.getAttribute('list') : '';
      var list = listId ? document.getElementById(listId) : null;
      if (!list) return null;
      return Array.prototype.find.call(list.options, function (option) {
        return option.value === (input.value || '');
      }) || null;
    }
    var syncAccountRole = function () {
      var roleId = '';
      if (select.tagName === 'INPUT') {
        var option = findDepartmentOption(select);
        roleId = option ? (option.getAttribute('data-default-role') || '') : '';
      } else {
        var selected = select.options[select.selectedIndex];
        roleId = selected ? (selected.getAttribute('data-default-role') || '') : '';
      }
      if (roleId) roleSelect.value = roleId;
    };
    select.addEventListener('change', syncAccountRole);
    select.addEventListener('input', syncAccountRole);
  });

  document.querySelectorAll('[data-open-account-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.querySelector('[data-account-dialog="' + button.getAttribute('data-open-account-dialog') + '"]');
      if (!dialog) return;
      if (dialog.showModal) dialog.showModal();
      else dialog.setAttribute('open', 'open');
    });
  });

  document.querySelectorAll('[data-close-account-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = button.closest('dialog');
      if (!dialog) return;
      if (dialog.close) dialog.close();
      else dialog.removeAttribute('open');
    });
  });

  document.querySelectorAll('.acl-account-dialog').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      if (dialog.close) dialog.close();
      else dialog.removeAttribute('open');
    });
  });

  document.querySelectorAll('[data-acl-advanced]').forEach(function (input) {
    var page = input.closest('.acl-page');
    input.addEventListener('change', function () {
      if (page) page.classList.toggle('show-advanced', input.checked);
    });
  });

  document.querySelectorAll('[data-perm-switch]').forEach(function (input) {
    input.addEventListener('change', function () {
      var form = input.closest('form');
      if (!form) return;
      var effect = form.querySelector('input[name="effect"]');
      if (effect) effect.value = input.checked ? 'allow' : 'deny';
      var previousChecked = !input.checked;
      form.classList.add('is-saving');
      input.disabled = true;
      fetch(window.location.href, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) throw new Error('权限保存失败');
        if (effect) effect.value = input.checked ? 'allow' : 'deny';
      }).catch(function (error) {
        input.checked = previousChecked;
        if (effect) effect.value = input.checked ? 'allow' : 'deny';
        window.alert(error.message || '权限保存失败');
      }).finally(function () {
        input.disabled = false;
        form.classList.remove('is-saving');
      });
    });
  });

  document.addEventListener('click', function (event) {
    var item = event.target.closest('[data-log-module]');
    if (!item) return;
    sendHomepageEvent(item.getAttribute('data-log-type') || 'module_click', item.getAttribute('data-log-module') || '', item.getAttribute('href') || '', {
      text: item.textContent.trim().slice(0, 80)
    });
  });

  window.addEventListener('pagehide', function () {
    sendHomepageEvent('leave', 'home', window.location.pathname);
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest('button.danger');
    if (button && !window.confirm('确认执行该危险操作？')) {
      event.preventDefault();
    }
  });

  document.querySelectorAll('.settings-control form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"], button:not([type])');
      if (button && !button.disabled) {
        button.dataset.originalText = button.textContent;
        button.textContent = '处理中...';
      }
    });
  });
})();
