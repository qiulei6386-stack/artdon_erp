/* 执行看板：一场推广的执行情况一页看全。规则见 docs/EXECUTION_BOARD.md；正式实现读取冻结名单、发送记录和人工待办。 */
(function () {
  'use strict';
  var A = window.PM_APP, D = window.PM_DATA, W = window.PM_WIZARD;
  var $ = A.$, $all = A.$all, esc = A.esc;
  var H = 3600e3, MIN = 60e3, NOW = W.NOW;
  var MODE = { now: '马上发', at: '指定时间发', local: '按客户国家工作时间发' };
  var METHOD = { owner: '按客户负责人', split: '勾选邮箱均分', single: '指定某个邮箱', manual: '手填各邮箱数量' };
  var ROW = {
    sent: ['已发出', 'ok'], failed: ['发送失败', 'bad'], uncertain: ['结果不确定', 'warn'], pending: ['待发', ''],
    deferred: ['待发 · 额度顺延', 'warn'], held: ['邮箱暂停', 'bad'], paused: ['推广暂停中', 'warn'], stopped: ['已停止未发', '']
  };
  var RESULTS = ['已联系-有意向', '已联系-暂无回复', '已联系-无兴趣', '无法联系（号码无效等）'];
  var WEEK = '日一二三四五六';
  var FILTERS = [['all', '全部'], ['issues', '仅异常'], ['offline', '仅线下未清'], ['failed', '仅失败']];
  var nextTaskId = 2000;

  function hash(n, salt) { return ((n * 2654435761 + salt * 40503) % 4294967296 >>> 0) % 1000; }
  function saltOf(id) { return id.split('').reduce(function (s, ch) { return s + ch.charCodeAt(0); }, 0); }
  function t8(ms) { return W.fmt(ms, 8); }
  function dayLabel(ms) { return W.dayKey(ms).slice(5) + '（周' + WEEK[new Date(ms + 8 * H).getUTCDay()] + '）'; }
  function tasksOf(c) { return D.manualTasks.filter(function (t) { return t.campaign === c.id; }); }

  /* 按冻结参数重算名单与计划（与新建向导同一套规则），再按“现在”模拟每封的执行结果。 */
  function draftFor(c, x) {
    var d = W.newDraft(null);
    d.name = c.name; d.groups = x.groups.slice(); d.picks = {};
    d.method = x.method; d.splitBoxes = (x.splitBoxes || []).slice(); d.singleBox = x.singleBox || null;
    d.timing = x.timing; d.at = x.at || d.at; d.localTime = x.localTime || d.localTime; d.noOwner = x.noOwner || null;
    d.subject = c.subject || ''; d.html = 'Dear {联系人姓名},<br><br>Please find our latest updates for {公司名称}.';
    d.fallback = x.fallback == null ? 'Sir or Madam' : x.fallback;
    d.asUser = c.createdBy; d.now = W.parseBj(x.now);
    return d;
  }
  function build(c) {
    var x = D.executions[c.id], d = draftFor(c, x), salt = saltOf(c.id);
    d.usedBase = x.usedBase === 'today' ? {} : x.usedBase;
    var p = W.plan(d);
    if (x.usedBase === 'today') {
      /* 今天开始的推广：开始时的已用量 = 今日实际用量 − 本场今天已发出的封数 */
      var today = W.dayKey(NOW), mine = {};
      p.ok.forEach(function (r) { if (r.box && r.at <= NOW && W.dayKey(r.at) === today) mine[r.box.id] = (mine[r.box.id] || 0) + 1; });
      D.mailboxes.forEach(function (m) { d.usedBase[m.id] = Math.max(0, m.used - (mine[m.id] || 0)); });
      p = W.plan(d);
    }
    var fault = x.fault ? { box: x.fault.box, from: W.parseBj(x.fault.from), error: x.fault.error, n: 0 } : null;
    var pausedAt = x.pausedAt ? W.parseBj(x.pausedAt) : (c.status === 'paused' ? NOW : null);
    var rows = p.rows.map(function (r) {
      var e = { c: r.c, p: r.p, box: r.box, reason: r.reason, at: r.at, desired: r.desired, deferred: r.deferred, warn: r.warn };
      if (r.kind === 'offline') { e.st = 'offline'; return e; }
      if (r.kind !== 'ok') { e.st = 'skip'; return e; }
      var h = hash(r.c.id + (r.p.main ? 0 : 500), salt);
      if (c.status === 'scheduled') e.st = 'pending';
      else if (pausedAt && r.at >= pausedAt) e.st = 'paused';
      else if (r.at > NOW) e.st = c.status === 'stopped' ? 'stopped' : (r.deferred ? 'deferred' : 'pending');
      else if (fault && r.box && r.box.id === fault.box && r.at >= fault.from) {
        if (fault.n++ < 2) { e.st = 'failed'; e.error = fault.error; }
        else { e.st = 'held'; e.error = '发信邮箱已暂停，修复后继续发送'; }
      } else if (h < 40) { e.st = 'failed'; e.error = h < 20 ? '550 5.1.1 收件地址不存在（退信）' : '421 对方服务器暂时拒收，已重试 3 次'; }
      else if (h < 55) { e.st = 'uncertain'; e.error = '与邮件服务器连接中断，无法确认是否送达（不会自动重发）'; }
      else {
        e.st = 'sent'; e.sentAt = r.at + (5 + h % 40) * 1000;
        var g = hash(r.c.id, salt + 7);
        e.open = g < 420; e.click = g < 120; e.unsub = g >= 985; e.reply = g >= 430 && g < 470;
      }
      return e;
    });
    if (x.uncertain) {
      var sentRows = rows.filter(function (e) { return e.st === 'sent'; }).sort(function (a, b) { return a.at - b.at; }), u = sentRows[x.uncertain - 1];
      if (u) { u.st = 'uncertain'; u.error = '与邮件服务器连接中断，无法确认是否送达（不会自动重发）'; delete u.sentAt; u.open = u.click = u.unsub = u.reply = false; }
    }
    var offSkip = [];
    D.manualTasks = D.manualTasks.filter(function (t) { return t.campaign !== c.id; });
    W.offlineGroups(p).forEach(function (g, i) {
      if (g.reason) { offSkip.push({ ch: g.ch, c: g.custs[0], handle: g.handle, reason: g.reason }); return; }
      var cu = g.custs[0], group = g.ch === 'wechat_group', h = hash(cu.id + i, salt + 3);
      var t = { id: nextTaskId++, campaign: c.id, company: group ? g.handle : cu.company, contact: group ? g.custs.length + ' 个客户' : cu.contact, channel: W.OFFLINE[g.ch], ch: g.ch, handle: g.handle, executor: g.executor, custs: g.custs.map(function (k) { return k.id; }), due: W.dayKey(p.due), dueMs: p.due, status: 'pending' };
      if (c.status === 'scheduled') t.status = 'waiting';
      else if (h < 400 && p.start + 2 * H < NOW) { t.status = 'done'; t.result = RESULTS[h % 4]; t.shot = 'screenshot-' + t.id + '.png'; t.doneAt = '2026-' + t8(Math.min(NOW - 30 * MIN, p.start + (2 + h % 30) * H)); }
      if (t.status === 'pending' && p.due < NOW) t.overdue = true;
      D.manualTasks.push(t);
    });
    c.exec = { cfg: x, plan: p, rows: rows, offSkip: offSkip, fault: fault, pausedAt: x.pausedAt ? pausedAt : null };
    recount(c);
  }
  function recount(c) {
    var k = { planned: 0, sent: 0, failed: 0, uncertain: 0, skipped: 0, manualPending: 0, manualDone: 0 }, x = c.exec, boxes = {};
    if (!x) return;
    x.rows.forEach(function (e) {
      if (e.st === 'offline') return;
      if (e.st === 'skip') { k.skipped++; return; }
      k.planned++; if (e.box) boxes[e.box.id] = 1;
      if (e.st === 'sent') k.sent++; else if (e.st === 'failed') k.failed++; else if (e.st === 'uncertain') k.uncertain++;
    });
    k.skipped += x.offSkip.length;
    tasksOf(c).forEach(function (t) { if (t.status === 'done') k.manualDone++; else k.manualPending++; });
    c.counts = k;
    c.mailboxes = Object.keys(boxes).map(Number);
    c.partialFailed = c.status === 'completed' && k.failed > 0;
    if (tasksOf(c).length || x.offSkip.length) c.channel = 'preference';
  }
  D.campaigns.forEach(function (c) { if (D.executions[c.id]) build(c); });

  /* 业务员看别人创建的推广时，只看自己负责的客户。 */
  function fullView(c) { return A.allScope() || c.createdBy === A.me().id; }
  function mineCust(c) { return c && c.owner === A.me().id; }
  function viewRows(c) { return !c.exec ? [] : fullView(c) ? c.exec.rows : c.exec.rows.filter(function (e) { return mineCust(e.c); }); }
  function viewOffSkip(c) { return !c.exec ? [] : fullView(c) ? c.exec.offSkip : c.exec.offSkip.filter(function (o) { return mineCust(o.c); }); }
  function viewTasks(c) {
    var me = A.me().id;
    return tasksOf(c).filter(function (t) { return fullView(c) || t.executor === me || (t.custs || []).some(function (id) { return mineCust(A.customer(id)); }); });
  }

  function reasonKey(text) {
    var map = [[/同邮箱/, '同邮箱只发一封'], [/负责人 .*没有可用邮箱/, '负责人无可用邮箱'], [/天内已推广过/, '近期已推广（频控）'], [/DNS/, 'DNS 不通过'], [/默认称呼/, '姓名为空'],
      [/^550/, '退信：地址不存在'], [/^421/, '对方暂时拒收'], [/SMTP 登录失败/, '发信邮箱登录失败'], [/连接中断/, '结果不确定'], [/已暂停，修复后/, '发信邮箱暂停'], [/偏好里没勾邮件/, '偏好里没勾邮件']];
    for (var i = 0; i < map.length; i++) if (map[i][0].test(text)) return map[i][1];
    return text;
  }
  function custCell(c, sub) { return '<div class="cell-title">' + esc(c.company) + '</div><div class="cell-sub ell" title="' + esc(sub) + '">' + esc(sub) + '</div>'; }
  function ownerName(c) { return c.owner ? A.user(c.owner).name : '无归属'; }

  function issues(c) {
    var out = [];
    viewRows(c).forEach(function (e) {
      var who = (e.p.name || '（无姓名）') + (e.p.email ? ' · ' + e.p.email : '');
      var base = { c: e.c, who: who, ch: '邮件', box: e.box };
      if (e.st === 'skip') out.push(Object.assign(base, { type: '发不了', cls: '', reason: e.reason, order: 6 }));
      else if (e.st === 'failed') out.push(Object.assign(base, { type: '失败', cls: 'bad', reason: e.error, order: 1, fail: true }));
      else if (e.st === 'uncertain') out.push(Object.assign(base, { type: '不确定', cls: 'warn', reason: e.error, order: 2, fail: true }));
      else if (e.st === 'held') out.push(Object.assign(base, { type: '邮箱暂停', cls: 'bad', reason: e.error, order: 3 }));
      else if (e.deferred) out.push(Object.assign(base, { type: '额度顺延', cls: 'warn', reason: '发信邮箱 ' + W.dayKey(e.desired).slice(5) + ' 日额度满，顺延到 ' + t8(e.at) + (e.st === 'sent' ? '，已发出' : ''), key: '日额度满顺延', order: 4 }));
    });
    viewOffSkip(c).forEach(function (o) { out.push({ c: o.c, who: o.handle || '—', ch: W.OFFLINE[o.ch], type: '发不了', cls: '', reason: o.reason, order: 6, offline: true }); });
    viewTasks(c).forEach(function (t) { if (t.overdue && t.status !== 'done') out.push({ c: { company: t.company, owner: t.executor }, who: t.contact + ' · ' + t.handle, ch: t.channel, type: '线下逾期', cls: 'bad', reason: '截止 ' + t.due + ' 未完成（执行人 ' + A.user(t.executor).name + '）', key: '线下逾期', order: 5, offline: true, task: t }); });
    out.forEach(function (i) { if (!i.key) i.key = reasonKey(i.reason); });
    return out.sort(function (a, b) { return a.order - b.order; });
  }

  function campaignOrder(list) {
    var rank = { sending: 0, paused: 1, scheduled: 2, completed: 3, stopped: 3, cancelled: 4, pending_approval: 5, rejected: 6, draft: 7 };
    return list.slice().sort(function (a, b) { return (rank[a.status] - rank[b.status]) || String(b.startAt || b.createdAt).localeCompare(String(a.startAt || a.createdAt)); });
  }
  function hasData(c) { return ['draft', 'pending_approval', 'rejected', 'cancelled'].indexOf(c.status) < 0 && (!!c.exec || tasksOf(c).length > 0); }
  function defaultCampaign(list) {
    var ordered = campaignOrder(list);
    return ordered.filter(function (c) { return c.status === 'sending'; })[0] ||
      list.filter(hasData).sort(function (a, b) { return String(b.startAt).localeCompare(String(a.startAt)); })[0] || ordered[0];
  }

  function page(id) {
    A.crumbs(['推广中心', '执行看板']);
    var list = A.visibleCampaigns();
    var head = function (sel) { return '<div class="page-head"><div><h1>执行看板</h1><p>一场推广发了多少、谁没发出去、线下还剩什么、哪个邮箱出问题，一页看全。</p></div><div class="actions">' + (sel || '') + '</div></div>'; };
    if (!list.length) return head() + '<div class="card"><div class="empty">还没有执行数据：你还没有可查看的推广。</div></div>';
    var c = id ? A.campaign(id) : null;
    if (id && (!c || list.indexOf(c) < 0)) return head() + '<div class="card"><div class="empty">推广不存在或你没有权限查看。业务员只能查看自己创建或自己负责客户的推广。</div></div>';
    c = c || defaultCampaign(list);
    var groupsOrder = [['进行中', ['sending', 'paused']], ['已排期', ['scheduled']], ['已结束', ['completed', 'stopped', 'cancelled']], ['未开始', ['pending_approval', 'rejected', 'draft']]];
    var sel = '<label class="small muted" for="board-sel">场次</label><select class="select board-sel" id="board-sel" data-bsel>' + groupsOrder.map(function (g) {
      var items = campaignOrder(list).filter(function (x) { return g[1].indexOf(x.status) >= 0; });
      return items.length ? '<optgroup label="' + g[0] + '">' + items.map(function (x) { return '<option value="' + x.id + '"' + (x === c ? ' selected' : '') + '>' + esc(x.name) + ' · ' + A.STATUS[x.status][0] + (x.source === 'crm' ? '（CRM）' : '') + '</option>'; }).join('') + '</optgroup>' : '';
    }).join('') + '</select>';
    return head(sel) + summary(c) + (hasData(c) ? body(c) : '<div class="card"><div class="empty">' + emptyText(c) + '</div></div>');
  }
  function emptyText(c) {
    var why = { draft: '这场推广还是草稿，提交并开始后才有执行数据。', pending_approval: '这场推广在等待审批，批准并开始后才有执行数据。', rejected: '这场推广已退回，修改后重新提交。', cancelled: '排期已取消，没有发出任何邮件或线下消息。' }[c.status];
    return '<b>还没有执行数据</b><br><span class="small">' + (why || '这场推广还没有发送记录。') + '</span>';
  }

  function summary(c) {
    var x = c.exec, rows = viewRows(c).filter(function (e) { return e.at && e.st !== 'skip' && e.st !== 'offline'; });
    var first = rows.reduce(function (s, e) { return !s || e.at < s ? e.at : s; }, 0), last = rows.reduce(function (s, e) { return e.at > s ? e.at : s; }, 0);
    var sent = rows.filter(function (e) { return e.sentAt; }), lastSent = sent.reduce(function (s, e) { return e.sentAt > s ? e.sentAt : s; }, 0);
    var range = first ? '计划 ' + t8(first) + ' → ' + t8(last) + (sent.length ? ' · 最后发出 ' + t8(lastSent) : '') : (c.startAt ? '开始 ' + c.startAt : '未设置时间');
    var mode = x ? MODE[x.cfg.timing] + (x.cfg.timing === 'local' ? '（当地 ' + x.cfg.localTime + '）' : x.cfg.timing === 'at' ? '（' + x.cfg.at.slice(5) + '）' : '') : '';
    var banners = [];
    if (c.source === 'crm') banners.push('<div class="banner info">CRM 历史推广，只读显示。暂停、继续、登记结果请在 CRM 中操作。</div>');
    if (!fullView(c)) banners.push('<div class="banner info">这场推广不是你创建的，只显示你负责的客户和分给你的线下待办。</div>');
    if (x && x.fault) {
      var held = x.rows.filter(function (e) { return e.st === 'held'; }).length, mine = viewRows(c).filter(function (e) { return e.st === 'held'; }).length;
      banners.push('<div class="banner bad"><b>' + esc(A.mailbox(x.fault.box).email) + ' 从 ' + t8(x.fault.from) + ' 起出错</b><span>' + esc(x.fault.error) + '。本场 ' + held + ' 封等待处理' + (fullView(c) ? '' : '（其中你负责的 ' + mine + ' 封）') + '，其他邮箱照常发送。</span></div>');
    }
    if (x && (c.status === 'paused')) banners.push('<div class="banner warn"><b>推广已暂停</b><span>' + (x.pausedAt ? t8(x.pausedAt) + ' 暂停，' : '') + viewRows(c).filter(function (e) { return e.st === 'paused'; }).length + ' 封未发。继续后按原计划顺序发送。</span></div>');
    return banners.join('') + '<div class="card board-sum"><div class="bs-main"><h2>' + esc(c.name) + ' ' + A.chip(c.status, c) + (c.source === 'crm' ? '<span class="tag">CRM 历史</span>' : '') + '</h2><div class="meta"><span class="num">' + c.id + '</span><span>创建人 ' + esc(A.user(c.createdBy).name) + '</span><span class="num">' + esc(range) + '</span>' +
      (mode ? '<span>发送时间：' + mode + '</span><span>发信方式：' + METHOD[x.cfg.method] + '</span>' : '') + '</div></div><div class="actions"><a class="btn sm" href="#/detail/' + c.id + '">推广详情</a></div></div>';
  }

  function body(c) {
    var rows = viewRows(c).filter(function (e) { return e.st !== 'skip' && e.st !== 'offline'; }), tasks = viewTasks(c), list = issues(c), f = A.state.boardFilter || 'all';
    var n = function (st) { return rows.filter(function (e) { return st.indexOf(e.st) >= 0; }).length; };
    var sent = n(['sent']), failed = n(['failed']), unc = n(['uncertain']), pend = n(['pending', 'deferred', 'held', 'paused']);
    var skip = viewRows(c).filter(function (e) { return e.st === 'skip'; }).length + viewOffSkip(c).length;
    var done = tasks.filter(function (t) { return t.status === 'done'; }).length, open = tasks.length - done, late = tasks.filter(function (t) { return t.overdue && t.status !== 'done'; }).length;
    var total = rows.length + tasks.length, rate = total ? Math.round((sent + done) / total * 100) : 0;
    var pct = function (a, b) { return b ? Math.round(a / b * 100) + '%' : '—'; };
    var stats = '<div class="board-stats">' +
      bstat('邮件已发', sent, '共 ' + rows.length + ' 封 · ' + pct(sent, rows.length)) +
      bstat('邮件待发', pend, [['deferred', '额度顺延'], ['held', '邮箱暂停'], ['paused', '推广暂停'], ['stopped', '已停止未发']].filter(function (s) { return n([s[0]]); }).map(function (s) { return s[1] + ' ' + n([s[0]]); }).join(' · ') || (pend ? '按计划发送' : '没有待发'), n(['held']) ? 'warn' : '') +
      bstat('邮件失败', failed + unc, unc ? '含结果不确定 ' + unc : '退信、拒收等', failed + unc ? 'bad' : '') +
      bstat('跳过（发不了）', skip, '原因见“发不了 / 异常”') +
      bstat('线下已完成', done, '共 ' + tasks.length + ' 条 · ' + pct(done, tasks.length)) +
      bstat('线下未完成', open, late ? '其中逾期 ' + late : '无逾期', late ? 'bad' : '') +
      bstat('完成率', total ? rate + '%' : '—', '邮件 ' + pct(sent, rows.length) + ' · 线下 ' + pct(done, tasks.length)) + '</div>' +
      (rows.length ? '<div class="progress board-bar" title="绿：已发出 红：失败 黄：不确定"><span class="p-ok" style="width:' + sent / rows.length * 100 + '%"></span><span class="p-bad" style="width:' + failed / rows.length * 100 + '%"></span><span class="p-warn" style="width:' + unc / rows.length * 100 + '%"></span></div>' : '');
    var bar = '<div class="board-filter"><div class="toolbar"><div class="seg" data-seg="boardFilter">' + FILTERS.map(function (x) { return '<button class="' + (f === x[0] ? 'on' : '') + '" data-v="' + x[0] + '">' + x[1] + '</button>'; }).join('') + '</div>' +
      (A.state.boardReason ? '<span class="chip info">原因：' + esc(A.state.boardReason) + ' <button class="chip-x" data-breason="" aria-label="清除原因筛选">×</button></span>' : '') +
      '<div class="spacer"></div><span class="small muted">数据截至 ' + t8(NOW) + '（北京时间）</span></div></div>';
    var left = issueCard(c, list, f) + (f === 'offline' ? '' : timelineCard(c, rows, f));
    var right = mailboxCard(c, rows) + offlineCard(c, tasks, f) + (f === 'all' ? effectCard(c, rows, tasks) : '');
    return stats + bar + '<div class="board-grid"><div class="board-col">' + left + '</div><div class="board-col">' + right + '</div></div>';
  }
  function bstat(label, value, sub, tone) { return '<div class="card stat bstat' + (tone ? ' ' + tone : '') + '"><div class="stat-label">' + label + '</div><div class="stat-value num">' + value + '</div><div class="stat-sub">' + sub + '</div></div>'; }

  function issueCard(c, list, f) {
    var shown = list.filter(function (i) { return f === 'offline' ? i.offline : f === 'failed' ? i.fail : true; });
    var keys = {}; shown.forEach(function (i) { keys[i.key] = (keys[i.key] || 0) + 1; });
    var r = A.state.boardReason;
    var rowsHtml = shown.filter(function (i) { return !r || i.key === r; }).map(function (i) {
      return '<tr class="' + (i.cls === 'bad' ? 'row-bad' : i.cls === 'warn' ? 'row-warn' : '') + '"><td><span class="chip ' + i.cls + '">' + i.type + '</span></td><td>' + custCell(i.c, i.who) + '</td><td class="small nw">' + esc(i.ch) + '</td><td class="small">' + esc(i.reason) + '</td><td class="small muted">' + esc(i.task ? A.user(i.task.executor).name : ownerName(i.c)) + '</td></tr>';
    }).join('');
    var head = { all: '发不了 / 异常', issues: '发不了 / 异常', offline: '线下发不了 / 逾期', failed: '发送失败' }[f];
    return '<div class="card"><div class="card-head"><h2>' + head + '</h2><span class="small muted num">' + shown.length + ' 条</span></div>' +
      (shown.length ? '<div class="reason-bar">' + Object.keys(keys).sort(function (a, b) { return keys[b] - keys[a]; }).map(function (k) { return '<button class="gtag rchip' + (r === k ? ' on' : '') + '" data-breason="' + esc(k) + '">' + esc(k) + ' <b class="num">' + keys[k] + '</b></button>'; }).join('') + '</div>' +
        '<div class="board-scroll"><table class="compact"><thead><tr><th>类型</th><th>客户</th><th>渠道</th><th>原因</th><th>负责人</th></tr></thead><tbody>' + (rowsHtml || '<tr><td colspan="5"><div class="empty">这个原因下没有记录</div></td></tr>') + '</tbody></table></div>'
        : '<div class="empty">' + (f === 'failed' ? '没有发送失败的邮件' : '没有发不了或异常的对象') + '</div>') + '</div>';
  }

  function timelineCard(c, rows, f) {
    var x = c.exec;
    var shown = rows.filter(function (e) {
      if (f === 'failed') return e.st === 'failed' || e.st === 'uncertain';
      if (f === 'issues') return ['failed', 'uncertain', 'held'].indexOf(e.st) >= 0 || e.deferred || e.warn;
      return true;
    }).sort(function (a, b) { return a.at - b.at; });
    var day = '', html = '';
    var perDay = {}; shown.forEach(function (e) { var k = W.dayKey(e.at); perDay[k] = (perDay[k] || 0) + 1; });
    shown.forEach(function (e) {
      var k = W.dayKey(e.at);
      if (k !== day) { day = k; html += '<tr class="day-row"><td colspan="6">' + dayLabel(e.at) + ' · <span class="num">' + perDay[k] + '</span> 封</td></tr>'; }
      var z = W.zone(e.c), s = ROW[e.st];
      var cls = e.st === 'failed' || e.st === 'held' ? 'row-bad' : (e.warn || e.deferred || e.st === 'uncertain') ? 'row-warn' : '';
      html += '<tr class="' + cls + '"><td class="num small">' + t8(e.at).slice(6) + (e.deferred ? '<div class="cell-sub">原定 ' + W.dayKey(e.desired).slice(5) + '</div>' : '') + '</td>' +
        '<td class="num small muted">' + W.fmt(e.at, z.off).slice(6) + '<div class="cell-sub">' + esc(e.c.country || '无国家') + '</div></td>' +
        '<td>' + custCell(e.c, e.p.email) + '</td><td class="small muted">' + esc(e.box ? e.box.email.split('@')[0] : '—') + '</td>' +
        '<td><span class="chip ' + s[1] + '">' + s[0] + '</span>' + (e.error ? '<div class="cell-sub">' + esc(e.error) + '</div>' : e.warn && e.st !== 'sent' ? '<div class="cell-sub warn-text">' + esc(e.warn) + '</div>' : '') + '</td>' +
        '<td class="num small">' + (e.sentAt ? t8(e.sentAt).slice(6) + '<div class="cell-sub">' + W.dayKey(e.sentAt).slice(5) + '</div>' : '<span class="muted">—</span>') + '</td></tr>';
    });
    var mode = x ? MODE[x.cfg.timing] + (x.cfg.timing === 'local' ? ' · 客户当地 ' + x.cfg.localTime + '，跳过当地周末' : x.cfg.timing === 'at' ? ' · ' + x.cfg.at : ' · 确认后 10 分钟起') : '';
    return '<div class="card"><div class="card-head"><h2>发送时间线</h2><span class="small muted">' + esc(mode) + '</span></div>' +
      (shown.length ? '<div class="board-scroll tall"><table class="compact"><thead><tr><th>计划（北京）</th><th>客户当地</th><th>客户 / 收件邮箱</th><th>发件</th><th>状态</th><th>实际发出</th></tr></thead><tbody>' + html + '</tbody></table></div>'
        : '<div class="empty">' + (rows.length ? '没有符合条件的邮件' : '本场没有邮件，只有线下渠道') + '</div>') + '</div>';
  }

  function mailboxCard(c, rows) {
    var by = {};
    rows.forEach(function (e) { if (!e.box) return; var b = by[e.box.id] = by[e.box.id] || { m: e.box, n: 0, sent: 0, fail: 0, pend: 0, deferred: 0 }; b.n++; if (e.st === 'sent') b.sent++; else if (e.st === 'failed' || e.st === 'uncertain') b.fail++; else if (e.st !== 'stopped') b.pend++; if (e.deferred) b.deferred++; });
    var ids = Object.keys(by);
    if (!ids.length) return '<div class="card"><div class="card-head"><h2>发信箱</h2></div><div class="empty">本场没有使用发信邮箱</div></div>';
    var today = W.dayKey(NOW), x = c.exec, quota = x ? x.plan.quota : {};
    return '<div class="card"><div class="card-head"><h2>发信箱</h2><span class="small muted">' + ids.length + ' 个邮箱</span></div><div class="card-body board-boxes">' + ids.map(function (id) {
      var b = by[id], m = b.m, q = quota[id] || {}, used, label;
      var days = Object.keys(q).filter(function (k) { return rows.some(function (e) { return e.box === m && W.dayKey(e.at) === k; }); });
      if (days.indexOf(today) >= 0) { used = m.used; label = '今日'; }
      else { var peak = days.sort(function (a, b2) { return q[b2] - q[a]; })[0]; used = peak ? q[peak] : 0; label = peak ? peak.slice(5) : '—'; }
      used = Math.min(used, m.limit);
      var p = m.limit ? used / m.limit : 0, fault = x && x.fault && x.fault.box === m.id;
      var chip = fault ? '<span class="chip bad">登录失败，已暂停</span>' : b.deferred ? '<span class="chip bad">触顶日额度 · 顺延 ' + b.deferred + ' 封</span>' : p >= D.settings.quotaWarn ? '<span class="chip warn">接近日额度</span>' : '<span class="chip ok">正常</span>';
      return '<div class="bbox' + (fault || b.deferred ? ' bad' : '') + '"><div class="bbox-head"><span class="cell-title small">' + esc(m.email) + '</span>' + chip + '</div>' +
        '<div class="bbox-nums num small"><span>本场 <b>' + b.n + '</b></span><span class="ok-text">已发 <b>' + b.sent + '</b></span><span class="' + (b.fail ? 'bad-text' : '') + '">失败 <b>' + b.fail + '</b></span><span>待发 <b>' + b.pend + '</b></span></div>' +
        '<div class="progress"><span class="' + (p >= 1 ? 'p-bad' : p >= D.settings.quotaWarn ? 'p-warn' : 'p-ok') + '" style="width:' + p * 100 + '%"></span></div><div class="progress-label num">' + label + ' 用量 ' + used + ' / ' + m.limit + (label === '今日' || label === '—' ? '' : W.dayKey(NOW).slice(5) < label ? '（计划当日）' : '（发送当日）') + '</div>' +
        (fault ? '<div class="cell-sub bad-text">' + esc(x.fault.error) + '</div>' : '') + '</div>';
    }).join('') + '</div></div>';
  }

  function offlineCard(c, tasks, f) {
    var skips = viewOffSkip(c);
    if (!tasks.length && !skips.length) return '<div class="card"><div class="card-head"><h2>线下执行</h2></div><div class="empty">本场没有线下渠道</div></div>';
    var chs = {};
    tasks.forEach(function (t) { var k = chs[t.channel] = chs[t.channel] || { todo: 0, done: 0, late: 0, skip: 0 }; if (t.status === 'done') k.done++; else k.todo++; if (t.overdue && t.status !== 'done') k.late++; });
    skips.forEach(function (o) { var k = chs[W.OFFLINE[o.ch]] = chs[W.OFFLINE[o.ch]] || { todo: 0, done: 0, late: 0, skip: 0 }; k.skip++; });
    var shown = tasks.filter(function (t) { return f === 'offline' ? t.status !== 'done' : f === 'issues' ? t.overdue && t.status !== 'done' : f === 'failed' ? /无法联系/.test(t.result || '') : true; });
    var st = function (t) { return t.status === 'done' ? '<span class="chip ok">已完成</span>' : t.status === 'waiting' ? '<span class="chip">等推广开始</span>' : t.overdue ? '<span class="chip bad">已逾期</span>' : '<span class="chip warn">待执行</span>'; };
    return '<div class="card"><div class="card-head"><h2>线下执行</h2><span class="small muted">截止 ' + (tasks[0] ? tasks[0].due : '—') + '</span></div>' +
      '<table class="compact"><thead><tr><th>渠道</th><th class="r">待办</th><th class="r">已完成</th><th class="r">逾期</th><th class="r">发不了</th></tr></thead><tbody>' + Object.keys(chs).map(function (k) { var x = chs[k]; return '<tr><td>' + k + '</td><td class="r num">' + x.todo + '</td><td class="r num">' + x.done + '</td><td class="r num' + (x.late ? ' bad-text' : '') + '">' + x.late + '</td><td class="r num muted">' + x.skip + '</td></tr>'; }).join('') + '</tbody></table>' +
      '<div class="board-sub-head small muted">执行明细 · 点一行看谁做的、有没有截图</div>' +
      (shown.length ? '<div class="board-scroll"><table class="compact"><tbody>' + shown.map(function (t) {
        return '<tr class="click' + (t.overdue && t.status !== 'done' ? ' row-bad' : '') + '" data-btask="' + t.id + '"><td><div class="cell-title small">' + esc(t.company) + '</div><div class="cell-sub">' + esc(t.channel) + ' · ' + esc(A.user(t.executor).name) + '</div></td><td>' + st(t) + '</td><td class="small" title="截图">' + (t.shot ? '<span class="ok-text">有截图</span>' : '<span class="muted">无截图</span>') + '</td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="empty">' + (f === 'offline' ? '线下已全部完成' : '没有符合条件的线下待办') + '</div>') + '</div>';
  }

  function effectCard(c, rows, tasks) {
    var sent = rows.filter(function (e) { return e.st === 'sent'; });
    var intentOff = tasks.filter(function (t) { return t.result === '已联系-有意向'; }).length;
    if (!sent.length && !intentOff) return '<div class="card"><div class="card-head"><h2>效果粗览</h2></div><div class="empty">还没有效果数据</div></div>';
    var cnt = function (k) { return sent.filter(function (e) { return e[k]; }).length; };
    var rate = function (v) { return sent.length ? Math.round(v / sent.length * 100) + '%' : '—'; };
    var reply = cnt('reply');
    return '<div class="card"><div class="card-head"><h2>效果粗览</h2><span class="small muted">详细分析见「效果分析」</span></div><div class="board-effects">' +
      eff('打开', cnt('open'), rate(cnt('open'))) + eff('点击', cnt('click'), rate(cnt('click'))) + eff('退订', cnt('unsub'), rate(cnt('unsub'))) + eff('意向', reply + intentOff, '邮件回复 ' + reply + ' · 线下有意向 ' + intentOff) +
      '</div><p class="hint" style="padding:0 18px 12px;margin:0">打开、点击需要开启追踪（设计文档第 13 节待确认）；没开追踪时只显示退订和意向。</p></div>';
  }
  function eff(label, v, sub) { return '<div><div class="stat-label">' + label + '</div><div class="eff-value num">' + v + '</div><div class="stat-sub">' + sub + '</div></div>'; }

  function bind() {
    var s = $('[data-bsel]');
    if (s) s.addEventListener('change', function () { A.state.boardReason = ''; location.hash = '#/board/' + s.value; });
    $all('[data-breason]').forEach(function (b) { b.addEventListener('click', function () { var k = b.getAttribute('data-breason'); A.state.boardReason = A.state.boardReason === k ? '' : k; A.route(); }); });
    $all('[data-btask]').forEach(function (tr) { tr.addEventListener('click', function () { taskModal(+tr.getAttribute('data-btask')); }); });
  }
  function taskModal(id) {
    var t = D.manualTasks.filter(function (x) { return x.id === id; })[0]; if (!t) return;
    var c = A.campaign(t.campaign), canDo = A.can('execute') && t.status === 'pending' && c.source === 'pm' && (t.executor === A.me().id || A.allScope());
    var custs = (t.custs || []).map(A.customer).filter(Boolean);
    var status = t.status === 'done' ? '已完成' : t.status === 'waiting' ? '等推广开始' : t.overdue ? '已逾期' : '待执行';
    A.modal({ title: '线下执行明细：' + t.company, ok: canDo ? '去登记结果' : '知道了',
      body: '<dl class="kv">' +
        '<dt>渠道</dt><dd>' + esc(t.channel) + '</dd>' +
        (t.ch === 'wechat_group' ? '<dt>微信群</dt><dd>' + esc(t.handle) + '</dd><dt>群里客户</dt><dd class="small">' + esc(custs.map(function (k) { return k.company; }).join('、')) + '</dd>' : '<dt>联系人</dt><dd>' + esc(t.contact) + ' · ' + esc(t.handle) + '</dd>') +
        '<dt>执行人</dt><dd>' + esc(A.user(t.executor).name) + '</dd>' +
        '<dt>截止</dt><dd class="num">' + esc(t.due) + '</dd>' +
        '<dt>状态</dt><dd>' + status + (t.result ? ' · ' + esc(t.result) : '') + '</dd>' +
        (t.doneAt ? '<dt>完成时间</dt><dd class="num">' + esc(t.doneAt) + '</dd>' : '') +
        '<dt>截图</dt><dd>' + (t.shot ? '<div class="shot-thumb"><span>' + esc(t.shot) + '</span></div>' : '<span class="muted">未上传</span>') + '</dd></dl>',
      onOk: function () { if (canDo) location.hash = '#/tasks'; } });
  }

  window.PM_BOARD = { page: page, bind: bind, recount: recount, hasData: hasData, issues: issues, viewRows: viewRows, viewTasks: viewTasks, defaultCampaign: defaultCampaign };
})();
