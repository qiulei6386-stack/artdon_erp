/* 演示数据。全部为虚构，不对应正式库中的客户或邮箱。 */
(function () {
  var companies = ['Lichtwerk GmbH', 'Brightline Supply Inc.', 'Al Noor Projects LLC', 'Lumen Polska', 'Nordic Lumen AB', 'Luce Italia SRL', 'Sol Iluminación S.L.', 'Gulf Lighting Trading', 'Kanto Light KK', 'Maple Fixtures Ltd.', 'Andes Electric SAC', 'Doha Build Co.', 'Helios Retail BV', 'Beacon Lighting Pty', 'Orion Electrical FZE', 'Lux Partners SARL', 'Northstar Lighting LLC', 'Atlas Projects Kft', 'Prisma Iluminação', 'Vega Light s.r.o.', 'Riverside Electric Co.', 'Zenith Lamps GmbH', 'Coral Bay Trading', 'Summit Lighting Inc.', 'Aurora Luminaires', 'Desert Rose Contracting', 'Pacific Glow Ltd.', 'Kestrel Lighting UK', 'Granite Electrical', 'Lumiere Distribution', 'Harbor Light Supply', 'Evergreen Fixtures', 'Polaris Projects AS', 'Crescent Lighting LLC', 'Sahara Electric', 'Baltic Lumen OÜ', 'Ironwood Lighting', 'Solstice Lighting BV', 'Meridian Electric', 'Cobalt Light Systems', 'Falcon Trading WLL', 'Redwood Lighting Co.', 'Silverline Electric', 'Tundra Lights Oy', 'Amber Lighting SRL', 'Highland Fixtures', 'Bluewater Lighting', 'Cedar Electric Ltd.'];
  var contacts = ['Jonas Weber', 'Emily Carter', '', 'Piotr Nowak', 'Elin Berg', 'Marco Rossi', 'Carlos Ruiz', 'Omar Khalid', 'Kenji Sato', 'Liam Brown', 'Diego Torres', 'Ahmed Saleh', 'Sanne de Vries', 'Chloe Wilson', 'Rami Haddad', 'Claire Martin', 'Noah Miller', 'Bence Kovács', 'Ana Souza', 'Tomáš Novák', 'Grace Lee', 'Felix Bauer', '', 'Olivia Davis', 'Léa Bernard', 'Yousef Ali', 'Mia Chen', 'Oliver Smith', 'Ethan Clark', 'Camille Petit', 'Lucas White', 'Sophie Hall', 'Erik Hansen', 'Hassan Noor', '', 'Kristjan Tamm', 'James King', 'Daan Bakker', 'Anna Fischer', 'Ryan Scott', 'Khalid Saeed', 'Henry Young', 'Ella Wright', 'Mikko Virtanen', 'Ioana Popescu', 'Jack Turner', 'Zoe Adams', 'Owen Hill'];
  var countries = ['德国', '美国', '阿联酋', '波兰', '瑞典', '意大利', '西班牙', '沙特', '日本', '加拿大', '秘鲁', '卡塔尔', '荷兰', '澳大利亚', '阿联酋', '法国'];
  var middleEast = ['阿联酋', '沙特', '卡塔尔'];
  var customers = companies.map(function (name, i) {
    var domain = name.split(' ')[0].toLowerCase().replace(/[^a-z]/g, '');
    var first = contacts[i] ? contacts[i].split(' ')[0].toLowerCase().replace(/[^a-z]/g, '') : 'info';
    var country = i % 20 === 19 ? '' : countries[i % countries.length];
    /* 客户推广偏好，对应 crm_customer_promotion_channels；phone 来自联系人推广渠道。 */
    var channels = ['email'];
    if (i % 8 === 5) channels = ['email', 'whatsapp'];
    if (i % 7 === 6) channels = ['email', 'wechat'];
    if (i % 9 === 8) channels = ['wechat'];
    if (i % 11 === 3) channels = ['email', 'wechat_group'];
    if (i % 15 === 14) channels = ['phone'];
    if (i === 10) channels = ['no_promotion'];
    var c = {
      id: 100 + i,
      company: name,
      contact: contacts[i],
      email: [7, 33].indexOf(i) >= 0 ? '' : (i === 31 ? first + '@' + domain : first + '@' + domain + '.example'),
      country: country,
      level: ['A', 'B', 'B', 'C'][i % 4],
      owner: i % 10 === 9 ? 0 : (i % 16 === 13 ? 6 : [3, 4, 2, 3, 4, 2][i % 6]),
      dnc: [5, 39].indexOf(i) >= 0,
      unsub: [11, 36].indexOf(i) >= 0,
      recent: [4, 30, 43].indexOf(i) >= 0,
      left: [12, 44].indexOf(i) >= 0,
      pstatus: i === 42 ? 'blacklist' : (i === 16 ? 'paused' : ''),
      noMx: i === 20,
      legacyBlack: i === 28,
      channels: channels,
      wechat: channels.indexOf('wechat') >= 0 && i !== 27 ? 'wx_' + first : '',
      whatsapp: channels.indexOf('whatsapp') >= 0 ? '+' + (40 + i) + ' 7** *** ' + (100 + i * 7) : '',
      phone: channels.indexOf('phone') >= 0 ? '+' + (30 + i) + ' 5** *** ' + (200 + i * 3) : '',
      chatGroup: channels.indexOf('wechat_group') >= 0 ? (middleEast.indexOf(country) >= 0 ? 'Artdon 中东项目群' : 'Artdon 欧洲渠道群') : '',
      avoidGroup: i === 47
    };
    if (i === 17) { c.tz = -7; c.tzLabel = '美西（客户时区）'; }
    if (i % 12 === 2) c.c2 = { name: 'Purchasing Dept.', email: c.email };
    if (i % 12 === 5) c.c2 = { name: 'Anna Ko', email: 'anna@' + domain + '.example' };
    return c;
  });
  function ids(pred) { return customers.filter(pred).map(function (c) { return c.id; }); }

  window.PM_DATA = {
    users: [
      { id: 1, name: '王敏', role: '管理员', scope: 'all', perms: ['view', 'task_create', 'execute', 'approve', 'manage', 'create_group', 'edit_group', 'delete_group', 'move_customer', 'export', 'use_any_mailbox'] },
      { id: 2, name: '陈晓', role: '市场', scope: 'all', perms: ['view', 'task_create', 'execute', 'create_group', 'edit_group', 'delete_group', 'move_customer', 'export', 'use_any_mailbox'] },
      { id: 3, name: '李强', role: '业务员', scope: 'self', perms: ['view', 'task_create', 'execute', 'create_group', 'edit_group', 'move_customer', 'exempt_small'] },
      { id: 4, name: '赵婷', role: '业务员', scope: 'self', perms: ['view', 'task_create', 'execute', 'create_group', 'edit_group', 'move_customer', 'exempt_small', 'use_any_mailbox'] },
      { id: 5, name: '孙悦', role: '只读', scope: 'all', perms: ['view'] },
      { id: 6, name: '刘洋', role: '业务员（未绑定邮箱）', scope: 'self', perms: ['view', 'task_create', 'execute', 'create_group', 'edit_group', 'move_customer', 'exempt_small'] }
    ],
    permLabels: { view: '查看', task_create: '创建推广', execute: '执行', approve: '审批', manage: '设置', create_group: '建分组', edit_group: '改分组', delete_group: '删分组', move_customer: '移动客户', export: '导出', use_any_mailbox: '用他人/公司邮箱', exempt_small: '小批量免审' },
    mailboxes: [
      { id: 11, email: 'wangmin@example-led.com', owner: 1, limit: 300, used: 42, sign: 'Wang Min\nGeneral Manager\nExample LED Lighting Co., Ltd.' },
      { id: 12, email: 'chenxiao@example-led.com', owner: 2, limit: 300, used: 236, sign: 'Chen Xiao\nMarketing Manager\nExample LED Lighting Co., Ltd.' },
      { id: 13, email: 'marketing@example-led.com', owner: 0, limit: 500, used: 476, sign: 'Marketing Team\nExample LED Lighting Co., Ltd.\nwww.example-led.com' },
      { id: 14, email: 'liqiang@example-led.com', owner: 3, limit: 200, used: 11, sign: 'Li Qiang\nSales Manager\nExample LED Lighting Co., Ltd.\nTel: +86 20 0000 0001' },
      { id: 15, email: 'zhaoting@example-led.com', owner: 4, limit: 200, used: 0, sign: 'Zhao Ting\nSales Manager\nExample LED Lighting Co., Ltd.\nTel: +86 20 0000 0002' }
    ],
    /* 国家 → 主要营业时区（2026-10-13 当天的 UTC 偏移，含夏令时）与周末。多时区国家优先用客户自己的 timezone 字段。 */
    countries: {
      德国: { tz: 2, weekend: [0, 6] }, 美国: { tz: -4, weekend: [0, 6], label: '美东' }, 阿联酋: { tz: 4, weekend: [0, 6] }, 波兰: { tz: 2, weekend: [0, 6] },
      瑞典: { tz: 2, weekend: [0, 6] }, 意大利: { tz: 2, weekend: [0, 6] }, 西班牙: { tz: 2, weekend: [0, 6] }, 沙特: { tz: 3, weekend: [5, 6] },
      日本: { tz: 9, weekend: [0, 6] }, 加拿大: { tz: -4, weekend: [0, 6], label: '多伦多' }, 秘鲁: { tz: -5, weekend: [0, 6] }, 卡塔尔: { tz: 3, weekend: [5, 6] },
      荷兰: { tz: 2, weekend: [0, 6] }, 澳大利亚: { tz: 11, weekend: [0, 6], label: '悉尼' }, 法国: { tz: 2, weekend: [0, 6] }
    },
    customers: customers,
    groups: [
      { id: 21, name: '2026 法兰克福展会客户', color: '#3b4fd8', visibility: 'public', createdBy: 2, members: ids(function (c) { return (c.id - 100) % 4 === 0 || (c.id - 100) % 5 === 1; }) },
      { id: 22, name: '北美经销商', color: '#19794b', visibility: 'public', createdBy: 2, members: ids(function (c) { return ['美国', '加拿大'].indexOf(c.country) >= 0; }) },
      { id: 23, name: '中东工程商', color: '#a35c00', visibility: 'public', createdBy: 1, members: ids(function (c) { return ['阿联酋', '沙特', '卡塔尔'].indexOf(c.country) >= 0; }) },
      { id: 24, name: '一年未下单老客户', color: '#807a71', visibility: 'public', createdBy: 2, members: ids(function (c) { return c.level === 'C' || c.id % 5 === 1; }) },
      { id: 25, name: '李强 · 重点跟进', color: '#b3261e', visibility: 'private', createdBy: 3, members: ids(function (c) { return c.owner === 3 && c.level === 'A'; }) }
    ],
    samples: [
      { company: 'Lichtwerk GmbH', contact: 'Jonas Weber', email: 'jonas@lichtwerk.example', country: '德国' },
      { company: 'Brightline Supply Inc.', contact: 'Emily Carter', email: 'emily@brightline.example', country: '美国' },
      { company: 'Al Noor Projects LLC', contact: '', email: 'info@alnoor.example', country: '阿联酋' }
    ],
    campaigns: [
      { id: 'pm-1011', source: 'pm', name: '10 月新品目录（全员）', channel: 'preference', status: 'sending', createdBy: 2, owners: [2, 3, 4], createdAt: '2026-10-12 17:30', startAt: '2026-10-13 10:00', subject: 'October catalogue for {公司名称}', mailboxes: [12, 13], counts: { planned: 0, sent: 0, failed: 0, uncertain: 0, skipped: 0, manualPending: 0, manualDone: 0 } },
      { id: 'pm-1012', source: 'pm', name: '法兰克福展后跟进', channel: 'email', status: 'sending', createdBy: 3, owners: [3], createdAt: '2026-10-12 15:20', startAt: '2026-10-13 08:00', subject: 'Nice meeting you at Light + Building, {公司名称}', mailboxes: [14], counts: { planned: 18, sent: 11, failed: 1, uncertain: 1, skipped: 2, manualPending: 0, manualDone: 0 } },
      { id: 'pm-1013', source: 'pm', name: '2026 秋季新品轨道灯', channel: 'email', status: 'pending_approval', createdBy: 2, owners: [2, 3, 4], createdAt: '2026-10-13 10:05', startAt: '2026-10-14 09:00', subject: 'New 2026 track light series for {公司名称}', mailboxes: [12, 13], counts: { planned: 240, sent: 0, failed: 0, uncertain: 0, skipped: 21, manualPending: 0, manualDone: 0 }, approvalReasons: ['没有“小批量免审”权限，所有推广都需要审批'] },
      { id: 'pm-1017', source: 'pm', name: '中东工程项目案例分享', channel: 'preference', status: 'pending_approval', createdBy: 4, owners: [4], createdAt: '2026-10-13 11:40', startAt: '2026-10-14 10:00', subject: 'Project references in the GCC region', mailboxes: [15, 13], counts: { planned: 36, sent: 0, failed: 0, uncertain: 0, skipped: 4, manualPending: 6, manualDone: 0 }, approvalReasons: ['使用了公司邮箱 marketing@example-led.com', '邮件数 36 超过免审额度 20'] },
      { id: 'pm-1014', source: 'pm', name: '中东项目报价回访', channel: 'email', status: 'rejected', createdBy: 4, owners: [4], createdAt: '2026-10-11 16:12', startAt: '2026-10-12 10:00', subject: 'Follow-up on your lighting quotation', mailboxes: [15, 13], counts: { planned: 28, sent: 0, failed: 0, uncertain: 0, skipped: 3, manualPending: 0, manualDone: 0 }, rejectReason: '正文里直接写了单价，请删掉价格，改为附报价单 PDF。' },
      { id: 'pm-1015', source: 'pm', name: '北美经销商圣诞备货', channel: 'email', status: 'scheduled', createdBy: 1, owners: [2, 4], createdAt: '2026-10-12 09:00', startAt: '2026-10-15 21:00', subject: 'Holiday season stock plan for {公司名称}', mailboxes: [11, 13], counts: { planned: 67, sent: 0, failed: 0, uncertain: 0, skipped: 3, manualPending: 0, manualDone: 0 } },
      { id: 'pm-1016', source: 'pm', name: '老客户复购提醒', channel: 'email', status: 'draft', createdBy: 3, owners: [3], createdAt: '2026-10-13 08:45', startAt: '', subject: '', mailboxes: [14], counts: { planned: 9, sent: 0, failed: 0, uncertain: 0, skipped: 0, manualPending: 0, manualDone: 0 } },
      { id: 'pm-1010', source: 'pm', name: '9 月工厂开放日回顾', channel: 'email', status: 'completed', createdBy: 2, owners: [2, 3, 4], createdAt: '2026-10-08 14:00', startAt: '2026-10-09 09:00', subject: 'Thanks for visiting our factory', mailboxes: [12], counts: { planned: 52, sent: 49, failed: 3, uncertain: 0, skipped: 5, manualPending: 0, manualDone: 0 } },
      { id: 'crm-890', source: 'crm', name: '欧洲渠道价格调整通知', channel: 'email', status: 'paused', createdBy: 4, owners: [4], createdAt: '2026-10-06 10:30', startAt: '2026-10-07 09:00', subject: 'Price update notice', mailboxes: [15], counts: { planned: 44, sent: 20, failed: 0, uncertain: 0, skipped: 2, manualPending: 0, manualDone: 0 } },
      { id: 'crm-882', source: 'crm', name: '展会邀请函（CRM）', channel: 'email', status: 'completed', partialFailed: true, createdBy: 2, owners: [2, 3, 4], createdAt: '2026-09-20 11:00', startAt: '2026-09-21 09:00', subject: 'Invitation: Light + Building 2026', mailboxes: [12, 13], counts: { planned: 310, sent: 296, failed: 14, uncertain: 0, skipped: 18, manualPending: 0, manualDone: 0 } },
      { id: 'crm-861', source: 'crm', name: 'WhatsApp 新品推送', channel: 'whatsapp', status: 'sending', manualOnly: true, createdBy: 3, owners: [3], createdAt: '2026-09-15 15:00', startAt: '2026-09-16 10:00', subject: '', mailboxes: [], counts: { planned: 0, sent: 0, failed: 0, uncertain: 0, skipped: 1, manualPending: 7, manualDone: 12 } },
      { id: 'crm-845', source: 'crm', name: '国庆放假通知', channel: 'email', status: 'cancelled', createdBy: 1, owners: [2, 3, 4], createdAt: '2026-09-10 09:00', startAt: '2026-09-28 09:00', subject: 'Holiday notice', mailboxes: [11], counts: { planned: 120, sent: 0, failed: 0, uncertain: 0, skipped: 0, manualPending: 0, manualDone: 0 } }
    ],
    manualTasks: [
      { id: 1, campaign: 'crm-861', company: 'Sol Iluminación S.L.', contact: 'Carlos Ruiz', channel: 'WhatsApp', handle: '+34 6** *** 218', executor: 3, due: '2026-10-13', status: 'pending' },
      { id: 2, campaign: 'crm-861', company: 'Nordic Lumen AB', contact: 'Elin Berg', channel: 'WhatsApp', handle: '+46 7** *** 902', executor: 3, due: '2026-10-13', status: 'pending' },
      { id: 3, campaign: 'crm-861', company: 'Luce Italia SRL', contact: 'Marco Rossi', channel: 'WhatsApp', handle: '+39 3** *** 441', executor: 3, due: '2026-10-12', status: 'pending', overdue: true },
      { id: 4, campaign: 'pm-1017', company: 'Gulf Lighting Trading', contact: 'Omar Khalid', channel: '微信', handle: 'omar_gl***', executor: 4, due: '2026-10-14', status: 'waiting' },
      { id: 5, campaign: 'pm-1017', company: 'Doha Build Co.', contact: 'Ahmed Saleh', channel: 'WhatsApp', handle: '+974 5** *** 120', executor: 4, due: '2026-10-14', status: 'waiting' },
      { id: 6, campaign: 'crm-861', company: 'Lumen Polska', contact: 'Piotr Nowak', channel: 'WhatsApp', handle: '+48 5** *** 377', executor: 3, due: '2026-10-11', status: 'done', result: '已联系-有意向', shot: 'screenshot-6.png', doneAt: '2026-10-10 16:42' }
    ],
    /* 执行看板演示：已开始（或已排期）推广提交时冻结的参数。看板据此重算名单，并按“现在”模拟发送结果。
       now = 提交/开始时刻（北京时间）；usedBase = 当时各邮箱当天已用量，'today' 表示按今日实际用量倒推；
       fault = 某邮箱从某时刻起出错；pausedAt = 推广暂停时刻；uncertain = 第 N 封已发出的改为“结果不确定”。 */
    executions: {
      'pm-1011': { groups: [21, 24], method: 'split', splitBoxes: [12, 13], timing: 'now', now: '2026-10-13 10:00', usedBase: 'today', noOwner: 'company',
        fault: { box: 12, from: '2026-10-13 10:12', error: 'SMTP 登录失败：535 认证未通过（邮箱密码可能已修改），该邮箱已自动暂停' } },
      'pm-1012': { groups: [21, 23, 24], method: 'owner', timing: 'local', localTime: '09:00', now: '2026-10-12 16:00', usedBase: {}, noOwner: 'exclude', uncertain: 1 },
      'pm-1010': { groups: [24], method: 'split', splitBoxes: [12], timing: 'at', at: '2026-10-09 09:00', now: '2026-10-09 08:30', usedBase: { 12: 292 }, noOwner: 'company' },
      'pm-1015': { groups: [22], method: 'split', splitBoxes: [11, 13], timing: 'local', localTime: '09:00', now: '2026-10-15 20:00', usedBase: {}, noOwner: 'company' },
      'crm-890': { groups: [21, 23], method: 'owner', timing: 'at', at: '2026-10-07 09:00', now: '2026-10-07 08:50', usedBase: {}, noOwner: 'exclude', pausedAt: '2026-10-07 09:01' },
      'crm-882': { groups: [21, 22, 23], method: 'split', splitBoxes: [12, 13], timing: 'at', at: '2026-09-21 09:00', now: '2026-09-21 08:50', usedBase: {}, noOwner: 'company' }
    },
    settings: { exempt: 20, freqDays: 7, windowStart: '09:00', windowEnd: '18:00', interval: 45, defaultTz: 8, quotaWarn: 0.8, offlineDueDays: 2, attachMaxMB: 10, attachMaxCount: 5, imageMaxKB: 1024, imageTotalMB: 3, testMaxTo: 5 },
    suppress: { crmDnc: 186, contactUnsub: 52, legacyUnsub: 73, legacyBlack: 41 }
  };
})();
