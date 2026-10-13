<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_permission('promotion.view');
$user = current_user();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>推广中心</title>
<style>
:root{
  --bg:#f1f5f9; --card:#ffffff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0;
  --blue:#3b82f6; --blue-d:#2563eb; --blue-l:#eff6ff;
  --green:#10b981; --green-l:#ecfdf5;
  --orange:#f59e0b; --orange-l:#fffbeb;
  --red:#ef4444; --red-l:#fef2f2;
  --purple:#8b5cf6; --purple-l:#f5f3ff;
  --radius:16px; --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 12px rgba(0,0,0,.04);
  --shadow-lg:0 8px 30px rgba(0,0,0,.12);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;font-size:14px;line-height:1.6;-webkit-font-smoothing:antialiased}
/* 顶部导航 */
.topbar{background:linear-gradient(135deg,#1e3a5f 0%,#2d5a8f 100%);color:#fff;padding:0 24px;display:flex;align-items:center;gap:20px;position:sticky;top:0;z-index:50;box-shadow:0 2px 12px rgba(0,0,0,.15);height:60px}
.topbar h1{font-size:17px;margin:0;font-weight:700;display:flex;align-items:center;gap:10px;letter-spacing:.5px}
.topbar h1 .logo{width:34px;height:34px;background:linear-gradient(135deg,#60a5fa,#3b82f6);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.tabs{display:flex;gap:4px}
.tabs button{border:none;background:transparent;color:rgba(255,255,255,.65);padding:9px 16px;border-radius:10px;font-size:14px;font-weight:500;cursor:pointer;transition:all .2s}
.tabs button:hover{color:#fff;background:rgba(255,255,255,.1)}
.tabs button.active{background:rgba(255,255,255,.18);color:#fff;font-weight:700;box-shadow:inset 0 0 0 1px rgba(255,255,255,.15)}
.topbar .spacer{margin-left:auto}
/* 按钮 */
button{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 16px;font-weight:600;cursor:pointer;font-size:13px;color:var(--text);transition:all .18s;display:inline-flex;align-items:center;gap:6px}
button:hover{border-color:#bfdbfe;background:#f8fafc;transform:translateY(-1px);box-shadow:var(--shadow)}
button:active{transform:translateY(0)}
button.primary{background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;border:none;box-shadow:0 2px 8px rgba(59,130,246,.35)}
button.primary:hover{background:linear-gradient(135deg,var(--blue-d),#1d4ed8);box-shadow:0 4px 14px rgba(59,130,246,.45)}
button.danger{color:var(--red);border-color:#fecaca;background:#fff}
button.danger:hover{background:var(--red-l);border-color:var(--red)}
button.xs{padding:4px 10px;font-size:12px;border-radius:8px}
button:disabled{opacity:.5;cursor:not-allowed;transform:none}
/* 输入框 */
input,select,textarea{border:1.5px solid var(--line);border-radius:10px;padding:9px 12px;font-size:14px;background:#fff;transition:all .18s;color:var(--text)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
input::placeholder{color:#94a3b8}
/* 布局 */
.wrap{max-width:1280px;margin:0 auto;padding:24px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px;box-shadow:var(--shadow)}
.card h3{margin:0 0 14px;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
.card h3 small{font-weight:400}
.grid{display:grid;gap:14px}.g4{grid-template-columns:repeat(4,1fr)}.g3{grid-template-columns:repeat(3,1fr)}
/* KPI卡片 */
.kpi{border:1px solid var(--line);border-radius:14px;padding:16px;background:linear-gradient(135deg,#fff,#f8fafc);position:relative;overflow:hidden;transition:all .2s}
.kpi:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.kpi::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--blue);border-radius:4px 0 0 4px}
.kpi.green::before{background:var(--green)}.kpi.orange::before{background:var(--orange)}.kpi.red::before{background:var(--red)}.kpi.purple::before{background:var(--purple)}
.kpi span{font-size:12px;color:var(--muted);display:block;margin-bottom:4px;font-weight:500}
.kpi b{font-size:28px;font-weight:800;letter-spacing:-.5px}
/* 表格 */
table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}
th,td{padding:12px;text-align:left;border-bottom:1px solid #f1f5f9}
th{background:#f8fafc;color:var(--muted);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
th:first-child{border-radius:10px 0 0 10px}th:last-child{border-radius:0 10px 10px 0}
tbody tr{transition:background .15s}
tbody tr:hover{background:#f8fafc}
tbody tr:last-child td{border-bottom:none}
/* 徽章 */
.badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600}
.badge.blue{background:var(--blue-l);color:var(--blue-d)}
.badge.green{background:var(--green-l);color:#047857}
.badge.orange{background:var(--orange-l);color:#b45309}
.badge.red{background:var(--red-l);color:#b91c1c}
.badge.gray{background:#f1f5f9;color:#64748b}
.badge.purple{background:var(--purple-l);color:#6d28d9}
/* 弹窗 */
.modal{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:100;padding:20px;animation:fadeIn .2s}
.modal.show{display:flex}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modalBox{background:#fff;border-radius:20px;width:min(1020px,96vw);max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-lg);animation:slideUp .25s}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modalHead{padding:18px 24px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.modalHead h3{margin:0;font-size:17px;font-weight:700}
.modalBody{padding:24px;overflow:auto}
.modalFoot{padding:16px 24px;border-top:1px solid var(--line);display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-radius:0 0 20px 20px}
/* 向导步骤 */
.steps{display:flex;gap:8px;margin-bottom:20px}
.step{flex:1;text-align:center;padding:12px 8px;font-weight:600;color:var(--muted);background:#f8fafc;border-radius:12px;font-size:13px;position:relative;transition:all .2s;border:1.5px solid transparent}
.step .num{display:inline-flex;width:24px;height:24px;border-radius:50%;background:#e2e8f0;color:var(--muted);align-items:center;justify-content:center;font-size:12px;font-weight:700;margin-right:6px}
.step.active{color:var(--blue-d);background:var(--blue-l);border-color:#bfdbfe}
.step.active .num{background:var(--blue);color:#fff}
.step.done{color:#047857;background:var(--green-l)}
.step.done .num{background:var(--green);color:#fff}
/* 筛选器 */
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:14px}
/* 富文本编辑器 */
.editor{border:1.5px solid var(--line);border-radius:12px;min-height:280px;padding:16px;background:#fff;line-height:1.8;transition:border .18s}
.editor:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.toolbar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;padding:10px;background:#f8fafc;border-radius:10px}
.toolbar button{padding:6px 12px;font-size:13px;background:#fff}
.varbtn{background:#f0fdf4!important;border-color:#bbf7d0!important;color:#166534!important}
/* 提示 */
.toast{position:fixed;bottom:24px;right:24px;background:#0f172a;color:#fff;padding:12px 20px;border-radius:12px;display:none;z-index:200;font-weight:600;box-shadow:var(--shadow-lg);animation:slideUp .25s}
.toolbar2{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.empty{text-align:center;color:var(--muted);padding:40px 20px}
.empty::before{content:'📭';font-size:32px;display:block;margin-bottom:8px;opacity:.5}
/* 进度条 */
.progress{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.progress i{display:block;height:100%;background:linear-gradient(90deg,var(--blue),var(--purple));border-radius:99px;transition:width .5s}
/* 工具类 */
.row-between{display:flex;justify-content:space-between;align-items:center}
.mb12{margin-bottom:12px}.mt12{margin-top:12px}
@media(max-width:900px){.g4,.g3{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr 1fr}.topbar{padding:0 12px}.wrap{padding:12px}}
</style>
</head>
<body>
<div class="topbar">
  <h1><span class="logo">📣</span> 推广中心</h1>
  <div class="tabs">
    <button class="active" onclick="showTab('dashboard',this)">数据看板</button>
    <button onclick="showTab('campaigns',this)">推广活动</button>
    <button onclick="showTab('intent',this)">高意向客户</button>
    <button onclick="showTab('tasks',this)">执行清单 <span id="taskBadge"></span></button>
    <button onclick="showTab('groups',this)">分组管理</button>
    <button onclick="showTab('segments',this)">动态人群</button>
    <button onclick="showTab('templates',this)">邮件模板</button>
    <button onclick="showTab('mail',this)">发件管理</button>
    <button onclick="showTab('approvals',this)">审批</button>
    <button onclick="showTab('blacklist',this)">黑名单</button>
    <button onclick="showTab('audit',this)">操作日志</button>
  </div>
  <div class="spacer"><button class="primary" onclick="openWizard()">＋ 新建推广</button></div>
</div>
<div class="wrap">
  <div id="view-dashboard"></div>
  <div id="view-campaigns" style="display:none"></div>
  <div id="view-intent" style="display:none"></div>
  <div id="view-tasks" style="display:none"></div>
  <div id="view-groups" style="display:none"></div>
  <div id="view-segments" style="display:none"></div>
  <div id="view-templates" style="display:none"></div>
  <div id="view-mail" style="display:none"></div>
  <div id="view-approvals" style="display:none"></div>
  <div id="view-blacklist" style="display:none"></div>
  <div id="view-audit" style="display:none"></div>
</div>

<!-- 新建向导 -->
<div class="modal" id="wizardModal"><div class="modalBox">
  <div class="modalHead"><b>新建推广活动</b><button class="small" onclick="closeWizard()">✕</button></div>
  <div class="modalBody">
    <div class="steps">
      <div class="step active" id="ws1"><span class="num">1</span>选客户</div>
      <div class="step" id="ws2"><span class="num">2</span>写邮件</div>
      <div class="step" id="ws3"><span class="num">3</span>发送设置</div>
      <div class="step" id="ws4"><span class="num">4</span>确认发送</div>
    </div>
    <div id="wz-step1">
      <div class="filters">
        <input id="f-kw" placeholder="客户名 / 编码 / 邮箱" onkeydown="if(event.key==='Enter')loadCustomers()">
        <select id="f-group"><option value="0">全部分组</option></select>
        <select id="f-country"><option value="">全部国家</option></select>
        <select id="f-level"><option value="">全部等级</option></select>
        <select id="f-owner"><option value="">全部负责人</option></select>
        <select id="f-list"><option value="0">全部（不用名单）</option></select>
        <button onclick="loadCustomers()">查询</button>
      </div>
      <div class="toolbar2">
        <button class="small" onclick="saveAsList()">保存为名单</button>
        <span style="color:var(--muted);font-size:13px">已选 <b id="selCount">0</b> 个（邮件 <b id="selEmail">0</b> / 微信 <b id="selWechat">0</b> / WhatsApp <b id="selWa">0</b>）</span>
      </div>
      <div style="max-height:380px;overflow:auto;border:1px solid var(--line);border-radius:12px">
      <table><thead><tr><th><input type="checkbox" onchange="toggleAll(this)"></th><th>客户</th><th>国家</th><th>邮箱</th><th>偏好渠道</th><th>负责人</th></tr></thead>
      <tbody id="custRows"></tbody></table>
      </div>
      <div class="toolbar2" style="margin-top:8px;justify-content:center">
        <button class="small" onclick="custPage--;loadCustomers()">‹ 上一页</button>
        <span id="pageInfo"></span>
        <button class="small" onclick="custPage++;loadCustomers()">下一页 ›</button>
      </div>
    </div>
    <div id="wz-step2" style="display:none">
      <div class="toolbar2">
        <select id="tplSel" onchange="loadTemplate(this.value)"><option value="">从模板选择…</option></select>
        <button class="small" onclick="saveAsTemplate()">存为模板</button>
      </div>
      <div style="margin-bottom:8px"><input id="w-subject" placeholder="邮件主题" style="width:100%"></div>
      <div class="toolbar">
        <button class="small" onclick="fmt('bold')"><b>B</b></button>
        <button class="small" onclick="fmt('fontSize',4)">大</button>
        <button class="small" onclick="fmt('fontSize',2)">小</button>
        <button class="small" onclick="fmt('foreColor','red')">红字</button>
        <button class="small" onclick="fmt('insertUnorderedList')">• 列表</button>
        <button class="small" onclick="fmt('createLink')">🔗 链接</button>
        <button class="small" onclick="document.getElementById('imgUp').click()">🖼 图片</button>
        <input type="file" id="imgUp" accept="image/*" style="display:none" onchange="uploadImage(this)">
        <button class="small" onclick="document.getElementById('attUp').click()">📎 附件</button>
        <input type="file" id="attUp" style="display:none" onchange="uploadAttach(this)">
        <button class="small varbtn" onclick="insVar('姓名')">姓名</button>
        <button class="small varbtn" onclick="insVar('公司')">公司</button>
        <button class="small varbtn" onclick="insVar('国家')">国家</button>
      </div>
      <div class="editor" id="w-editor" contenteditable="true"></div>
      <div id="attList" style="margin-top:8px"></div>
      <div class="toolbar2" style="margin-top:10px">
        <button class="small" onclick="doPreview()">👁 预览</button>
        <button class="small" onclick="doTestSend()">✉ 试发</button>
        <input id="testEmail" placeholder="试发邮箱" style="width:220px">
      </div>
      <div id="previewBox" style="display:none;margin-top:8px;border:1px dashed var(--line);border-radius:12px;padding:12px"></div>
    </div>
    <div id="wz-step3" style="display:none">
      <div class="card"><h3>发送方式</h3>
        <label style="display:block;margin:8px 0"><input type="radio" name="sendmode" value="now" checked onchange="refreshPreview()"> 马上发送</label>
        <label style="display:block;margin:8px 0"><input type="radio" name="sendmode" value="scheduled" onchange="refreshPreview()"> 指定时间 <input type="datetime-local" id="w-sched" onchange="refreshPreview()"></label>
        <label style="display:block;margin:8px 0"><input type="radio" name="sendmode" value="smart" onchange="refreshPreview()"> 按客户国家工作时间智能发送 <span style="color:var(--muted);font-size:12px">（自动算时差，在对方 9:00–18:00 发出）</span></label>
      </div>
      <div class="card"><h3>发件邮箱</h3><select id="w-account" style="width:100%"></select>
        <small style="color:var(--muted)">不选则按轮换策略自动分配；多邮箱轮换发送，提升送达率</small></div>
      <div class="card"><h3>发送策略</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label>每批发送量 <input type="number" id="w-batch" value="50" min="10" max="500" style="width:100px"> <small style="color:var(--muted)">大名单分批发出</small></label>
          <label>频率控制 <input type="number" id="w-freq" value="14" min="0" max="90" style="width:100px"> 天 <small style="color:var(--muted)">同一客户N天内只收一封，0=不限制</small></label>
        </div>
        <label style="display:block;margin-top:10px"><input type="checkbox" id="w-approval"> 大额群发需主管审批后才能发送</label>
      </div>
      <div class="card"><h3>发送时间预览 <span style="font-size:12px;color:var(--muted);font-weight:normal">共 <span id="pvCount">0</span> 位客户</span></h3>
        <div id="previewBox" style="max-height:280px;overflow:auto"><div class="empty">选择发送方式后显示每位客户的预计发送时间</div></div>
      </div>
    </div>
    <div id="wz-step4" style="display:none">
      <div class="card"><h3>发送前检查</h3><div id="dedupBox"><div class="empty">计算中…</div></div>
        <button class="btn" onclick="runSpamCheck()">🛡 反垃圾预检</button> <span id="spamResult" style="font-size:13px"></span></div>
      <div class="card"><h3>确认信息</h3><div id="confirmBox"></div></div>
    </div>
  </div>
  <div class="modalFoot">
    <button onclick="closeWizard()">取消</button>
    <button id="wzPrev" onclick="wzGo(-1)" style="display:none">上一步</button>
    <button class="primary" id="wzNext" onclick="wzGo(1)">下一步</button>
  </div>
</div></div>

<div class="toast" id="toast"></div>

<script>
const $=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
function toast(m,err){const t=$('toast');t.textContent=m;t.style.background=err?'#991b1b':'#111827';t.style.display='block';clearTimeout(t._h);t._h=setTimeout(()=>t.style.display='none',2200)}
async function api(action,data={},method='POST'){
  const opt={method,credentials:'same-origin'};
  let url='api.php?action='+encodeURIComponent(action);
  if(method==='GET'&&data){url+='&'+new URLSearchParams(data).toString()}
  else if(method==='POST'){opt.headers={'Content-Type':'application/json'};opt.body=JSON.stringify(data)}
  const r=await fetch(url,opt);const t=await r.text();
  let j;try{j=JSON.parse(t)}catch(e){throw new Error('接口异常：'+t.slice(0,120))}
  if(!j.success)throw new Error(j.message||'请求失败');
  return j.data;
}

// ---------- tabs ----------
function showTab(t,btn){
  document.querySelectorAll('.tabs button').forEach(b=>b.classList.remove('active'));
  (btn||document.querySelector(`.tabs button[onclick*="'${t}'"]`)).classList.add('active');
  ['dashboard','campaigns','intent','tasks','groups','segments','templates','mail','approvals','blacklist','audit'].forEach(k=>$('view-'+k).style.display=k===t?'':'none');
  if(t==='dashboard')loadDashboard();if(t==='campaigns')loadCampaigns();if(t==='intent')loadIntent();
  if(t==='tasks')loadTasks();if(t==='groups')loadGroups();if(t==='segments')loadSegments();
  if(t==='templates')loadTemplates();if(t==='mail')loadMail();if(t==='approvals')loadApprovals();
  if(t==='blacklist')loadBlacklist();if(t==='audit')loadAudit();
}

// ---------- dashboard ----------
async function loadDashboard(){
  const d=await api('dashboard_stats',{},'GET');
  $('view-dashboard').innerHTML=`
  <div class="grid g4">
    <div class="kpi"><span>累计发送</span><b>${d.sent}</b></div>
    <div class="kpi green"><span>打开率</span><b>${d.open_rate}%</b><small style="color:var(--muted)">${d.opened} 人打开</small></div>
    <div class="kpi orange"><span>点击率</span><b>${d.click_rate}%</b><small style="color:var(--muted)">${d.clicked} 人点击</small></div>
    <div class="kpi purple"><span>回复率</span><b>${d.reply_rate}%</b><small style="color:var(--muted)">${d.replied} 人回复</small></div>
  </div>
  <div class="grid g4" style="margin-top:14px">
    <div class="kpi"><span>推广活动</span><b>${d.campaigns}</b></div>
    <div class="kpi red"><span>退信</span><b>${d.bounced}</b></div>
    <div class="kpi"><span>退订</span><b>${d.unsubscribed}</b></div>
    <div class="kpi"><span>待审批</span><b id="dashPending">-</b></div>
  </div>
  <div class="card" style="margin-top:14px"><h3>各国效果 Top5</h3>
  <table><thead><tr><th>国家</th><th>发送</th><th>打开</th><th>点击</th><th>打开率</th></tr></thead><tbody>
  ${d.by_country.map(c=>{const r=c.sent?Math.round(c.opened/c.sent*100):0;return `<tr><td>${esc(c.country||'-')}</td><td>${c.sent}</td><td>${c.opened}</td><td>${c.clicked}</td><td><div class="progress" style="width:120px"><i style="width:${r}%"></i></div> ${r}%</td></tr>`}).join('')||'<tr><td colspan="5" class="empty">暂无数据</td></tr>'}
  </tbody></table></div>`;
  try{const a=await api('approval_list',{},'GET');$('dashPending').textContent=a.length}catch(e){}
}

// ---------- high intent ----------
async function loadIntent(){
  const d=await api('high_intent',{},'GET');
  $('view-intent').innerHTML=`<div class="card"><h3>高意向客户 <small style="color:var(--muted)">打开≥2次或点过链接，优先跟进</small></h3>
  <table><thead><tr><th>客户</th><th>邮箱</th><th>国家</th><th>打开</th><th>点击</th><th>来源活动</th><th>负责人</th></tr></thead><tbody>
  ${d.map(r=>`<tr><td><b>${esc(r.customer_name)}</b></td><td style="font-size:12px">${esc(r.email)}</td><td>${esc(r.country||'-')}</td>
  <td><span class="badge green">${r.open_count}次</span></td><td><span class="badge blue">${r.click_count}次</span></td>
  <td style="font-size:12px">${esc(r.campaign||'-')}</td><td>${esc(r.owner||'-')}</td></tr>`).join('')||'<tr><td colspan="7" class="empty">暂无高意向客户</td></tr>'}
  </tbody></table></div>`;
}

// ---------- segments ----------
async function loadSegments(){
  const d=await api('segments',{},'GET');
  $('view-segments').innerHTML=`<div class="card"><div class="row-between mb12"><h3>动态人群</h3><button class="btn primary" onclick="openSegmentEdit(0)">＋ 新建人群</button></div>
  <table><thead><tr><th>名称</th><th>创建人</th><th>操作</th></tr></thead><tbody>
  ${d.map(s=>`<tr><td><b>${esc(s.name)}</b><br><small style="color:var(--muted)">${esc(s.conditions)}</small></td><td>${esc(s.creator||'-')}</td>
  <td><button class="btn xs" onclick="previewSegment(${s.id})">看人数</button>
  <button class="btn xs" onclick="openSegmentEdit(${s.id},'${esc(s.name).replace(/'/g,"")}')">编辑</button>
  <button class="btn xs danger" onclick="delSegment(${s.id})">删除</button></td></tr>`).join('')||'<tr><td colspan="3" class="empty">暂无</td></tr>'}
  </tbody></table></div><div id="segPreview"></div>`;
}
function openSegmentEdit(id,name){
  const n=prompt('人群名称：',name||'');
  if(n===null||!n.trim())return;
  const country=prompt('国家（留空=不限）：','')||'';
  const level=prompt('等级（留空=不限）：','')||'';
  const nof=prompt('多少天未跟进（数字，留空=不限）：','')||'';
  api('segment_save',{id,name:n.trim(),conditions:{country:coalesceEmpty(country),level:coalesceEmpty(level),no_follow_days:coalesceEmpty(nof),has_email:1}}).then(()=>{toast('已保存');loadSegments()}).catch(e=>toast(e.message,1));
}
function coalesceEmpty(v){v=(v||'').trim();return v===''?null:v}
async function previewSegment(id){
  const d=await api('segments',{},'GET');const s=d.find(x=>x.id===id);if(!s)return;
  const c=await api('segment_count',{conditions:JSON.parse(s.conditions||'{}')});
  $('segPreview').innerHTML=`<div class="card"><h3>「${esc(s.name)}」当前符合 ${c.count} 个客户</h3></div>`;
}
async function delSegment(id){if(!confirm('删除这个人群？'))return;await api('segment_delete',{id});loadSegments()}

// ---------- mail quotas ----------
async function loadMail(){
  const d=await api('quota_status',{},'GET');
  $('view-mail').innerHTML=`<div class="card"><h3>发件邮箱额度（今日）</h3>
  <table><thead><tr><th>邮箱</th><th>发件人</th><th>今日已发 / 上限</th><th>使用率</th><th>操作</th></tr></thead><tbody>
  ${d.map(m=>{const pct=m.lim?Math.round(m.sent/m.lim*100):0;return `<tr><td style="font-size:12px">${esc(m.email_address)}</td><td>${esc(m.sender_name||'-')}</td>
  <td>${m.sent} / ${m.lim}</td><td><div class="progress" style="width:140px"><i style="width:${Math.min(100,pct)}%;${pct>=90?'background:var(--red)':''}"></i></div> ${pct}%</td>
  <td><button class="btn xs" onclick="setQuota(${m.id},${m.lim})">设上限</button></td></tr>`}).join('')}
  </tbody></table></div>`;
}
async function setQuota(id,cur){const v=prompt('每日发送上限：',cur);if(v===null)return;await api('quota_set',{mail_account_id:id,daily_limit:+v});loadMail()}

// ---------- approvals ----------
async function loadApprovals(){
  const d=await api('approval_list',{},'GET');
  $('view-approvals').innerHTML=`<div class="card"><h3>待审批 <small style="color:var(--muted)">大额群发需主管审批</small></h3>
  <table><thead><tr><th>活动</th><th>申请人</th><th>申请时间</th><th>备注</th><th>操作</th></tr></thead><tbody>
  ${d.map(a=>`<tr><td><b>${esc(a.title)}</b></td><td>${esc(a.requester||'-')}</td><td style="font-size:12px">${esc(a.requested_at||'')}</td><td style="font-size:12px">${esc(a.note||'-')}</td>
  <td><button class="btn xs primary" onclick="decideApproval(${a.id},'approved')">批准</button>
  <button class="btn xs danger" onclick="decideApproval(${a.id},'rejected')">驳回</button></td></tr>`).join('')||'<tr><td colspan="5" class="empty">暂无待审批</td></tr>'}
  </tbody></table></div>`;
}
async function decideApproval(id,decision){const note=decision==='rejected'?prompt('驳回理由：','')||'':'';await api('approval_decide',{id,decision,note});toast(decision==='approved'?'已批准':'已驳回');loadApprovals()}

// ---------- audit ----------
async function loadAudit(){
  const d=await api('audit_log',{},'GET');
  $('view-audit').innerHTML=`<div class="card"><h3>操作日志</h3>
  <table><thead><tr><th>时间</th><th>操作人</th><th>动作</th><th>对象</th><th>详情</th></tr></thead><tbody>
  ${d.map(l=>`<tr><td style="font-size:12px">${esc(l.created_at||'')}</td><td>${esc(l.real_name||'-')}</td><td><span class="badge blue">${esc(l.action)}</span></td>
  <td style="font-size:12px">${esc(l.target_type)}#${l.target_id}</td><td style="font-size:12px">${esc(l.detail||'-')}</td></tr>`).join('')||'<tr><td colspan="5" class="empty">暂无</td></tr>'}
  </tbody></table></div>`;
}

// ---------- groups ----------
async function loadGroups(){
  try{
    const d=await api('groups',{},'GET');
    const rows=Array.isArray(d)?d:[];
    const grpCache={};rows.forEach(g=>{grpCache[g.id]=g});
    window._grpCache=grpCache;
    $('view-groups').innerHTML=`<div class="card"><div class="row-between mb12"><h3>客户分组 <small style="color:var(--muted);font-weight:normal">推广专用分组</small></h3><button class="btn primary" onclick="openGroupEdit(0)">＋ 新建分组</button></div>
    <table><thead><tr><th>分组名</th><th>成员数</th><th>操作</th></tr></thead><tbody>
    ${rows.map(g=>`<tr><td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${esc(g.group_color||'#3b82f6')};margin-right:6px"></span>${esc(g.group_name)}</td><td>${g.member_count}</td>
    <td><button class="btn xs" onclick="viewGroupMembers(${g.id})">查看成员</button>
    <button class="btn xs" onclick="openGroupEditById(${g.id})">改名</button>
    <button class="btn xs danger" onclick="delGroup(${g.id})">删除</button></td></tr>`).join('')||'<tr><td colspan="3" class="empty">暂无分组，点右上角"新建分组"</td></tr>'}
    </tbody></table></div><div id="groupMemberBox"></div>`;
  }catch(e){
    $('view-groups').innerHTML=`<div class="card"><div class="empty">加载失败：${esc(e.message)} <button class="btn xs" onclick="loadGroups()">重试</button></div></div>`;
  }
}
function openGroupEditById(id){const g=(window._grpCache||{})[id];if(!g)return;openGroupEdit(g.id,g.group_name,g.group_color)}
async function viewGroupMembers(id){
  const g=(window._grpCache||{})[id]||{group_name:''};
  const d=await api('group_members',{id},'GET');
  $('groupMemberBox').innerHTML=`<div class="card"><div class="row-between mb12"><h3>「${esc(g.group_name)}」成员（${d.length}）</h3>
  <div><button class="btn" onclick="openGroupEditById(${id})">＋ 管理成员</button> <button class="btn" onclick="$('groupMemberBox').innerHTML=''">关闭</button></div></div>
  <table><thead><tr><th>客户</th><th>国家</th><th>邮箱</th><th>操作</th></tr></thead><tbody>
  ${d.map(m=>`<tr><td>${esc(m.customer_name)}</td><td>${esc(m.country||'-')}</td><td style="font-size:12px">${esc(m.email||'-')}</td><td><button class="btn xs danger" onclick="removeGroupMember(${id},${m.id})">移除</button></td></tr>`).join('')||'<tr><td colspan="4" class="empty">暂无成员</td></tr>'}
  </tbody></table></div>`;
}
async function removeGroupMember(gid,cid){await api('group_remove_member',{id:gid,customer_id:cid});toast('已移除');viewGroupMembers(gid);loadGroups()}
let grpEditId=0, grpSelected=new Map(), grpCustPage=1, grpCustCache={};
function openGroupEdit(id,name,color){
  grpEditId=id||0;grpSelected=new Map();grpCustPage=1;
  $('ge-title').textContent=id?'编辑分组':'新建分组';
  $('ge-name').value=name||'';
  $('ge-color').value=color||'#3b82f6';
  $('groupEditModal').classList.add('show');
  initGroupCust();
}
function closeGroupEdit(){$('groupEditModal').classList.remove('show')}
async function initGroupCust(){
  const f=await api('filter_options',{},'GET');
  $('ge-country').innerHTML='<option value="">全部国家</option>'+f.countries.map(c=>`<option>${esc(c)}</option>`).join('');
  $('ge-level').innerHTML='<option value="">全部等级</option>'+f.levels.map(c=>`<option>${esc(c)}</option>`).join('');
  $('ge-owner').innerHTML='<option value="">全部负责人</option>'+(f.owners||[]).map(o=>`<option value="${o.id}">${esc(o.real_name)}</option>`).join('');
  if(grpEditId){
    const m=await api('group_members',{id:grpEditId},'GET');
    m.forEach(x=>grpSelected.set(x.id,{name:x.customer_name}));
  }
  loadGroupCust();
}
async function loadGroupCust(){
  const d=await api('customers',{kw:$('ge-kw').value,country:$('ge-country').value,level:$('ge-level').value,owner:$('ge-owner').value,page:grpCustPage},'GET');
  grpCustCache={};d.rows.forEach(r=>{grpCustCache[r.id]=r});
  $('ge-custRows').innerHTML=d.rows.map(r=>`<tr><td><input type="checkbox" data-cid="${r.id}" ${grpSelected.has(r.id)?'checked':''} onchange="grpToggle(this)"></td>
  <td><b>${esc(r.customer_name)}</b></td><td>${esc(r.country||'-')}</td><td style="font-size:12px">${esc(r.email||'-')}</td></tr>`).join('')||'<tr><td colspan="4" class="empty">没有符合条件的客户</td></tr>';
  $('ge-pageInfo').textContent=`第 ${d.page} 页 / 共 ${d.total} 个`;
  $('ge-selCount').textContent=grpSelected.size;
}
function grpToggle(cb){const id=+cb.dataset.cid;const r=grpCustCache[id];if(!r)return;cb.checked?grpSelected.set(id,{name:r.customer_name}):grpSelected.delete(id);$('ge-selCount').textContent=grpSelected.size}
function grpToggleAll(box){document.querySelectorAll('#ge-custRows input[type=checkbox]').forEach(cb=>{if(cb.checked!==box.checked){cb.checked=box.checked;grpToggle(cb)}})}
async function saveGroup(){
  const name=$('ge-name').value.trim();
  if(!name)return toast('请填写分组名称',1);
  let gid=grpEditId;
  if(!gid){
    const r=await api('group_create',{group_name:name,group_color:$('ge-color').value});
    gid=r.id;
  }else{
    await api('group_update',{id:gid,group_name:name,group_color:$('ge-color').value});
    // 先清空再重建（编辑模式）
    const cur=await api('group_members',{id:gid},'GET');
    for(const m of cur)await api('group_remove_member',{id:gid,customer_id:m.id});
  }
  if(grpSelected.size){
    await api('group_add_members',{id:gid,customer_ids:[...grpSelected.keys()]});
  }
  toast('已保存');closeGroupEdit();loadGroups();
}
async function delGroup(id){if(!confirm('确定删除这个分组吗？组内客户不受影响。'))return;await api('group_delete',{id});toast('已删除');loadGroups()}
// ---------- campaigns ----------
async function loadCampaigns(){
  const d=await api('campaigns',{});
  $('view-campaigns').innerHTML=`<div class="card"><h3>推广活动 ${d.is_manager?'<span class="badge blue">主管视图</span>':''}</h3>
  ${d.rows.length?`<table><tr><th>活动</th><th>创建人</th><th>状态</th><th>邮件</th><th>线下</th><th>回信</th><th>操作</th></tr>${d.rows.map(c=>{
    const s=c.stats;const done=s.sent+s.failed+s.bounced;const tot=done+s.pending;
    const pct=tot?Math.round(done/tot*100):0;
    return `<tr><td><b>${esc(c.title)}</b><br><span style="color:var(--muted);font-size:12px">${esc(c.created_at)}</span></td>
    <td>${esc(c.creator_name||'')}</td>
    <td>${statusBadge(c.status)}</td>
    <td>发${s.sent} / 待${s.pending} / 败${s.failed}</td>
    <td>${c.offline_done}/${c.offline_total} 完成</td>
    <td>${c.reply_count||0}</td>
    <td><button class="small" onclick="openDetail(${c.id})">详情</button>
    ${c.status!=='done'&&c.status!=='cancelled'?`<button class="small" onclick="sendCampaign(${c.id})">发送</button><button class="small danger" onclick="cancelCampaign(${c.id})">取消</button>`:''}</td></tr>`}).join('')}</table>`
  :'<div class="empty">还没有推广活动，点右上角"新建推广"开始</div>'}</div>`;
}
function statusBadge(s){const m={draft:['gray','草稿'],sending:['blue','发送中'],done:['green','已完成'],cancelled:['red','已取消']};const x=m[s]||['gray',s];return `<span class="badge ${x[0]}">${x[1]}</span>`}
async function sendCampaign(id){if(!confirm('确认开始发送？'))return;await api('campaign_send',{id});toast('已加入发送队列');loadCampaigns()}
async function cancelCampaign(id){if(!confirm('确认取消？未发送的不再发送。'))return;await api('campaign_cancel',{id});toast('已取消');loadCampaigns()}
async function openDetail(id){
  const d=await api('campaign_detail',{id},'GET');
  const c=d.campaign;const rs=d.recipients;const ts=d.offline_tasks;
  const emailN=rs.length, offN=ts.length;
  const sentN=rs.filter(r=>r.status==='sent').length, pendN=rs.filter(r=>r.status==='pending').length;
  let statsHtml='';
  try{
    const s=await api('campaign_stats',{id},'GET');
    const or=s.sent?Math.round((s.opened||0)/s.sent*100):0, cr=s.sent?Math.round((s.clicked||0)/s.sent*100):0;
    statsHtml=`<div class="card"><h3>效果数据</h3><div class="grid g4">
      <div class="kpi green"><span>打开率</span><b>${or}%</b><small style="color:var(--muted)">${s.opened||0}人打开</small></div>
      <div class="kpi orange"><span>点击率</span><b>${cr}%</b><small style="color:var(--muted)">${s.clicked||0}人点击</small></div>
      <div class="kpi purple"><span>回复</span><b>${s.replied||0}</b></div>
      <div class="kpi red"><span>退信/失败</span><b>${(s.bounced||0)+(s.failed||0)}</b></div>
    </div>
    ${s.top_opens&&s.top_opens.length?`<h3 style="margin-top:12px">打开最多</h3><table><tr><th>客户</th><th>打开次数</th><th>首次打开</th></tr>${s.top_opens.map(o=>`<tr><td>${esc(o.customer_name)}</td><td><span class="badge green">${o.open_count}次</span></td><td style="font-size:12px">${esc(o.first_opened_at||'')}</td></tr>`).join('')}</table>`:''}
    ${s.top_clicks&&s.top_clicks.length?`<h3 style="margin-top:12px">点击最多</h3><table><tr><th>客户</th><th>点击次数</th><th>首次点击</th></tr>${s.top_clicks.map(o=>`<tr><td>${esc(o.customer_name)}</td><td><span class="badge blue">${o.click_count}次</span></td><td style="font-size:12px">${esc(o.first_clicked_at||'')}</td></tr>`).join('')}</table>`:''}
    </div>`;
  }catch(e){}
  $('view-campaigns').innerHTML=`<button class="small" onclick="loadCampaigns()">‹ 返回列表</button>
  <div class="card"><h3>${esc(c.title)} ${statusBadge(c.status)} ${c.approval_status==='pending'?'<span class="badge orange">待审批</span>':c.approval_status==='approved'?'<span class="badge green">已批准</span>':''}</h3>
  <div class="grid g4">
    <div class="kpi"><span>走邮件</span><b>${emailN}</b></div>
    <div class="kpi"><span>走线下</span><b>${offN}</b></div>
    <div class="kpi"><span>已发送</span><b>${sentN}</b></div>
    <div class="kpi"><span>待发送</span><b>${pendN}</b></div>
  </div></div>
  ${statsHtml}
  <div class="card"><h3>邮件明细（${emailN}）</h3><div style="max-height:360px;overflow:auto"><table>
  <tr><th>客户</th><th>邮箱</th><th>国家</th><th>状态</th><th>计划/发送时间</th><th>回信</th></tr>
  ${rs.map(r=>`<tr><td>${esc(r.customer_name)}</td><td>${esc(r.email)}</td><td>${esc(r.country)}</td>
  <td>${r.status==='sent'?'<span class="badge green">已发</span>':r.status==='pending'?'<span class="badge gray">待发</span>':r.status==='failed'?`<span class="badge red">失败</span><br><small>${esc(r.error_text)}</small>`:'<span class="badge orange">退信</span>'}</td>
  <td style="font-size:12px">${esc(r.sent_at||r.scheduled_at||'-')}</td>
  <td>${r.has_reply=='1'?'✉️ 有':''}</td></tr>`).join('')}</table></div></div>
  <div class="card"><h3>线下执行（${offN}）</h3><table>
  <tr><th>客户</th><th>渠道</th><th>负责人</th><th>状态</th><th>完成时间</th></tr>
  ${ts.map(t=>`<tr><td>${esc(t.customer_name)}</td><td>${t.channel==='wechat'?'微信':'WhatsApp'}</td><td>${esc(t.assigned_name||t.assignee||'')}</td>
  <td>${t.status==='done'?'<span class="badge green">已完成</span>':'<span class="badge gray">待执行</span>'}</td>
  <td style="font-size:12px">${esc(t.done_at||'-')}</td></tr>`).join('')}</table></div>`;
  window.scrollTo(0,0);
}

// ---------- wizard ----------
let wzStep=1, custPage=1, selected=new Map(), wzAttachments=[];
function openWizard(){wzStep=1;selected=new Map();wzAttachments=[];$('w-editor').innerHTML='';$('w-subject').value='';$('attList').innerHTML='';$('previewBox').style.display='none';$('wizardModal').classList.add('show');wzRender();initWizard()}
function closeWizard(){$('wizardModal').classList.remove('show')}
function wzRender(){[1,2,3,4].forEach(i=>{$('wz-step'+i).style.display=i===wzStep?'':'none';const s=$('ws'+i);s.className='step'+(i===wzStep?' active':i<wzStep?' done':'')});$('wzPrev').style.display=wzStep>1?'':'none';$('wzNext').textContent=wzStep===4?'创建活动':'下一步';if(wzStep===3)refreshPreview()}
async function refreshPreview(){
  const ids=[...selected.keys()];
  $('pvCount').textContent=ids.length;
  if(!ids.length){$('previewBox').innerHTML='<div class="empty">请先在第一步选择客户</div>';return}
  const mode=document.querySelector('input[name=sendmode]:checked').value;
  if(mode==='scheduled'&&!$('w-sched').value){$('previewBox').innerHTML='<div class="empty">请先选择指定时间</div>';return}
  $('previewBox').innerHTML='<div class="empty">计算中…</div>';
  const d=await api('schedule_preview',{customer_ids:ids.slice(0,100),mode,scheduled_at:$('w-sched').value});
  const more=ids.length>100?`<div style="padding:8px;color:var(--muted);font-size:12px">仅显示前 100 位，共 ${ids.length} 位</div>`:'';
  $('previewBox').innerHTML=`<table><thead><tr><th>客户</th><th>国家</th><th>对方当地时间</th><th>北京时间</th></tr></thead><tbody>${d.map(r=>`<tr><td>${esc(r.customer_name)}</td><td>${esc(r.country||'-')}</td><td>${esc(r.local_time)} <small style="color:var(--muted)">${esc(r.timezone.split('/').pop().replace('_',' '))}</small></td><td><b>${esc(r.beijing_time)}</b></td></tr>`).join('')}</tbody></table>`+more;
}
async function initWizard(){
  const f=await api('filter_options',{},'GET');
  $('f-group').innerHTML='<option value="0">全部分组</option>'+(f.groups||[]).map(g=>`<option value="${g.id}">${esc(g.group_name)}</option>`).join('');
  $('f-country').innerHTML='<option value="">全部国家</option>'+f.countries.map(c=>`<option>${esc(c)}</option>`).join('');
  $('f-level').innerHTML='<option value="">全部等级</option>'+f.levels.map(c=>`<option>${esc(c)}</option>`).join('');
  $('f-owner').innerHTML='<option value="">全部负责人</option>'+f.owners.map(o=>`<option value="${o.id}">${esc(o.real_name)}</option>`).join('');
  const ls=await api('lists',{},'GET');
  $('f-list').innerHTML='<option value="0">全部（不用名单）</option>'+ls.rows.map(l=>`<option value="${l.id}">${esc(l.name)}</option>`).join('');
  const tpls=await api('templates',{},'GET');
  $('tplSel').innerHTML='<option value="">从模板选择…</option>'+tpls.rows.map(t=>`<option value="${t.id}">${esc(t.title)}</option>`).join('');
  const accs=await api('mail_accounts',{},'GET');
  $('w-account').innerHTML=accs.rows.map(a=>`<option value="${a.id}">${esc(a.email_address)}${a.is_default?'（默认）':''}</option>`).join('')||'<option value="0">无可用邮箱</option>';
  loadCustomers();
}
let custCache={};
async function loadCustomers(){
  const d=await api('customers',{kw:$('f-kw').value,group_id:$('f-group').value,country:$('f-country').value,level:$('f-level').value,owner:$('f-owner').value,list_id:$('f-list').value,page:custPage},'GET');
  const chBadge={email:'<span class="badge blue">邮件</span>',wechat:'<span class="badge green">微信</span>',whatsapp:'<span class="badge orange">WA</span>'};
  custCache={};
  d.rows.forEach(r=>{custCache[r.id]=r});
  $('custRows').innerHTML=d.rows.map(r=>`<tr><td><input type="checkbox" data-cid="${r.id}" ${selected.has(r.id)?'checked':''} onchange="toggleCustById(this)"></td>
  <td><b>${esc(r.customer_name)}</b><br><small style="color:var(--muted)">${esc(r.customer_code||'')}</small></td>
  <td>${esc(r.country||'-')}</td><td style="font-size:12px">${esc(r.email||'-')}</td><td>${chBadge[r.channel]||r.channel}</td><td>${esc(r.owner_name||'-')}</td></tr>`).join('')||'<tr><td colspan="6" class="empty">没有符合条件的客户</td></tr>';
  $('pageInfo').textContent=`第 ${d.page} 页 / 共 ${d.total} 个`;
  updateSelCount();
}
function toggleCustById(cb){const id=+cb.dataset.cid;const r=custCache[id];if(!r)return;cb.checked?selected.set(id,{name:r.customer_name,email:r.email,channel:r.channel}):selected.delete(id);updateSelCount()}
function toggleCust(id,name,email,channel,on){on?selected.set(id,{name,email,channel}):selected.delete(id);updateSelCount()}
function toggleAll(box){document.querySelectorAll('#custRows input[type=checkbox]').forEach(cb=>{cb.checked=box.checked;cb.onchange&&cb.dispatchEvent(new Event('change'))})}
function updateSelCount(){let e=0,w=0,wa=0;selected.forEach(v=>{v.channel==='email'?e++:v.channel==='wechat'?w++:wa++});$('selCount').textContent=selected.size;$('selEmail').textContent=e;$('selWechat').textContent=w;$('selWa').textContent=wa}
async function saveAsList(){if(!selected.size)return toast('先选客户',1);const name=prompt('名单名称：');if(!name)return;await api('list_save',{name,customer_ids:[...selected.keys()]});toast('已保存');initWizard()}
function fmt(c,v){document.execCommand(c,false,v);$('w-editor').focus()}
function insVar(k){fmt('insertText','{{'+k+'}}')}
async function uploadImage(input){const f=input.files[0];if(!f)return;const d=await uploadFile(f);fmt('insertImage',d.url);input.value=''}
async function uploadAttach(input){const f=input.files[0];if(!f)return;const d=await uploadFile(f);wzAttachments.push(d);renderAtts();input.value=''}
async function uploadFile(f){const fd=new FormData();fd.append('file',f);const r=await fetch('api.php?action=upload',{method:'POST',credentials:'same-origin',body:fd});const t=await r.text();const j=JSON.parse(t);if(!j.success)throw new Error(j.message);return j.data}
function renderAtts(){$('attList').innerHTML=wzAttachments.map((a,i)=>`<span class="badge blue" style="margin:2px">📎 ${esc(a.name)} <a href="#" onclick="wzAttachments.splice(${i},1);renderAtts();return false" style="color:#991b1b">✕</a></span>`).join('')}
async function loadTemplate(id){if(!id)return;const d=await api('template_get',{id},'GET');$('w-subject').value=d.subject||'';$('w-editor').innerHTML=d.body_html||''}
async function saveAsTemplate(){const t=prompt('模板标题：');if(!t)return;await api('template_save',{title:t,subject:$('w-subject').value,body_html:$('w-editor').innerHTML});toast('已存为模板')}
async function doPreview(){const d=await api('preview',{body_html:$('w-editor').innerHTML,customer_id:[...selected.keys()][0]||0});$('previewBox').style.display='';$('previewBox').innerHTML='<b>预览（变量已渲染）：</b><hr>'+d.html}
async function doTestSend(){const em=$('testEmail').value.trim();if(!em)return toast('先填试发邮箱',1);toast('发送中…');await api('test_send',{to:em,subject:$('w-subject').value,body_html:$('w-editor').innerHTML,mail_account_id:$('w-account').value});toast('试发已发送')}
function wzGo(dir){
  if(dir>0){
    if(wzStep===1&&!selected.size)return toast('请先选择客户',1);
    if(wzStep===2&&!$('w-subject').value.trim())return toast('请填写邮件主题',1);
    if(wzStep===3){
      const mode=document.querySelector('input[name=sendmode]:checked').value;
      if(mode==='scheduled'&&!$('w-sched').value)return toast('请选择发送时间',1);
      buildConfirm();wzStep=4;wzRender();return;
    }
    wzStep=Math.min(4,wzStep+1);
  }else wzStep=Math.max(1,wzStep-1);
  wzRender();
}
function buildConfirm(){
  let e=0,w=0,wa=0;selected.forEach(v=>{v.channel==='email'?e++:v.channel==='wechat'?w++:wa++});
  const mode=document.querySelector('input[name=sendmode]:checked').value;
  const modeTxt={now:'马上发送',scheduled:'指定时间：'+$('w-sched').value,smart:'按客户国家工作时间智能发送'}[mode];
  $('confirmBox').innerHTML=`<table>
  <tr><td>邮件主题</td><td><b>${esc($('w-subject').value)}</b></td></tr>
  <tr><td>客户</td><td>共 ${selected.size} 个：邮件 ${e} / 微信 ${w} / WhatsApp ${wa}</td></tr>
  <tr><td>发送方式</td><td>${esc(modeTxt)}</td></tr>
  <tr><td>发件邮箱</td><td>${esc($('w-account').selectedOptions[0]?.text||'自动轮换')}</td></tr>
  <tr><td>每批发送</td><td>${$('w-batch').value} 封</td></tr>
  <tr><td>频率控制</td><td>${$('w-freq').value} 天内不重复发送</td></tr>
  <tr><td>审批</td><td>${$('w-approval').checked?'<span class="badge orange">需主管审批</span>':'<span class="badge gray">无需审批</span>'}</td></tr>
  <tr><td>附件</td><td>${wzAttachments.length} 个</td></tr></table>
  <p style="color:var(--muted);font-size:13px">微信/WhatsApp 客户将生成线下执行清单分配给负责人；邮件按上述方式发送。每封邮件自动带打开追踪、点击追踪和退订链接。</p>
  <div style="margin-top:8px"><input id="w-title" placeholder="活动标题（必填）" style="width:100%"></div>`;
  runDedupPreview();
}
async function runDedupPreview(){
  $('dedupBox').innerHTML='<div class="empty">检查中…</div>';
  try{
    const d=await api('dedup_preview',{customer_ids:[...selected.keys()],frequency_days:+$('w-freq').value});
    $('dedupBox').innerHTML=`<div class="grid g4">
      <div class="kpi"><span>总计</span><b>${d.total}</b></div>
      <div class="kpi green"><span>可发送</span><b>${d.ok}</b></div>
      <div class="kpi orange"><span>重复/无效</span><b>${d.dup_email}</b></div>
      <div class="kpi red"><span>黑名单/退订/频控拦截</span><b>${d.unsub+d.freq_blocked}</b></div>
    </div>`;
  }catch(e){$('dedupBox').innerHTML=`<div class="empty">检查失败：${esc(e.message)}</div>`}
}
async function runSpamCheck(){
  $('spamResult').textContent='检查中…';
  const d=await api('spam_check',{subject:$('w-subject').value,body_html:$('w-editor').innerHTML});
  $('spamResult').innerHTML=d.score>=60?`<span class="badge red">风险${d.level}（${d.score}分）</span> 敏感词：${d.hits.map(esc).join('、')}`
    :d.score>=30?`<span class="badge orange">风险${d.level}（${d.score}分）</span> 敏感词：${d.hits.map(esc).join('、')}`
    :`<span class="badge green">风险低（${d.score}分）</span>`;
}
async function createCampaign(){
  const title=$('w-title').value.trim();if(!title)return toast('请填写活动标题',1);
  const mode=document.querySelector('input[name=sendmode]:checked').value;
  const d=await api('campaign_create',{title,subject:$('w-subject').value,body_html:$('w-editor').innerHTML,send_mode:mode,scheduled_at:$('w-sched').value,customer_ids:[...selected.keys()],mail_account_id:$('w-account').value,attachments:wzAttachments,frequency_days:+$('w-freq').value,batch_size:+$('w-batch').value,require_approval:$('w-approval').checked?1:0});
  closeWizard();toast(d.message||'活动已创建');
  if(d.need_approval){toast('已提交审批，主管批准后才能发送');}
  else if(mode==='now'&&d.id&&confirm('活动已创建，是否立即开始发送？')){await api('campaign_send',{id:d.id});}
  showTab('campaigns');loadCampaigns();
}
// 重写创建：第四步按钮变为创建
const _wzRender=wzRender;
wzRender=function(){_wzRender();if(wzStep===4){$('wzNext').textContent='创建活动';$('wzNext').onclick=createCampaign}else{$('wzNext').textContent='下一步';$('wzNext').onclick=()=>wzGo(1)}};

// ---------- tasks ----------
async function loadTasks(){
  const d=await api('offline_tasks',{mine:1},'GET');
  const rows=d.rows;
  const pend=rows.filter(t=>t.status==='pending').length;
  $('taskBadge').innerHTML=pend?`<span class="badge red">${pend}</span>`:'';
  $('view-tasks').innerHTML=`<div class="card"><h3>我的执行清单 ${d.is_manager?'<button class="small" onclick="loadAllTasks()">看全部</button>':''}</h3>
  ${rows.length?`<table><tr><th>活动</th><th>客户</th><th>渠道</th><th>状态</th><th>备注</th><th>操作</th></tr>
  ${rows.map(t=>`<tr style="${t.status==='done'?'opacity:.6':''}"><td>${esc(t.campaign_title||'')}</td><td><b>${esc(t.customer_name)}</b></td>
  <td>${t.channel==='wechat'?'微信':'WhatsApp'}</td>
  <td>${t.status==='done'?'<span class="badge green">已完成</span>':'<span class="badge gray">待执行</span>'}</td>
  <td style="font-size:12px">${esc(t.note||'')}</td>
  <td>${t.status!=='done'?`<button class="small primary" onclick="doneTask(${t.id})">标记完成</button>`:esc(t.done_at||'')}</td></tr>`).join('')}</table>`
  :'<div class="empty">暂无执行任务</div>'}</div>`;
}
async function loadAllTasks(){const d=await api('offline_tasks',{mine:0},'GET');
  $('view-tasks').innerHTML=`<div class="card"><h3>全部执行清单 <button class="small" onclick="loadTasks()">只看我的</button></h3>
  <table><tr><th>活动</th><th>客户</th><th>渠道</th><th>负责人</th><th>状态</th></tr>
  ${d.rows.map(t=>`<tr><td>${esc(t.campaign_title||'')}</td><td>${esc(t.customer_name)}</td><td>${t.channel==='wechat'?'微信':'WhatsApp'}</td><td>${esc(t.assignee||t.assigned_name||'')}</td><td>${t.status==='done'?'<span class="badge green">完成</span>':'<span class="badge gray">待执行</span>'}</td></tr>`).join('')}</table></div>`}
async function doneTask(id){const note=prompt('备注（可选）：')||'';await api('offline_done',{id,note});toast('已完成');loadTasks()}

// ---------- templates ----------
async function loadTemplates(){
  const d=await api('templates',{},'GET');
  $('view-templates').innerHTML=`<div class="card"><h3>邮件模板</h3>
  ${d.rows.map(t=>`<div style="border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">
  <div><b>${esc(t.title)}</b><br><small style="color:var(--muted)">${esc(t.subject||'')}</small></div>
  <div><button class="small" onclick="delTemplate(${t.id})">删除</button></div></div>`).join('')||'<div class="empty">暂无模板，在新建推广第二步可存为模板</div>'}</div>`;
}
async function delTemplate(id){if(!confirm('删除这个模板？'))return;await api('template_delete',{id});loadTemplates()}

// ---------- blacklist ----------
async function loadBlacklist(){
  const d=await api('blacklist',{},'GET');
  $('view-blacklist').innerHTML=`<div class="card"><h3>黑名单 <span style="font-size:12px;color:var(--muted)">退信自动加入，不再发送</span></h3>
  <div class="toolbar2"><input id="bl-email" placeholder="邮箱地址"><input id="bl-reason" placeholder="原因"><button class="small" onclick="addBlack()">加入</button></div>
  <table><tr><th>邮箱</th><th>原因</th><th>时间</th><th>操作</th></tr>
  ${d.rows.map(r=>`<tr><td>${esc(r.email)}</td><td>${esc(r.reason)}</td><td style="font-size:12px">${esc(r.created_at)}</td><td><button class="small" onclick="delBlack(${r.id})">移除</button></td></tr>`).join('')}</table></div>`;
}
async function addBlack(){const e=$('bl-email').value.trim();if(!e)return;await api('blacklist_add',{email:e,reason:$('bl-reason').value});loadBlacklist()}
async function delBlack(id){await api('blacklist_remove',{id});loadBlacklist()}

loadDashboard();
</script>

<!-- 分组编辑弹窗 -->
<div class="modal" id="groupEditModal"><div class="modalBox">
  <div class="row-between mb12"><h3 id="ge-title">新建分组</h3><button class="btn" onclick="closeGroupEdit()">✕</button></div>
  <div class="card">
    <label>分组名称 <input id="ge-name" placeholder="如：中东大客户" style="width:300px"></label>
    <label style="margin-left:12px">颜色 <input type="color" id="ge-color" value="#3b82f6"></label>
  </div>
  <div class="card"><h3>选择客户 <span style="font-size:12px;color:var(--muted)">已选 <b id="ge-selCount">0</b> 个</span></h3>
    <div class="filters">
      <input id="ge-kw" placeholder="客户名 / 编码 / 邮箱" onkeydown="if(event.key==='Enter')loadGroupCust()">
      <select id="ge-country"><option value="">全部国家</option></select>
      <select id="ge-level"><option value="">全部等级</option></select>
      <select id="ge-owner"><option value="">全部负责人</option></select>
      <button onclick="loadGroupCust()">查询</button>
    </div>
    <div style="max-height:320px;overflow:auto"><table>
      <thead><tr><th><input type="checkbox" onchange="grpToggleAll(this.checked)"></th><th>客户</th><th>国家</th><th>邮箱</th></tr></thead>
      <tbody id="ge-custRows"></tbody>
    </table></div>
    <div class="row-between mt12"><span id="ge-pageInfo" style="color:var(--muted);font-size:12px"></span>
    <span><button class="btn xs" onclick="grpCustPage=Math.max(1,grpCustPage-1);loadGroupCust()">‹ 上一页</button>
    <button class="btn xs" onclick="grpCustPage++;loadGroupCust()">下一页 ›</button></span></div>
  </div>
  <div style="text-align:right;margin-top:12px"><button class="btn primary" onclick="saveGroup()">保存分组</button></div>
</div></div>
</body>
</html>
