/* 推广中心 V1 静态原型：演示页面结构、角色差异与审批规则，不连接后端。 */
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
  var ROLE = { admin: '管理员', marketing: '市场', sales: '业务员', viewer: '只读' };
  var CHANNEL = { email: '邮件', preference: '按客户偏好', whatsapp: 'WhatsApp', wechat: '微信' };

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function me() { return D.users.filter(function (u) { return u.id === state.userId; })[0]; }
  function user(id) { return D.users.filter(function (u) { return u.id === id; })[0] || { name: '—' }; }
  function mailbox(id) { return D.mailboxes.filter(function (m) { return m.id === id; })[0]; }
  function campaign(id) { return D.campaigns.filter(function (c) { return c.id === id; })[0]; }
  function isManager() { return me().role === 'admin'; }
  function canCreate() { return me().role !== 'viewer'; }
  function chip(status, c) {
    var s = STATUS[status] || [status, ''];
    var label = s[0];
    if (c && c.partialFailed) label += ' · 部分失败';
    return '<span class="chip ' + s[1] + '">' + label + '</span>';
  }
  function visibleCampaigns() {
    var u = me();
    return D.campaigns.filter(function (c) {
      if (u.role !== 'sales') return true;
      return c.createdBy === u.id || c.owners.indexOf(u.id) >= 0;
    });
  }
  function pendingApprovals() { return D.campaigns.filter(function (c) { return c.status === 'pending_approval'; }); }
  function myManualTasks() {
    return D.manualTasks.filter(function (t) { return t.status === 'pending' && (isManager() || t.executor === state.userId); });
  }
  function toast(msg) {
    var el = document.createElement('div');
    el.className = 'toast'; el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 2600);
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
    if (u.role === 'admin') return [];
    if (u.role === 'marketing') return ['市场创建的推广始终需要审批'];
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
      ['home', '工作台', 0],
      ['list', '推广', 0],
      ['approvals', isManager() ? '待我审批' : '审批记录', isManager() ? approvals : 0],
      ['tasks', '人工待办', tasks],
      ['settings', '设置', 0]
    ];
    $('#side-nav').innerHTML = nav.map(function (n) {
      var active = route === n[0] || (n[0] === 'list' && (route === 'detail' || route === 'new'));
      return '<a class="nav-item' + (active ? ' active' : '') + '" href="#/' + n[0] + '"><span>' + n[1] + '</span>' + (n[2] ? '<span class="nav-count num">' + n[2] + '</span>' : '') + '</a>';
    }).join('');
    $('#role-select').innerHTML = D.users.map(function (x) { return '<option value="' + x.id + '"' + (x.id === u.id ? ' selected' : '') + '>' + x.name + '（' + ROLE[x.role] + '）</option>'; }).join('');
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
    var sentToday = D.mailboxes.filter(function (m) { return isManager() || m.owner === u.id; }).reduce(function (s, m) { return s + m.used; }, 0);
    var todo = [];
    if (isManager()) pendingApprovals().forEach(function (c) { todo.push(['审批', c.name, user(c.createdBy).name + ' 提交，' + c.counts.planned + ' 封邮件', '#/approvals/' + c.id, '去审批']); });
    mine.forEach(function (c) {
      if (c.status === 'rejected') todo.push(['已退回', c.name, '退回原因：' + c.rejectReason, '#/new/' + c.id, '修改']);
      if (c.status === 'draft') todo.push(['草稿', c.name, '还未提交', '#/new/' + c.id, '继续编辑']);
    });
    issues.forEach(function (c) { if (c.counts.uncertain) todo.push(['需核对', c.name, c.counts.uncertain + ' 封邮件结果不确定，不会自动重发', '#/detail/' + c.id, '核对']); });
    if (myManualTasks().length) todo.push(['人工待办', myManualTasks().length + ' 个客户待联系', '其中 ' + myManualTasks().filter(function (t) { return t.overdue; }).length + ' 个已逾期', '#/tasks', '去处理']);

    return '<div class="page-head"><div><h1>' + esc(u.name) + '，今天要处理 ' + todo.length + ' 件事</h1><p>' + ROLE[u.role] + (u.role === 'sales' ? ' · 只显示你创建或你负责客户的推广' : ' · 显示全部推广') + '</p></div><div class="actions">' + (canCreate() ? '<a class="btn primary" href="#/new">新建推广</a>' : '') + '</div></div>' +
      '<div class="grid g-4" style="margin-bottom:16px">' +
      stat(isManager() ? '待我审批' : '我的待审批', isManager() ? pendingApprovals().length : list.filter(function (c) { return c.createdBy === u.id && c.status === 'pending_approval'; }).length, '个推广') +
      stat('人工待办', myManualTasks().length, '个客户', myManualTasks().some(function (t) { return t.overdue; })) +
      stat('发送异常', issues.reduce(function (s, c) { return s + c.counts.failed + c.counts.uncertain; }, 0), '封失败或结果不确定', issues.length > 0) +
      stat('今日已发送', sentToday, isManager() ? '全部邮箱' : '你的邮箱') + '</div>' +
      '<div class="grid g-main"><div class="card"><div class="card-head"><h2>需要处理</h2></div>' +
      (todo.length ? '<div class="table-wrap"><table><tbody>' + todo.map(function (t) { return '<tr><td style="width:96px"><span class="chip ' + (t[0] === '已退回' || t[0] === '需核对' ? 'bad' : 'warn') + '">' + t[0] + '</span></td><td><div class="cell-title">' + esc(t[1]) + '</div><div class="cell-sub">' + esc(t[2]) + '</div></td><td style="text-align:right"><a class="btn sm" href="' + t[3] + '">' + t[4] + '</a></td></tr>'; }).join('') + '</tbody></table></div>' : '<div class="empty">没有需要处理的事项</div>') +
      '</div><div class="grid"><div class="card"><div class="card-head"><h2>进行中</h2><a class="small" href="#/list">全部推广</a></div><div class="card-body" style="padding-top:4px;padding-bottom:4px">' +
      (running.length ? running.map(function (c) { return '<a href="#/detail/' + c.id + '" style="display:block;color:inherit;text-decoration:none;padding:10px 0;border-bottom:1px solid var(--line)"><div style="display:flex;justify-content:space-between;gap:8px"><span class="cell-title">' + esc(c.name) + '</span>' + chip(c.status) + '</div>' + progress(c) + '</a>'; }).join('') : '<div class="empty">没有进行中的推广</div>') +
      '</div></div><div class="card"><div class="card-head"><h2>邮箱今日用量</h2><span class="small muted">北京时间 0 点重置</span></div><div class="card-body">' +
      D.mailboxes.filter(function (m) { return isManager() || m.owner === u.id || m.owner === 0; }).map(function (m) {
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
      (me().role !== 'sales' ? '<select class="select" style="width:140px"><option>全部创建人</option>' + D.users.slice(0, 4).map(function (u) { return '<option>' + u.name + '</option>'; }).join('') + '</select>' : '') +
      '<input class="input" style="width:200px" placeholder="搜索推广名称"></div>' +
      '<div class="table-wrap"><table><thead><tr><th>推广</th><th>状态</th><th>渠道</th><th style="width:220px">进度</th><th>开始时间</th><th>创建人</th></tr></thead><tbody>' +
      (rows.length ? rows.map(function (c) {
        return '<tr class="click" data-go="#/detail/' + c.id + '"><td><div class="cell-title">' + esc(c.name) + (c.source === 'crm' ? '<span class="tag">CRM 历史</span>' : '') + '</div><div class="cell-sub num">' + c.id + ' · ' + c.createdAt + '</div></td><td>' + chip(c.status, c) + '</td><td>' + CHANNEL[c.channel] + '</td><td>' + (c.status === 'draft' || c.status === 'pending_approval' || c.status === 'rejected' ? '<span class="small muted num">计划 ' + c.counts.planned + ' 封</span>' : progress(c)) + '</td><td class="num small">' + (c.startAt || '—') + '</td><td>' + user(c.createdBy).name + '</td></tr>';
      }).join('') : '<tr><td colspan="6"><div class="empty">没有符合条件的推广</div></td></tr>') +
      '</tbody></table></div></div>';
  }

  /* 新建/编辑推广 */
  function newDraft(from) {
    var u = me();
    var own = D.mailboxes.filter(function (m) { return m.owner === u.id; }).map(function (m) { return m.id; });
    var d = { id: from ? from.id : null, name: from ? from.name : '', channel: from && from.channel !== 'whatsapp' ? from.channel : 'email', groups: [], mailboxes: from && from.mailboxes.length ? from.mailboxes.slice() : own.slice(0, 1), rule: 'owner', start: 'later', startAt: '2026-10-14 09:00', subject: from ? from.subject : '', body: from && from.subject ? 'Dear {联系人姓名},\n\nThank you for your continued interest in our lighting products. We would like to share our latest updates with {公司名称}.\n\nPlease let me know if you need the catalogue or samples.\n' : '', signature: 'personal', attachments: [], version: 1, tested: 0, sample: 0 };
    if (from && from.status === 'rejected') d.rejectReason = from.rejectReason;
    if (from) d.groups = [21];
    return d;
  }
  function audience(d) {
    var u = me();
    var customers = 0, skip = {};
    d.groups.forEach(function (gid) {
      var g = D.groups.filter(function (x) { return x.id === gid; })[0];
      var n = 0;
      Object.keys(g.byOwner).forEach(function (o) { if (u.role !== 'sales' || +o === u.id) n += g.byOwner[o]; });
      customers += n;
      Object.keys(g.skip).forEach(function (k) { var v = u.role === 'sales' ? Math.round(g.skip[k] / 3) : g.skip[k]; skip[k] = (skip[k] || 0) + v; });
    });
    var skipped = Object.keys(skip).reduce(function (s, k) { return s + skip[k]; }, 0);
    var send = Math.max(0, customers - skipped);
    var manual = d.channel === 'preference' ? Math.round(send * 0.15) : 0;
    return { customers: customers, skip: skip, skipped: skipped, emails: send - manual, manual: manual };
  }
  function draftChecks(d, a) {
    return [
      ['推广名称', !!d.name.trim()],
      ['接收对象', a.emails + a.manual > 0],
      ['主题与正文', !!d.subject.trim() && !!d.body.trim()],
      ['发件邮箱', d.rule === 'owner' || d.mailboxes.length > 0],
      ['开始时间', d.start === 'now' || !!d.startAt],
      ['测试信（当前内容）', d.tested === d.version]
    ];
  }
  function render(text, s, d) {
    var u = me();
    return esc(text).replace(/\{公司名称\}/g, '<span class="var">' + esc(s.company) + '</span>').replace(/\{联系人姓名\}/g, s.contact ? '<span class="var">' + esc(s.contact) + '</span>' : '<span class="var" style="background:var(--bad-soft);color:var(--bad)">缺少联系人姓名</span>').replace(/\{发件人姓名\}/g, '<span class="var">' + esc(u.name) + '</span>');
  }
  function pageNew(id) {
    var src = id ? campaign(id) : null;
    if (!state.draft || state.draft._for !== (id || 'new')) { state.draft = newDraft(src); state.draft._for = id || 'new'; }
    var d = state.draft, u = me();
    crumbs(['推广中心', '推广', d.id ? '编辑 ' + d.id : '新建推广']);
    if (!canCreate()) return '<div class="card"><div class="empty">你的角色（只读）不能创建推广。</div></div>';
    var a = audience(d);
    var checks = draftChecks(d, a);
    var reasons = approvalReasons(u, d.rule === 'owner' ? D.mailboxes.filter(function (m) { return m.owner === u.id; }).map(function (m) { return m.id; }) : d.mailboxes, a.emails);
    var ready = checks.every(function (c) { return c[1]; });
    var s = D.samples[d.sample];
    var allowedBoxes = D.mailboxes.filter(function (m) { return isManager() || m.owner === u.id || m.owner === 0 || u.role === 'marketing'; });
    var sign = d.signature === 'none' ? '' : (u.name + '\nSales Manager\nExample LED Lighting Co., Ltd.\nTel: +86 20 0000 0000');
    var senderEmail = d.rule === 'owner' ? '客户负责人的邮箱' : (mailbox(d.mailboxes[0]) || {}).email || '未选择';

    var html = (d.rejectReason ? '<div class="banner bad"><b>审批退回</b><span>' + esc(d.rejectReason) + '</span></div>' : '') +
      '<div class="page-head"><div><h1>' + (d.id ? '编辑推广' : '新建推广') + '</h1><p>保存草稿和预览都不会发送邮件。提交后内容冻结，直到审批完成或撤回。</p></div></div>' +
      '<div class="composer"><div>' +
      section(1, '基本信息', !!d.name.trim(), '',
        '<div class="field"><label for="f-name">推广名称</label><input id="f-name" class="input" data-bind="name" value="' + esc(d.name) + '" placeholder="例如：法兰克福展后跟进"><span class="hint">只在内部显示，客户看不到。</span></div>' +
        '<div class="field"><label>推广方式</label><div class="radio-cards">' +
        radioCard('channel', 'email', d.channel, '只发邮件', '没有有效邮箱的客户会被跳过') +
        radioCard('channel', 'preference', d.channel, '按客户偏好', '偏好微信、WhatsApp 等的客户生成人工待办') + '</div></div>') +
      section(2, '接收对象', a.emails + a.manual > 0, a.customers ? a.customers + ' 个客户' : '',
        '<div class="field"><label>推广分组</label><div class="check-list">' + D.groups.map(function (g) {
          var n = 0; Object.keys(g.byOwner).forEach(function (o) { if (u.role !== 'sales' || +o === u.id) n += g.byOwner[o]; });
          return '<label><input type="checkbox" data-group="' + g.id + '"' + (d.groups.indexOf(g.id) >= 0 ? ' checked' : '') + '> <span style="flex:1">' + esc(g.name) + '</span><span class="muted small num">' + n + ' 个客户</span></label>';
        }).join('') + '</div>' + (u.role === 'sales' ? '<span class="hint">业务员只能选择自己负责的客户，数量已按你的客户计算。</span>' : '') + '</div>' +
        (d.groups.length ? '<div class="audience-sum"><div><b class="num">' + a.emails + '</b><span>封邮件</span></div>' + (d.channel === 'preference' ? '<div><b class="num">' + a.manual + '</b><span>个人工待办</span></div>' : '') + '<div><b class="num">' + a.skipped + '</b><span>个跳过</span></div></div>' +
          '<ul class="skip-list">' + Object.keys(a.skip).filter(function (k) { return a.skip[k]; }).map(function (k) { return '<li>' + k + '：<span class="num">' + a.skip[k] + '</span> 个</li>'; }).join('') + '</ul><span class="hint">跳过的客户在最终预览中逐个列出，可以导出核对。</span>' : '<span class="hint">至少选择一个分组。</span>')) +
      section(3, '内容', !!d.subject.trim() && !!d.body.trim(), 'v' + d.version,
        '<div class="field"><label for="f-subject">邮件主题</label><input id="f-subject" class="input" data-bind="subject" value="' + esc(d.subject) + '"></div>' +
        '<div class="field"><label>正文</label><div class="var-bar">' + ['{联系人姓名}', '{公司名称}', '{发件人姓名}'].map(function (v) { return '<button class="btn sm ghost" data-insert="' + v + '">插入 ' + v.slice(1, -1) + '</button>'; }).join('') + '</div><textarea class="textarea" data-bind="body" style="min-height:200px">' + esc(d.body) + '</textarea><span class="hint">修改主题或正文后，之前发的测试信失效，需要重新发送。</span></div>' +
        '<div class="row"><div class="field"><label>签名</label><select class="select" data-bind="signature"><option value="personal"' + (d.signature === 'personal' ? ' selected' : '') + '>发件邮箱的个人签名</option><option value="none"' + (d.signature === 'none' ? ' selected' : '') + '>不使用签名</option></select></div>' +
        '<div class="field"><label>附件</label><button class="btn" data-act="attach">上传附件</button><span class="hint">' + (d.attachments.length ? d.attachments.join('、') : '单个 8MB 以内，最多 10 个') + '</span></div></div>') +
      section(4, '发件与时间', (d.rule === 'owner' || d.mailboxes.length > 0) && (d.start === 'now' || !!d.startAt), '',
        '<div class="field"><label>发件邮箱</label><div class="radio-cards">' +
        radioCard('rule', 'owner', d.rule, '客户负责人的邮箱', '每个客户由自己的负责人发出') +
        radioCard('rule', 'pool', d.rule, '指定邮箱轮流发', '在选中的邮箱之间平均分配') + '</div></div>' +
        (d.rule === 'pool' ? '<div class="field"><div class="check-list">' + allowedBoxes.map(function (m) { return '<label><input type="checkbox" data-mbox="' + m.id + '"' + (d.mailboxes.indexOf(m.id) >= 0 ? ' checked' : '') + '> <span style="flex:1">' + esc(m.email) + '</span><span class="muted small num">今日剩余 ' + (m.limit - m.used) + '</span></label>'; }).join('') + '</div></div>' : '') +
        '<div class="row"><div class="field"><label>开始时间（北京时间）</label><select class="select" data-bind="start"><option value="later"' + (d.start === 'later' ? ' selected' : '') + '>指定时间</option><option value="now"' + (d.start === 'now' ? ' selected' : '') + '>审批通过或确认后 10 分钟</option></select></div>' +
        (d.start === 'later' ? '<div class="field"><label for="f-at">时间</label><input id="f-at" class="input num" data-bind="startAt" value="' + esc(d.startAt) + '"></div>' : '<div></div>') + '</div>' +
        '<span class="hint">每个邮箱每封间隔 ' + D.settings.interval + ' 秒，并受日上限限制；预计 ' + Math.max(1, Math.ceil(a.emails * D.settings.interval / 3600)) + ' 小时内发完。</span>') +
      '</div><div class="preview"><div class="card"><div class="card-head"><h2>预览</h2><select class="select" style="width:auto;height:30px" data-bind-sample>' + D.samples.map(function (x, i) { return '<option value="' + i + '"' + (i === d.sample ? ' selected' : '') + '>' + esc(x.company) + '</option>'; }).join('') + '</select></div><div class="card-body">' +
      '<div class="mail"><div class="mail-meta"><div><b>发件人</b>' + esc(senderEmail) + '</div><div><b>收件人</b>' + esc(s.contact || s.company) + ' &lt;' + esc(s.email) + '&gt;</div></div>' +
      '<div class="mail-subject">' + (d.subject ? render(d.subject, s, d) : '<span class="muted">（未填写主题）</span>') + '</div>' +
      '<div class="mail-body">' + (d.body ? render(d.body, s, d) : '<span class="muted">（未填写正文）</span>') + '</div>' +
      (sign ? '<div class="mail-sign">' + esc(sign) + '</div>' : '') +
      '<div class="mail-foot">If you no longer wish to receive these emails, unsubscribe here.</div></div>' +
      (!s.contact && /\{联系人姓名\}/.test(d.subject + d.body) ? '<div class="banner warn" style="margin:12px 0 0">这个客户没有联系人姓名。最终预览会把它列为“需处理”，不会用公司名代替。</div>' : '') +
      '</div></div></div></div>' +
      '<div class="dock"><div class="checks">' + checks.map(function (c) { return '<span class="' + (c[1] ? 'y' : 'n') + '">' + (c[1] ? '✓' : '○') + ' ' + c[0] + '</span>'; }).join('') + '</div><div class="spacer"></div>' +
      '<span class="approval-note ' + (reasons.length ? 'need' : 'free') + '" title="' + esc(reasons.join('；')) + '">' + (reasons.length ? '需要审批：' + esc(reasons[0]) + (reasons.length > 1 ? ' 等 ' + reasons.length + ' 项' : '') : '无需审批') + '</span>' +
      '<button class="btn" data-act="save">保存草稿</button><button class="btn" data-act="test">发送测试信</button>' +
      '<button class="btn primary" data-act="submit"' + (ready ? '' : ' disabled title="还有未完成的检查项"') + '>' + (reasons.length ? '提交审批' : '确认并排期') + '</button></div>';
    return html;
  }
  function section(no, title, done, side, body) {
    return '<div class="card section"><div class="section-head"><span class="section-no' + (done ? ' done' : '') + '">' + (done ? '✓' : no) + '</span><h2>' + title + '</h2>' + (side ? '<span class="muted num">' + side + '</span>' : '') + '</div><div class="card-body">' + body + '</div></div>';
  }
  function radioCard(key, val, cur, title, sub) { return '<button type="button" class="radio-card' + (cur === val ? ' on' : '') + '" data-radio="' + key + '" data-v="' + val + '"><b>' + title + '</b><span>' + sub + '</span></button>'; }

  function bindNew() {
    var d = state.draft;
    if (!d) return;
    $all('[data-bind]').forEach(function (el) {
      var key = el.getAttribute('data-bind');
      var ev = el.tagName === 'SELECT' ? 'change' : 'input';
      el.addEventListener(ev, function () {
        d[key] = el.value;
        if (key === 'subject' || key === 'body') d.version = d.tested === d.version ? d.version + 1 : d.version;
        if (ev === 'input') { softRefresh(); } else route();
      });
    });
    var sampleSel = $('[data-bind-sample]'); if (sampleSel) sampleSel.addEventListener('change', function () { d.sample = +sampleSel.value; route(); });
    $all('[data-radio]').forEach(function (el) { el.addEventListener('click', function () { d[el.getAttribute('data-radio')] = el.getAttribute('data-v'); route(); }); });
    $all('[data-group]').forEach(function (el) { el.addEventListener('change', function () { var id = +el.getAttribute('data-group'); d.groups = el.checked ? d.groups.concat(id) : d.groups.filter(function (x) { return x !== id; }); route(); }); });
    $all('[data-mbox]').forEach(function (el) { el.addEventListener('change', function () { var id = +el.getAttribute('data-mbox'); d.mailboxes = el.checked ? d.mailboxes.concat(id) : d.mailboxes.filter(function (x) { return x !== id; }); route(); }); });
    $all('[data-insert]').forEach(function (el) { el.addEventListener('click', function () {
      var ta = $('[data-bind="body"]'); var v = el.getAttribute('data-insert');
      var st = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, st) + v + ta.value.slice(ta.selectionEnd || st);
      d.body = ta.value; if (d.tested === d.version) d.version++; route();
    }); });
    var act = function (name, fn) { var b = $('[data-act="' + name + '"]'); if (b) b.addEventListener('click', fn); };
    act('attach', function () { d.attachments.push('产品目录-2026.pdf'); route(); });
    act('save', function () { toast('草稿已保存（原型不写入数据库）'); });
    act('test', function () {
      modal({ title: '发送测试信', ok: '发送', body: '<div class="field"><label>测试收件邮箱</label><input class="input" value="' + esc(me().name === '王敏' ? 'wangmin@example-led.com' : 'test@example-led.com') + '"></div><p class="small muted">使用当前内容 v' + d.version + '，按“' + esc(D.samples[d.sample].company) + '”替换变量，主题前加 [测试]。不计入推广发送量。</p><p class="form-error small hidden" style="color:var(--bad)"></p>',
        onOk: function (w) { if (!/@/.test($('input', w).value)) return '请输入有效的邮箱地址'; d.tested = d.version; route(); toast('测试信已发送（原型演示）'); } });
    });
    act('submit', function () {
      var u = me(), a = audience(d);
      var reasons = approvalReasons(u, d.rule === 'owner' ? D.mailboxes.filter(function (m) { return m.owner === u.id; }).map(function (m) { return m.id; }) : d.mailboxes, a.emails);
      modal({ title: reasons.length ? '提交审批' : '确认并排期', ok: reasons.length ? '提交审批' : '确认排期',
        body: '<p>将向 <b class="num">' + a.emails + '</b> 个邮箱发送' + (a.manual ? '，并生成 <b class="num">' + a.manual + '</b> 个人工待办' : '') + '。开始时间：' + esc(d.start === 'now' ? '确认后 10 分钟' : d.startAt) + '（北京时间）。</p>' + (reasons.length ? '<p class="small">需要审批的原因：</p><ul class="reason-list small">' + reasons.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul><p class="small muted">提交后内容冻结；审批人看到的就是你现在的预览。</p>' : '<p class="small muted">确认后生成发送队列。开始前可以取消；已交给邮件服务器的邮件无法撤回。</p>'),
        onOk: function () {
          var id = d.id && campaign(d.id) ? d.id : 'pm-' + (1018 + D.campaigns.length);
          var c = campaign(id);
          var fields = { id: id, source: 'pm', name: d.name, channel: d.channel, status: reasons.length ? 'pending_approval' : 'scheduled', createdBy: u.id, owners: [u.id], createdAt: '2026-10-13 14:00', startAt: d.start === 'now' ? '2026-10-13 14:10' : d.startAt, subject: d.subject, mailboxes: d.mailboxes, counts: { planned: a.emails, sent: 0, failed: 0, uncertain: 0, skipped: a.skipped, manualPending: a.manual, manualDone: 0 }, approvalReasons: reasons };
          if (c) { Object.keys(fields).forEach(function (k) { c[k] = fields[k]; }); delete c.rejectReason; } else D.campaigns.unshift(fields);
          state.draft = null;
          location.hash = '#/detail/' + id;
          toast(reasons.length ? '已提交审批' : '已确认，等待开始时间');
        } });
    });
  }
  function softRefresh() {
    var d = state.draft; var a = audience(d);
    var checks = draftChecks(d, a);
    var dock = $('.checks'); if (dock) dock.innerHTML = checks.map(function (c) { return '<span class="' + (c[1] ? 'y' : 'n') + '">' + (c[1] ? '✓' : '○') + ' ' + c[0] + '</span>'; }).join('');
    var btn = $('[data-act="submit"]'); if (btn) btn.disabled = !checks.every(function (c) { return c[1]; });
    var s = D.samples[d.sample];
    var subj = $('.mail-subject'); if (subj) subj.innerHTML = d.subject ? render(d.subject, s, d) : '<span class="muted">（未填写主题）</span>';
    var body = $('.mail-body'); if (body) body.innerHTML = d.body ? render(d.body, s, d) : '<span class="muted">（未填写正文）</span>';
  }

  /* 推广详情 */
  function recipients(c) {
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
    var acts = [];
    if (c.source === 'crm') acts.push('<a class="btn" href="#/list">在 CRM 中打开</a>', canCreate() ? '<a class="btn primary" href="#/new/' + c.id + '">复制为新推广</a>' : '');
    else if (u.role !== 'viewer') {
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
        (rows.length ? rows.map(function (r) { var s = RSTATUS[r.status]; return '<tr><td class="cell-title">' + esc(r.company) + '</td><td class="small">' + esc(r.email) + '</td><td class="small muted">' + esc(r.mbox) + '</td><td><span class="chip ' + s[1] + '">' + s[0] + '</span>' + (r.extra ? '<div class="cell-sub">' + esc(r.extra) + '</div>' : '') + '</td><td class="small num muted">' + (r.time || '—') + '</td><td style="text-align:right">' + (r.status === 'uncertain' && c.source === 'pm' && u.role !== 'viewer' ? '<button class="btn sm" data-verify>核对</button>' : '') + '</td></tr>'; }).join('') : '<tr><td colspan="6"><div class="empty">' + (c.manualOnly ? '这个推广只有人工渠道，请查看人工待办。' : '没有符合条件的对象') + '</div></td></tr>') + '</tbody></table></div>';
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
        onOk: function (w) { var v = $('input[name=v]:checked', w); if (!v) return '请选择核对结果'; c.counts.uncertain--; if (v.value === 'sent') c.counts.sent++; route(); toast('已保存核对结果'); } });
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
    return '<div class="page-head"><div><h1>' + (isManager() ? '待我审批' : '审批记录') + '</h1><p>' + (isManager() ? '审批看到的是提交时冻结的内容，批准后按原计划时间开始。' : '只有管理员可以审批。这里显示你提交的推广的审批进度。') + '</p></div></div>' +
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
      var can = !readOnly && t.status === 'pending' && (t.executor === state.userId || isManager());
      return '<tr><td><div class="cell-title">' + esc(t.company) + '</div><div class="cell-sub">' + esc(t.contact) + ' · ' + esc(t.handle) + '</div></td><td>' + t.channel + '</td><td><a href="#/detail/' + c.id + '">' + esc(c.name) + '</a>' + (c.source === 'crm' ? '<span class="tag">CRM</span>' : '') + '</td><td>' + user(t.executor).name + '</td><td class="num small">' + t.due + '</td><td>' + st + '</td><td style="text-align:right">' + (can ? '<button class="btn sm" data-task="' + t.id + '">登记结果</button>' : (c.source === 'crm' && t.status === 'pending' ? '<span class="small muted">在 CRM 登记</span>' : '')) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }
  function pageTasks() {
    crumbs(['推广中心', '人工待办']);
    var rows = D.manualTasks.filter(function (t) { return isManager() || t.executor === state.userId; });
    return '<div class="page-head"><div><h1>人工待办</h1><p>微信、WhatsApp、电话等渠道由负责人执行，系统不代发；完成后必须登记实际结果。</p></div></div><div class="card">' + (rows.length ? taskTable(rows, false) : '<div class="empty">没有分配给你的人工待办</div>') + '</div>';
  }
  function bindTasks() {
    $all('[data-task]').forEach(function (b) { b.addEventListener('click', function () {
      var t = D.manualTasks.filter(function (x) { return x.id === +b.getAttribute('data-task'); })[0];
      modal({ title: '登记结果：' + t.company, ok: '保存', body: '<div class="field"><label>联系结果</label><select class="select"><option value="">请选择</option><option>已联系-有意向</option><option>已联系-暂无回复</option><option>已联系-无兴趣</option><option>无法联系（号码无效等）</option></select></div><div class="field"><label>备注</label><textarea class="textarea" placeholder="客户说了什么、下一步计划"></textarea></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
        onOk: function (w) { var v = $('select', w).value; if (!v) return '请选择联系结果；不能只勾选完成'; t.status = 'done'; t.result = v; var c = campaign(t.campaign); c.counts.manualPending--; c.counts.manualDone++; route(); toast('结果已登记'); } });
    }); });
  }

  /* 设置 */
  function pageSettings() {
    crumbs(['推广中心', '设置']);
    if (!isManager()) return '<div class="page-head"><div><h1>设置</h1></div></div><div class="card"><div class="empty">只有管理员可以查看和修改推广设置。</div></div>';
    var s = D.settings, x = D.suppress;
    return '<div class="page-head"><div><h1>设置</h1><p>修改只影响之后提交的推广；已排期的推广按提交时的规则执行。</p></div><div class="actions"><button class="btn primary" data-save>保存设置</button></div></div>' +
      '<div class="grid g-2"><div class="card"><div class="card-head"><h2>审批与频控</h2></div><div class="card-body">' +
      '<div class="field"><label>业务员免审额度（封）</label><input class="input num" value="' + s.exempt + '"><span class="hint">业务员只用自己的邮箱、且邮件数不超过此值时无需审批。管理员始终免审，市场始终需审批。</span></div>' +
      '<div class="field"><label>同一客户最短间隔（天）</label><input class="input num" value="' + s.freqDays + '"><span class="hint">同时检查本模块、CRM 推广和旧推广中心的发送记录。</span></div>' +
      '<div class="row"><div class="field"><label>每日发送开始</label><input class="input num" value="' + s.windowStart + '"></div><div class="field"><label>每日发送结束</label><input class="input num" value="' + s.windowEnd + '"></div></div>' +
      '<div class="field"><label>同一邮箱每封间隔（秒）</label><input class="input num" value="' + s.interval + '"></div></div></div>' +
      '<div class="grid"><div class="card"><div class="card-head"><h2>邮箱日上限</h2></div><div class="table-wrap"><table><thead><tr><th>邮箱</th><th>归属</th><th>日上限</th></tr></thead><tbody>' + D.mailboxes.map(function (m) { return '<tr><td class="small">' + esc(m.email) + '</td><td>' + (m.owner ? user(m.owner).name : '公司') + '</td><td style="width:110px"><input class="input num" style="height:30px" value="' + m.limit + '"></td></tr>'; }).join('') + '</tbody></table></div></div>' +
      '<div class="card"><div class="card-head"><h2>屏蔽名单</h2><span class="small muted">任一来源命中即不发送</span></div><div class="table-wrap"><table><tbody>' +
      '<tr><td>本模块退订</td><td class="num" style="text-align:right">' + x.pm + '</td></tr><tr><td>CRM 客户禁联</td><td class="num" style="text-align:right">' + x.crmDnc + '</td></tr><tr><td>旧推广中心退订</td><td class="num" style="text-align:right">' + x.legacyUnsub + '</td></tr><tr><td>旧推广中心黑名单</td><td class="num" style="text-align:right">' + x.legacyBlack + '</td></tr></tbody></table></div></div></div></div>';
  }

  /* 路由 */
  function route() {
    var parts = (location.hash.replace(/^#\/?/, '') || 'home').split('/');
    var name = parts[0], arg = parts[1];
    var view = $('#view'), html;
    if (name === 'new') html = pageNew(arg);
    else if (name === 'detail') html = pageDetail(arg);
    else if (name === 'list') html = pageList();
    else if (name === 'approvals') html = pageApprovals(arg);
    else if (name === 'tasks') html = pageTasks();
    else if (name === 'settings') html = pageSettings();
    else { name = 'home'; html = pageHome(); }
    renderShell(name);
    view.innerHTML = html;
    document.body.classList.toggle('has-dock', name === 'new');
    $all('[data-go]').forEach(function (el) { el.addEventListener('click', function () { location.hash = el.getAttribute('data-go'); }); });
    $all('[data-seg]').forEach(function (seg) { $all('button', seg).forEach(function (b) { b.addEventListener('click', function () { state[seg.getAttribute('data-seg')] = b.getAttribute('data-v'); route(); }); }); });
    if (name === 'new') bindNew();
    if (name === 'detail') bindDetail(arg);
    if (name === 'approvals') bindApprovals(arg);
    if (name === 'tasks' || name === 'detail') bindTasks();
  }
  var lastHash = location.hash;
  window.addEventListener('hashchange', function () {
    if (lastHash.split('/')[1] !== location.hash.split('/')[1]) { state.detailTab = 'recipients'; state.recipientFilter = 'all'; }
    lastHash = location.hash;
    route();
    window.scrollTo(0, 0);
  });
  document.addEventListener('DOMContentLoaded', function () {
    var params = new URLSearchParams(location.search);
    if (params.get('user')) state.userId = +params.get('user');
    $('#role-select').addEventListener('change', function (e) { state.userId = +e.target.value; state.draft = null; route(); toast('已切换为 ' + me().name + '（' + ROLE[me().role] + '）'); });
    route();
  });
})();
