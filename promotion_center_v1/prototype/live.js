/* 把原型的客户列表、客户详情和客户分组接到 promotion_center_v1/api.php。不发送邮件。 */
(function () {
  'use strict';
  var live = window.PM_LIVE;
  if (!live || !live.api) return;
  live.groups = [];
  live.customerById = {};
  live.perms = [];
  live.scopeType = 'self';
  live.scopeLabel = '本人负责';
  live.userId = 0;
  live.user = null;
  live.groupsLoaded = false;
  live.memberKey = {};
  live.centerRows = [];
  live.centerTotal = 0;
  live.centerKey = null;
  live.listRows = [];
  live.listTotal = 0;
  live.listKey = null;
  live.countries = [];
  live.countriesLoaded = false;
  live.detailKey = {};

  function st() { return (window.PM_APP && window.PM_APP.state) || {}; }

  function api(action, payload, method) {
    var url = live.api + (String(live.api).indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action);
    var opt = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method === 'POST') {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = live.csrf || '';
      var body = payload || {};
      body.action = action;
      opt.body = JSON.stringify(body);
    } else if (payload) {
      Object.keys(payload).forEach(function (key) {
        if (payload[key] === undefined || payload[key] === null || payload[key] === '') return;
        url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]);
      });
    }
    return fetch(url, opt).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { status: res.status, body: body || {} };
      });
    }).then(function (res) {
      var body = res.body;
      if (body.error_code === 'AUTH_REQUIRED') {
        location.href = live.loginUrl || '../login.php';
        throw new Error(body.message || '请先登录');
      }
      if (!body.success) throw new Error(body.message || '请求失败');
      return body.data || {};
    });
  }

  function toCustomer(row) {
    var status = row.promotion_status || '';
    var c = {
      id: +row.id,
      company: row.customer_name || '',
      code: row.customer_code || '',
      contact: row.primary_contact || '',
      email: row.primary_email || '',
      country: row.country_label || row.country || '',
      countryRaw: row.country || '',
      level: row.level || '',
      owner: row.owner_user_id || 0,
      ownerName: row.owner_label || '无负责人',
      dnc: !!row.do_not_contact,
      unsub: !!row.unsubscribe_email,
      pstatus: status === 'blacklist' ? 'blacklist' : (status === 'paused' || status === 'stopped' ? 'paused' : ''),
      channels: row.channels || [],
      channelLabels: row.channel_labels || [],
      promotionLabel: row.promotion_status_label || '',
      wechat: '',
      whatsapp: '',
      phone: '',
      chatGroup: '',
      avoidGroup: false,
      live: true
    };
    if (row.contacts && row.contacts.length) {
      var primary = row.contacts.filter(function (x) { return x.is_primary && !x.is_left; })[0] || row.contacts.filter(function (x) { return !x.is_left; })[0] || row.contacts[0];
      c.contact = primary.name || c.contact;
      c.email = (primary.emails && primary.emails[0]) || c.email;
      c.unsub = !!primary.unsubscribe_email;
      c.wechat = primary.wechat || '';
      c.whatsapp = primary.whatsapp || '';
      c.contacts = row.contacts;
    }
    if (row.company_emails) c.companyEmails = row.company_emails;
    if (row.chat_groups) c.chatGroups = row.chat_groups;
    if (row.no_promotion_reason) c.noPromotionReason = row.no_promotion_reason;
    var prev = live.customerById[c.id];
    if (prev) {
      if ((!c.channels || !c.channels.length) && prev.channels && prev.channels.length) c.channels = prev.channels;
      if (!row.promotion_status && prev.pstatus) c.pstatus = prev.pstatus;
      if (!c.promotionLabel && prev.promotionLabel) c.promotionLabel = prev.promotionLabel;
      if ((!c.channelLabels || !c.channelLabels.length) && prev.channelLabels && prev.channelLabels.length) c.channelLabels = prev.channelLabels;
      if (!c.contacts && prev.contacts) c.contacts = prev.contacts;
      if (!c.companyEmails && prev.companyEmails) c.companyEmails = prev.companyEmails;
      if (!c.chatGroups && prev.chatGroups) c.chatGroups = prev.chatGroups;
      if ((!c.ownerName || c.ownerName === '无负责人') && prev.ownerName && prev.ownerName !== '无负责人') c.ownerName = prev.ownerName;
      if (!c.otherGroups && prev.otherGroups) c.otherGroups = prev.otherGroups;
    }
    live.customerById[c.id] = c;
    return c;
  }

  function mapGroup(row) {
    return {
      id: +row.id,
      name: row.name,
      color: row.color || '#3b4fd8',
      visibility: row.visibility === 'private' ? 'private' : 'public',
      createdBy: +row.created_by,
      createdByName: row.created_by_name || '',
      members: [],
      memberTotal: row.total_count,
      visibleCount: row.visible_count,
      canEdit: !!row.can_edit,
      canDelete: !!row.can_delete,
      canMove: !!row.can_move,
      deleted: false
    };
  }

  function invalidate() {
    live.groupsLoaded = false;
    live.memberKey = {};
  }

  live.schedule = function (fn) {
    clearTimeout(live._timer);
    live._timer = setTimeout(fn, 280);
  };

  live.boot = function () {
    return api('session').then(function (data) {
      live.user = data.user || {};
      live.userId = +(live.user.id || 0);
      live.scopeType = (data.scopes && data.scopes.customer && data.scopes.customer.scope_type) || 'self';
      live.scopeLabel = (data.scope_labels && data.scope_labels.customer) || live.scopeType;
      live.perms = Object.keys(data.permissions || {}).filter(function (key) { return data.permissions[key]; });
      live.ready = true;
    });
  };

  function loadGroups() {
    return api('groups').then(function (data) {
      var previous = {};
      live.groups.forEach(function (g) { previous[g.id] = g.members; });
      live.groups = (data.rows || []).map(function (row) {
        var g = mapGroup(row);
        if (previous[g.id]) g.members = previous[g.id];
        return g;
      });
      live.groupsLoaded = true;
    });
  }

  function loadCountries() {
    return api('customer_filters').then(function (data) {
      live.countries = data.countries || [];
      live.countriesLoaded = true;
    });
  }

  function loadMembers(id) {
    return api('group', { id: id, page: 1, page_size: 100 }).then(function (data) {
      var g = live.groups.filter(function (item) { return String(item.id) === String(id); })[0];
      if (!g) {
        g = mapGroup(data);
        live.groups.unshift(g);
      }
      g.memberTotal = data.total_count;
      g.visibleCount = data.visible_count;
      g.members = (data.members || []).map(function (row) {
        return toCustomer({
          id: row.id,
          customer_name: row.customer_name,
          customer_code: row.customer_code,
          country: row.country,
          country_label: row.country_label || row.country,
          owner_user_id: row.owner_user_id,
          owner_label: row.owner_label || '',
          do_not_contact: row.do_not_contact,
          primary_contact: row.primary_contact,
          primary_email: row.primary_email,
          unsubscribe_email: row.unsubscribe_email,
          channels: (live.customerById[+row.id] && live.customerById[+row.id].channels) || []
        }).id;
      });
      (data.members || []).forEach(function (row) {
        var c = live.customerById[row.id];
        if (c && data.members) {
          var others = row.other_groups || [];
          c.otherGroups = others;
        }
      });
      live.memberKey[id] = '1';
    });
  }

  function centerFilter() {
    var s = st();
    var q = s.centerQ || '';
    var country = s.centerCountry || '';
    return {
      q: q,
      country: country,
      active: !!(String(q).trim() || String(country).trim()),
      key: q + '|' + country
    };
  }

  function loadCenter() {
    var filter = centerFilter();
    live._centerSeq = (live._centerSeq || 0) + 1;
    var seq = live._centerSeq;
    if (!filter.active) {
      live.centerRows = [];
      live.centerTotal = 0;
      live.centerKey = filter.key;
      return Promise.resolve();
    }
    return api('customers', { q: filter.q, country: filter.country, page_size: 30 }).then(function (data) {
      if (seq !== live._centerSeq) return;
      live.centerRows = (data.rows || []).map(toCustomer);
      live.centerTotal = data.total || 0;
      live.centerKey = filter.key;
    });
  }

  function loadList() {
    var s = st();
    var q = s.customerQ || '';
    var country = s.customerCountry || '';
    var key = q + '|' + country;
    live._listSeq = (live._listSeq || 0) + 1;
    var seq = live._listSeq;
    return api('customers', { q: q, country: country, page_size: 30 }).then(function (data) {
      if (seq !== live._listSeq) return;
      live.listRows = (data.rows || []).map(toCustomer);
      live.listTotal = data.total || 0;
      live.listKey = key;
    });
  }

  function loadDetail(id) {
    return api('customer', { id: id }).then(function (row) {
      toCustomer(row);
      live.detailKey[id] = true;
    });
  }

  live.needs = function (name, arg) {
    var s = st();
    if (name === 'groups') {
      if (!live.groupsLoaded) return true;
      var memberId = arg || (live.groups[0] && live.groups[0].id);
      if (memberId && live.memberKey[memberId] !== '1') return true;
      var filter = centerFilter();
      if (filter.active && live.centerKey !== filter.key) return true;
    }
    if (name === 'customers' && !arg) {
      var lkey = (s.customerQ || '') + '|' + (s.customerCountry || '');
      if (live.listKey !== lkey) return true;
    }
    if (name === 'customers' && arg && !live.detailKey[arg]) return true;
    return false;
  };

  live.prepare = function (name, arg) {
    var jobs = [];
    if (!live.groupsLoaded) jobs.push(loadGroups());
    if (!live.countriesLoaded) jobs.push(loadCountries());
    return Promise.all(jobs).then(function () {
      var more = [];
      if (name === 'groups' && arg) more.push(loadMembers(arg));
      if (name === 'groups' && !arg && live.groups[0]) more.push(loadMembers(live.groups[0].id));
      if (name === 'groups' && centerFilter().active) more.push(loadCenter());
      if (name === 'customers' && arg) more.push(loadDetail(arg));
      if (name === 'customers' && !arg) more.push(loadList());
      return Promise.all(more);
    });
  };

  function afterWrite(data) {
    invalidate();
    return data;
  }

  live.searchCenter = function () { return loadCenter(); };
  live.searchList = function () { return loadList(); };
  live.createGroup = function (name, visibility) {
    return api('group_create', { name: name, visibility: visibility === 'private' ? 'private' : 'team' }, 'POST').then(afterWrite);
  };
  live.renameGroup = function (id, name) {
    return api('group_rename', { group_id: id, name: name }, 'POST').then(afterWrite);
  };
  live.deleteGroup = function (id) {
    return api('group_delete', { group_id: id }, 'POST').then(afterWrite);
  };
  live.addMembers = function (id, ids) {
    return api('group_members_add', { group_id: id, customer_ids: ids }, 'POST').then(afterWrite);
  };
  live.removeMembers = function (id, ids) {
    return api('group_members_remove', { group_id: id, customer_ids: ids }, 'POST').then(afterWrite);
  };
  live.moveMembers = function (fromId, toId, ids) {
    return api('group_members_move', { from_id: fromId, to_id: toId, customer_ids: ids }, 'POST').then(afterWrite);
  };
  live.copyMembers = function (fromId, toId, ids) {
    return api('group_members_copy', { from_id: fromId, to_id: toId, customer_ids: ids }, 'POST').then(afterWrite);
  };
  live.undo = function (token) {
    return api('undo', { token: token }, 'POST').then(afterWrite);
  };
})();
