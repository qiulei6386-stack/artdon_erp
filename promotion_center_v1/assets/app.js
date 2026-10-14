(function () {
  var view = document.getElementById('view');
  var crumbs = document.getElementById('crumbs');
  var state = {
    session: null,
    customers: null,
    filters: { countries: [], owners: [] },
    query: { q: '', country: '', owner_user_id: '', activity: '', sort: 'updated_at', dir: 'DESC', page: 1 },
    detail: null,
    groups: [],
    group: null,
    groupId: 0,
    picker: [],
    pickQ: '',
    history: null,
    message: '',
    error: '',
    undo: null
  };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  function token() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function api(action, payload, method) {
    var verb = method || (payload ? 'POST' : 'GET');
    var url = 'api.php?action=' + encodeURIComponent(action);
    var opt = { method: verb, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (verb === 'GET' && payload) {
      Object.keys(payload).forEach(function (key) {
        if (payload[key] !== '' && payload[key] != null) url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]);
      });
    } else if (verb === 'POST') {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = token();
      opt.body = JSON.stringify(payload || {});
    }
    return fetch(url, opt).then(function (res) {
      return res.json().then(function (body) {
        if (!body.success) throw new Error(body.message || '请求失败');
        return body.data;
      });
    });
  }

  function setMessage(text, isError) {
    state.message = isError ? '' : (text || '');
    state.error = isError ? (text || '') : '';
  }

  function banner() {
    if (state.error) return '<div class="banner bad">' + esc(state.error) + '</div>';
    if (state.undo) return '<div class="banner info">' + esc(state.undo.text) + '<button class="btn sm" type="button" data-undo="' + esc(state.undo.token) + '">撤销</button></div>';
    if (state.message) return '<div class="banner info">' + esc(state.message) + '</div>';
    return '';
  }

  function nav(name) {
    document.querySelectorAll('[data-nav]').forEach(function (el) {
      el.classList.toggle('active', el.getAttribute('data-nav') === name);
    });
  }

  function chips(labels, bad) {
    return (labels || []).map(function (label) {
      return '<span class="chip' + (bad ? ' bad' : '') + '">' + esc(label) + '</span>';
    }).join(' ');
  }

  function renderCustomers() {
    nav('customers');
    crumbs.innerHTML = '推广中心 / <b>客户</b>';
    var q = state.query;
    var data = state.customers || { rows: [], total: 0, page: 1, page_size: 20 };
    var countryOptions = '<option value="">全部国家</option>' + state.filters.countries.map(function (item) {
      return '<option value="' + esc(item.value) + '"' + (item.value === q.country ? ' selected' : '') + '>' + esc(item.label) + '</option>';
    }).join('');
    var ownerOptions = '<option value="">全部负责人</option>' + state.filters.owners.map(function (item) {
      return '<option value="' + item.id + '"' + (String(item.id) === String(q.owner_user_id) ? ' selected' : '') + '>' + esc(item.name) + '</option>';
    }).join('');
    var rows = data.rows.map(function (row) {
      var marks = [];
      if (row.blocked) marks.push('<span class="chip bad">禁止联系</span>');
      if (!row.has_owner) marks.push('<span class="chip warn">无负责人</span>');
      return '<tr><td><a class="cell-title" href="#/customers/' + row.id + '">' + esc(row.customer_name) + '</a><div class="cell-sub">' + esc(row.customer_code) + '</div></td>' +
        '<td>' + esc(row.country_label) + '</td><td>' + esc(row.owner_label) + '</td><td>' + esc(row.promotion_status_label) + '</td>' +
        '<td>' + chips(row.channel_labels) + '</td><td class="num">' + row.contact_count + '</td><td>' + marks.join(' ') + '</td></tr>';
    }).join('');
    var pages = Math.max(1, Math.ceil(data.total / data.page_size));
    view.innerHTML = '<div class="page-head"><div><h1>客户</h1><p>只显示你的客户范围内的客户。本阶段只读，不发送邮件。</p></div></div>' + banner() +
      '<div class="card"><form class="toolbar pm-filters" data-filter>' +
      '<input class="input" name="q" placeholder="公司名 / 客户编码" value="' + esc(q.q) + '">' +
      '<select class="select" name="country">' + countryOptions + '</select>' +
      '<select class="select" name="owner_user_id">' + ownerOptions + '</select>' +
      '<select class="select" name="activity"><option value="">最近活跃：不限</option><option value="7d"' + (q.activity === '7d' ? ' selected' : '') + '>近 7 天活跃</option><option value="30d"' + (q.activity === '30d' ? ' selected' : '') + '>近 30 天活跃</option><option value="inactive_30d"' + (q.activity === 'inactive_30d' ? ' selected' : '') + '>超过 30 天未活跃</option></select>' +
      '<select class="select" name="sort"><option value="updated_at">最近更新</option><option value="customer_name"' + (q.sort === 'customer_name' ? ' selected' : '') + '>公司名</option><option value="country"' + (q.sort === 'country' ? ' selected' : '') + '>国家</option><option value="created_at"' + (q.sort === 'created_at' ? ' selected' : '') + '>创建时间</option><option value="last_followup"' + (q.sort === 'last_followup' ? ' selected' : '') + '>最近跟进</option></select>' +
      '<button class="btn primary" type="submit">筛选</button></form>' +
      (rows ? '<div class="table-wrap"><table><thead><tr><th>客户</th><th>国家</th><th>负责人</th><th>推广状态</th><th>渠道偏好</th><th>联系人</th><th>标记</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div class="empty">没有符合条件的客户</div>') +
      '<div class="pager"><span class="muted small">共 ' + data.total + ' 条 · 客户范围：' + esc(state.session.scope_labels.customer) + '</span><button class="btn sm" type="button" data-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>上一页</button><span class="num">' + data.page + ' / ' + pages + '</span><button class="btn sm" type="button" data-page="' + (data.page + 1) + '"' + (data.page >= pages ? ' disabled' : '') + '>下一页</button></div></div>';
    bindCustomers();
  }

  function bindCustomers() {
    var form = view.querySelector('[data-filter]');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var fd = new FormData(form);
      state.query.q = String(fd.get('q') || '');
      state.query.country = String(fd.get('country') || '');
      state.query.owner_user_id = String(fd.get('owner_user_id') || '');
      state.query.activity = String(fd.get('activity') || '');
      state.query.sort = String(fd.get('sort') || 'updated_at');
      state.query.page = 1;
      loadCustomers();
    });
    view.querySelectorAll('[data-page]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var page = Number(btn.getAttribute('data-page'));
        if (page < 1) return;
        state.query.page = page;
        loadCustomers();
      });
    });
  }

  function loadCustomers() {
    return Promise.all([
      api('customers', state.query, 'GET'),
      api('customer_filters', null, 'GET')
    ]).then(function (pair) {
      state.customers = pair[0];
      state.filters = pair[1];
      renderCustomers();
    }).catch(function (err) {
      setMessage(err.message, true);
      renderCustomers();
    });
  }

  function renderDetail() {
    nav('customers');
    var row = state.detail;
    crumbs.innerHTML = '推广中心 / <a href="#/customers">客户</a> / <b>' + esc(row.customer_name) + '</b>';
    var contacts = (row.contacts || []).map(function (ct) {
      var flags = [];
      if (ct.is_primary) flags.push('<span class="chip info">主要联系人</span>');
      if (ct.is_left) flags.push('<span class="chip warn">已离职</span>');
      if (ct.unsubscribe_email) flags.push('<span class="chip bad">邮件已退订</span>');
      if (ct.do_not_contact) flags.push('<span class="chip bad">禁止联系</span>');
      var channels = (ct.channels || []).map(function (ch) {
        return esc(ch.label) + '（' + esc(ch.status_label) + '）';
      }).join('、') || '无';
      return '<tr><td>' + esc(ct.name) + '<div class="pm-note">' + flags.join(' ') + '</div></td><td>' + ((ct.emails || []).map(esc).join('<br>') || '无邮箱') + '</td><td>' + esc(ct.wechat || '—') + '</td><td>' + esc(ct.whatsapp || '—') + '</td><td>' + channels + '</td></tr>';
    }).join('');
    var groups = (row.chat_groups || []).map(function (g) {
      return '<li>' + esc(g.platform_label) + '：' + esc(g.name) + '</li>';
    }).join('');
    view.innerHTML = '<div class="page-head"><div><h1>' + esc(row.customer_name) + '</h1><p>' + esc(row.customer_code) + ' · ' + esc(row.country_label) + ' · ' + esc(row.owner_label) + '</p></div><div class="actions"><a class="btn" href="#/customers">返回列表</a></div></div>' + banner() +
      '<div class="card" style="margin-bottom:16px"><div class="card-body">' +
      '<div>' + esc(row.promotion_status_label) + (row.blocked ? ' · <span class="chip bad">' + esc(row.block_label || '禁止联系') + '</span>' : '') + (row.no_promotion_reason ? ' · ' + esc(row.no_promotion_reason) : '') + (row.has_owner ? '' : ' · <span class="chip warn">无负责人</span>') + '</div>' +
      '<div class="pm-note">客户渠道偏好：' + (row.channel_labels.length ? chips(row.channel_labels) : '未设置') + '</div>' +
      '<div class="pm-note">公司邮箱：' + ((row.company_emails || []).map(esc).join('、') || '无') + '</div>' +
      '<div class="pm-note">可推广聊天群：' + (groups ? '<ul>' + groups + '</ul>' : '没有启用且允许推广的群') + '</div></div></div>' +
      '<div class="card"><div class="card-head"><h2>联系人</h2></div>' +
      (contacts ? '<div class="table-wrap"><table><thead><tr><th>姓名</th><th>邮箱</th><th>微信</th><th>WhatsApp</th><th>渠道</th></tr></thead><tbody>' + contacts + '</tbody></table></div>' : '<div class="empty">没有联系人</div>') +
      '</div>';
  }

  function renderGroups() {
    nav('groups');
    crumbs.innerHTML = '推广中心 / <b>客户分组</b>';
    var perms = state.session.permissions;
    var list = state.groups.map(function (g) {
      return '<div class="list-item' + (g.id === state.groupId ? ' on' : '') + '" data-gid="' + g.id + '"><div style="display:flex;align-items:center;gap:8px"><span class="dot" style="background:' + esc(g.color) + '"></span><span class="cell-title" style="flex:1">' + esc(g.name) + '</span><span class="num small muted">' + g.visible_count + '</span></div><div class="cell-sub">' + esc(g.visibility_label) + ' · ' + esc(g.created_by_name) + '</div></div>';
    }).join('');
    var cur = state.group;
    var main = '<div class="card"><div class="empty">还没有分组。' + (perms.create_group ? '在左侧新建一个。' : '') + '</div></div>';
    if (cur && cur.id) {
      var members = (cur.members || []).map(function (m) {
        var others = (m.other_groups || []).map(function (g) { return esc(g.name); }).join('、');
        return '<tr><td><input type="checkbox" data-mid="' + m.id + '"></td><td><a href="#/customers/' + m.id + '">' + esc(m.customer_name) + '</a><div class="cell-sub">' + esc(m.customer_code) + '</div></td><td>' + esc(m.country) + '</td><td>' + (others || '—') + '</td></tr>';
      }).join('');
      var targets = state.groups.filter(function (g) { return g.id !== cur.id; }).map(function (g) {
        return '<option value="' + g.id + '">' + esc(g.name) + '</option>';
      }).join('');
      main = '<div class="card groups-main"><div class="card-head"><div><h2 style="display:flex;align-items:center;gap:8px"><span class="dot" style="background:' + esc(cur.color) + '"></span>' + esc(cur.name) + '</h2><div class="cell-sub">' + esc(cur.visibility_label) + ' · ' + esc(cur.created_by_name) + ' 创建 · 你负责的 ' + cur.visible_count + ' 个（分组共 ' + cur.total_count + ' 个）</div></div></div><div class="card-body">' +
        (perms.edit_group && cur.can_edit ? '<form class="pm-filters" data-rename><input class="input" name="name" value="' + esc(cur.name) + '"><button class="btn sm" type="submit">重命名</button></form>' : '') +
        '<div class="actions" style="margin:12px 0">' +
        (perms.edit_group && cur.can_edit ? '<button class="btn sm danger" type="button" data-remove>移出勾选</button>' : '') +
        (perms.move_customer && cur.can_move && targets ? '<select class="select" data-target style="width:auto">' + targets + '</select><button class="btn sm" type="button" data-move>移到其他分组</button><button class="btn sm" type="button" data-copy>复制到其他分组</button>' : '') +
        (perms.delete_group && cur.can_delete ? '<button class="btn sm danger" type="button" data-delete>删除分组</button>' : '') +
        '</div>' +
        (members ? '<div class="table-wrap"><table><thead><tr><th></th><th>客户</th><th>国家</th><th>其他分组</th></tr></thead><tbody>' + members + '</tbody></table></div>' : '<div class="empty">这个分组里还没有你负责的客户</div>') +
        '</div></div>';
    }
    var pickerRows = state.picker.map(function (row) {
      return '<label class="list-item"><input type="checkbox" data-pid="' + row.id + '"> ' + esc(row.customer_name) + '<div class="cell-sub">' + esc(row.customer_code) + ' · ' + esc(row.country_label) + '</div></label>';
    }).join('');
    var picker = '<div class="card picker"><div class="card-head"><h2>添加客户</h2></div><div class="card-body"><form class="pm-filters" data-pick><input class="input" name="q" placeholder="搜索公司名 / 编码" value="' + esc(state.pickQ) + '"><button class="btn sm" type="submit">搜索</button></form><div style="margin-top:8px">' + (pickerRows || '<div class="empty">输入公司名后搜索</div>') + '</div>' + (perms.edit_group ? '<button class="btn primary sm" type="button" data-add style="margin-top:12px">加入当前分组</button>' : '') + '</div></div>';
    view.innerHTML = '<div class="page-head"><div><h1>客户分组</h1><p>一个客户可以在多个分组里。删除分组或移出客户不会删除客户资料，也不会发信。</p></div></div>' + banner() +
      '<div class="groups with-picker"><div class="card groups-list"><div class="card-body">' +
      (perms.create_group ? '<form class="pm-filters" data-create><input class="input" name="name" placeholder="新分组名称"><select class="select" name="visibility" style="width:auto"><option value="team">团队共用</option><option value="private">仅自己可见</option></select><button class="btn primary sm" type="submit">新建分组</button></form>' : '') +
      (list || '<div class="empty">没有分组</div>') + '</div></div>' + main + picker + '</div>';
    bindGroups();
  }

  function selectedIds(attr) {
    return Array.prototype.map.call(view.querySelectorAll('[' + attr + ']:checked'), function (el) {
      return Number(el.getAttribute(attr));
    });
  }

  function afterGroup(data, text) {
    if (data && data.undo_token) state.undo = { token: data.undo_token, text: text };
    else state.undo = null;
    setMessage(text, false);
    return refreshGroup(data && data.id ? data.id : state.groupId);
  }

  function bindGroups() {
    var create = view.querySelector('[data-create]');
    if (create) create.addEventListener('submit', function (event) {
      event.preventDefault();
      var fd = new FormData(create);
      api('group_create', { name: fd.get('name'), visibility: fd.get('visibility') }).then(function (data) {
        location.hash = '#/groups/' + data.id;
        afterGroup(data, '已新建分组，10 秒内可撤销');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    view.querySelectorAll('[data-gid]').forEach(function (el) {
      el.addEventListener('click', function () { location.hash = '#/groups/' + el.getAttribute('data-gid'); });
    });
    var rename = view.querySelector('[data-rename]');
    if (rename) rename.addEventListener('submit', function (event) {
      event.preventDefault();
      api('group_rename', { id: state.groupId, name: new FormData(rename).get('name') }).then(function (data) {
        afterGroup(data, '已重命名，10 秒内可撤销');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var pick = view.querySelector('[data-pick]');
    if (pick) pick.addEventListener('submit', function (event) {
      event.preventDefault();
      state.pickQ = String(new FormData(pick).get('q') || '');
      api('customers', { q: state.pickQ, page: 1, page_size: 20 }, 'GET').then(function (data) {
        state.picker = data.rows || [];
        renderGroups();
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var add = view.querySelector('[data-add]');
    if (add) add.addEventListener('click', function () {
      var ids = selectedIds('data-pid');
      api('group_members_add', { group_id: state.groupId, customer_ids: ids }).then(function (data) {
        afterGroup(data, '已加入 ' + (data.added || 0) + ' 个客户');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var remove = view.querySelector('[data-remove]');
    if (remove) remove.addEventListener('click', function () {
      api('group_members_remove', { group_id: state.groupId, customer_ids: selectedIds('data-mid') }).then(function (data) {
        afterGroup(data, '已移出客户，10 秒内可撤销');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    function targetId() {
      var sel = view.querySelector('[data-target]');
      return sel ? Number(sel.value) : 0;
    }
    var move = view.querySelector('[data-move]');
    if (move) move.addEventListener('click', function () {
      api('group_members_move', { from_id: state.groupId, to_id: targetId(), customer_ids: selectedIds('data-mid') }).then(function (data) {
        afterGroup(data, '已移动客户，10 秒内可撤销');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var copy = view.querySelector('[data-copy]');
    if (copy) copy.addEventListener('click', function () {
      api('group_members_copy', { from_id: state.groupId, to_id: targetId(), customer_ids: selectedIds('data-mid') }).then(function (data) {
        afterGroup(data, '已复制客户，10 秒内可撤销');
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var del = view.querySelector('[data-delete]');
    if (del) del.addEventListener('click', function () {
      if (!window.confirm('删除这个分组？客户资料不会删除。')) return;
      api('group_delete', { id: state.groupId }).then(function (data) {
        state.undo = { token: data.undo_token, text: '分组已删除，10 秒内可撤销' };
        state.groupId = 0;
        state.group = null;
        location.hash = '#/groups';
        refreshGroup(0);
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
    var undo = view.querySelector('[data-undo]');
    if (undo) undo.addEventListener('click', function () {
      api('undo', { token: undo.getAttribute('data-undo') }).then(function () {
        state.undo = null;
        setMessage('已撤销', false);
        refreshGroup(state.groupId);
      }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
    });
  }

  function refreshGroup(id) {
    return api('groups', null, 'GET').then(function (data) {
      state.groups = data.rows || [];
      if (!id && state.groups[0]) id = state.groups[0].id;
      state.groupId = id || 0;
      if (!state.groupId) { state.group = null; renderGroups(); return; }
      return api('group', { id: state.groupId }, 'GET').then(function (group) {
        state.group = group;
        renderGroups();
      });
    }).catch(function (err) { setMessage(err.message, true); renderGroups(); });
  }

  function renderHistory() {
    nav('history');
    crumbs.innerHTML = '推广中心 / <b>历史推广</b>';
    var data = state.history || { rows: [], note: '' };
    var rows = (data.rows || []).map(function (row) {
      return '<tr><td><span class="chip info">' + esc(row.source_label) + '</span></td><td>' + esc(row.name) + '</td><td>' + esc(row.status_label) + '</td><td>' + esc(row.creator_name) + '</td><td>' + esc(row.created_at) + '</td><td>只读</td></tr>';
    }).join('');
    view.innerHTML = '<div class="page-head"><div><h1>历史推广</h1><p>' + esc(data.note || 'CRM/旧中心历史，只读。') + '</p></div></div>' + banner() +
      '<div class="card">' + (rows ? '<div class="table-wrap"><table><thead><tr><th>来源</th><th>名称</th><th>状态</th><th>创建人</th><th>时间</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div class="empty">还没有可读取的历史推广</div>') + '</div>';
  }

  function open() {
    var hash = (location.hash || '#/customers').replace(/^#\/?/, '');
    var parts = hash.split('/');
    var page = parts[0] || 'customers';
    if (page === 'customers' && parts[1]) {
      api('customer', { id: parts[1] }, 'GET').then(function (data) {
        state.detail = data;
        setMessage('', false);
        renderDetail();
      }).catch(function (err) { setMessage(err.message, true); renderCustomers(); });
      return;
    }
    if (page === 'groups') {
      state.groupId = Number(parts[1] || 0);
      refreshGroup(state.groupId);
      return;
    }
    if (page === 'history') {
      api('history', null, 'GET').then(function (data) {
        state.history = data;
        renderHistory();
      }).catch(function (err) { setMessage(err.message, true); state.history = { rows: [], note: '' }; renderHistory(); });
      return;
    }
    loadCustomers();
  }

  document.querySelectorAll('[data-nav]').forEach(function (el) {
    el.addEventListener('click', function () { location.hash = '#/' + el.getAttribute('data-nav'); });
  });
  window.addEventListener('hashchange', open);
  api('session', null, 'GET').then(function (session) {
    state.session = session;
    if (!location.hash) location.hash = '#/customers';
    else open();
  }).catch(function (err) {
    view.innerHTML = '<div class="banner bad">' + esc(err.message) + '</div>';
  });
})();
