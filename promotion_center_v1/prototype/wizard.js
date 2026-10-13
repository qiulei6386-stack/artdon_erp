/* 新建推广向导。流程与规则见 docs/CREATE_FLOW.md；正式实现由服务端重新计算并校验。 */
(function () {
  'use strict';
  var A = window.PM_APP, D = window.PM_DATA;
  var $ = A.$, $all = A.$all, esc = A.esc;
  var H = 3600e3, MIN = 60e3;
  var NOW = Date.UTC(2026, 9, 13, 6, 10); /* 原型的“现在”：北京时间 2026-10-13 14:10（周二） */
  var STEPS = ['推广名称', '选客户', '编邮件', '预览', '发信方式', '试发', '发送时间', '线下明细', '确认'];
  var OFFLINE = { wechat: '微信', wechat_group: '微信群', whatsapp: 'WhatsApp', phone: '电话' };
  var CH_LABEL = { email: '邮件', wechat: '微信', wechat_group: '微信群', whatsapp: 'WhatsApp', phone: '电话', no_promotion: '拒推广' };
  var VARS = ['联系人姓名', '公司名称', '国家', '发件人姓名'];
  var WEEK = '日一二三四五六';
  var DEMO_FILES = [{ name: '产品目录-2026.pdf', mb: 6.2 }, { name: '报价单-Q4.xlsx', mb: 0.3 }, { name: 'CE-RoHS 证书.zip', mb: 4.6 }, { name: '安装说明.pdf', mb: 1.2 }];
  var IMG = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="480" height="200"><rect width="480" height="200" fill="#eef0fd"/><rect x="40" y="70" width="400" height="16" rx="8" fill="#3b4fd8"/><circle cx="120" cy="120" r="18" fill="#1d1b18"/><circle cx="240" cy="120" r="18" fill="#1d1b18"/><circle cx="360" cy="120" r="18" fill="#1d1b18"/><text x="240" y="178" font-family="sans-serif" font-size="16" text-anchor="middle" fill="#4a4640">2026 Track Light Series</text></svg>');
  var IMG_KB = 180;
  var ui = { focus: 'editor', copyTexts: [] };

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmt(ms, off) { var t = new Date(ms + off * H); return pad(t.getUTCMonth() + 1) + '-' + pad(t.getUTCDate()) + ' ' + pad(t.getUTCHours()) + ':' + pad(t.getUTCMinutes()); }
  function bj(ms) { return fmt(ms, 8) + '（周' + WEEK[new Date(ms + 8 * H).getUTCDay()] + '）'; }
  function dayKey(ms) { var t = new Date(ms + 8 * H); return t.getUTCFullYear() + '-' + pad(t.getUTCMonth() + 1) + '-' + pad(t.getUTCDate()); }
  function parseBj(s) { var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/.exec(String(s || '').trim()); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) - 8 * H : null; }
  function textOf(html) { var div = document.createElement('div'); div.innerHTML = html; return div.textContent || ''; }
  function offLabel(o) { return 'UTC' + (o >= 0 ? '+' : '') + o; }

  function zone(c) {
    var k = D.countries[c.country];
    if (c.tz != null) return { off: c.tz, weekend: k ? k.weekend : [0, 6], label: c.tzLabel || offLabel(c.tz), src: 'customer' };
    if (k) return { off: k.tz, weekend: k.weekend, label: (k.label ? k.label + ' ' : '') + offLabel(k.tz), src: 'country' };
    return { off: D.settings.defaultTz, weekend: [0, 6], label: '无国家，按默认时区 ' + offLabel(D.settings.defaultTz), src: 'default' };
  }
  function ownBoxes(u) { return D.mailboxes.filter(function (m) { return m.owner === u.id; }); }
  function allowedBoxes() { var u = A.me(); return D.mailboxes.filter(function (m) { return m.owner === u.id || A.can('use_any_mailbox'); }); }
  function ownerBox(uid) { return D.mailboxes.filter(function (m) { return m.owner === uid; })[0] || null; }
  function companyBox() { return D.mailboxes.filter(function (m) { return m.owner === 0; })[0]; }
  function remaining(m) { return Math.max(0, m.limit - m.used); }
  function senderName(m) { return m ? (m.owner ? A.user(m.owner).name : 'Marketing Team') : '{发件人姓名}'; }

  var DEFAULT_HTML = 'Dear {联系人姓名},<br><br>Thank you for your continued interest in our lighting products. We would like to share our latest updates with <b>{公司名称}</b>.<br><br>Please let me know if you need the catalogue or samples.';
  function newDraft(from) {
    var u = A.me(), own = ownBoxes(u)[0];
    var d = {
      id: from ? from.id : null, name: from ? from.name : '', step: 0, reached: from ? 8 : 0,
      groups: from ? [21] : [], picks: {}, audTab: 'groups', pickQ: '', pickCountry: '',
      channels: { email: true, wechat: true, wechat_group: true, whatsapp: true, phone: true }, allContacts: false, noOwner: null,
      subject: from ? from.subject : '', html: from && from.subject ? DEFAULT_HTML : '', signature: 'mailbox', attachments: [], fallback: '',
      previewMode: 'vars', previewIdx: 0, previewIssues: false,
      method: 'owner', splitBoxes: [], singleBox: null, manual: {},
      testTo: own ? own.email : '', tests: {}, testSample: 0,
      timing: 'local', at: '2026-10-14 09:00', localTime: '09:30', rowFilter: 'all',
      scripts: {
        wechat: 'Hi {联系人姓名}, this is {发件人姓名} from Example LED. Our 2026 track light series is out — may I send you the catalogue?',
        wechat_group: 'Hello everyone, sharing our new 2026 track light series. Catalogue and sample policy available on request — feel free to @{发件人姓名}.',
        whatsapp: 'Hi {联系人姓名}, {发件人姓名} here from Example LED. We just launched our 2026 track lights for {公司名称}. Shall I send the catalogue?',
        phone: '通话要点：1. 介绍 2026 新品轨道灯；2. 问 {公司名称} 近期项目；3. 约寄样时间。'
      },
      executors: {}, version: 1
    };
    if (from && from.status === 'rejected') d.rejectReason = from.rejectReason;
    return d;
  }

  /* d.asUser / d.now / d.usedBase：执行看板按创建人、提交时刻和当时邮箱用量重算冻结名单时使用。 */
  function actor(d) { return d.asUser ? A.user(d.asUser) : A.me(); }
  function nowOf(d) { return d.now || NOW; }
  function inScope(c, d) { if (!d.asUser) return A.visibleCustomer(c); var u = actor(d); return u.scope !== 'self' || c.owner === u.id; }

  /* 选中的客户：分组成员与直接勾选的并集，只含当前用户客户数据范围内的客户。 */
  function selected(d) {
    var seen = {}, list = [];
    function add(c) { if (c && inScope(c, d) && !seen[c.id]) { seen[c.id] = 1; list.push(c); } }
    d.groups.forEach(function (gid) { var g = A.group(gid); if (g && !g.deleted) g.members.forEach(function (id) { add(A.customer(id)); }); });
    Object.keys(d.picks).forEach(function (id) { if (d.picks[id]) add(A.customer(+id)); });
    return list;
  }
  function blockAll(c, d) {
    if (c.dnc) return '客户禁联';
    if (c.channels.indexOf('no_promotion') >= 0) return '客户拒推广';
    if (c.pstatus === 'paused') return '客户推广已暂停';
    if (c.pstatus === 'stopped') return '客户推广已停止';
    if (c.pstatus === 'blacklist') return '客户在黑名单';
    if (!c.owner && d.noOwner === 'exclude') return '无归属客户，本场排除';
    return '';
  }
  function unowned(d) { return selected(d).filter(function (c) { return !c.owner && !blockAll(c, { noOwner: null }); }); }

  function localDesired(c, d) {
    var now = nowOf(d), z = zone(c), hm = (d.localTime || '09:30').split(':'), base = new Date(now + z.off * H);
    for (var i = 0; i < 9; i++) {
      var t = Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate() + i, +hm[0] || 0, +hm[1] || 0) - z.off * H;
      if (t >= now + 10 * MIN && z.weekend.indexOf(new Date(t + z.off * H).getUTCDay()) < 0) return t;
    }
    return now + 10 * MIN;
  }
  function nextDay(t, r, d) {
    if (d.timing === 'local') {
      var z = zone(r.c); t += 24 * H;
      while (z.weekend.indexOf(new Date(t + z.off * H).getUTCDay()) >= 0) t += 24 * H;
      return t;
    }
    var b = new Date(t + 8 * H), n = Date.UTC(b.getUTCFullYear(), b.getUTCMonth(), b.getUTCDate() + 1, 9, 0) - 8 * H;
    while ([0, 6].indexOf(new Date(n + 8 * H).getUTCDay()) >= 0) n += 24 * H;
    return n;
  }

  /* 计划：每个邮件对象一行（含发不了的），线下每个渠道一行。 */
  function plan(d) {
    var u = actor(d), now = nowOf(d), custs = selected(d), rows = [], offline = [], seenEmail = {};
    var usesName = /\{联系人姓名\}/.test(d.subject + d.html), usesCountry = /\{国家\}/.test(d.subject + d.html);
    custs.forEach(function (c) {
      var b = blockAll(c, d);
      var hasOff = c.channels.some(function (k) { return OFFLINE[k] && d.channels[k]; });
      var people = [{ name: c.contact, email: c.email, left: c.left, unsub: c.unsub, main: true }];
      if (d.allContacts && c.c2) people.push({ name: c.c2.name, email: c.c2.email, left: false, unsub: false });
      people.forEach(function (p) {
        var r = b, kind = 'skip';
        if (!r && c.channels.indexOf('email') < 0) { r = hasOff ? '偏好里没勾邮件，走线下' : '偏好里没勾邮件'; if (hasOff) kind = 'offline'; }
        else if (!r && !d.channels.email) { r = '本场未启用邮件'; if (hasOff) kind = 'offline'; }
        if (!r && p.left) r = '联系人已离职';
        if (!r && !p.email) r = '无邮箱';
        if (!r && !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(p.email)) r = '邮箱格式无效';
        if (!r && p.unsub) r = '已退订邮件';
        if (!r && c.legacyBlack) r = '旧推广中心黑名单';
        if (!r && c.noMx) r = '邮箱域名 DNS 不通过（无 MX）';
        if (!r && c.recent) r = D.settings.freqDays + ' 天内已推广过';
        if (!r && seenEmail[p.email.toLowerCase()]) r = '与「' + seenEmail[p.email.toLowerCase()] + '」同邮箱，本场只发一封';
        if (!r && usesName && !p.name && !d.fallback.trim()) r = '联系人姓名为空，未设默认称呼';
        if (!r && usesCountry && !c.country) r = '国家为空（正文用了 {国家}）';
        if (!r) seenEmail[p.email.toLowerCase()] = c.company + (p.main ? '' : ' · ' + p.name);
        rows.push({ c: c, p: p, reason: r, kind: r ? kind : 'ok', box: null });
      });
      c.channels.forEach(function (k) {
        if (!OFFLINE[k] || !d.channels[k]) return;
        var r = b, handle = '';
        if (k === 'wechat') { handle = c.wechat; if (!r && !handle) r = '没有微信号'; }
        if (k === 'whatsapp') { handle = c.whatsapp; if (!r && !handle) r = '没有 WhatsApp 号码'; }
        if (k === 'phone') { handle = c.phone; if (!r && !handle) r = '没有电话'; }
        if (k === 'wechat_group') { handle = c.chatGroup; if (!r && c.avoidGroup) r = '联系人不接受群里联系'; if (!r && !handle) r = '没有可用于推广的客户群'; }
        if (!r && c.left) r = '联系人已离职';
        offline.push({ c: c, ch: k, handle: handle, reason: r });
      });
    });

    var ok = rows.filter(function (r) { return r.kind === 'ok'; });
    if (d.method === 'owner') {
      ok.forEach(function (r) {
        if (!r.c.owner) { if (d.noOwner === 'company') r.box = companyBox(); else { r.reason = '无归属客户，还没决定怎么处理'; r.kind = 'skip'; } return; }
        r.box = ownerBox(r.c.owner);
        if (!r.box) { r.reason = '负责人 ' + A.user(r.c.owner).name + ' 没有可用邮箱'; r.kind = 'skip'; }
      });
    } else if (d.method === 'single') {
      ok.forEach(function (r) { r.box = A.mailbox(d.singleBox) || null; });
    } else if (d.method === 'split') {
      var boxes = d.splitBoxes.map(A.mailbox).filter(Boolean);
      var share = splitShares(boxes, ok.length), idx = 0;
      boxes.forEach(function (m) { for (var i = 0; i < share[m.id]; i++) ok[idx++].box = m; });
    } else if (d.method === 'manual') {
      var j = 0;
      allowedBoxes().forEach(function (m) { var n = Math.max(0, parseInt(d.manual[m.id], 10) || 0); for (var i = 0; i < n && j < ok.length; i++) ok[j++].box = m; });
    }
    ok = rows.filter(function (r) { return r.kind === 'ok'; });

    ok.forEach(function (r) { r.desired = d.timing === 'now' ? now + 10 * MIN : d.timing === 'at' ? (parseBj(d.at) || now + 10 * MIN) : localDesired(r.c, d); });
    var byBox = {}, quota = {};
    ok.forEach(function (r) { if (r.box) (byBox[r.box.id] = byBox[r.box.id] || []).push(r); else r.at = r.desired; });
    Object.keys(byBox).forEach(function (id) {
      var m = A.mailbox(+id), list = byBox[id].sort(function (a, b) { return a.desired - b.desired; }), last = 0, count = quota[id] = {};
      count[dayKey(now)] = d.usedBase ? d.usedBase[id] || 0 : m.used;
      list.forEach(function (r) {
        var t = Math.max(r.desired, last + D.settings.interval * 1000), guard = 0;
        while ((count[dayKey(t)] || 0) >= m.limit && guard++ < 10) t = nextDay(t, r, d);
        count[dayKey(t)] = (count[dayKey(t)] || 0) + 1; r.at = t; last = t;
        r.deferred = dayKey(t) !== dayKey(r.desired);
      });
      list.forEach(function (r) { var k = dayKey(r.at), pct = count[k] / m.limit; if (pct >= D.settings.quotaWarn) r.warn = '该邮箱 ' + k.slice(5) + ' 用量将到 ' + Math.round(pct * 100) + '%（' + count[k] + '/' + m.limit + '）'; });
    });

    var used = {};
    ok.forEach(function (r) { if (r.box) used[r.box.id] = r.box; });
    var start = ok.reduce(function (s, r) { return r.at && (!s || r.at < s) ? r.at : s; }, 0) || now + 10 * MIN;
    var due = start; for (var k = 0; k < D.settings.offlineDueDays; k++) { due += 24 * H; while ([0, 6].indexOf(new Date(due + 8 * H).getUTCDay()) >= 0) due += 24 * H; }
    offline.forEach(function (o) { o.executor = d.executors[o.ch === 'wechat_group' ? 'g:' + o.handle : o.ch + ':' + o.c.id] || o.c.owner || u.id; });
    return { custs: custs, rows: rows, ok: ok, offline: offline, boxes: Object.keys(used).map(function (id) { return used[id]; }), start: start, due: due, quota: quota };
  }
  function splitShares(boxes, n) {
    var share = {}, k = boxes.length; if (!k) return share;
    var base = Math.floor(n / k), rest = n - base * k;
    boxes.forEach(function (m) { share[m.id] = base; });
    boxes.slice().sort(function (a, b) { return remaining(b) - remaining(a); }).slice(0, rest).forEach(function (m) { share[m.id]++; });
    return share;
  }
  function reasons(d, p) { return A.approvalReasons(A.me(), p.boxes.map(function (m) { return m.id; }), p.ok.length); }

  function attachMB(d) { return d.attachments.reduce(function (s, f) { return s + f.mb; }, 0); }
  function stepErrors(d, p) {
    var e = [];
    e[0] = d.name.trim() ? '' : '填写推广名称';
    e[1] = !p.custs.length ? '至少选一个分组或客户' : (unowned(d).length && !d.noOwner ? '决定无归属客户怎么处理' : (!p.ok.length && !p.offline.some(function (o) { return !o.reason; }) ? '选中的客户都发不了，请调整' : ''));
    e[2] = !d.subject.trim() || !textOf(d.html).trim() ? '填写主题和正文' : (attachMB(d) > D.settings.attachMaxMB ? '附件合计超过 ' + D.settings.attachMaxMB + 'MB' : '');
    e[3] = '';
    var n = p.ok.length;
    if (d.method === 'split') e[4] = d.splitBoxes.length ? '' : '至少勾选一个邮箱';
    else if (d.method === 'single') e[4] = d.singleBox ? '' : '选择一个邮箱';
    else if (d.method === 'manual') { var sum = manualSum(d); e[4] = sum === n ? '' : '手填合计 ' + sum + ' 封，可发名单 ' + n + ' 封，' + (sum > n ? '多 ' + (sum - n) : '少 ' + (n - sum)) + ' 封'; }
    else e[4] = '';
    var untested = p.boxes.filter(function (m) { return d.tests[m.id] !== d.version; });
    e[5] = untested.length ? '还有 ' + untested.length + ' 个发件邮箱没用当前内容试发成功' : '';
    e[6] = d.timing === 'at' && (!parseBj(d.at) || parseBj(d.at) < NOW + 10 * MIN) ? '指定时间无效或早于现在' : '';
    e[7] = '';
    e[8] = '';
    return e;
  }
  function manualSum(d) { return allowedBoxes().reduce(function (s, m) { return s + (Math.max(0, parseInt(d.manual[m.id], 10) || 0)); }, 0); }

  function fill(text, r, d, mode, isHtml) {
    var src = isHtml ? text : esc(text);
    return src.replace(/\{(联系人姓名|公司名称|国家|发件人姓名)\}/g, function (all, k) {
      if (mode === 'vars' || !r) return '<span class="var">{' + k + '}</span>';
      var v = k === '联系人姓名' ? r.p.name : k === '公司名称' ? r.c.company : k === '国家' ? r.c.country : senderName(r.box);
      if (v) return '<span class="var">' + esc(v) + '</span>';
      if (k === '联系人姓名' && d.fallback.trim()) return '<span class="var var-fallback" title="联系人姓名为空，用默认称呼">' + esc(d.fallback.trim()) + '</span>';
      return '<span class="var var-empty">' + k + '为空</span>';
    });
  }
  function mailView(d, r, mode) {
    var box = r ? r.box : null;
    var from = mode === 'vars' ? '{发件邮箱}（' + methodName(d) + '）' : box ? box.email : '未分配发件邮箱';
    var to = mode === 'vars' ? '{联系人姓名} <{收件邮箱}>' : (r.p.name || '（无姓名）') + ' <' + r.p.email + '>';
    var sign = d.signature === 'none' ? '' : mode === 'vars' ? '{发件邮箱的签名}' : box ? box.sign : '';
    return '<div class="mail"><div class="mail-meta"><div><b>发件人</b>' + esc(from) + '</div><div><b>收件人</b>' + esc(to) + '</div></div>' +
      '<div class="mail-subject">' + (d.subject ? fill(d.subject, r, d, mode) : '<span class="muted">（未填写主题）</span>') + '</div>' +
      '<div class="mail-body rich">' + (d.html ? fill(d.html, r, d, mode, true) : '<span class="muted">（未填写正文）</span>') + '</div>' +
      (sign ? '<div class="mail-sign">' + esc(sign) + '</div>' : '') +
      (d.attachments.length ? '<div class="mail-files">' + d.attachments.map(function (f) { return '<span class="file">📎 ' + esc(f.name) + ' <span class="muted num">' + f.mb + 'MB</span></span>'; }).join('') + '</div>' : '') +
      '<div class="mail-foot">If you no longer wish to receive these emails, <u>unsubscribe here</u>.（系统自动加，另带 List-Unsubscribe 信头）</div></div>';
  }
  function methodName(d) { return { owner: '按客户负责人', split: '勾选邮箱均分', single: '指定一个邮箱', manual: '手填各邮箱数量' }[d.method]; }

  /* ?wzstep=N 用演示内容填好草稿并直接打开第 N 步，便于评审各步骤界面。 */
  function demoFill(d) {
    var params = new URLSearchParams(location.search), step = params.get('wzstep');
    if (step === null || ui.demoUsed) return;
    ui.demoUsed = true;
    d.name = '2026 秋季新品轨道灯'; d.groups = A.allScope() ? [21, 24] : [21]; d.noOwner = 'company';
    d.subject = 'New 2026 track lights for {公司名称}'; d.html = DEFAULT_HTML + '<br><img src="' + IMG + '" alt="产品图" style="max-width:100%;display:block;margin:8px 0">Catalogue: <a href="https://www.example-led.com/2026" target="_blank" rel="noopener">www.example-led.com/2026</a>';
    d.attachments = [DEMO_FILES[0], DEMO_FILES[1]]; d.fallback = 'Sir/Madam';
    if (params.get('wzmethod')) d.method = params.get('wzmethod');
    if (d.method === 'split') d.splitBoxes = [12, 13];
    if (params.get('wzpreview')) d.previewMode = 'customer';
    plan(d).boxes.forEach(function (m) { d.tests[m.id] = d.version; });
    d.step = Math.max(0, Math.min(8, +step)); d.reached = 8;
  }

  /* 页面 */
  function page(id) {
    var st = A.state, src = id ? A.campaign(id) : null;
    if (!st.draft || st.draft._for !== (id || 'new') || st.draft._user !== st.userId) { st.draft = newDraft(src); st.draft._for = id || 'new'; st.draft._user = st.userId; demoFill(st.draft); }
    var d = st.draft;
    A.crumbs(['推广中心', '推广', d.id ? '编辑 ' + d.id : '新建推广']);
    if (!A.canCreate()) return '<div class="card"><div class="empty">你没有“创建推广”权限（promotion.task_create）。请在统一权限中心申请。</div></div>';
    var p = plan(d), errs = stepErrors(d, p);
    var bodies = [stepName, stepAudience, stepContent, stepPreview, stepMethod, stepTest, stepTiming, stepOffline, stepConfirm];
    return (d.rejectReason ? '<div class="banner bad"><b>审批退回</b><span>' + esc(d.rejectReason) + '</span></div>' : '') +
      '<div class="page-head"><div><h1>' + (d.id ? '编辑推广' : '新建推广') + (d.name ? '<span class="muted" style="font-weight:400"> · ' + esc(d.name) + '</span>' : '') + '</h1><p>每一步都可以回头改；点“' + (reasons(d, p).length ? '提交审批' : '确认发送') + '”之前不会发出任何推广邮件（试发除外）。</p></div></div>' +
      '<div id="wz-stepper">' + stepper(d, errs) + '</div>' +
      '<div class="wz-body">' + bodies[d.step](d, p, errs) + '</div>' +
      '<div class="dock" id="wz-dock">' + dock(d, p, errs) + '</div>';
  }
  function stepper(d, errs) {
    return '<ol class="stepper">' + STEPS.map(function (s, i) {
      var cls = i === d.step ? 'on' : i <= d.reached ? (errs[i] ? 'warn' : 'done') : '';
      return '<li class="' + cls + '"><button type="button" data-step="' + i + '"' + (i > d.reached ? ' disabled' : '') + ' title="' + esc(errs[i] || s) + '"><span class="step-no">' + (cls === 'done' ? '✓' : i + 1) + '</span><span class="step-label">' + s + '</span></button></li>';
    }).join('') + '</ol>';
  }
  function dock(d, p, errs) {
    var rs = reasons(d, p), last = d.step === STEPS.length - 1, err = errs[d.step];
    var allOk = errs.every(function (e) { return !e; });
    return '<button class="btn" data-nav="prev"' + (d.step ? '' : ' disabled') + '>上一步</button>' +
      '<span class="dock-msg ' + (err ? 'n' : 'y') + '">' + (err ? '○ ' + esc(err) : '✓ 这一步已完成') + '</span><div class="spacer"></div>' +
      '<span class="approval-note ' + (rs.length ? 'need' : 'free') + '" title="' + esc(rs.join('；')) + '">' + (rs.length ? '需要审批：' + esc(rs[0]) + (rs.length > 1 ? ' 等 ' + rs.length + ' 项' : '') : '无需审批') + '</span>' +
      '<button class="btn" data-act="save">保存草稿</button>' +
      (last ? '<button class="btn primary" data-act="submit"' + (allOk ? '' : ' disabled title="还有步骤没完成"') + '>' + (rs.length ? '提交审批' : '确认发送') + '</button>'
        : '<button class="btn primary" data-nav="next"' + (err ? ' disabled title="' + esc(err) + '"' : '') + '>下一步</button>');
  }
  function card(title, side, body, extra) { return '<div class="card' + (extra ? ' ' + extra : '') + '"><div class="card-head"><h2>' + title + '</h2>' + (side || '') + '</div><div class="card-body">' + body + '</div></div>'; }
  function chipFor(r) {
    if (r.kind === 'ok') return r.deferred ? '<span class="chip warn">额度满，顺延</span>' : r.warn ? '<span class="chip warn">会发 · 额度预警</span>' : '<span class="chip ok">会发</span>';
    if (r.kind === 'offline') return '<span class="chip info">走线下</span>';
    return '<span class="chip bad">发不了</span>';
  }
  function countReasons(list) {
    var m = {}; list.forEach(function (r) { var k = r.reason.replace(/「.*」/, '「…」').replace(/负责人 .+ 没有/, '负责人没有'); m[k] = (m[k] || 0) + 1; });
    return Object.keys(m).sort(function (a, b) { return m[b] - m[a]; }).map(function (k) { return [k, m[k]]; });
  }

  /* 1 名称 */
  function stepName(d) {
    return card('推广名称', '', '<div class="field" style="max-width:560px"><label for="f-name">名称</label><input id="f-name" class="input" data-bind="name" value="' + esc(d.name) + '" placeholder="例如：2026 秋季新品轨道灯 · 欧洲经销商" maxlength="80"><span class="hint">只在内部显示，客户看不到。建议写清主题和对象，方便以后在列表里找。</span></div>');
  }

  /* 2 选客户 */
  function stepAudience(d, p) {
    var groups = A.visibleGroups(), mail = p.ok.length, skip = p.rows.filter(function (r) { return r.kind === 'skip'; }).length;
    var offOk = p.offline.filter(function (o) { return !o.reason; }).length;
    var un = unowned(d);
    var left = '<div class="seg" data-seg-wz="audTab">' + [['groups', '按分组'], ['pick', '直接勾选客户']].map(function (t) { return '<button class="' + (d.audTab === t[0] ? 'on' : '') + '" data-v="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>';
    var src;
    if (d.audTab === 'groups') {
      src = '<div class="check-list" style="margin-top:12px">' + groups.map(function (g) {
        return '<label><span class="dot" style="background:' + g.color + '"></span><input type="checkbox" data-group="' + g.id + '"' + (d.groups.indexOf(g.id) >= 0 ? ' checked' : '') + '> <span style="flex:1">' + esc(g.name) + '</span><span class="muted small num">' + A.visibleMembers(g).length + ' 个</span></label>';
      }).join('') + '</div><a class="small" href="#/groups" style="display:inline-block;margin-top:8px">管理分组</a>';
    } else {
      var q = d.pickQ.toLowerCase();
      var pool = D.customers.filter(A.visibleCustomer).filter(function (c) { return (!q || (c.company + ' ' + c.contact + ' ' + c.email).toLowerCase().indexOf(q) >= 0) && (!d.pickCountry || c.country === d.pickCountry); });
      var countries = D.customers.filter(A.visibleCustomer).map(function (c) { return c.country || '（无国家）'; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
      src = '<div class="toolbar" style="padding:12px 0 8px;border:0"><input class="input" style="flex:1;min-width:0" data-pickq placeholder="搜索公司、联系人、邮箱" value="' + esc(d.pickQ) + '"><select class="select" style="width:130px" data-pickc><option value="">全部国家</option>' + countries.map(function (v) { return '<option' + (v === (d.pickCountry || '') ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select></div>' +
        '<div class="pick-scroll">' + pool.map(function (c) { return '<label class="pick"><input type="checkbox" data-pick="' + c.id + '"' + (d.picks[c.id] ? ' checked' : '') + '><span style="flex:1;min-width:0"><span class="cell-title">' + esc(c.company) + '</span><span class="cell-sub" style="display:block">' + esc(c.country || '无国家') + ' · ' + (c.owner ? A.user(c.owner).name : '<b style="color:var(--warn)">无负责人</b>') + ' · ' + c.channels.map(function (k) { return CH_LABEL[k]; }).join('/') + '</span></span></label>'; }).join('') + '</div>' +
        '<div style="display:flex;gap:8px;margin-top:8px"><button class="btn sm" data-pickall>全选当前结果（' + pool.length + '）</button><button class="btn sm ghost" data-pickclear>清空直接勾选</button></div>';
    }
    var chans = '<div class="field" style="margin:16px 0 8px"><label>本场使用的渠道</label><div class="chan-list">' + ['email', 'wechat', 'wechat_group', 'whatsapp', 'phone'].map(function (k) { return '<label class="chan"><input type="checkbox" data-chan="' + k + '"' + (d.channels[k] ? ' checked' : '') + '> ' + CH_LABEL[k] + '</label>'; }).join('') + '</div><span class="hint">每个客户按自己的推广偏好走对应渠道；偏好里同时有邮件和微信的，会有一封邮件 + 一条微信待办。</span></div>' +
      '<label class="small" style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-allcontacts' + (d.allContacts ? ' checked' : '') + '> 发给客户下全部有效联系人（默认只发主联系人）</label>';
    var unBox = un.length ? '<div class="banner ' + (d.noOwner ? 'info' : 'warn') + '" style="margin:12px 0 0"><b>' + un.length + ' 个无归属客户</b><span>' + (d.noOwner === 'company' ? '用公司市场邮箱 ' + esc(companyBox().email) + ' 发' : d.noOwner === 'exclude' ? '本场排除' : '还没决定怎么处理') + '</span><button class="btn sm" data-noowner>' + (d.noOwner ? '修改' : '去决定') + '</button></div>' : '';
    var list = p.custs.map(function (c) {
      var er = p.rows.filter(function (r) { return r.c === c; }), off = p.offline.filter(function (o) { return o.c === c; });
      var res = er.map(function (r) { return r.kind === 'ok' ? '<span class="chip ok">邮件</span>' : r.kind === 'skip' ? '<span class="chip bad" title="' + esc(r.reason) + '">邮件发不了</span>' : ''; }).join(' ') + ' ' +
        off.map(function (o) { return o.reason ? '<span class="chip bad" title="' + esc(o.reason) + '">' + OFFLINE[o.ch] + '发不了</span>' : '<span class="chip info">' + OFFLINE[o.ch] + '</span>'; }).join(' ');
      var why = er.concat(off).filter(function (r) { return r.reason && r.kind !== 'offline'; }).map(function (r) { return r.reason; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
      return '<tr><td><div class="cell-title">' + esc(c.company) + '</div><div class="cell-sub">' + esc(c.contact || '（无联系人姓名）') + ' · ' + esc(c.country || '无国家') + '</div></td><td>' + (c.owner ? A.user(c.owner).name : '<span style="color:var(--warn)">无负责人</span>') + '</td><td class="col-wide small">' + c.channels.map(function (k) { return CH_LABEL[k]; }).join('、') + '</td><td>' + res + (why.length ? '<div class="cell-sub" style="color:var(--bad)">' + esc(why.join('；')) + '</div>' : '') + '</td></tr>';
    }).join('');
    return '<div class="wz-two">' + card('从哪里选', '', left + src + chans + unBox) +
      '<div class="card wz-list"><div class="card-head"><h2>已选客户</h2><span class="muted small num">' + p.custs.length + ' 个客户</span></div>' +
        '<div class="card-body" style="padding-bottom:12px"><div class="audience-sum"><div><b class="num">' + mail + '</b><span>封邮件</span></div><div><b class="num">' + offOk + '</b><span>条线下</span></div><div><b class="num" style="color:var(--bad)">' + skip + '</b><span>封发不了</span></div></div>' +
        (A.allScope() ? '' : '<p class="hint" style="margin:8px 0 0">你的客户数据范围是“本人”，只列出你负责的客户。</p>') + '</div>' +
        (p.custs.length ? '<div class="table-wrap sel-wrap"><table><thead><tr><th>客户</th><th>负责人</th><th class="col-wide">推广偏好</th><th>本场结果</th></tr></thead><tbody>' + list + '</tbody></table></div>' : '<div class="empty">左边选分组或勾选客户</div>') + '</div></div>';
  }

  /* 3 编邮件 */
  function stepContent(d) {
    var imgs = (d.html.match(/<img /g) || []).length, mb = attachMB(d);
    var tb = '<div class="rt-bar">' +
      [['bold', '<b>B</b>', '加粗'], ['italic', '<i>I</i>', '斜体'], ['underline', '<u>U</u>', '下划线'], ['insertUnorderedList', '• 列表', '项目列表']].map(function (b) { return '<button type="button" class="btn sm ghost" data-cmd="' + b[0] + '" title="' + b[2] + '">' + b[1] + '</button>'; }).join('') +
      '<span class="rt-sep"></span><button type="button" class="btn sm ghost" data-rt="link">🔗 超链接</button><button type="button" class="btn sm ghost" data-rt="image">🖼 图片</button><button type="button" class="btn sm ghost" data-rt="attach">📎 附件</button>' +
      '<span class="rt-sep"></span>' + VARS.map(function (v) { return '<button type="button" class="btn sm ghost var-btn" data-var="' + v + '">{' + v + '}</button>'; }).join('') + '</div>';
    return '<div class="wz-two wide-left">' + card('邮件内容', '<span class="muted small num">内容 v' + d.version + '</span>',
      '<div class="field"><label for="f-subject">主题</label><input id="f-subject" class="input" data-bind="subject" data-varfield value="' + esc(d.subject) + '" placeholder="例如：New 2026 track lights for {公司名称}"></div>' +
      '<div class="field"><label>正文</label>' + tb + '<div class="editor rich" contenteditable="true" data-editor>' + d.html + '</div><span class="hint">变量按钮会插在光标处（主题或正文）。改了主题、正文、图片或附件后，之前的试发作废，需要重新试发。</span></div>' +
      (d.attachments.length ? '<div class="field"><label>附件 <span class="muted small num">' + d.attachments.length + '/' + D.settings.attachMaxCount + ' 个 · 合计 ' + mb.toFixed(1) + '/' + D.settings.attachMaxMB + 'MB</span></label><div class="files">' + d.attachments.map(function (f, i) { return '<span class="file">📎 ' + esc(f.name) + ' <span class="muted num">' + f.mb + 'MB</span><button type="button" class="file-x" data-rmfile="' + i + '" aria-label="删除附件">×</button></span>'; }).join('') + '</div></div>' : '')) +
      card('签名与空变量', '',
        '<div class="field"><label>签名</label><select class="select" data-bind-change="signature"><option value="mailbox"' + (d.signature === 'mailbox' ? ' selected' : '') + '>跟着发件邮箱走（用邮箱系统里的签名）</option><option value="none"' + (d.signature === 'none' ? ' selected' : '') + '>不加签名</option></select><span class="hint">按负责人发时，每封带的是实际发件人的签名，不会出现 A 的邮箱带 B 的签名。</span></div>' +
        '<div class="field"><label for="f-fb">联系人姓名为空时称呼</label><input id="f-fb" class="input" data-bind-change="fallback" value="' + esc(d.fallback) + '" placeholder="例如：Sir/Madam"><span class="hint">留空 = 这些客户不发，列进“发不了”名单。公司名称在客户中心是必填，不会为空。</span></div>' +
        '<div class="field"><label>图片与附件限制</label><ul class="rule-list"><li>图片内嵌在邮件里（不外链），单张超过 ' + D.settings.imageMaxKB + 'KB 自动压缩，正文图片合计 ≤ ' + D.settings.imageTotalMB + 'MB；当前 ' + imgs + ' 张</li><li>附件最多 ' + D.settings.attachMaxCount + ' 个，合计 ≤ ' + D.settings.attachMaxMB + 'MB；更大的文件请放网盘发链接</li><li>退订链接与退订信头由系统自动加</li></ul></div>') + '</div>';
  }

  /* 4 预览 */
  function previewRows(d, p) {
    var list = p.rows.filter(function (r) { return r.kind === 'ok' || /联系人姓名为空/.test(r.reason); });
    if (d.previewIssues) list = list.filter(function (r) { return !r.p.name; });
    return list;
  }
  function stepPreview(d, p) {
    var list = previewRows(d, p), i = Math.min(d.previewIdx, Math.max(0, list.length - 1)), r = list[i];
    var head = '<div class="seg" data-seg-wz="previewMode"><button class="' + (d.previewMode === 'vars' ? 'on' : '') + '" data-v="vars">看变量名</button><button class="' + (d.previewMode === 'customer' ? 'on' : '') + '" data-v="customer">代入客户</button></div>';
    var ctl = d.previewMode === 'customer' ? '<div class="toolbar" style="border:0;padding:0 0 12px"><button class="btn sm" data-pv="-1"' + (i > 0 ? '' : ' disabled') + '>上一个</button><select class="select" style="flex:1;min-width:0" data-pvsel>' + list.map(function (x, k) { return '<option value="' + k + '"' + (k === i ? ' selected' : '') + '>' + esc(x.c.company + (x.p.main ? '' : ' · ' + x.p.name) + (x.p.name ? '' : ' ⚠ 无姓名')) + '</option>'; }).join('') + '</select><button class="btn sm" data-pv="1"' + (i < list.length - 1 ? '' : ' disabled') + '>下一个</button><label class="small" style="display:flex;gap:6px;align-items:center;white-space:nowrap"><input type="checkbox" data-pvissues' + (d.previewIssues ? ' checked' : '') + '> 只看缺姓名的</label></div>' : '';
    var body = d.previewMode === 'vars' ? mailView(d, null, 'vars') : r ? mailView(d, r, 'customer') : '<div class="empty">' + (d.previewIssues ? '没有缺姓名的客户' : '没有可以代入的客户') + '</div>';
    var note = d.previewMode === 'vars' ? '<p class="hint" style="margin:12px 0 0">蓝色是变量，发送时替换成每个客户的内容。切到“代入客户”逐个看成品。</p>' :
      '<p class="hint" style="margin:12px 0 0">黄色 = 用了默认称呼；红色 = 变量为空，这个客户不会发（在“发送时间”的名单里能看到）。发件人和签名按第 5 步的发信方式显示。</p>';
    return '<div class="wz-preview">' + card('预览', head, ctl + body + note) + '</div>';
  }

  /* 5 发信方式 */
  function stepMethod(d, p) {
    var n = p.ok.length, boxes = allowedBoxes(), u = A.me(), body = '';
    var cards = '<div class="radio-cards">' + A.radioCard('method', 'owner', d.method, '按客户负责人', '每个客户由自己的负责人邮箱发') + A.radioCard('method', 'split', d.method, '勾选邮箱均分', '在勾选的邮箱之间平均分') + A.radioCard('method', 'single', d.method, '指定一个邮箱', '全部由这一个邮箱发') + A.radioCard('method', 'manual', d.method, '手填各邮箱数量', '自己定每个邮箱发多少') + '</div><p class="hint" style="margin:8px 0 16px">一场推广只能选一种，不能混用。可发名单 <b class="num">' + n + '</b> 封。</p>';
    function boxRow(m, right) { return '<tr><td><div class="cell-title small">' + esc(m.email) + '</div><div class="cell-sub">' + (m.owner ? A.user(m.owner).name : '公司邮箱') + (m.owner !== u.id ? ' · <span style="color:var(--warn)">非本人邮箱，需审批</span>' : '') + '</div></td><td class="num small">今日剩余 ' + remaining(m) + ' / ' + m.limit + '</td><td style="text-align:right">' + right + '</td></tr>'; }
    var noBox = boxes.length ? '' : '<div class="banner warn" style="margin:0 0 12px">你还没有绑定发件邮箱，只能“按客户负责人”发；要用其他方式，先在邮箱系统绑定邮箱。</div>';
    if (d.method !== 'owner' && !boxes.length) return card('选发信方式', '', cards + noBox);
    if (d.method === 'owner') {
      var by = {};
      p.rows.forEach(function (r) { if (r.kind !== 'ok' && !/负责人|无归属客户，还没/.test(r.reason)) return; var k = r.c.owner || 0; by[k] = by[k] || { n: 0, bad: '' }; if (r.kind === 'ok') by[k].n++; else by[k].bad = r.reason; });
      body = '<div class="table-wrap"><table><thead><tr><th>负责人</th><th>发件邮箱</th><th>封数</th><th></th></tr></thead><tbody>' + Object.keys(by).map(function (k) {
        var uid = +k, m = uid ? ownerBox(uid) : (d.noOwner === 'company' ? companyBox() : null);
        return '<tr><td>' + (uid ? A.user(uid).name : '无归属客户') + '</td><td class="small">' + (m ? esc(m.email) : '<span style="color:var(--bad)">' + (uid ? '没有可用邮箱' : d.noOwner === 'exclude' ? '本场排除' : '未决定') + '</span>') + '</td><td class="num">' + by[k].n + '</td><td class="small muted">' + (m ? '今日剩余 ' + remaining(m) : esc(by[k].bad)) + '</td></tr>';
      }).join('') + '</tbody></table></div><p class="hint" style="margin-top:8px">负责人没有可用邮箱的客户列进“发不了”；要发给他们，换成其他发信方式。</p>';
    } else if (d.method === 'split') {
      var chosen = d.splitBoxes.map(A.mailbox).filter(Boolean), share = splitShares(chosen, n);
      body = '<div class="table-wrap"><table><thead><tr><th>邮箱</th><th>额度</th><th style="text-align:right">分到</th></tr></thead><tbody>' + boxes.map(function (m) { var on = d.splitBoxes.indexOf(m.id) >= 0; return boxRow(m, '<label style="display:inline-flex;gap:8px;align-items:center"><b class="num">' + (on ? share[m.id] : '—') + '</b><input type="checkbox" data-split="' + m.id + '"' + (on ? ' checked' : '') + '></label>'); }).join('') + '</tbody></table></div>' +
        '<p class="hint" style="margin-top:8px">除不尽的余数，每个邮箱 1 封，优先给今天剩余额度多的邮箱。分到的超过今天剩余额度时，超出部分顺延到下一个工作日。</p>';
    } else if (d.method === 'single') {
      body = '<div class="table-wrap"><table><tbody>' + boxes.map(function (m) { return boxRow(m, '<input type="radio" name="single" data-single="' + m.id + '"' + (d.singleBox === m.id ? ' checked' : '') + '>'); }).join('') + '</tbody></table></div>' + (d.singleBox && n > remaining(A.mailbox(d.singleBox)) ? '<div class="banner warn" style="margin-top:12px">今天只剩 ' + remaining(A.mailbox(d.singleBox)) + ' 封额度，其余 ' + (n - remaining(A.mailbox(d.singleBox))) + ' 封会顺延到下一个工作日。</div>' : '');
    } else {
      var sum = manualSum(d);
      body = '<div class="table-wrap"><table><thead><tr><th>邮箱</th><th>额度</th><th style="text-align:right">发多少</th></tr></thead><tbody>' + boxes.map(function (m) { return boxRow(m, '<input class="input num" style="width:90px;height:30px;text-align:right" data-manual="' + m.id + '" value="' + esc(d.manual[m.id] || '') + '" inputmode="numeric" placeholder="0">'); }).join('') + '</tbody></table></div>' +
        '<div class="manual-sum ' + (sum === n ? 'y' : 'n') + '">合计 <b class="num">' + sum + '</b> / 可发 <b class="num">' + n + '</b> 封' + (sum === n ? ' ✓' : '：对不上不能进入下一步') + '<button class="btn sm" data-manualfit>按今日剩余额度比例自动填</button></div>' +
        '<p class="hint">不会悄悄替你改数；要自动分配，点上面的按钮，填完仍可手改。</p>';
    }
    return card('选发信方式', '', cards + body);
  }

  /* 6 试发 */
  function stepTest(d, p) {
    var sample = p.ok[Math.min(d.testSample, Math.max(0, p.ok.length - 1))];
    var rows = p.boxes.map(function (m) {
      var st = d.tests[m.id] === d.version ? '<span class="chip ok">已成功（v' + d.version + '）</span>' : d.tests[m.id] ? '<span class="chip warn">内容已改，需重发</span>' : '<span class="chip">未试发</span>';
      return '<tr><td class="small">' + esc(m.email) + '</td><td>' + (m.owner ? A.user(m.owner).name : '公司邮箱') + '</td><td class="num small">' + p.ok.filter(function (r) { return r.box === m; }).length + ' 封</td><td>' + st + '</td><td style="text-align:right"><button class="btn sm" data-testbox="' + m.id + '">试发</button></td></tr>';
    }).join('');
    return card('试发', '<button class="btn sm primary" data-testall' + (p.boxes.length ? '' : ' disabled') + '>全部试发</button>',
      '<div class="row"><div class="field"><label for="f-testto">试发给谁（最多 ' + D.settings.testMaxTo + ' 个，逗号分隔）</label><input id="f-testto" class="input" data-bind="testTo" value="' + esc(d.testTo) + '" placeholder="you@example-led.com"></div>' +
      '<div class="field"><label>用哪个客户代入变量</label><select class="select" data-testsample>' + p.ok.slice(0, 60).map(function (r, k) { return '<option value="' + k + '"' + (k === d.testSample ? ' selected' : '') + '>' + esc(r.c.company) + '</option>'; }).join('') + '</select></div></div>' +
      (p.boxes.length ? '<div class="table-wrap"><table><thead><tr><th>正式要用的发件邮箱</th><th>归属</th><th>本场</th><th>试发结果</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div class="empty">本场没有邮件要发，不需要试发。</div>') +
      '<ul class="rule-list" style="margin-top:12px"><li>每个正式要用的邮箱，都要用当前内容版本至少成功试发一封</li><li>试发走原邮箱系统真发；主题前加 [测试]，不计推广量，不写客户时间线</li><li>“成功”以邮箱系统返回的发送成功为准；结果不确定的不算</li>' + (sample ? '<li>当前代入：' + esc(sample.c.company) + '</li>' : '') + '</ul>');
  }

  /* 7 发送时间 */
  function stepTiming(d, p) {
    var rs = reasons(d, p);
    var cards = '<div class="radio-cards">' + A.radioCard('timing', 'now', d.timing, '马上发', rs.length ? '批准后 10 分钟开始' : '确认后 10 分钟开始') + A.radioCard('timing', 'at', d.timing, '指定时间发', '按北京时间') + A.radioCard('timing', 'local', d.timing, '按客户国家工作时间', '每个客户当地工作日的同一时刻') + '</div>';
    var input = d.timing === 'at' ? '<div class="field"><label for="f-at">北京时间</label><input id="f-at" class="input num" data-bind-change="at" value="' + esc(d.at) + '" placeholder="2026-10-14 09:00"></div>' :
      d.timing === 'local' ? '<div class="field"><label for="f-lt">客户当地时间</label><input id="f-lt" class="input num" data-bind-change="localTime" value="' + esc(d.localTime) + '" placeholder="09:30"><span class="hint">遇当地周末顺延（沙特、卡塔尔周五六休）</span></div>' : '<div class="field"><label>开始</label><div class="small" style="padding-top:8px">' + (rs.length ? '批准后 10 分钟' : '确认后 10 分钟') + '</div></div>';
    var all = p.rows, f = d.rowFilter;
    var shown = all.filter(function (r) { return f === 'all' || (f === 'ok' && r.kind === 'ok') || (f === 'skip' && r.kind === 'skip') || (f === 'offline' && r.kind === 'offline') || (f === 'warn' && (r.deferred || r.warn)); });
    var cnt = { ok: p.ok.length, skip: all.filter(function (r) { return r.kind === 'skip'; }).length, offline: all.filter(function (r) { return r.kind === 'offline'; }).length, warn: p.ok.filter(function (r) { return r.deferred || r.warn; }).length };
    var rsn = countReasons(all.filter(function (r) { return r.kind === 'skip'; }));
    var table = '<div class="toolbar"><div class="seg" data-seg-wz="rowFilter">' + [['all', '全部 ' + all.length], ['ok', '会发 ' + cnt.ok], ['skip', '发不了 ' + cnt.skip], ['warn', '顺延/预警 ' + cnt.warn], ['offline', '走线下 ' + cnt.offline]].map(function (x) { return '<button class="' + (f === x[0] ? 'on' : '') + '" data-v="' + x[0] + '">' + x[1] + '</button>'; }).join('') + '</div><div class="spacer"></div><button class="btn sm">导出 Excel</button></div>' +
      (rsn.length && (f === 'all' || f === 'skip') ? '<div class="reason-bar">' + rsn.map(function (x) { return '<span class="gtag">' + esc(x[0]) + ' <b class="num">' + x[1] + '</b></span>'; }).join('') + '</div>' : '') +
      '<div class="table-wrap plan-wrap"><table class="plan"><thead><tr><th>客户 / 联系人</th><th>收件邮箱</th><th>国家 · 当地时间</th><th>发件邮箱</th><th>计划发送（北京时间）</th><th>状态 / 原因</th></tr></thead><tbody>' +
      (shown.length ? shown.map(function (r) {
        var z = zone(r.c), loc = r.at ? fmt(r.at, z.off) + ' 周' + WEEK[new Date(r.at + z.off * H).getUTCDay()] : '';
        return '<tr class="' + (r.kind === 'skip' ? 'row-bad' : r.deferred || r.warn ? 'row-warn' : '') + '"><td><div class="cell-title">' + esc(r.c.company) + '</div><div class="cell-sub">' + esc(r.p.name || '（无姓名）') + (r.p.main ? '' : ' · 其他联系人') + '</div></td><td class="small">' + esc(r.p.email || '—') + '</td><td class="small">' + esc(r.c.country || '无国家') + '<div class="cell-sub' + (z.src === 'default' ? '" style="color:var(--warn)' : '') + '">' + esc(z.label) + (loc && r.kind === 'ok' ? ' · ' + loc : '') + '</div></td><td class="small">' + (r.box ? esc(r.box.email) : r.kind === 'ok' ? '<span style="color:var(--bad)">未分配</span>' : '—') + '</td><td class="num small">' + (r.kind === 'ok' && r.at ? bj(r.at) : '—') + '</td><td>' + chipFor(r) + (r.reason ? '<div class="cell-sub">' + esc(r.reason) + '</div>' : '') + (r.deferred ? '<div class="cell-sub">当天额度已满</div>' : '') + (r.warn ? '<div class="cell-sub" style="color:var(--warn)">' + esc(r.warn) + '</div>' : '') + '</td></tr>';
      }).join('') : '<tr><td colspan="6"><div class="empty">没有符合条件的对象</div></td></tr>') + '</tbody></table></div>';
    var last = p.ok.reduce(function (s, r) { return r.at > s ? r.at : s; }, 0);
    return card('什么时候发', '', '<div class="timing-row">' + cards + input + '</div>' + (rs.length ? '<div class="banner warn" style="margin:12px 0 0">这场需要审批：批准之前不会发出。指定时间如果在批准前就过了，批准时要重新选时间，不会补发。</div>' : ''), 'compact') +
      '<div class="card" style="margin-top:16px"><div class="card-head"><h2>全员发送计划</h2><span class="small muted">' + (cnt.ok ? '首封 ' + bj(p.start) + ' · 末封 ' + bj(last) : '没有会发的邮件') + '</span></div>' + table + '</div>';
  }

  /* 8 线下明细 */
  function offlineGroups(p) {
    var out = [], byGroup = {};
    p.offline.forEach(function (o) {
      if (o.ch === 'wechat_group' && !o.reason) { var g = byGroup[o.handle]; if (!g) { g = byGroup[o.handle] = { ch: 'wechat_group', handle: o.handle, custs: [], executor: o.executor, key: 'g:' + o.handle }; out.push(g); } g.custs.push(o.c); }
      else out.push({ ch: o.ch, handle: o.handle, custs: [o.c], executor: o.executor, reason: o.reason, key: o.ch + ':' + o.c.id });
    });
    return out;
  }
  function scriptText(r, d) {
    if (r.reason) return '';
    var c = r.custs[0], group = r.ch === 'wechat_group';
    return d.scripts[r.ch].replace(/\{(联系人姓名|公司名称|国家|发件人姓名)\}/g, function (a, k) {
      if (k === '联系人姓名') return group ? 'all' : (c.contact || d.fallback.trim() || 'there');
      if (k === '公司名称') return group ? 'everyone' : c.company;
      if (k === '国家') return c.country;
      return A.user(r.executor).name;
    });
  }
  function stepOffline(d, p) {
    var rows = offlineGroups(p), used = {};
    rows.forEach(function (r) { if (!r.reason) used[r.ch] = 1; });
    var execs = D.users.filter(function (x) { return x.perms.indexOf('execute') >= 0; });
    var scripts = Object.keys(OFFLINE).filter(function (k) { return used[k]; }).map(function (k) { return '<div class="field"><label>' + OFFLINE[k] + (k === 'phone' ? ' 通话要点' : ' 话术') + '</label><textarea class="textarea" data-script="' + k + '" rows="2" style="min-height:52px">' + esc(d.scripts[k]) + '</textarea></div>'; }).join('');
    var list = rows.map(function (r, i) {
      var c = r.custs[0], text = scriptText(r, d);
      return '<tr class="' + (r.reason ? 'row-bad' : '') + '"><td><span class="chip ' + (r.reason ? 'bad' : 'info') + '">' + OFFLINE[r.ch] + '</span></td><td>' + (r.ch === 'wechat_group' && !r.reason ? '<div class="cell-title">' + esc(r.handle) + '</div><div class="cell-sub">群里 ' + r.custs.length + ' 个本场客户：' + esc(r.custs.map(function (x) { return x.company; }).join('、')) + '</div>' : '<div class="cell-title">' + esc(c.company) + '</div><div class="cell-sub">' + esc(c.contact || '（无姓名）') + '</div>') + '</td>' +
        '<td class="small">' + esc(r.handle || '—') + '</td><td>' + (r.reason ? '—' : '<select class="select" style="height:30px;width:auto" data-exec="' + esc(r.key) + '">' + execs.map(function (x) { return '<option value="' + x.id + '"' + (x.id === r.executor ? ' selected' : '') + '>' + x.name + '</option>'; }).join('') + '</select>') + '</td>' +
        '<td class="num small">' + (r.reason ? '—' : bj(p.due).slice(0, 5)) + '</td>' +
        '<td class="script-cell">' + (r.reason ? '<span class="small" style="color:var(--bad)">发不了：' + esc(r.reason) + '</span>' : '<div class="script-text small">' + esc(text) + '</div><button class="btn sm" data-copy="' + i + '">复制</button>') + '</td></tr>';
    }).join('');
    ui.copyTexts = rows.map(function (r) { return scriptText(r, d); });
    return (rows.length ? card('话术模板', '<span class="small muted">变量：{联系人姓名} {公司名称} {国家} {发件人姓名}（= 执行人）</span>', '<div class="script-grid">' + (scripts || '<p class="muted small">没有可执行的线下对象。</p>') + '</div>' +
      '<p class="hint" style="margin:4px 0 0">线下清单与邮件明细分开，推广开始后进入执行人的“人工待办”。默认执行人是客户负责人（无归属的是你），可逐条改；截止为首封邮件日起 ' + D.settings.offlineDueDays + ' 个工作日，逾期标红；完成时必须上传截图并选结果；微信群按群合并成一条。</p>', 'compact') : '') +
      '<div class="card" style="margin-top:16px"><div class="card-head"><h2>线下执行清单</h2><span class="small muted num">' + rows.filter(function (r) { return !r.reason; }).length + ' 条可执行 · ' + rows.filter(function (r) { return r.reason; }).length + ' 条发不了</span></div>' +
      (rows.length ? '<div class="table-wrap"><table class="offline"><thead><tr><th>渠道</th><th>客户 / 群</th><th>联系方式</th><th>执行人</th><th>截止</th><th>话术</th></tr></thead><tbody>' + list + '</tbody></table></div>' : '<div class="empty">本场没有线下对象。客户偏好里有微信、微信群、WhatsApp、电话的，会出现在这里。</div>') + '</div>';
  }
  /* 9 确认 */
  function stepConfirm(d, p, errs) {
    var rs = reasons(d, p), last = p.ok.reduce(function (s, r) { return r.at > s ? r.at : s; }, 0);
    var off = offlineGroups(p).filter(function (r) { return !r.reason; }), byCh = {};
    off.forEach(function (r) { byCh[r.ch] = (byCh[r.ch] || 0) + 1; });
    var skip = p.rows.filter(function (r) { return r.kind === 'skip'; }).length + p.offline.filter(function (o) { return o.reason; }).length;
    var list = errs.map(function (e, i) { return i < 8 ? '<li class="' + (e ? 'n' : 'y') + '"><button type="button" class="linkish" data-step="' + i + '">' + (e ? '○ ' : '✓ ') + (i + 1) + '. ' + STEPS[i] + (e ? '：' + esc(e) : '') + '</button></li>' : ''; }).join('');
    return '<div class="wz-two">' + card('这场推广', '',
      '<dl class="kv"><dt>名称</dt><dd>' + esc(d.name) + '</dd><dt>邮件</dt><dd><b class="num">' + p.ok.length + '</b> 封 · ' + methodName(d) + ' · ' + p.boxes.length + ' 个邮箱</dd><dt>时间</dt><dd>' + (p.ok.length ? bj(p.start) + ' 至 ' + bj(last) + (d.timing === 'local' ? '（按客户当地 ' + esc(d.localTime) + '）' : '') : '—') + '</dd>' +
      '<dt>线下</dt><dd>' + (off.length ? Object.keys(byCh).map(function (k) { return OFFLINE[k] + ' ' + byCh[k] + ' 条'; }).join('、') : '无') + '</dd><dt>发不了</dt><dd>' + skip + ' 个（邮件与线下合计，原因见第 7、8 步）</dd><dt>审批</dt><dd>' + (rs.length ? '<span style="color:var(--warn)">需要：' + esc(rs.join('；')) + '</span>' : '无需审批') + '</dd></dl>' +
      '<div class="banner info" style="margin:12px 0 0">点“' + (rs.length ? '提交审批' : '确认发送') + '”后，名单、内容、发件邮箱和时间计划全部冻结；之后分组怎么改都不影响这场。' + (rs.length ? '退回后可修改再提交。' : '开始前可以取消。') + '</div>') +
      card('检查', '', '<ul class="check-steps">' + list + '</ul>') + '</div>';
  }

  /* 交互 */
  function refreshChrome() {
    var d = A.state.draft, p = plan(d), errs = stepErrors(d, p);
    $('#wz-stepper').innerHTML = stepper(d, errs);
    $('#wz-dock').innerHTML = dock(d, p, errs);
    bindChrome();
  }
  function bumpIfTested(d) { if (Object.keys(d.tests).some(function (k) { return d.tests[k] === d.version; })) d.version++; }
  function go(i) { var d = A.state.draft; d.step = i; d.reached = Math.max(d.reached, i); A.route(); window.scrollTo(0, 0); }
  function bindChrome() {
    var d = A.state.draft;
    $all('[data-step]').forEach(function (b) { b.addEventListener('click', function () { go(+b.getAttribute('data-step')); }); });
    $all('[data-nav]').forEach(function (b) { b.addEventListener('click', function () { var next = d.step + (b.getAttribute('data-nav') === 'next' ? 1 : -1); if (next === 2 && b.getAttribute('data-nav') === 'next' && unowned(d).length && !d.noOwner) { askNoOwner(function () { go(next); }); return; } go(next); }); });
    act('save', function () { A.toast('草稿已保存（原型不写入数据库）'); });
    act('submit', submit);
  }
  function act(name, fn) { $all('[data-act="' + name + '"]').forEach(function (b) { b.addEventListener('click', fn); }); }
  var savedRange = null;
  function saveRange() { var s = window.getSelection(), ed = $('[data-editor]'); if (s.rangeCount && ed && ed.contains(s.getRangeAt(0).commonAncestorContainer)) savedRange = s.getRangeAt(0).cloneRange(); }
  function restoreRange() { var ed = $('[data-editor]'); ed.focus(); if (savedRange) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(savedRange); } }
  function editorChanged() { var d = A.state.draft, ed = $('[data-editor]'); d.html = ed.innerHTML; bumpIfTested(d); refreshChrome(); }

  function askNoOwner(after) {
    var d = A.state.draft, un = unowned(d), canCo = A.can('use_any_mailbox'), co = companyBox();
    A.modal({ title: un.length + ' 个客户没有负责人', ok: '确定',
      body: '<p class="small">这些客户没有归属业务员：' + esc(un.slice(0, 6).map(function (c) { return c.company; }).join('、')) + (un.length > 6 ? ' 等' : '') + '。这场推广里怎么处理？</p><div class="check-list">' +
        '<label' + (canCo ? '' : ' class="disabled-opt"') + '><input type="radio" name="no" value="company"' + (d.noOwner === 'company' ? ' checked' : '') + (canCo ? '' : ' disabled') + '> <span>用公司市场邮箱 ' + esc(co.email) + ' 发' + (canCo ? (A.can('approve') ? '' : '<span class="cell-sub" style="display:block">用公司邮箱需要审批</span>') : '<span class="cell-sub" style="display:block">你没有“使用他人或公司邮箱”权限</span>') + '</span></label>' +
        '<label><input type="radio" name="no" value="exclude"' + (d.noOwner === 'exclude' ? ' checked' : '') + '> 本场排除（列入“发不了”名单）</label></div><p class="hint">线下渠道的无归属客户默认由你执行。只在“按客户负责人”发信时用到公司邮箱；选其他发信方式时，他们跟其他客户一样分配。</p><p class="form-error small hidden" style="color:var(--bad)"></p>',
      onOk: function (w) { var v = $('input[name=no]:checked', w); if (!v) return '请选择一种处理方式'; d.noOwner = v.value; A.route(); if (after) after(); } });
  }
  function testSend(ids) {
    var d = A.state.draft, to = d.testTo.split(/[,，;\s]+/).filter(Boolean);
    if (!to.length) return A.toast('先填写试发给谁');
    if (to.length > D.settings.testMaxTo) return A.toast('试发最多 ' + D.settings.testMaxTo + ' 个收件人');
    if (to.some(function (x) { return !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(x); })) return A.toast('试发邮箱格式不对');
    ids.forEach(function (id) { d.tests[id] = d.version; });
    A.route();
    A.toast('已通过原邮箱系统试发 ' + ids.length * to.length + ' 封（原型演示，均成功）');
  }
  function submit() {
    var d = A.state.draft, p = plan(d), rs = reasons(d, p), u = A.me();
    var off = offlineGroups(p).filter(function (r) { return !r.reason; });
    var skip = p.rows.filter(function (r) { return r.kind === 'skip'; }).length + p.offline.filter(function (o) { return o.reason; }).length;
    A.modal({ title: rs.length ? '提交审批' : '确认发送', ok: rs.length ? '提交审批' : '确认发送',
      body: '<p>' + p.ok.length + ' 封邮件' + (off.length ? '、' + off.length + ' 条线下待办' : '') + '，' + skip + ' 个发不了。首封 ' + (p.ok.length ? bj(p.start) : '—') + '。</p><p class="small muted">' + (rs.length ? '审批人看到的就是现在冻结的名单和内容；批准后按计划开始。' : '确认后冻结并排队。开始前可以取消；已交给邮件服务器的邮件无法撤回。') + '</p>',
      onOk: function () {
        var id = d.id && A.campaign(d.id) ? d.id : 'pm-' + (1018 + D.campaigns.length), c = A.campaign(id);
        var fields = { id: id, source: 'pm', name: d.name, channel: off.length ? 'preference' : 'email', status: rs.length ? 'pending_approval' : 'scheduled', createdBy: u.id, owners: [u.id], createdAt: '2026-10-13 14:10', startAt: bj(p.start).slice(0, 11).replace(/^/, '2026-'), subject: d.subject, mailboxes: p.boxes.map(function (m) { return m.id; }), counts: { planned: p.ok.length, sent: 0, failed: 0, uncertain: 0, skipped: skip, manualPending: off.length, manualDone: 0 }, approvalReasons: rs };
        if (c) { Object.keys(fields).forEach(function (k) { c[k] = fields[k]; }); delete c.rejectReason; } else D.campaigns.unshift(fields);
        off.forEach(function (r, i) { var cu = r.custs[0]; D.manualTasks.push({ id: 1000 + D.manualTasks.length + i, campaign: id, company: r.ch === 'wechat_group' ? r.handle : cu.company, contact: r.ch === 'wechat_group' ? r.custs.length + ' 个客户' : cu.contact, channel: OFFLINE[r.ch], handle: r.handle, executor: r.executor, due: '2026-' + bj(p.due).slice(0, 5), status: 'waiting' }); });
        A.state.draft = null;
        location.hash = '#/detail/' + id;
        A.toast(rs.length ? '已提交审批，名单和内容已冻结' : '已确认，名单和内容已冻结');
      } });
  }

  function bind() {
    var d = A.state.draft; if (!d || !$('#wz-stepper')) return;
    bindChrome();
    $all('[data-bind]').forEach(function (el) { el.addEventListener('input', function () { var k = el.getAttribute('data-bind'); d[k] = el.value; if (k === 'subject') bumpIfTested(d); refreshChrome(); }); });
    $all('[data-bind-change]').forEach(function (el) { el.addEventListener('change', function () { d[el.getAttribute('data-bind-change')] = el.value; A.route(); }); });
    $all('[data-radio]').forEach(function (el) { el.addEventListener('click', function () { d[el.getAttribute('data-radio')] = el.getAttribute('data-v'); A.route(); }); });
    $all('[data-seg-wz]').forEach(function (seg) { $all('button', seg).forEach(function (b) { b.addEventListener('click', function () { d[seg.getAttribute('data-seg-wz')] = b.getAttribute('data-v'); A.route(); }); }); });
    function afterPick() { A.route(); if (unowned(d).length && !d.noOwner) askNoOwner(); }
    $all('[data-group]').forEach(function (el) { el.addEventListener('change', function () { var id = +el.getAttribute('data-group'); d.groups = el.checked ? d.groups.concat(id) : d.groups.filter(function (x) { return x !== id; }); afterPick(); }); });
    $all('[data-pick]').forEach(function (el) { el.addEventListener('change', function () { d.picks[el.getAttribute('data-pick')] = el.checked; afterPick(); }); });
    var pq = $('[data-pickq]'); if (pq) pq.addEventListener('change', function () { d.pickQ = pq.value; A.route(); });
    var pc = $('[data-pickc]'); if (pc) pc.addEventListener('change', function () { d.pickCountry = pc.value === '（无国家）' ? '' : pc.value; A.route(); });
    var pa = $('[data-pickall]'); if (pa) pa.addEventListener('click', function () { $all('[data-pick]').forEach(function (el) { d.picks[el.getAttribute('data-pick')] = true; }); afterPick(); });
    var pclr = $('[data-pickclear]'); if (pclr) pclr.addEventListener('click', function () { d.picks = {}; A.route(); });
    $all('[data-chan]').forEach(function (el) { el.addEventListener('change', function () { d.channels[el.getAttribute('data-chan')] = el.checked; A.route(); }); });
    var ac = $('[data-allcontacts]'); if (ac) ac.addEventListener('change', function () { d.allContacts = ac.checked; A.route(); });
    var no = $('[data-noowner]'); if (no) no.addEventListener('click', function () { askNoOwner(); });

    var ed = $('[data-editor]');
    if (ed) {
      ['keyup', 'mouseup', 'blur'].forEach(function (ev) { ed.addEventListener(ev, saveRange); });
      ed.addEventListener('input', function () { saveRange(); editorChanged(); });
      ed.addEventListener('focus', function () { ui.focus = 'editor'; });
      var subj = $('[data-varfield]'); subj.addEventListener('focus', function () { ui.focus = 'subject'; });
      $all('[data-cmd]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('click', function () { restoreRange(); document.execCommand(b.getAttribute('data-cmd')); editorChanged(); }); });
      $all('[data-var]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('click', function () {
        var v = '{' + b.getAttribute('data-var') + '}';
        if (ui.focus === 'subject') { var s = subj.selectionStart != null ? subj.selectionStart : subj.value.length; subj.value = subj.value.slice(0, s) + v + subj.value.slice(subj.selectionEnd || s); d.subject = subj.value; bumpIfTested(d); subj.focus(); subj.setSelectionRange(s + v.length, s + v.length); refreshChrome(); }
        else { restoreRange(); document.execCommand('insertText', false, v); editorChanged(); }
      }); });
      $all('[data-rt]').forEach(function (b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); });
      $('[data-rt="link"]').addEventListener('click', function () {
        saveRange(); var sel = savedRange ? savedRange.toString() : '';
        A.modal({ title: '插入超链接', ok: '插入', body: '<div class="field"><label>显示文字</label><input class="input" data-lt value="' + esc(sel || 'Download catalogue') + '"></div><div class="field"><label>链接地址</label><input class="input" data-lu placeholder="https://"></div><p class="form-error small hidden" style="color:var(--bad)"></p>',
          onOk: function (w) { var t = $('[data-lt]', w).value.trim(), url = $('[data-lu]', w).value.trim(); if (!t) return '请填写显示文字'; if (!/^https?:\/\/[^\s]+\.[^\s]+/.test(url)) return '链接要以 http:// 或 https:// 开头'; restoreRange(); document.execCommand('insertHTML', false, '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(t) + '</a>'); editorChanged(); } });
      });
      $('[data-rt="image"]').addEventListener('click', function () {
        var n = (d.html.match(/<img /g) || []).length;
        if ((n + 1) * IMG_KB > D.settings.imageTotalMB * 1024) return A.toast('正文图片合计不能超过 ' + D.settings.imageTotalMB + 'MB');
        restoreRange(); document.execCommand('insertHTML', false, '<img src="' + IMG + '" alt="产品图" style="max-width:100%;display:block;margin:8px 0">'); editorChanged(); A.toast('已插入图片（内嵌，约 ' + IMG_KB + 'KB）');
      });
      $('[data-rt="attach"]').addEventListener('click', function () {
        var next = DEMO_FILES.filter(function (f) { return !d.attachments.some(function (a) { return a.name === f.name; }); })[0];
        if (!next) return A.toast('演示附件已全部添加');
        if (d.attachments.length >= D.settings.attachMaxCount) return A.toast('附件最多 ' + D.settings.attachMaxCount + ' 个');
        if (attachMB(d) + next.mb > D.settings.attachMaxMB) return A.toast('加上「' + next.name + '」（' + next.mb + 'MB）会超过 ' + D.settings.attachMaxMB + 'MB 上限，请改为发下载链接');
        d.attachments.push(next); bumpIfTested(d); A.route();
      });
      $all('[data-rmfile]').forEach(function (b) { b.addEventListener('click', function () { d.attachments.splice(+b.getAttribute('data-rmfile'), 1); bumpIfTested(d); A.route(); }); });
    }

    $all('[data-pv]').forEach(function (b) { b.addEventListener('click', function () { d.previewIdx = Math.max(0, d.previewIdx + +b.getAttribute('data-pv')); A.route(); }); });
    var pvs = $('[data-pvsel]'); if (pvs) pvs.addEventListener('change', function () { d.previewIdx = +pvs.value; A.route(); });
    var pvi = $('[data-pvissues]'); if (pvi) pvi.addEventListener('change', function () { d.previewIssues = pvi.checked; d.previewIdx = 0; A.route(); });

    $all('[data-split]').forEach(function (el) { el.addEventListener('change', function () { var id = +el.getAttribute('data-split'); d.splitBoxes = el.checked ? d.splitBoxes.concat(id) : d.splitBoxes.filter(function (x) { return x !== id; }); A.route(); }); });
    $all('[data-single]').forEach(function (el) { el.addEventListener('change', function () { d.singleBox = +el.getAttribute('data-single'); A.route(); }); });
    $all('[data-manual]').forEach(function (el) { el.addEventListener('change', function () { d.manual[el.getAttribute('data-manual')] = el.value.replace(/\D/g, ''); A.route(); }); });
    var mf = $('[data-manualfit]'); if (mf) mf.addEventListener('click', function () {
      var n = plan(d).ok.length, boxes = allowedBoxes(), tot = boxes.reduce(function (s, m) { return s + remaining(m); }, 0) || 1, given = 0;
      boxes.forEach(function (m) { d.manual[m.id] = Math.floor(n * remaining(m) / tot); given += d.manual[m.id]; });
      boxes.slice().sort(function (a, b) { return remaining(b) - remaining(a); }).forEach(function (m) { if (given < n) { d.manual[m.id]++; given++; } });
      A.route(); A.toast('已按今日剩余额度比例填好，可再手改');
    });

    $all('[data-testbox]').forEach(function (b) { b.addEventListener('click', function () { testSend([+b.getAttribute('data-testbox')]); }); });
    var ta = $('[data-testall]'); if (ta) ta.addEventListener('click', function () { testSend(plan(d).boxes.map(function (m) { return m.id; })); });
    var ts = $('[data-testsample]'); if (ts) ts.addEventListener('change', function () { d.testSample = +ts.value; A.route(); });

    $all('[data-script]').forEach(function (el) { el.addEventListener('change', function () { d.scripts[el.getAttribute('data-script')] = el.value; A.route(); }); });
    $all('[data-exec]').forEach(function (el) { el.addEventListener('change', function () { d.executors[el.getAttribute('data-exec')] = +el.value; A.route(); }); });
    $all('[data-copy]').forEach(function (b) { b.addEventListener('click', function () {
      var t = ui.copyTexts[+b.getAttribute('data-copy')];
      if (navigator.clipboard) navigator.clipboard.writeText(t).catch(function () {});
      b.textContent = '已复制'; setTimeout(function () { b.textContent = '复制'; }, 1500);
    }); });
  }

  window.PM_WIZARD = { page: page, bind: bind, plan: plan, newDraft: newDraft, offlineGroups: offlineGroups, zone: zone, dayKey: dayKey, parseBj: parseBj, fmt: fmt, bj: bj, NOW: NOW, OFFLINE: OFFLINE };
})();
