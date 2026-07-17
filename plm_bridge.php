<?php
/* Artdon PLM 7.7 Safe Bridge Config */
require_once __DIR__ . '/plm_auth.php';
plm_auth_require('admin','admin');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PLM 7.2 安全联动配置</title>
<style>
:root{--line:#dbe4ef;--blue:#2563eb;--green:#16a34a;--orange:#f59e0b;--red:#dc2626;--bg:#f6f9fd;--text:#0f172a;--muted:#64748b;--shadow:0 14px 36px rgba(15,23,42,.08)}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#eef6ff,#f8fbff);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;color:var(--text);font-size:14px}.app{max-width:1260px;margin:auto;padding:18px}.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.brand{font-weight:900;color:#1d4ed8}.top h1{margin:4px 0;font-size:28px}.sub,.hint{font-size:12px;color:var(--muted);font-weight:800;line-height:1.55}.panel{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow);padding:14px;margin:14px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.card{border:1px solid var(--line);border-radius:16px;background:#fff;padding:12px}.card h3{margin:0 0 8px}.btn{border:1px solid var(--line);background:#fff;border-radius:12px;padding:9px 13px;font-weight:900;cursor:pointer;text-decoration:none;color:#1e293b}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.good{background:#dcfce7;border-color:#bbf7d0;color:#15803d}.btn.warn{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.actions{display:flex;gap:8px;flex-wrap:wrap}input,select,textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:9px 11px;background:#fff;font:inherit;outline:none}.field label{display:block;color:#64748b;font-size:12px;font-weight:900;margin:7px 0 4px}.tag{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:12px;font-weight:900;margin:2px}.tag.green{background:#dcfce7;color:#15803d}.tag.blue{background:#dbeafe;color:#1d4ed8}.tag.orange{background:#ffedd5;color:#c2410c}.tag.red{background:#fee2e2;color:#991b1b}.cols{max-height:92px;overflow:auto;background:#f8fafc;border-radius:12px;padding:8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#475569}.table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top}.safe{border-left:6px solid var(--green)}.warnbox{border-left:6px solid var(--orange);background:#fff7ed}.toast{position:fixed;right:18px;bottom:18px;background:#111827;color:#fff;border-radius:14px;padding:13px 16px;display:none;box-shadow:0 18px 50px rgba(15,23,42,.28)}.toast.show{display:block}@media(max-width:900px){.grid,.grid2{grid-template-columns:1fr}.app{padding:10px}}
</style>
</head>
<body>
<div class="app">
  <div class="top">
    <div><div class="brand">中山雅大光电有限公司 · Artdon Lighting Limited</div><h1>PLM 7.2 安全联动配置</h1><div class="sub">优先打通 PLM → BOM → 报价 → CRM。只按你选定的真实表写入，不改 BOM / 报价 / 共享物料库表结构。</div></div>
    <div class="actions"><a class="btn primary" href="plm.php">返回PLM</a><a class="btn" href="users.php">用户权限</a><a class="btn" href="bom.php">打开BOM</a><a class="btn" href="quotation.php">打开报价</a><a class="btn danger" href="logout.php">退出</a></div>
  </div>

  <div class="panel safe">
    <h2>7.2 安全规则</h2>
    <div class="grid">
      <div class="card"><h3>1. 先选表再写入</h3><div class="hint">BOM主表、BOM明细表、报价产品表、共享物料库表都可手动指定。</div></div>
      <div class="card"><h3>2. 不改原表结构</h3><div class="hint">外部表字段不存在就跳过，不执行 ALTER，不破坏你现有 BOM/报价数据。</div></div>
      <div class="card"><h3>3. 写不进去就交接</h3><div class="hint">字段不匹配时只生成 PLM 交接单和跳转参数，不乱写数据库。</div></div>
    </div>
  </div>

  <div class="panel warnbox">
    <h2>联动配置</h2>
    <div class="grid2">
      <div class="field"><label>BOM 主表：建议 bom_projects</label><select id="bom_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>BOM 明细表：建议 bom_items</label><select id="bom_items_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>共享物料库表：建议 bom_materials</label><select id="material_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>BOM 页面地址</label><input id="bom_url" value="bom.php"></div>
      <div class="field"><label>报价产品表：建议 quote_products</label><select id="quote_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>报价单表：建议 quote_orders</label><select id="quote_order_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>CRM报价表：建议 crm_quotes</label><select id="crm_quote_table"><option value="">自动/不指定</option></select></div>
      <div class="field"><label>报价页面地址</label><input id="quote_url" value="quotation.php"></div>
      <div class="field"><label>CRM 页面地址</label><input id="crm_url" value="crm.php"></div>
    </div>
    <div class="actions" style="margin-top:12px"><button class="btn primary" onclick="saveConfig()">保存联动配置</button><button class="btn" onclick="autoPick()">按推荐自动选择</button><button class="btn" onclick="loadDetect()">重新检测</button></div>
    <p class="hint">按你截图，推荐选择：BOM主表 bom_projects、BOM明细表 bom_items、共享物料库 bom_materials、报价产品 quote_products、报价单 quote_orders、CRM报价 crm_quotes。</p>
  </div>

  <div class="panel">
    <h2>数据库检测结果</h2>
    <div id="overview" class="hint">正在检测...</div>
    <div class="grid" id="candidates"></div>
  </div>

  <div class="panel">
    <h2>最近 PLM → BOM / 报价交接单</h2>
    <div id="handoffs" class="hint">正在读取...</div>
  </div>
</div>
<div id="toast" class="toast"></div>
<script>
const API='plm_api.php';
const $=id=>document.getElementById(id);
let bridge=null;
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function toast(t){const x=$('toast');x.textContent=t;x.classList.add('show');setTimeout(()=>x.classList.remove('show'),2600)}
async function api(action,data={}){try{const r=await fetch(API+'?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});return await r.json();}catch(e){return {ok:false,error:e.message}}}
function opt(table,cols){return `<option value="${esc(table)}">${esc(table)} ｜ ${cols.length}字段</option>`}
function fillSelect(id,arr,cur){const el=$(id);let html='<option value="">自动/不指定</option>'+(arr||[]).map(x=>opt(x.table,x.cols||[])).join('');el.innerHTML=html;el.value=cur||''}
function candCard(title,arr,kind){return `<div class="card"><h3>${esc(title)} <span class="tag blue">${(arr||[]).length}</span></h3>${(arr||[]).slice(0,8).map(x=>`<div style="margin-bottom:8px"><b>${esc(x.table)}</b> <span class="tag green">${x.score}</span><div class="cols">${esc((x.cols||[]).join(', '))}</div><button class="btn" style="margin-top:5px" onclick="pick('${kind}','${esc(x.table)}')">选这张表</button></div>`).join('')||'<div class="hint">未检测到</div>'}</div>`}
function pick(kind,table){$(kind+'_table').value=table;toast('已选择：'+table+'，记得点击保存联动配置')}
async function loadDetect(){
  let r=await api('bridge_detect');
  if(!r.ok){$('overview').innerHTML='<span class="tag red">检测失败</span> '+esc(r.error||'');toast(r.error||'检测失败');return}
  bridge=r.bridge||{}; const cfg=bridge.config||{};
  fillSelect('bom_table',bridge.bom||[],cfg.bom_table); fillSelect('bom_items_table',bridge.bom_items||[],cfg.bom_items_table);
  fillSelect('quote_table',bridge.quote||[],cfg.quote_table); fillSelect('quote_order_table',bridge.quote_order||[],cfg.quote_order_table); fillSelect('crm_quote_table',bridge.crm_quote||[],cfg.crm_quote_table);
  fillSelect('material_table',bridge.material||[],cfg.material_table);
  $('bom_url').value=cfg.bom_url||'bom.php'; $('quote_url').value=cfg.quote_url||'quotation.php'; $('crm_url').value=cfg.crm_url||'crm.php';
  $('overview').innerHTML=`当前数据库：<b>${esc(bridge.database||'-')}</b>　PLM表：${(bridge.plm_tables||[]).map(x=>`<span class="tag">${esc(x)}</span>`).join('')}`;
  $('candidates').innerHTML=candCard('BOM主表候选',bridge.bom||[],'bom')+candCard('BOM明细表候选',bridge.bom_items||[],'bom_items')+candCard('共享物料库候选',bridge.material||[],'material')+candCard('报价产品候选',bridge.quote||[],'quote')+candCard('报价单候选',bridge.quote_order||[],'quote_order')+candCard('CRM报价候选',bridge.crm_quote||[],'crm_quote');
  loadHandoffs();
}
function first(arr,name){arr=arr||[]; const exact=arr.find(x=>x.table===name); return exact?exact.table:(arr[0]?arr[0].table:'')}
function autoPick(){ if(!bridge){toast('请先检测');return} $('bom_table').value=first(bridge.bom,'bom_projects'); $('bom_items_table').value=first(bridge.bom_items,'bom_items'); $('material_table').value=first(bridge.material,'bom_materials'); $('quote_table').value=first(bridge.quote,'quote_products'); $('quote_order_table').value=first(bridge.quote_order,'quote_orders'); $('crm_quote_table').value=first(bridge.crm_quote,'crm_quotes'); toast('已按推荐选择，记得保存'); }
async function saveConfig(){
  const config={bom_table:$('bom_table').value, bom_items_table:$('bom_items_table').value, material_table:$('material_table').value, quote_table:$('quote_table').value, quote_order_table:$('quote_order_table').value, crm_quote_table:$('crm_quote_table').value, bom_url:$('bom_url').value||'bom.php', quote_url:$('quote_url').value||'quotation.php', crm_url:$('crm_url').value||'crm.php'};
  let r=await api('save_bridge_config',{config}); if(!r.ok){toast(r.error||'保存失败');return} toast('联动配置已保存'); loadDetect();
}
async function loadHandoffs(){
  let r=await api('handoffs'); if(!r.ok){$('handoffs').textContent=r.error||'读取失败';return}
  const rows=r.handoffs||[];
  $('handoffs').innerHTML=rows.length?`<table class="table"><thead><tr><th>ID</th><th>类型</th><th>项目</th><th>样品</th><th>状态</th><th>目标表</th><th>操作</th></tr></thead><tbody>${rows.map(x=>`<tr><td>${x.id}</td><td>${esc(x.target_type)}</td><td>${x.project_id}</td><td>${x.model_id}</td><td>${esc(x.status)}</td><td>${esc(x.target_table||'-')} #${esc(x.target_id||'')}</td><td>${x.open_url?`<a class="btn" href="${esc(x.open_url)}" target="_blank">打开</a>`:''}</td></tr>`).join('')}</tbody></table>`:'暂无交接单';
}
loadDetect();
</script>
</body>
</html>
