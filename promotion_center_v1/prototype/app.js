/* 推广中心 V1。客户列表、客户详情、客户分组在登录后读取客户中心；其余页面仍是演示数据，不发送邮件。 */
(function () {
  'use strict';
  var D = window.PM_DATA;
  var state = { userId: 1, listFilter: 'all', listSource: 'all', detailTab: 'recipients', recipientFilter: 'all', approvalId: null, draft: null };

  var STATUS = {
    draft: ['草稿', ''],
    pending_approval: ['待审批', 'warn'],
    rejected: ['已退回', 'bad'],
    scheduled: ['已排期', 'info'],
    sending: ['发送中', 'info'],
    paused: ['已暂停', 'warn'],
    completed: ['已完成', 'ok'],
    stopped: ['已停止', ''],
    cancelled: ['已取消', '']
  };
  var CHANNEL = { email: '邮件', preference: '按客户偏好', whatsapp: 'WhatsApp', wechat: '微信' };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function me() { return D.users.filter(function (u) { return u.id === state.userId; })[0]; }
  function user(id) { return D.users.filter(function (u) { return u.id === id; })[0] || { name: '—' }; }
  function mailbox(id) { return D.mailboxes.filter(function (m) { return m.id === id; })[0]; }
  function campaign(id) { return D.campaigns.filter(function (c) { return c.id === id; })[0]; }
  function liveOn() { return !!(window.PM_LIVE && window.PM_LIVE.ready && state.useLive); }
  function can(p) { return me().perms.indexOf(p) >= 0; }
  function allScope() { return me().scope !== 'self'; }
  function liveCan(p) { return liveOn() ? (window.PM_LIVE.perms || []).indexOf(p) >= 0 : can(p); }
  function liveAllScope() { return liveOn() ? window.PM_LIVE.scopeType === 'all' : allScope(); }
  function isManager() { return can('approve'); }
  function canCreate() { return can('task_create'); }
  function visibleCustomer(c) { return allScope() || c.owner === state.userId; }
  function group(id) {
    if (liveOn()) return (window.PM_LIVE.groups || []).filter(function (g) { return String(g.id) === String(id); })[0];
    return D.groups.filter(function (g) { return g.id === id; })[0];
  }
  function customer(id) {
    if (liveOn()) return window.PM_LIVE.customerById[+id] || null;
    return D.customers.filter(function (c) { return c.id === id; })[0];
  }
  function visibleGroups() {
    if (liveOn()) return (window.PM_LIVE.groups || []).slice();
    return D.groups.filter(function (g) { return !g.deleted && (g.visibility === 'public' || g.createdBy === state.userId); });
  }
  function visibleMembers(g) {
    if (!g) return [];
    if (liveOn()) return (g.members || []).map(function (id) { return customer(id); }).filter(Boolean);
    return g.members.map(customer).filter(function (c) { return c && visibleCustomer(c); });
  }
  function groupCount(g) {
    if (liveOn() && g.visibleCount != null) return g.visibleCount;
    return visibleMembers(g).length;
  }
  function creatorName(g) { return g.createdByName || user(g.createdBy).name; }
  function ownerLabel(c) {
    if (!c) return '—';
    if (c.ownerName) return c.ownerName;
    if (!c.owner) return '无负责人';
    return user(c.owner).name;
  }
  function chip(status, c) {
    var s = STATUS[status] || [status, ''];
    var label = s[0];
    if (c && c.partialFailed) label += ' · 部分失败';
    return '<span class="chip ' + s[1] + '">' + label + '</span>';
  }
  function visibleCampaigns() {
    var u = me();
    return D.campaigns.filter(function (c) {
      if (u.scope !== 'self') return true;
      return c.createdBy === u.id || c.owners.indexOf(u.id) >= 0;
    });
  }
  function pendingApprovals() { return D.campaigns.filter(function (c) { return c.status === 'pending_approval'; }); }
  function myManualTasks() {
    return D.manualTasks.filter(function (t) { return t.status === 'pending' && (allScope() || t.executor === state.userId); });
  }
  function toast(msg, undo) {
    $all('.toast').forEach(function (t) { t.remove(); });
    var el = document.createElement('div');
    el.className = 'toast'; el.setAttribute('role', 'status');
    el.appendChild(document.createTextNode(msg));
    if (undo) {
      var b = document.createElement('button');
      b.className = 'toast-undo'; b.textContent = '撤销';
      b.addEventListener('click', function () { el.remove(); undo(); });
      el.appendChild(b);
    }
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, undo ? 10000 : 2600);
  }
  function modal(opts) {
    var wrap = document.createElement('div');
    wrap.className = 'mask';
    wrap.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><div class="modal-head"><h3>' + esc(opts.title) + '</h3></div><div class="modal-body">' + opts.body + '</div><div class="modal-foot"><button class="btn" data-x>取消</button><button class="btn ' + (opts.danger ? 'danger' : 'primary') + '" data-ok>' + esc(opts.ok || '确定') + '</button></div></div>';
    document.body.appendChild(wrap);
    function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    wrap.addEventListener('click', function (e) { if (e.target === wrap || e.target.hasAttribute('data-x')) close(); });
    $('[data-ok]', wrap).addEventListener('click', function () {
      var err = opts.onOk ? opts.onOk(wrap) : null;
      if (typeof err === 'string') { var h = $('.form-error', wrap); if (h) { h.textContent = err; h.classList.remove('hidden'); } return; }
      close();
    });
    var first = $('input, select, textarea', wrap); (first || $('[data-ok]', wrap)).focus();
  }

  /* 审批规则：与 docs/DESIGN.md 第 4 节一致；正式实现由服务端判定。 */
  function approvalReasons(u, mailboxIds, emailCount) {
    if (u.perms.indexOf('approve') >= 0) return [];
    if (u.perms.indexOf('exempt_small') < 0) return ['没有“小批量免审”权限，所有推广都需要审批'];
    var reasons = [];
    mailboxIds.forEach(function (id) {
      var m = mailbox(id);
      if (m && m.owner !== u.id) reasons.push((m.owner ? '使用了他人邮箱 ' : '使用了公司邮箱 ') + m.email);
    });
    if (emailCount > D.settings.exempt) reasons.push('邮件数 ' + emailCount + ' 超过免审额度 ' + D.settings.exempt);
    return reasons;
  }

  /* 框架 */
  function renderShell(route) {
    var u = me();
    var approvals = pendingApprovals().length;
    var tasks = myManualTasks().length;
    var nav = [
      ['home', '工作台', 0, 'M3 10.5 12 4l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
      ['list', '推广', 0, 'M4 6h16M4 12h16M4 18h10'],
      ['board', '执行看板', 0, 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
      ['customers', '客户', 0, 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20c1.2-3.2 4.2-5 8-5s6.8 1.8 8 5'],
      ['groups', '客户分组', 0, 'M8 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm8.5 0a3 3 0 1 0 0-6M2 20c0-3.3 2.7-6 6-6s6 2.7 6 6m2-6c2.8.3 5 2.7 5 6'],
      ['approvals', isManager() ? '待我审批' : '审批记录', isManager() ? approvals : 0, 'M9 12l2 2 4-4M5 4h14v16H5z'],
      ['tasks', '人工待办', tasks, 'M8 6h12M8 12h12M8 18h12M3.5 6h.01M3.5 12h.01M3.5 18h.01'],
      ['settings', '设置', 0, 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z']
    ];
    if (!window.PM_LIVE) nav = nav.filter(function (n) { return n[0] !== 'customers'; });
    $('#side-nav').innerHTML = nav.map(function (n) {
      var active = route === n[0] || (n[0] === 'list' && (route === 'detail' || route === 'new'));
      return '<a class="nav-item' + (active ? ' active' : '') + '" href="#/' + n[0] + '" title="' + n[1] + '"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' + n[3] + '"/></svg><span class="nav-label">' + n[1] + '</span>' + (n[2] ? '<span class="nav-count num">' + n[2] + '</span>' : '') + '</a>';
    }).join('');
    var role = $('#role-select');
    if (role) role.innerHTML = D.users.map(function (x) { return '<option value="' + x.id + '"' + (x.id === u.id ? ' selected' : '') + '>' + x.name + '（' + x.role + '）</option>'; }).join('');
    var perm = $('#perm-info');
    if (perm && liveOn() && window.PM_LIVE.user) {
      var lu = window.PM_LIVE.user;
      perm.innerHTML = '登录账号：' + esc(lu.name || '') + (lu.role_name ? '（' + esc(lu.role_name) + '）' : '') + '<br>客户范围：' + esc(window.PM_LIVE.scopeLabel || '');
    } else if (perm) {
      perm.innerHTML = '数据范围：' + (u.scope === 'self' ? '本人' : '全部') + '<br>权限：' + u.perms.map(function (p) { return D.permLabels[p]; }).join('、');
    }
  }

  function crumbs(parts) { $('#crumbs').innerHTML = parts.map(function (p, i) { return i === parts.length - 1 ? '<b>' + esc(p) + '</b>' : esc(p); }).join(' / '); }

  /* 工作台 */
  function pageHome() {
    crumbs(['推广中心', '工作台']);
    var u = me();
    var list = visibleCampaigns();
    var issues = list.filter(function (c) { return c.counts.failed || c.counts.uncertain; });
    var running = list.filter(function (c) { return ['sending', 'scheduled', 'paused'].indexOf(c.status) >= 0; });
    var mine = list.filter(function (c) { return c.createdBy === u.id && ['pending_approval', 'rejected', 'draft'].indexOf(c.status) >= 0; });
    var sentToday = D.mailboxes.filter(function (m) { return allScope() || m.owner === u.id; }).reduce(function (s, m) { return s + m.used; }, 0);
    var todo = [];
    if (isManager()) pendingApprovals().forEach(function (c) { todo.push(['审批', c.name, user(c.createdBy).name + ' 提交，' + c.counts.planned + ' 封邮件', '#/approvals/' + c.id, '去审批']); });
    mine.forEach(function (c) {
      if (c.status === 'rejected') todo.push(['已退回', c.name, '退回原因：' + c.rejectReason, '#/new/' + c.id, '修改']);
      if (c.status === 'draft') todo.push(['草稿', c.name, '还未提交', '#/new/' + c.id, '继续编辑']);
    });
    issues.forEach(function (c) { if (c.counts.uncertain) todo.push(['需核对', c.name, c.counts.uncertain + ' 封邮件结果不确定，不会自动重发', '#/detail/' + c.id, '核对']); });
    if (myManualTasks().length) todo.push(['人工待办', myManualTasks().length + ' 个客户待联系', '其中 ' + myManualTasks().filter(function (t) { return t.overdue; }).length + ' 个已逾期', '#/tasks', '去处理']);

    return '<div class="page-head"><div><h1>' + esc(u.name) + '，今天要处理 ' + todo.length + ' 件事</h1><p>' + u.role + (u.scope === 'self' ? ' · 只显示你创建或你负责客户的推广' : ' · 显示全部推广') + '</p></div><div class="actions">' + (canCreate() ? '<a class="btn primary" href="#/new">新建推广</a>' : '') + '</div></div>' +
      '<div class="grid g-4" style="margin-bottom:16px">' +
      stat(isManager() ? '待我审批' : '我的待审批', isManager() ? pendingApprovals().length : list.filter(function (c) { return c.createdBy === u.id && c.status === 'pending_approval'; }).length, '个推广') +
      stat('人工待办', myManualTasks().length, '个客户', myManualTasks().some(function (t) { return t.overdue; })) +
      stat('发送异常', issues.reduce(function (s, c) { return s + c.counts.failed + c.counts.uncertain; }, 0), '封失败或结果不确定', issues.length > 0) +
      stat('今日已发送', sentToday, allScope() ? '全部邮箱' : '你的邮箱') + '</div>' +
      '<div class="grid g-main home-grid"><div class="card"><div class="card-head"><h2>需要处理</h2></div>' +
      (todo.length ? '<div class="table-wrap"><table><tbody>' + todo.map(function (t) { return '<tr><td style="width:96px"><span class="chip ' + (t[0] === '已退回' || t[0] === '需核对' ? 'bad' : 'warn') + '">' + t[0] + '</span></td><td><div class="cell-title">' + esc(t[1]) + '</div><div class="cell-sub">' + esc(t[2]) + '</div></td><td style="text-align:right"><a class="btn sm" href="' + t[3] + '">' + t[4] + '</a></td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="empty">没有需要处理的事项</div>') +
      '</div><div class="grid"><div class="card"><div class="card-head"><h2>进行中</h2><a class="small" href="#/list">全部推广</a></div><div class="card-body" style="padding-top:4px;padding-bottom:4px">' +
      (running.length ? running.map(function (c) { return '<a href="#/detail/' + c.id + '" style="display:block;color:inherit;text-decoration:none;padding:10px 0;border-bottom:1px solid var(--line)"><div style="display:flex;justify-content:space-between;gap:8px"><span class="cell-title">' + esc(c.name) + '</span>' + chip(c.status) + '</div>' + progress(c) + '</a>'; }).join('') : '<div class="empty">没有进行中的推广</div>') +
      '</div></div><div class="card"><div class="card-head"><h2>邮箱今日用量</h2><span class="small muted">北京时间 0 点重置</span></div><div class="card-body">' +
      D.mailboxes.filter(function (m) { return allScope() || m.owner === u.id || (m.owner === 0 && can('use_any_mailbox')); }).map(function (m) {
        var pct = Math.round(m.used / m.limit * 100);
        return '<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between" class="small"><span>' + esc(m.email) + '</span><span class="num muted">' + m.used + ' / ' + m.limit + '</span></div><div class="progress" style="margin-top:4px"><span class="' + (pct > 80 ? 'p-warn' : 'p-ok') + '" style="width:' + pct + '%"></span></div></div>';
      }).join('') + '</div></div></div></div>';
  }
  function stat(label, value, sub, alert) { return '<div class="card stat' + (alert ? ' alert' : '') + '"><div class="stat-label">' + label + '</div><div class="stat-value num">' + value + '</div><div class="stat-sub">' + sub + '</div></div>'; }
  function progress(c) {
    var k = c.counts;
    if (c.manualOnly) {
      var all = k.manualPending + k.manualDone;
      return '<div class="progress" style="margin-top:8px"><span class="p-ok" style="width:' + (all ? k.manualDone / all * 100 : 0) + '%"></span></div><div class="progress-label num">人工完成 ' + k.manualDone + ' / ' + all + '</div>';
    }
    var total = k.planned || 1;
    return '<div class="progress" style="margin-top:8px"><span class="p-ok" style="width:' + (k.sent / total * 100) + '%"></span><span class="p-bad" style="width:' + (k.failed / total * 100) + '%"></span><span class="p-warn" style="width:' + (k.uncertain / total * 100) + '%"></span></div><div class="progress-label num">已发送 ' + k.sent + ' / ' + k.planned + (k.failed ? ' · 失败 ' + k.failed : '') + (k.uncertain ? ' · 不确定 ' + k.uncertain : '') + '</div>';
  }

  /* 推广列表 */
  function pageList() {
    crumbs(['推广中心', '推广']);
    var filters = [['all', '全部'], ['active', '进行中'], ['pending_approval', '待审批'], ['rejected', '已退回'], ['draft', '草稿'], ['done', '已结束']];
    var rows = visibleCampaigns().filter(function (c) {
      if (state.listSource !== 'all' && c.source !== state.listSource) return false;
      var f = state.listFilter;
      if (f === 'all') return true;
      if (f === 'active') return ['scheduled', 'sending', 'paused'].indexOf(c.status) >= 0;
      if (f === 'done') return ['completed', 'stopped', 'cancelled'].indexOf(c.status) >= 0;
      return c.status === f;
    });
    return '<div class="page-head"><div><h1>推广</h1><p>新建的推广与 CRM 历史推广合并显示；CRM 历史只读。</p></div><div class="actions">' + (canCreate() ? '<a class="btn primary" href="#/new">新建推广</a>' : '') + '</div></div>' +
      '<div class="card"><div class="toolbar"><div class="seg" data-seg="listFilter">' + filters.map(function (f) { return '<button class="' + (state.listFilter === f[0] ? 'on' : '') + '" data-v="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div><div class="spacer"></div>' +
      '<div class="seg" data-seg="listSource">' + [['all', '全部来源'], ['pm', '新推广'], ['crm', 'CRM 历史']].map(function (f) { return '<button class="' + (state.listSource === f[0] ? 'on' : '') + '" data-v="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div>' +
      (me().scope !== 'self' ? '<select class="select" style="width:140px"><option>全部创建人</option>' + D.users.slice(0, 4).map(function (u) { return '<option>' + u.name + '</option>'; }).join('') + '</select>' : '') +
      '<input class="input" style="width:200px" placeholder="搜索推广名称"></div>' +
      '<div class="table-wrap"><table><thead><tr><th>推广</th><th>状态</th><th>渠道</th><th style="width:220px">进度</th><th>开始时间</th><th>创建人</th><th></th></tr></thead><tbody>' +
      (rows.length ? rows.map(function (c) {
        return '<tr class="click" data-go="#/detail/' + c.id + '"><td><div class="cell-title">' + esc(c.name) + (c.source === 'crm' ? '<span class="tag">CRM 历史</span>' : '') + '</div><div class="cell-sub num">' + c.id + ' · ' + c.createdAt + '</div></td><td>' + chip(c.status, c) + '</td><td>' + CHANNEL[c.channel] + '</td><td>' + (c.status === 'draft' || c.status === 'pending_approval' || c.status === 'rejected' ? '<span class="small muted num">计划 ' + c.counts.planned + ' 封</span>' : progress(c)) + '</td><td class="num small">' + (c.startAt || '—') + '</td><td>' + user(c.createdBy).name + '</td><td style="text-align:right">' + (window.PM_BOARD.hasData(c) ? '<a class="btn sm" href="#/board/' + c.id + '">查看执行</a>' : '') + '</td></tr>';
      }).join('') : '<tr><td colspan="7"><div class="empty">没有符合条件的推广</div></td></tr>') +
      '</tbody></table></div></div>';
  }

  function render(text, s) {
    var u = me();
    return esc(text).replace(/\{公司名称\}/g, '<span class="var">' + esc(s.company) + '</span>').replace(/\{联系人姓名\}/g, s.contact ? '<span class="var">' + esc(s.contact) + '</span>' : '<span class="var" style="background:var(--bad-soft);color:var(--bad)">缺少联系人姓名</span>').replace(/\{发件人姓名\}/g, '<span class="var">' + esc(u.name) + '</span>');
  }
  function radioCard(key, val, cur, title, sub) { return '<button type="button" class="radio-card' + (cur === val ? ' on' : '') + '" data-radio="' + key + '" data-v="' + val + '"><b>' + title + '</b><span>' + sub + '</span></button>'; }

  /* 推广详情 */
  function recipients(c) {
    if (c.exec) {
      var map = { sent: 'sent', failed: 'failed', uncertain: 'uncertain', skip: 'skipped' };
      return window.PM_BOARD.viewRows(c).filter(function (e) { return e.st !== 'offline'; }).map(function (e) {
        var t = e.sentAt || (e.st !== 'skip' && e.at);
        return { company: e.c.company, email: e.p.email || '（无邮箱）', status: map[e.st] || 'pending', extra: e.st === 'skip' ? e.reason : e.error || '', mbox: e.box ? e.box.email : '—', time: t ? '2026-' + window.PM_WIZARD.fmt(t, 8) : '', ref: c.exec.rows.indexOf(e) };
      });
    }
    var names = ['Lichtwerk GmbH', 'Brightline Supply Inc.', 'Lumen Polska', 'Nordic Lumen AB', 'Luce Italia SRL', 'Sol Iluminación S.L.', 'Gulf Lighting Trading', 'Kanto Light KK', 'Maple Fixtures Ltd.', 'Andes Electric SAC'];
    var rows = [], k = c.counts, i = 0;
    function add(n, st, extra) { for (var j = 0; j < n && rows.length < 14; j++, i++) rows.push({ company: names[i % names.length], email: 'buyer' + (i + 1) + '@' + names[i % names.length].split(' ')[0].toLowerCase() + '.example', status: st, extra: extra || '', mbox: (mailbox(c.mailboxes[i % Math.max(1, c.mailboxes.length)]) || {}).email || '—', time: st === 'sent' ? '2026-10-13 ' + (9 + Math.floor(i / 6)) + ':' + String(30 + i % 6 * 5).slice(-2) : '' }); }
    add(k.uncertain, 'uncertain', '邮件服务器连接中断，无法确认是否送达');
    add(k.failed, 'failed', '550 Recipient address rejected');
    add(k.skipped, 'skipped', i % 2 ? '7 天内已推广' : '客户已退订');
    add(k.sent, 'sent');
    add(k.planned - k.sent - k.failed - k.uncertain, 'pending');
    return rows;
  }
  var RSTATUS = { pending: ['待发送', ''], sent: ['已发送', 'ok'], failed: ['发送失败', 'bad'], uncertain: ['结果不确定', 'warn'], skipped: ['已跳过', ''] };

  function pageDetail(id) {
    var c = campaign(id);
    if (!c) return '<div class="card"><div class="empty">推广不存在或你没有权限查看。</div></div>';
    if (visibleCampaigns().indexOf(c) < 0) return '<div class="card"><div class="empty">你没有权限查看这个推广：业务员只能查看自己创建或自己负责客户的推广。</div></div>';
    crumbs(['推广中心', '推广', c.name]);
    var u = me(), creator = c.createdBy === u.id, k = c.counts;
    var acts = ['<a class="btn" href="#/board/' + c.id + '">查看执行</a>'];
    if (c.source === 'crm') acts.push('<a class="btn" href="#/list">在 CRM 中打开</a>', canCreate() ? '<a class="btn primary" href="#/new/' + c.id + '">复制为新推广</a>' : '');
    else if (can('execute')) {
      if (c.status === 'draft' && creator) acts.push('<button class="btn danger" data-do="delete">删除</button>', '<a class="btn primary" href="#/new/' + c.id + '">继续编辑</a>');
      if (c.status === 'pending_approval') { if (creator) acts.push('<button class="btn" data-do="withdraw">撤回</button>'); if (isManager()) acts.push('<a class="btn primary" href="#/approvals/' + c.id + '">去审批</a>'); }
      if (c.status === 'rejected' && creator) acts.push('<a class="btn primary" href="#/new/' + c.id + '">修改后重新提交</a>');
      if (c.status === 'scheduled' && (creator || isManager())) acts.push('<button class="btn danger" data-do="cancel">取消排期</button>');
      if (c.status === 'sending' && (creator || isManager())) acts.push('<button class="btn danger" data-do="stop">停止</button>', '<button class="btn primary" data-do="pause">暂停</button>');
      if (c.status === 'paused' && (creator || isManager())) acts.push('<button class="btn danger" data-do="stop">停止</button>', '<button class="btn primary" data-do="resume">继续发送</button>');
      if (['completed', 'stopped', 'cancelled'].indexOf(c.status) >= 0 && canCreate()) acts.push('<a class="btn primary" href="#/new/' + c.id + '">复制为新推广</a>');
    }
    var banner = '';
    if (c.source === 'crm') banner = '<div class="banner info">这是 CRM 中的历史推广，只读显示，进度实时读取。暂停、继续、登记结果请在 CRM 中操作；切换完成后这些操作会移到这里。</div>';
    else if (c.status === 'rejected') banner = '<div class="banner bad"><b>已退回</b><span>' + esc(c.rejectReason) + '</span></div>';
    else if (c.status === 'pending_approval') banner = '<div class="banner warn"><b>等待审批</b><span>' + esc((c.approvalReasons || []).join('；')) + '。内容已冻结。</span></div>';
    else if (k.uncertain) banner = '<div class="banner warn"><b>' + k.uncertain + ' 封邮件结果不确定</b><span>系统不会自动重发，请在收件对象中逐个核对。</span></div>';

    var tabs = [['recipients', c.manualOnly ? '执行对象' : '收件对象'], ['manual', '人工待办'], ['approval', '审批记录'], ['log', '操作日志']];
    var body = '';
    if (state.detailTab === 'recipients') {
      var rows = recipients(c).filter(function (r) { return state.recipientFilter === 'all' || r.status === state.recipientFilter; });
      body = '<div class="toolbar"><div class="seg" data-seg="recipientFilter">' + [['all', '全部'], ['sent', '已发送'], ['pending', '待发送'], ['failed', '失败'], ['uncertain', '不确定'], ['skipped', '已跳过']].map(function (f) { return '<button class="' + (state.recipientFilter === f[0] ? 'on' : '') + '" data-v="' + f[0] + '">' + f[1] + '</button>'; }).join('') + '</div><div class="spacer"></div><button class="btn sm">导出 Excel</button></div>' +
        '<div class="table-wrap"><table><thead><tr><th>客户</th><th>收件邮箱</th><th>发件邮箱</th><th>状态</th><th>时间</th><th></th></tr></thead><tbody>' +
        (rows.length ? rows.map(function (r) { var s = RSTATUS[r.status]; return '<tr><td class="cell-title">' + esc(r.company) + '</td><td class="small">' + esc(r.email) + '</td><td class="small muted">' + esc(r.mbox) + '</td><td><span class="chip ' + s[1] + '">' + s[0] + '</span>' + (r.extra ? '<div class="cell-sub">' + esc(r.extra) + '</div>' : '') + '</td><td class="small num muted">' + (r.time || '—') + '</td><td style="text-align:right">' + (r.status === 'uncertain' && c.source === 'pm' && can('execute') ? '<button class="btn sm" data-verify="' + (r.ref == null ? '' : r.ref) + '">核对</button>' : '') + '</td></tr>'; }).join('') : '<tr><td colspan="6"><div class="empty">' + (c.manualOnly ? '这个推广只有人工渠道，请查看人工待办。' : '没有符合条件的对象') + '</div></td></tr>') + '</tbody></table></div>';
    } else if (state.detailTab === 'manual') {
      var mt = D.manualTasks.filter(function (t) { return t.campaign === c.id; });
      body = mt.length ? taskTable(mt, c.source === 'crm') : '<div class="empty">这个推广没有人工待办。</div>';
    } else if (state.detailTab === 'approval') {
      body = '<div class="card-body"><ul class="timeline">' +
        (c.status === 'rejected' ? '<li class="bad"><time>2026-10-12 09:12</time>王敏 退回：' + esc(c.rejectReason) + '</li>' : '') +
        (['scheduled', 'sending', 'paused', 'completed'].indexOf(c.status) >= 0 && c.source === 'pm' ? '<li class="ok"><time>' + c.createdAt + '</time>' + (c.createdBy === 1 ? '管理员创建，无需审批' : '王敏 批准') + '</li>' : '') +
        (c.approvalReasons && c.approvalReasons.length ? '<li><time>' + c.createdAt + '</time>' + user(c.createdBy).name + ' 提交审批（' + esc(c.approvalReasons.join('；')) + '）</li>' : '') +
        (c.source === 'crm' ? '<li><time>' + c.createdAt + '</time>CRM 推广没有审批流程</li>' : '') +
        (c.source === 'pm' && c.status !== 'draft' ? '<li class="ok"><time>' + c.createdAt + '</time>测试信已发送至 ' + esc(user(c.createdBy).name) + ' 的邮箱（内容 v1）</li>' : '') +
        (c.status === 'draft' ? '<li><time>' + c.createdAt + '</time>草稿，尚未提交</li>' : '') + '</ul></div>';
    } else {
      body = '<div class="card-body"><ul class="timeline"><li><time>' + c.createdAt + '</time>' + user(c.createdBy).name + ' 创建推广</li>' + (c.status === 'sending' ? '<li class="ok"><time>' + c.startAt + '</time>开始发送</li>' : '') + (c.status === 'paused' ? '<li><time>2026-10-08 11:20</time>' + user(c.createdBy).name + ' 暂停</li>' : '') + (c.status === 'cancelled' ? '<li><time>2026-09-27 17:00</time>王敏 取消（放假安排调整）</li>' : '') + '</ul></div>';
    }

    return banner + '<div class="detail-head"><div><h1>' + esc(c.name) + ' ' + chip(c.status, c) + (c.source === 'crm' ? '<span class="tag">CRM 历史</span>' : '') + '</h1><div class="meta"><span class="num">' + c.id + '</span><span>' + CHANNEL[c.channel] + '</span><span>创建人 ' + user(c.createdBy).name + '</span><span class="num">开始 ' + (c.startAt || '未设置') + '</span>' + (c.subject ? '<span>主题：' + esc(c.subject) + '</span>' : '') + '</div></div><div class="actions">' + acts.join('') + '</div></div>' +
      '<div class="grid g-4" style="margin-bottom:16px">' + (c.manualOnly ? stat('人工待办', k.manualPending, '待执行') + stat('已完成', k.manualDone, '已登记结果') + stat('已跳过', k.skipped, '无联系方式等') + stat('邮件', 0, '本推广无邮件') :
        stat('计划发送', k.planned, '封邮件') + stat('已发送', k.sent, k.planned ? Math.round(k.sent / k.planned * 100) + '%' : '—') + stat('失败 / 不确定', k.failed + ' / ' + k.uncertain, '不确定的不会自动重发', k.failed + k.uncertain > 0) + stat('已跳过', k.skipped, '禁联、退订、频控等')) + '</div>' +
      (c.mailboxes.length && ['sending', 'paused', 'completed'].indexOf(c.status) >= 0 ? '<div class="card" style="margin-bottom:16px"><div class="card-head"><h2>按发件邮箱</h2></div><div class="card-body">' + c.mailboxes.map(function (mid, i) {
        var m = mailbox(mid), share = Math.round(k.planned / c.mailboxes.length), sent = Math.round(k.sent / c.mailboxes.length);
        return '<div class="mbox"><span class="small">' + esc(m.email) + '</span><div><div class="progress"><span class="p-ok" style="width:' + (share ? sent / share * 100 : 0) + '%"></span></div></div><span class="small num muted" style="text-align:right">' + sent + ' / ' + share + '</span></div>';
      }).join('') + '</div></div>' : '') +
      '<div class="card"><div class="tabs">' + tabs.map(function (t) { return '<button class="' + (state.detailTab === t[0] ? 'on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' + body + '</div>';
  }
  function bindDetail(id) {
    var c = campaign(id); if (!c) return;
    $all('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { state.detailTab = b.getAttribute('data-tab'); route(); }); });
    $all('[data-verify]').forEach(function (b) { b.addEventListener('click', function () {
      modal({ title: '核对结果不确定的邮件', ok: '保存', body: '<p class="small">请到发件邮箱的“已发送”中查找这封邮件，再选择结果：</p><div class="check-list"><label><input type="radio" name="v" value="sent"> 已在已发送中找到，标记为已发送</label><label><input type="radio" name="v" value="requeue"> 没有找到，重新排队发送</label></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
        onOk: function (w) {
          var v = $('input[name=v]:checked', w); if (!v) return '请选择核对结果';
          var e = c.exec && c.exec.rows[+b.getAttribute('data-verify')];
          if (e) { e.error = ''; if (v.value === 'sent') { e.st = 'sent'; e.sentAt = e.at; } else { e.st = 'pending'; e.at = window.PM_WIZARD.NOW + 10 * 60e3; } window.PM_BOARD.recount(c); }
          else { c.counts.uncertain--; if (v.value === 'sent') c.counts.sent++; }
          route(); toast('已保存核对结果');
        } });
    }); });
    var map = {
      pause: ['暂停发送', '暂停后不再领取新邮件；正在发送的那一封可能仍会发出。', '暂停', function () { c.status = 'paused'; }],
      resume: ['继续发送', '从剩余的待发送对象继续，按原有限速排程。', '继续发送', function () { c.status = 'sending'; }],
      stop: ['停止推广', '剩余 ' + (c.counts.planned - c.counts.sent - c.counts.failed) + ' 个对象将标记为“未发送（已停止）”，停止后不能继续。', '停止', function () { c.status = 'stopped'; }, true],
      cancel: ['取消排期', '推广还未开始，取消后不会发出任何邮件。', '取消排期', function () { c.status = 'cancelled'; }, true],
      withdraw: ['撤回审批', '撤回后回到草稿，可以修改内容后重新提交。', '撤回', function () { c.status = 'draft'; }],
      delete: ['删除草稿', '草稿删除后无法恢复。', '删除', function () { D.campaigns.splice(D.campaigns.indexOf(c), 1); location.hash = '#/list'; }, true]
    };
    $all('[data-do]').forEach(function (b) { b.addEventListener('click', function () {
      var m = map[b.getAttribute('data-do')];
      modal({ title: m[0], body: '<p>' + esc(m[1]) + '</p>', ok: m[2], danger: m[4], onOk: function () { m[3](); route(); toast(m[0] + '：已完成'); } });
    }); });
  }

  /* 审批 */
  function pageApprovals(id) {
    crumbs(['推广中心', isManager() ? '待我审批' : '审批记录']);
    var u = me();
    var list = isManager() ? pendingApprovals() : D.campaigns.filter(function (c) { return c.createdBy === u.id && (c.status === 'pending_approval' || c.status === 'rejected' || c.approvalReasons); });
    if (!list.length) return '<div class="page-head"><div><h1>' + (isManager() ? '待我审批' : '审批记录') + '</h1></div></div><div class="card"><div class="empty">' + (isManager() ? '没有待审批的推广' : '你还没有提交过需要审批的推广') + '</div></div>';
    var cur = campaign(id) && list.indexOf(campaign(id)) >= 0 ? campaign(id) : list[0];
    var s = D.samples[0];
    return '<div class="page-head"><div><h1>' + (isManager() ? '待我审批' : '审批记录') + '</h1><p>' + (isManager() ? '审批看到的是提交时冻结的内容，批准后按原计划时间开始。' : '审批需要“审批推广”权限（promotion.approve）。这里显示你提交的推广的审批进度。') + '</p></div></div>' +
      '<div class="split"><div class="card">' + list.map(function (c) { return '<div class="list-item' + (c === cur ? ' on' : '') + '" data-go="#/approvals/' + c.id + '"><div style="display:flex;justify-content:space-between;gap:8px"><span class="cell-title">' + esc(c.name) + '</span>' + chip(c.status) + '</div><div class="cell-sub">' + user(c.createdBy).name + ' · ' + c.counts.planned + ' 封 · ' + c.createdAt + '</div></div>'; }).join('') + '</div>' +
      '<div class="card"><div class="card-head"><h2>' + esc(cur.name) + '</h2><div class="actions">' + (isManager() && cur.status === 'pending_approval' ? '<button class="btn danger" data-ap="reject">退回</button><button class="btn primary" data-ap="approve">批准</button>' : chip(cur.status)) + '</div></div><div class="card-body">' +
      '<div class="grid g-2" style="margin-bottom:16px"><div><div class="stat-label">为什么需要审批</div><ul class="reason-list" style="margin-top:6px">' + (cur.approvalReasons || ['—']).map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>' +
      '<div><div class="stat-label">发送安排</div><div style="margin-top:6px" class="small">' + cur.counts.planned + ' 封邮件' + (cur.counts.manualPending ? '，' + cur.counts.manualPending + ' 个人工待办' : '') + '，跳过 ' + cur.counts.skipped + ' 个<br>开始：<span class="num">' + cur.startAt + '</span>（北京时间）<br>发件邮箱：' + cur.mailboxes.map(function (m) { return esc(mailbox(m).email); }).join('、') + '<br>测试信：<span style="color:var(--ok)">已发送（内容 v1）</span></div></div></div>' +
      (cur.status === 'rejected' ? '<div class="banner bad">退回原因：' + esc(cur.rejectReason) + '</div>' : '') +
      '<div class="stat-label" style="margin-bottom:6px">冻结的内容（按第一个收件对象渲染）</div><div class="mail"><div class="mail-meta"><div><b>发件人</b>' + esc(mailbox(cur.mailboxes[0]).email) + '</div><div><b>收件人</b>' + esc(s.contact) + ' &lt;' + esc(s.email) + '&gt;</div></div><div class="mail-subject">' + render(cur.subject, s) + '</div><div class="mail-body">' + render('Dear {联系人姓名},\n\nWe are pleased to share our latest lighting solutions with {公司名称}. Please find the catalogue attached.\n\nBest regards,', s) + '</div><div class="mail-sign">' + esc(user(cur.createdBy).name) + '\nExample LED Lighting Co., Ltd.</div></div></div></div></div>';
  }
  function bindApprovals(id) {
    var list = isManager() ? pendingApprovals() : [];
    var cur = campaign(id) && list.indexOf(campaign(id)) >= 0 ? campaign(id) : list[0];
    if (!cur) return;
    var a = $('[data-ap="approve"]'), r = $('[data-ap="reject"]');
    if (a) a.addEventListener('click', function () { modal({ title: '批准推广', ok: '批准', body: '<p>批准后将于 <b class="num">' + esc(cur.startAt) + '</b> 开始发送 <b class="num">' + cur.counts.planned + '</b> 封邮件。批准后内容不能再修改。</p>', onOk: function () { cur.status = 'scheduled'; location.hash = '#/approvals'; route(); toast('已批准'); } }); });
    if (r) r.addEventListener('click', function () { modal({ title: '退回推广', ok: '退回', danger: true, body: '<div class="field"><label>退回原因（创建人会看到）</label><textarea class="textarea" placeholder="例如：正文里不要写价格"></textarea></div><p class="form-error small hidden" style="color:var(--bad)"></p>', onOk: function (w) { var v = $('textarea', w).value.trim(); if (!v) return '请填写退回原因'; cur.status = 'rejected'; cur.rejectReason = v; location.hash = '#/approvals'; route(); toast('已退回'); } }); });
  }

  /* 人工待办 */
  function taskTable(rows, readOnly) {
    return '<div class="table-wrap"><table><thead><tr><th>客户</th><th>渠道</th><th>推广</th><th>执行人</th><th>截止</th><th>状态</th><th></th></tr></thead><tbody>' + rows.map(function (t) {
      var c = campaign(t.campaign);
      var st = t.status === 'done' ? '<span class="chip ok">已完成</span><div class="cell-sub">' + esc(t.result) + '</div>' : t.status === 'waiting' ? '<span class="chip">等待推广开始</span>' : t.overdue ? '<span class="chip bad">已逾期</span>' : '<span class="chip warn">待执行</span>';
      var canDo = !readOnly && can('execute') && t.status === 'pending' && (t.executor === state.userId || allScope());
      return '<tr><td><div class="cell-title">' + esc(t.company) + '</div><div class="cell-sub">' + esc(t.contact) + ' · ' + esc(t.handle) + '</div></td><td>' + t.channel + '</td><td><a href="#/detail/' + c.id + '">' + esc(c.name) + '</a>' + (c.source === 'crm' ? '<span class="tag">CRM</span>' : '') + '</td><td>' + user(t.executor).name + '</td><td class="num small">' + t.due + '</td><td>' + st + '</td><td style="text-align:right">' + (canDo ? '<button class="btn sm" data-task="' + t.id + '">登记结果</button>' : (c.source === 'crm' && t.status === 'pending' ? '<span class="small muted">在 CRM 登记</span>' : '')) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }
  function pageTasks() {
    crumbs(['推广中心', '人工待办']);
    var rows = D.manualTasks.filter(function (t) { return allScope() || t.executor === state.userId; });
    return '<div class="page-head"><div><h1>人工待办</h1><p>微信、WhatsApp、电话等渠道由负责人执行，系统不代发；完成后必须登记实际结果。</p></div></div><div class="card">' + (rows.length ? taskTable(rows, false) : '<div class="empty">没有分配给你的人工待办</div>') + '</div>';
  }
  function bindTasks() {
    $all('[data-task]').forEach(function (b) { b.addEventListener('click', function () {
      var t = D.manualTasks.filter(function (x) { return x.id === +b.getAttribute('data-task'); })[0];
      modal({ title: '登记结果：' + t.company, ok: '保存', body: '<p class="small muted">' + esc(t.channel) + ' · ' + esc(t.handle) + '</p><div class="field"><label>联系结果</label><select class="select"><option value="">请选择</option><option>已联系-有意向</option><option>已联系-暂无回复</option><option>已联系-无兴趣</option><option>无法联系（号码无效等）</option></select></div><div class="field"><label>截图（必传）</label><div style="display:flex;gap:8px;align-items:center"><button type="button" class="btn sm" data-shot>上传截图</button><span class="small muted" data-shot-name>未上传</span></div><span class="hint">聊天、群消息或通话记录截图。原型中点击即模拟上传。</span></div><div class="field"><label>备注</label><textarea class="textarea" placeholder="客户说了什么、下一步计划"></textarea></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
        onOk: function (w) { var v = $('select', w).value; if (!v) return '请选择联系结果；不能只勾选完成'; if (!w.getAttribute('data-shot-ok')) return '请上传截图'; t.status = 'done'; t.result = v; t.shot = 'screenshot-' + t.id + '.png'; t.doneAt = '2026-10-13 14:10'; t.overdue = false; var c = campaign(t.campaign); c.counts.manualPending--; c.counts.manualDone++; route(); toast('结果已登记'); } });
      var mk = $all('.mask').pop();
      $('[data-shot]', mk).addEventListener('click', function () { mk.setAttribute('data-shot-ok', '1'); $('[data-shot-name]', mk).textContent = 'screenshot-' + t.id + '.png'; });
    }); });
  }

  /* 客户分组 */
  function customerChip(c) {
    if (!c) return '';
    var channels = c.channels || [];
    if (c.dnc) return '<span class="chip bad">禁联</span>';
    if (channels.indexOf('no_promotion') >= 0) return '<span class="chip bad">拒推广</span>';
    if (c.pstatus === 'blacklist') return '<span class="chip bad">黑名单</span>';
    if (c.pstatus === 'paused') return '<span class="chip warn">推广暂停</span>';
    if (c.unsub) return '<span class="chip warn">已退订</span>';
    if (!c.email) return '<span class="chip">无邮箱</span>';
    return '<span class="chip ok">可推广</span>';
  }
  function canEditGroup(g) {
    if (liveOn() && g.canEdit != null) return !!g.canEdit;
    return liveCan('edit_group') && (g.visibility === 'public' || g.createdBy === state.userId);
  }
  function canDeleteGroup(g) {
    if (liveOn() && g.canDelete != null) return !!g.canDelete;
    return liveCan('delete_group') && (g.createdBy === state.userId || liveAllScope());
  }
  function livePicker(cur, editable) {
    var L = window.PM_LIVE;
    var q = state.centerQ || '';
    var pc = state.centerCountry || '';
    var rows = q.trim() ? (L.centerRows || []) : [];
    var countries = L.countries || [];
    var list = rows.map(function (c) {
      var inGroup = !!(cur && cur.members.indexOf(c.id) >= 0);
      var action = '';
      if (inGroup) action = '<span class="small muted">已在组内</span>';
      else if (editable && cur) action = '<button class="btn sm" data-add="' + c.id + '">加入本组</button>';
      return '<div class="pick"><a href="#/customers/' + c.id + '" style="flex:1;min-width:0;color:inherit;text-decoration:none"><span class="cell-title">' + esc(c.company) + '</span><span class="cell-sub" style="display:block">' + esc(c.code ? c.code + ' · ' : '') + esc(c.country || '未填') + ' · ' + esc(ownerLabel(c)) + '</span></a>' + customerChip(c) + action + '</div>';
    }).join('');
    var hint = !q.trim() ? '<div class="empty">输入公司、客户代码、联系人或邮箱。只显示你权限范围内的客户。</div>' : (rows.length ? '' : '<div class="empty">没有符合条件的客户。超出你客户范围的客户不会出现。</div>');
    var totalHint = q.trim() && L.centerTotal > rows.length ? '<p class="hint" style="padding:0 14px 12px">共 ' + L.centerTotal + ' 个，这里显示前 ' + rows.length + ' 个。</p>' : '';
    return '<div class="card picker"><div class="card-head"><h2>搜索客户中心</h2></div><div class="toolbar"><input class="input" data-cq placeholder="搜索客户中心（公司、代码、联系人、邮箱）" value="' + esc(q) + '"><select class="select" data-cc><option value="">全部国家</option>' + countries.map(function (v) { return '<option value="' + esc(v.value) + '"' + (v.value === pc ? ' selected' : '') + '>' + esc(v.label) + '</option>'; }).join('') + '</select></div><div class="picker-list">' + (list || hint) + '</div>' + totalHint + '</div>';
  }
  function pageCustomers(arg) {
    if (arg) return pageCustomer(+arg);
    crumbs(['推广中心', '客户']);
    var L = window.PM_LIVE || {};
    var rows = L.listRows || [];
    var q = state.customerQ || '';
    var country = state.customerCountry || '';
    var countries = L.countries || [];
    return '<div class="page-head"><div><h1>客户</h1><p>只显示你在客户中心权限范围内的客户。这里只读，不修改客户资料，也不发送邮件。</p></div></div>' +
      '<div class="card"><div class="toolbar"><input class="input" style="max-width:320px" data-custq placeholder="搜索公司、代码、联系人、邮箱" value="' + esc(q) + '"><select class="select" style="width:160px" data-custc><option value="">全部国家</option>' + countries.map(function (v) { return '<option value="' + esc(v.value) + '"' + (v.value === country ? ' selected' : '') + '>' + esc(v.label) + '</option>'; }).join('') + '</select><div class="spacer"></div><span class="small muted num">' + (L.listTotal || 0) + ' 个</span></div>' +
      '<div class="table-wrap"><table><thead><tr><th>客户</th><th>国家</th><th>负责人</th><th>推广状态</th></tr></thead><tbody>' +
      (rows.length ? rows.map(function (c) {
        return '<tr class="click" data-go="#/customers/' + c.id + '"><td><div class="cell-title">' + esc(c.company) + '</div><div class="cell-sub num">' + esc(c.code) + ' · ' + esc(c.contact || '无联系人') + ' · ' + esc(c.email || '无邮箱') + '</div></td><td>' + esc(c.country) + '</td><td>' + esc(ownerLabel(c)) + '</td><td>' + customerChip(c) + '</td></tr>';
      }).join('') : '<tr><td colspan="4"><div class="empty">' + (q || country ? '没有符合条件的客户。超出范围的客户不会出现。' : '你的范围内还没有客户。') + '</div></td></tr>') +
      '</tbody></table></div></div>';
  }
  function pageCustomer(id) {
    var c = customer(id);
    crumbs(['推广中心', '客户', c ? c.company : '详情']);
    if (!c) return '<div class="page-head"><div><h1>客户</h1></div><div class="actions"><a class="btn" href="#/customers">返回列表</a></div></div><div class="card"><div class="empty">这个客户不存在，或不在你的客户范围内。</div></div>';
    var contacts = (c.contacts || []).map(function (p) {
      return '<tr><td>' + esc(p.name || '—') + (p.is_left ? ' <span class="chip">已离职</span>' : '') + (p.is_primary ? ' <span class="chip info">主联系人</span>' : '') + '</td><td class="small">' + esc((p.emails || []).join('、') || '无邮箱') + '</td><td>' + (p.unsubscribe_email ? '<span class="chip warn">已退订</span>' : '—') + '</td><td class="small">' + esc([p.wechat, p.whatsapp].filter(Boolean).join(' · ') || '—') + '</td></tr>';
    }).join('');
    var chats = (c.chatGroups || []).map(function (g) { return '<span class="gtag">' + esc(g.name || '') + (g.platform_label ? ' · ' + esc(g.platform_label) : '') + '</span>'; }).join(' ') || '<span class="muted small">—</span>';
    return '<div class="page-head"><div><h1>' + esc(c.company) + '</h1><p class="num">' + esc(c.code) + (c.country ? ' · ' + esc(c.country) : '') + '</p></div><div class="actions"><a class="btn" href="#/customers">返回列表</a><a class="btn" href="#/groups">去分组</a></div></div>' +
      '<div class="grid g-2"><div class="card"><div class="card-head"><h2>资料</h2>' + customerChip(c) + '</div><div class="card-body"><dl class="kv"><dt>负责人</dt><dd>' + esc(ownerLabel(c)) + '</dd><dt>推广状态</dt><dd>' + esc(c.promotionLabel || '—') + '</dd>' + (c.noPromotionReason ? '<dt>不推广原因</dt><dd>' + esc(c.noPromotionReason) + '</dd>' : '') + '<dt>公司邮箱</dt><dd>' + esc((c.companyEmails || []).join('、') || '—') + '</dd><dt>渠道</dt><dd>' + esc((c.channelLabels || []).join('、') || '—') + '</dd><dt>可推广群</dt><dd>' + chats + '</dd></dl></div></div>' +
      '<div class="card"><div class="card-head"><h2>联系人</h2></div>' + (contacts ? '<div class="table-wrap"><table><thead><tr><th>姓名</th><th>邮箱</th><th>退订</th><th>即时通讯</th></tr></thead><tbody>' + contacts + '</tbody></table></div>' : '<div class="empty">没有联系人</div>') + '</div></div>';
  }
  function pageGroups(arg) {
    crumbs(['推广中心', '客户分组']);
    var groups = visibleGroups();
    var q = (state.groupQ || '').toLowerCase();
    var listed = groups.filter(function (g) { return !q || g.name.toLowerCase().indexOf(q) >= 0; });
    var cur = group(+arg);
    if (!cur || groups.indexOf(cur) < 0) cur = groups[0];
    state.groupId = cur ? cur.id : null;
    var head = '<div class="page-head"><div><h1>客户分组</h1><p>' + (liveOn() ? '客户来自客户中心，只显示你权限范围内的客户。分组保存在本模块，删除分组或移出客户不会删除客户资料，也不会发信。' : '一个客户可以同时在多个分组里。删除分组或移出客户不会删除客户资料；已提交的推广使用提交时冻结的名单，不受分组变动影响。') + '</p></div><div class="actions">' + (liveCan('create_group') ? '<button class="btn primary" data-g="create">新建分组</button>' : '') + '</div></div>';
    if (!cur) {
      var emptySearch = liveOn() ? livePicker(null, false) : '';
      return head + '<div class="groups' + (emptySearch ? ' with-picker' : '') + '"><div class="card"><div class="empty">还没有分组。' + (liveCan('create_group') ? '点击“新建分组”开始。' : '') + '</div></div>' + emptySearch + '</div>';
    }

    var members = visibleMembers(cur);
    var mq = (state.memberQ || '').toLowerCase();
    var country = state.memberCountry || '';
    var shown = members.filter(function (c) { return (!mq || (c.company + ' ' + c.contact + ' ' + c.email).toLowerCase().indexOf(mq) >= 0) && (!country || c.country === country); });
    var sel = state.memberSel || {};
    var selCount = shown.filter(function (c) { return sel[c.id]; }).length;
    var editable = canEditGroup(cur);
    var countries = members.map(function (c) { return c.country; }).filter(function (v, i, a) { return a.indexOf(v) === i; });

    var left = '<div class="card groups-list"><div class="toolbar"><input class="input" data-gq placeholder="搜索分组" value="' + esc(state.groupQ || '') + '"></div>' +
      listed.map(function (g) {
        return '<div class="list-item' + (g === cur ? ' on' : '') + '" data-go="#/groups/' + g.id + '"><div style="display:flex;align-items:center;gap:8px"><span class="dot" style="background:' + g.color + '"></span><span class="cell-title" style="flex:1">' + esc(g.name) + '</span><span class="num small muted">' + groupCount(g) + '</span></div><div class="cell-sub">' + (g.visibility === 'private' ? '仅自己可见' : '团队共用') + ' · ' + esc(creatorName(g)) + ' 创建</div></div>';
      }).join('') + (listed.length ? '' : '<div class="empty">没有匹配的分组</div>') + '</div>';

    var canMove = liveOn() ? !!cur.canMove : liveCan('move_customer');
    var bulk = selCount ? '<div class="bulk-bar"><b class="num">已选 ' + selCount + ' 个</b>' +
      (canMove ? '<button class="btn sm" data-g="move">移到其他分组</button><button class="btn sm" data-g="copy">复制到其他分组</button>' : '') +
      (editable ? '<button class="btn sm danger" data-g="remove-sel">从本组移出</button>' : '') + '<button class="btn sm ghost" data-g="clear">取消选择</button></div>' : '';

    var totalN = liveOn() && cur.memberTotal != null ? cur.memberTotal : cur.members.length;
    var visibleN = liveOn() && cur.visibleCount != null ? cur.visibleCount : members.length;
    var countText = liveAllScope() ? '共 ' + totalN + ' 个客户' : '你可见的 ' + visibleN + ' 个（分组共 ' + totalN + ' 个）';
    var middle = '<div class="card groups-main"><div class="card-head"><div><h2 style="display:flex;align-items:center;gap:8px"><span class="dot" style="background:' + cur.color + '"></span>' + esc(cur.name) + '</h2><div class="cell-sub">' + (cur.visibility === 'private' ? '仅自己可见' : '团队共用') + ' · ' + esc(creatorName(cur)) + ' 创建 · ' + countText + '</div></div><div class="actions">' +
      (canDeleteGroup(cur) ? '<button class="btn sm danger" data-g="delete">删除分组</button>' : '') +
      (editable ? '<button class="btn sm" data-g="rename">重命名</button><button class="btn sm primary" data-g="add">添加客户</button>' : '') + '</div></div>' +
      '<div class="toolbar"><input class="input" style="max-width:260px" data-mq placeholder="搜索公司、联系人、邮箱" value="' + esc(state.memberQ || '') + '"><select class="select" style="width:140px" data-mc><option value="">全部国家</option>' + countries.map(function (v) { return '<option' + (v === country ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select><div class="spacer"></div><span class="small muted num">显示 ' + shown.length + ' 个</span></div>' + bulk +
      '<div class="table-wrap"><table class="members"><thead><tr><th style="width:36px"><input type="checkbox" data-selall' + (shown.length && selCount === shown.length ? ' checked' : '') + ' aria-label="全选"></th><th>客户</th><th>国家</th><th class="col-wide">等级</th><th>负责人</th><th>推广状态</th><th class="col-wide">其他分组</th><th></th></tr></thead><tbody>' +
      (shown.length ? shown.map(function (c) {
        var othersHtml;
        if (liveOn() && c.otherGroups) {
          othersHtml = c.otherGroups.length ? c.otherGroups.map(function (og) { return '<span class="gtag"><span class="dot" style="background:' + esc(og.color || '#3b4fd8') + '"></span>' + esc(og.name) + '</span>'; }).join('') : '<span class="muted small">—</span>';
        } else {
          var others = visibleGroups().filter(function (g) { return g !== cur && g.members.indexOf(c.id) >= 0; });
          othersHtml = others.length ? others.map(function (g) { return '<span class="gtag"><span class="dot" style="background:' + g.color + '"></span>' + esc(g.name) + '</span>'; }).join('') : '<span class="muted small">—</span>';
        }
        var company = liveOn() ? '<a href="#/customers/' + c.id + '">' + esc(c.company) + '</a>' : esc(c.company);
        return '<tr' + (sel[c.id] ? ' class="selected"' : '') + '><td><input type="checkbox" data-sel="' + c.id + '"' + (sel[c.id] ? ' checked' : '') + ' aria-label="选择 ' + esc(c.company) + '"></td><td><div class="cell-title">' + company + '</div><div class="cell-sub">' + esc(c.contact || '（无联系人姓名）') + ' · ' + esc(c.email || '无邮箱') + '</div></td><td>' + esc(c.country) + '</td><td class="col-wide">' + esc(c.level || '—') + '</td><td>' + esc(ownerLabel(c)) + '</td><td>' + customerChip(c) + '</td><td class="col-wide">' + othersHtml + '</td><td style="text-align:right">' + (editable ? '<button class="btn sm ghost" data-rm="' + c.id + '">移出</button>' : '') + '</td></tr>';
      }).join('') : '<tr><td colspan="8"><div class="empty">' + (members.length ? '没有符合筛选条件的客户' : ((liveOn() ? (cur.memberTotal || 0) : cur.members.length) ? '分组里有 ' + (liveOn() ? cur.memberTotal : cur.members.length) + ' 个客户，但没有你可见的。' : '这个分组还没有客户。') + (editable ? (liveOn() ? '在右侧搜索客户中心并加入。' : '点击“添加客户”。') : '')) + '</div></td></tr>') + '</tbody></table></div></div>';

    var picker = '';
    if (liveOn()) picker = livePicker(cur, editable);
    else if (state.pickerOpen && editable) {
      var pq = (state.pickQ || '').toLowerCase(), pc = state.pickCountry || '';
      var pool = D.customers.filter(visibleCustomer).filter(function (c) { return (!pq || (c.company + ' ' + c.contact + ' ' + c.email).toLowerCase().indexOf(pq) >= 0) && (!pc || c.country === pc); });
      var pick = state.pickSel || {};
      var pickCount = Object.keys(pick).filter(function (k) { return pick[k]; }).length;
      var allCountries = D.customers.filter(visibleCustomer).map(function (c) { return c.country; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
      picker = '<div class="card picker"><div class="card-head"><h2>添加到「' + esc(cur.name) + '」</h2><button class="btn sm ghost" data-g="close-picker" aria-label="关闭">关闭</button></div>' +
        '<div class="toolbar"><input class="input" data-pq placeholder="搜索客户" value="' + esc(state.pickQ || '') + '"><select class="select" data-pc><option value="">全部国家</option>' + allCountries.map(function (v) { return '<option' + (v === pc ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select></div>' +
        '<div class="picker-list">' + pool.map(function (c) {
          var inGroup = cur.members.indexOf(c.id) >= 0;
          return '<label class="pick' + (inGroup ? ' disabled' : '') + '"><input type="checkbox" data-pick="' + c.id + '"' + (inGroup ? ' disabled checked' : (pick[c.id] ? ' checked' : '')) + '><span style="flex:1;min-width:0"><span class="cell-title">' + esc(c.company) + '</span><span class="cell-sub" style="display:block">' + esc(c.country) + ' · ' + user(c.owner).name + (inGroup ? ' · 已在组内' : '') + '</span></span>' + customerChip(c) + '</label>';
        }).join('') + '</div><div class="picker-foot"><button class="btn sm" data-g="pick-all">全选当前结果</button><div class="spacer"></div><button class="btn primary" data-g="add-confirm"' + (pickCount ? '' : ' disabled') + '>加入 ' + pickCount + ' 个</button></div></div>';
    }
    return head + '<div class="groups' + (picker ? ' with-picker' : '') + '">' + left + middle + picker + '</div>';
  }
  function groupPickModal(title, cur, onPick) {
    var targets = visibleGroups().filter(function (g) { return g !== cur && canEditGroup(g); });
    modal({ title: title, ok: '确定', body: (targets.length ? '<div class="check-list">' + targets.map(function (g) { return '<label><input type="radio" name="tg" value="' + g.id + '"><span class="dot" style="background:' + g.color + '"></span><span style="flex:1">' + esc(g.name) + '</span><span class="small muted num">' + groupCount(g) + '</span></label>'; }).join('') + '</div>' : '<p class="muted">没有可以写入的其他分组，请先新建分组。</p>') + '<p class="form-error small hidden" style="color:var(--bad)"></p>',
      onOk: function (w) { var r = $('input[name=tg]:checked', w); if (!r) return '请选择目标分组'; onPick(group(+r.value)); } });
  }
  function bindGroups() {
    var cur = group(state.groupId);
    function refreshLive(groupId) {
      return window.PM_LIVE.prepare('groups', groupId || '').then(function () { route(); });
    }
    function toastUndo(msg, token, groupId) {
      toast(msg, token ? function () {
        window.PM_LIVE.undo(token).then(function () { return refreshLive(groupId || (cur && cur.id)); }).then(function () { toast('已撤销'); }).catch(function (err) { toast(err.message || '撤销失败'); });
      } : null);
    }
    function sel() { return Object.keys(state.memberSel || {}).filter(function (k) { return state.memberSel[k]; }).map(Number); }
    function snapshot() { return D.groups.map(function (g) { return { g: g, members: g.members.slice(), deleted: g.deleted, name: g.name }; }); }
    function restore(snap) { snap.forEach(function (s) { s.g.members = s.members; s.g.deleted = s.deleted; s.g.name = s.name; }); }
    function undoable(msg, snap, after) { toast(msg, function () { restore(snap); if (after) after(); route(); toast('已撤销'); }); }
    function on(selr, ev, fn) { $all(selr).forEach(function (el) { el.addEventListener(ev, function (e) { fn(el, e); }); }); }
    var keepFocus = function (selector, el) { route(); var n = $(selector); if (n) { n.focus(); n.setSelectionRange(el.value.length, el.value.length); } };
    on('[data-gq]', 'input', function (el) { state.groupQ = el.value; keepFocus('[data-gq]', el); });
    on('[data-mq]', 'input', function (el) { state.memberQ = el.value; keepFocus('[data-mq]', el); });
    on('[data-pq]', 'input', function (el) { state.pickQ = el.value; keepFocus('[data-pq]', el); });
    on('[data-cq]', 'input', function (el) {
      state.centerQ = el.value;
      window.PM_LIVE.schedule(function () {
        window.PM_LIVE.searchCenter().then(function () {
          route();
          var n = $('[data-cq]');
          if (n) { n.focus(); n.setSelectionRange(el.value.length, el.value.length); }
        }).catch(function (err) { toast(err.message || '搜索失败'); });
      });
    });
    on('[data-cc]', 'change', function (el) {
      state.centerCountry = el.value;
      window.PM_LIVE.searchCenter().then(function () { route(); }).catch(function (err) { toast(err.message || '筛选失败'); });
    });
    on('[data-mc]', 'change', function (el) { state.memberCountry = el.value; route(); });
    on('[data-pc]', 'change', function (el) { state.pickCountry = el.value; route(); });
    on('[data-sel]', 'change', function (el) { state.memberSel = state.memberSel || {}; state.memberSel[el.getAttribute('data-sel')] = el.checked; route(); });
    on('[data-selall]', 'change', function (el) {
      state.memberSel = {};
      if (el.checked) $all('[data-sel]').forEach(function (x) { state.memberSel[x.getAttribute('data-sel')] = true; });
      route();
    });
    on('[data-pick]', 'change', function (el) { state.pickSel = state.pickSel || {}; state.pickSel[el.getAttribute('data-pick')] = el.checked; route(); });
    on('[data-rm]', 'click', function (el) {
      var id = +el.getAttribute('data-rm'), c = customer(id);
      if (liveOn()) {
        window.PM_LIVE.removeMembers(cur.id, [id]).then(function (data) {
          return refreshLive(cur.id).then(function () { toastUndo('已将 ' + (c ? c.company : '客户') + ' 移出「' + cur.name + '」', data.undo_token, cur.id); });
        }).catch(function (err) { toast(err.message || '移出失败'); });
        return;
      }
      var snap = snapshot();
      cur.members = cur.members.filter(function (x) { return x !== id; });
      route(); undoable('已将 ' + c.company + ' 移出「' + cur.name + '」', snap);
    });
    on('[data-add]', 'click', function (el) {
      if (!cur) return;
      var id = +el.getAttribute('data-add');
      window.PM_LIVE.addMembers(cur.id, [id]).then(function (data) {
        return refreshLive(cur.id).then(function () { toastUndo('已加入「' + cur.name + '」', data.undo_token, cur.id); });
      }).catch(function (err) { toast(err.message || '加入失败'); });
    });
    var acts = {
      create: function () {
        modal({ title: '新建分组', ok: '创建', body: '<div class="field"><label>分组名称</label><input class="input" data-n placeholder="例如：2027 迪拜展潜在客户"></div><div class="field"><label>谁可以使用</label><div class="check-list"><label><input type="radio" name="vis" value="public" checked> 团队共用（其他人只能看到自己负责的客户）</label><label><input type="radio" name="vis" value="private"> 仅自己</label></div></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
          onOk: function (w) {
            var name = $('[data-n]', w).value.trim();
            if (!name) return '请填写分组名称';
            var vis = $('input[name=vis]:checked', w).value;
            if (liveOn()) {
              window.PM_LIVE.createGroup(name, vis).then(function (data) {
                location.hash = '#/groups/' + data.id;
                return refreshLive(data.id).then(function () { toastUndo('分组已创建，现在可以在右侧搜索并加入客户', data.undo_token, data.id); });
              }).catch(function (err) { toast(err.message || '创建失败'); });
              return;
            }
            if (visibleGroups().some(function (g) { return g.name === name; })) return '已有同名分组';
            var g = { id: 30 + D.groups.length, name: name, color: ['#3b4fd8', '#19794b', '#a35c00', '#b3261e', '#6b4fd8'][D.groups.length % 5], visibility: vis, createdBy: state.userId, members: [] };
            D.groups.push(g); state.pickerOpen = true; state.pickSel = {}; location.hash = '#/groups/' + g.id; toast('分组已创建，现在可以添加客户');
          } });
      },
      rename: function () {
        if (!cur) return;
        modal({ title: '重命名分组', ok: '保存', body: '<div class="field"><label>分组名称</label><input class="input" data-n value="' + esc(cur.name) + '"></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
          onOk: function (w) {
            var name = $('[data-n]', w).value.trim();
            if (!name) return '请填写分组名称';
            if (liveOn()) {
              window.PM_LIVE.renameGroup(cur.id, name).then(function (data) {
                return refreshLive(cur.id).then(function () { toastUndo('已重命名', data.undo_token, cur.id); });
              }).catch(function (err) { toast(err.message || '重命名失败'); });
              return;
            }
            if (visibleGroups().some(function (g) { return g !== cur && g.name === name; })) return '已有同名分组';
            cur.name = name; route(); toast('已重命名');
          } });
      },
      delete: function () {
        if (!cur) return;
        var totalShown = liveOn() && cur.memberTotal != null ? cur.memberTotal : cur.members.length;
        var using = liveOn() ? 0 : D.campaigns.filter(function (c) { return c.source === 'pm' && ['draft', 'rejected'].indexOf(c.status) >= 0; }).length;
        modal({ title: '删除分组「' + cur.name + '」', ok: '删除分组', danger: true, body: '<p>分组里的 <b class="num">' + totalShown + '</b> 个客户不会被删除，只是不再属于这个分组。</p><p class="small muted">' + (liveOn() ? '不会发送邮件。' : '已提交的推广使用冻结名单，不受影响；还在编辑的 ' + using + ' 个草稿如果选了这个分组，需要重新选择。') + '删除后 10 秒内可以撤销。</p>',
          onOk: function () {
            if (liveOn()) {
              var id = cur.id;
              window.PM_LIVE.deleteGroup(id).then(function (data) {
                location.hash = '#/groups';
                return refreshLive('').then(function () { toastUndo('分组已删除', data.undo_token, id); });
              }).catch(function (err) { toast(err.message || '删除失败'); });
              return;
            }
            var snap = snapshot(), id = cur.id; cur.deleted = true; location.hash = '#/groups'; undoable('分组已删除', snap, function () { location.hash = '#/groups/' + id; });
          } });
      },
      add: function () {
        if (liveOn()) { var n = $('[data-cq]'); if (n) n.focus(); return; }
        state.pickerOpen = true; state.pickSel = {}; route();
      },
      'close-picker': function () { state.pickerOpen = false; route(); },
      'pick-all': function () { state.pickSel = state.pickSel || {}; $all('[data-pick]:not([disabled])').forEach(function (x) { state.pickSel[x.getAttribute('data-pick')] = true; }); route(); },
      'add-confirm': function () {
        var ids = Object.keys(state.pickSel || {}).filter(function (k) { return state.pickSel[k]; }).map(Number), snap = snapshot();
        ids.forEach(function (id) { if (cur.members.indexOf(id) < 0) cur.members.push(id); });
        state.pickSel = {}; route(); undoable('已加入 ' + ids.length + ' 个客户到「' + cur.name + '」', snap);
      },
      clear: function () { state.memberSel = {}; route(); },
      'remove-sel': function () {
        var ids = sel();
        if (liveOn()) {
          window.PM_LIVE.removeMembers(cur.id, ids).then(function (data) {
            state.memberSel = {};
            return refreshLive(cur.id).then(function () { toastUndo('已将 ' + ids.length + ' 个客户移出「' + cur.name + '」', data.undo_token, cur.id); });
          }).catch(function (err) { toast(err.message || '移出失败'); });
          return;
        }
        var snap = snapshot();
        cur.members = cur.members.filter(function (x) { return ids.indexOf(x) < 0; });
        state.memberSel = {}; route(); undoable('已将 ' + ids.length + ' 个客户移出「' + cur.name + '」', snap);
      },
      move: function () { transfer(true); },
      copy: function () { transfer(false); }
    };
    function transfer(isMove) {
      var ids = sel();
      groupPickModal((isMove ? '移动 ' : '复制 ') + ids.length + ' 个客户到', cur, function (target) {
        if (liveOn()) {
          var job = isMove ? window.PM_LIVE.moveMembers(cur.id, target.id, ids) : window.PM_LIVE.copyMembers(cur.id, target.id, ids);
          job.then(function (data) {
            state.memberSel = {};
            return refreshLive(cur.id).then(function () { toastUndo((isMove ? '已移动 ' : '已复制 ') + ids.length + ' 个客户到「' + target.name + '」', data.undo_token, cur.id); });
          }).catch(function (err) { toast(err.message || '操作失败'); });
          return;
        }
        var snap = snapshot(), already = 0;
        ids.forEach(function (id) { if (target.members.indexOf(id) >= 0) already++; else target.members.push(id); });
        if (isMove) cur.members = cur.members.filter(function (x) { return ids.indexOf(x) < 0; });
        state.memberSel = {}; route();
        undoable((isMove ? '已移动 ' : '已复制 ') + ids.length + ' 个客户到「' + target.name + '」' + (already ? '（其中 ' + already + ' 个原本就在）' : ''), snap);
      });
    }
    on('[data-g]', 'click', function (el) { acts[el.getAttribute('data-g')](); });
  }

  /* 设置 */
  function pageSettings() {
    crumbs(['推广中心', '设置']);
    if (!can('manage')) return '<div class="page-head"><div><h1>设置</h1></div></div><div class="card"><div class="empty">需要“推广设置”权限（promotion.manage）。请在统一权限中心申请。</div></div>';
    var s = D.settings, x = D.suppress;
    return '<div class="page-head"><div><h1>设置</h1><p>修改只影响之后提交的推广；已排期的推广按提交时的规则执行。</p></div><div class="actions"><button class="btn primary" data-save>保存设置</button></div></div>' +
      '<div class="grid g-2"><div class="card"><div class="card-head"><h2>审批与频控</h2></div><div class="card-body">' +
      '<div class="field"><label>小批量免审额度（封）</label><input class="input num" value="' + s.exempt + '"><span class="hint">有“小批量免审”权限的人只用自己的邮箱、且邮件数不超过此值时无需审批；有“审批推广”权限的人始终免审；其他人始终需要审批。权限在统一权限中心分配。</span></div>' +
      '<div class="field"><label>同一客户最短间隔（天）</label><input class="input num" value="' + s.freqDays + '"><span class="hint">同时检查本模块、CRM 推广和旧推广中心的发送记录。</span></div>' +
      '<div class="row"><div class="field"><label>每日发送开始</label><input class="input num" value="' + s.windowStart + '"></div><div class="field"><label>每日发送结束</label><input class="input num" value="' + s.windowEnd + '"></div></div>' +
      '<div class="field"><label>同一邮箱每封间隔（秒）</label><input class="input num" value="' + s.interval + '"></div></div></div>' +
      '<div class="grid"><div class="card"><div class="card-head"><h2>邮箱日上限</h2></div><div class="table-wrap"><table><thead><tr><th>邮箱</th><th>归属</th><th>日上限</th></tr></thead><tbody>' + D.mailboxes.map(function (m) { return '<tr><td class="small">' + esc(m.email) + '</td><td>' + (m.owner ? user(m.owner).name : '公司') + '</td><td style="width:110px"><input class="input num" style="height:30px" value="' + m.limit + '"></td></tr>'; }).join('') + '</tbody></table></div></div>' +
      '<div class="card"><div class="card-head"><h2>屏蔽名单</h2><span class="small muted">任一来源命中即不发送</span></div><div class="table-wrap"><table><tbody>' +
      '<tr><td>联系人邮件退订（CRM 联系人）</td><td class="num" style="text-align:right">' + x.contactUnsub + '</td></tr><tr><td>CRM 客户禁联</td><td class="num" style="text-align:right">' + x.crmDnc + '</td></tr><tr><td>旧推广中心退订</td><td class="num" style="text-align:right">' + x.legacyUnsub + '</td></tr><tr><td>旧推广中心黑名单</td><td class="num" style="text-align:right">' + x.legacyBlack + '</td></tr></tbody></table></div></div></div></div>';
  }

  function bindCustomers() {
    $all('[data-custq]').forEach(function (el) {
      el.addEventListener('input', function () {
        state.customerQ = el.value;
        window.PM_LIVE.schedule(function () {
          window.PM_LIVE.searchList().then(function () {
            route();
            var n = $('[data-custq]');
            if (n) { n.focus(); n.setSelectionRange(n.value.length, n.value.length); }
          }).catch(function (err) { toast(err.message || '搜索失败'); });
        });
      });
    });
    $all('[data-custc]').forEach(function (el) {
      el.addEventListener('change', function () {
        state.customerCountry = el.value;
        window.PM_LIVE.searchList().then(function () { route(); }).catch(function (err) { toast(err.message || '筛选失败'); });
      });
    });
  }

  /* 路由 */
  function paint(name, arg) {
    var view = $('#view'), html;
    if (name === 'new') html = window.PM_WIZARD.page(arg);
    else if (name === 'detail') html = pageDetail(arg);
    else if (name === 'board') html = window.PM_BOARD.page(arg);
    else if (name === 'list') html = pageList();
    else if (name === 'approvals') html = pageApprovals(arg);
    else if (name === 'tasks') html = pageTasks();
    else if (name === 'groups') html = pageGroups(arg);
    else if (name === 'customers') html = pageCustomers(arg);
    else if (name === 'settings') html = pageSettings();
    else { name = 'home'; html = pageHome(); }
    renderShell(name);
    view.innerHTML = html;
    document.body.classList.toggle('has-dock', name === 'new');
    $all('[data-go]').forEach(function (el) { el.addEventListener('click', function (e) { if (e.target.closest('a, button')) return; location.hash = el.getAttribute('data-go'); }); });
    $all('[data-seg]').forEach(function (seg) { $all('button', seg).forEach(function (b) { b.addEventListener('click', function () { state[seg.getAttribute('data-seg')] = b.getAttribute('data-v'); route(); }); }); });
    if (name === 'new') window.PM_WIZARD.bind();
    if (name === 'detail') bindDetail(arg);
    if (name === 'board') window.PM_BOARD.bind();
    if (name === 'approvals') bindApprovals(arg);
    if (name === 'tasks' || name === 'detail') bindTasks();
    if (name === 'groups') bindGroups();
    if (name === 'customers') bindCustomers();
  }
  function route() {
    var parts = (location.hash.replace(/^#\/?/, '') || 'home').split('/');
    var name = parts[0], arg = parts[1] || '';
    state.useLive = !!(window.PM_LIVE && window.PM_LIVE.ready && (name === 'groups' || name === 'customers'));
    if (state.useLive && window.PM_LIVE.needs(name, arg)) {
      renderShell(name);
      var view = $('#view');
      if (view) view.innerHTML = '<div class="card"><div class="empty">正在读取客户中心…</div></div>';
      state._ticket = (state._ticket || 0) + 1;
      var ticket = state._ticket;
      window.PM_LIVE.prepare(name, arg).then(function () {
        if (ticket !== state._ticket) return;
        var now = (location.hash.replace(/^#\/?/, '') || 'home').split('/');
        if (now[0] !== name || (now[1] || '') !== arg) return;
        paint(name, arg);
      }).catch(function (err) {
        if (ticket !== state._ticket) return;
        renderShell(name);
        if (view) view.innerHTML = '<div class="card"><div class="empty">' + esc(err.message || '读取客户中心失败') + '</div></div>';
      });
      return;
    }
    paint(name, arg);
  }
  window.PM_APP = { D: D, state: state, $: $, $all: $all, esc: esc, me: me, user: user, mailbox: mailbox, campaign: campaign, can: can, allScope: allScope, canCreate: canCreate, visibleCustomer: visibleCustomer, visibleGroups: visibleGroups, visibleMembers: visibleMembers, group: group, customer: customer, approvalReasons: approvalReasons, crumbs: crumbs, toast: toast, modal: modal, radioCard: radioCard, route: route, CHANNEL: CHANNEL, STATUS: STATUS, chip: chip, visibleCampaigns: visibleCampaigns };

  var lastHash = location.hash;
  window.addEventListener('hashchange', function () {
    if (lastHash.split('/')[1] !== location.hash.split('/')[1]) { state.boardReason = ''; state.detailTab = 'recipients'; state.recipientFilter = 'all'; state.memberSel = {}; state.memberQ = ''; state.memberCountry = ''; }
    if (lastHash.split('/')[0] !== location.hash.split('/')[0]) state.pickerOpen = false;
    lastHash = location.hash;
    route();
    window.scrollTo(0, 0);
  });
  document.addEventListener('DOMContentLoaded', function () {
    var params = new URLSearchParams(location.search);
    if (params.get('user')) state.userId = +params.get('user');
    if (params.get('picker')) state.pickerOpen = true;
    if (localStorage.getItem('pm-side') === 'collapsed') document.body.classList.add('side-collapsed');
    var toggle = $('#side-toggle');
    if (toggle) toggle.addEventListener('click', function () {
      var c = document.body.classList.toggle('side-collapsed');
      localStorage.setItem('pm-side', c ? 'collapsed' : 'open');
    });
    function start() {
      var role = $('#role-select');
      if (role) role.addEventListener('change', function (e) { state.userId = +e.target.value; state.draft = null; state.memberSel = {}; state.pickerOpen = false; route(); toast('已切换为 ' + me().name + '（' + me().role + '）。客户和分组仍按当前登录账号的权限显示。'); });
      route();
    }
    if (window.PM_LIVE && window.PM_LIVE.boot) {
      window.PM_LIVE.boot().then(start).catch(function (err) {
        var view = $('#view');
        if (view) view.innerHTML = '<div class="card"><div class="empty">' + esc(err.message || '无法读取登录状态') + '</div></div>';
      });
    } else {
      start();
    }
  });
})();
