<?php
// Artdon PLM → 派工待办按钮自动补丁 V7.6
// 上传到 Artdon 目录后访问本文件，点击“安装/更新 PLM 派工按钮”。
error_reporting(E_ALL);
ini_set('display_errors','1');

$root = __DIR__;
$plm = $root . '/plm.php';
$msg = '';
$ok = false;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function patch_plm($plm){
    if (!is_file($plm)) {
        throw new RuntimeException('没有找到 plm.php，请确认本文件放在 /Artdon/ 目录。');
    }
    $code = file_get_contents($plm);
    if ($code === false || $code === '') throw new RuntimeException('读取 plm.php 失败。');

    if (strpos($code, 'PLM_DISPATCH_BRIDGE_V76') !== false) {
        return 'PLM 派工按钮已经安装过，无需重复安装。';
    }

    $backup = $plm . '.bak_v76_' . date('Ymd_His');
    if (!copy($plm, $backup)) throw new RuntimeException('备份 plm.php 失败，请检查目录权限。');

    $css = <<<'CSS'

/* PLM_DISPATCH_BRIDGE_V76 */
.pd-modal-mask{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:99999;display:none;align-items:center;justify-content:center;padding:18px}.pd-modal-mask.show{display:flex}.pd-modal{width:min(720px,96vw);background:#fff;border:1px solid #dbe5f1;border-radius:26px;box-shadow:0 24px 80px rgba(15,23,42,.24);overflow:hidden}.pd-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid #e5edf7;background:linear-gradient(135deg,#f8fbff,#eff6ff)}.pd-head h3{margin:0;font-size:22px;letter-spacing:-.03em}.pd-close{border:0;background:#eef2ff;color:#1e293b;border-radius:12px;padding:8px 12px;font-weight:1000;cursor:pointer}.pd-body{padding:18px 20px}.pd-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pd-field label{display:block;font-size:12px;color:#64748b;font-weight:1000;margin:4px 0 6px}.pd-field.full{grid-column:1/-1}.pd-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:16px 20px;border-top:1px solid #e5edf7;background:#f8fafc}.pd-info{font-size:12px;color:#64748b;font-weight:800;line-height:1.5}.pd-btn{border:1px solid #dbe5f1;background:#fff;color:#0f172a;border-radius:14px;padding:11px 16px;font-weight:1000;cursor:pointer}.pd-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}.pd-btn.dark{background:#0f172a;border-color:#0f172a;color:#fff}@media(max-width:640px){.pd-grid{grid-template-columns:1fr}.pd-foot{display:grid}.pd-btn{width:100%}}
CSS;

    if (strpos($code, '</style>') !== false) {
        $code = str_replace('</style>', $css . "\n</style>", $code);
    } else {
        throw new RuntimeException('未找到 </style>，无法安全插入样式。');
    }

    $modal = <<<'HTML'

<!-- PLM_DISPATCH_BRIDGE_V76 -->
<div id="plmDispatchModal" class="pd-modal-mask">
  <div class="pd-modal">
    <div class="pd-head">
      <h3>从 PLM 生成派工</h3>
      <button class="pd-close" onclick="closePlmDispatch()">关闭</button>
    </div>
    <div class="pd-body">
      <div class="pd-grid">
        <div class="pd-field"><label>方式</label><select id="pd_mode" onchange="plmDispatchModeChanged()"><option value="dispatch">派工</option><option value="self">个人待办</option><option value="private">私人待办</option></select></div>
        <div class="pd-field"><label>负责人</label><select id="pd_assignee"></select></div>
        <div class="pd-field full"><label>任务标题</label><input id="pd_title" placeholder="例如：完成该项目 3D 图 / IES 测试 / BOM 整理"></div>
        <div class="pd-field"><label>待办日期</label><input id="pd_task_date" type="date"></div>
        <div class="pd-field"><label>截止时间</label><input id="pd_due_at" type="datetime-local"></div>
        <div class="pd-field"><label>客户</label><input id="pd_customer"></div>
        <div class="pd-field"><label>型号 / 系列</label><input id="pd_model"></div>
        <div class="pd-field full"><label>说明</label><textarea id="pd_desc" placeholder="要求、注意事项、交付标准"></textarea></div>
      </div>
    </div>
    <div class="pd-foot">
      <div class="pd-info" id="pd_project_info">当前 PLM 项目：-</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end"><button class="pd-btn" onclick="closePlmDispatch()">取消</button><button class="pd-btn primary" onclick="submitPlmDispatch()">生成派工</button><button class="pd-btn dark" onclick="location.href='dispatch_todo.php'">打开派工待办</button></div>
    </div>
  </div>
</div>
HTML;

    if (strpos($code, '<div id="toast" class="toast"></div>') !== false) {
        $code = str_replace('<div id="toast" class="toast"></div>', '<div id="toast" class="toast"></div>' . $modal, $code);
    } else if (strpos($code, "<div id='toast' class='toast'></div>") !== false) {
        $code = str_replace("<div id='toast' class='toast'></div>", "<div id='toast' class='toast'></div>" . $modal, $code);
    } else {
        $code = str_replace('<script>', $modal . "\n<script>", $code);
    }

    $button = '<button class="btn primary" onclick="openPlmDispatch()">生成派工</button>';
    if (strpos($code, 'onclick="openPlmDispatch()"') === false) {
        $target1 = '<button class="btn good" onclick="genQuoteProject()">整项目生成报价产品</button>';
        if (strpos($code, $target1) !== false) {
            $code = str_replace($target1, $target1 . $button, $code);
        } else {
            $target2 = '<button class="btn" onclick="location.href=\'quotation.php\'">打开报价</button>';
            if (strpos($code, $target2) !== false) {
                $code = str_replace($target2, $target2 . $button, $code);
            } else {
                $target3 = '<button class="btn" onclick="location.href=\'bom.php\'">打开BOM</button>';
                if (strpos($code, $target3) !== false) {
                    $code = str_replace($target3, $target3 . $button, $code);
                } else {
                    throw new RuntimeException('没有找到可插入按钮的位置。已生成备份，未覆盖文件。');
                }
            }
        }
    }

    $js = <<<'JS'

/* PLM_DISPATCH_BRIDGE_V76 */
let plmDispatchUsersCache=null;
function pdToday(){const d=new Date(); const m=String(d.getMonth()+1).padStart(2,'0'); const day=String(d.getDate()).padStart(2,'0'); return d.getFullYear()+'-'+m+'-'+day;}
async function plmDispatchUsers(){
  if(plmDispatchUsersCache) return plmDispatchUsersCache;
  try{
    const r=await fetch('dispatch_api.php?action=users',{credentials:'same-origin'});
    const j=await r.json();
    plmDispatchUsersCache=(j.users||[]).filter(u=>String(u.is_active??1)!=='0');
  }catch(e){plmDispatchUsersCache=[];}
  return plmDispatchUsersCache;
}
function plmDispatchModeChanged(){
  const mode=document.getElementById('pd_mode')?.value||'dispatch';
  const ass=document.getElementById('pd_assignee');
  if(ass) ass.disabled=(mode!=='dispatch');
}
async function openPlmDispatch(){
  const p=typeof cur==='function'?cur():null;
  if(!p){toast('请先选择一个 PLM 项目');return;}
  const users=await plmDispatchUsers();
  const ass=document.getElementById('pd_assignee');
  if(ass){
    ass.innerHTML=users.map(u=>`<option value="${u.id}">${esc(u.name||u.display_name||u.username||'未命名')}</option>`).join('');
  }
  document.getElementById('pd_mode').value='dispatch';
  document.getElementById('pd_title').value='处理：'+(p.name||p.project_name||'PLM项目');
  document.getElementById('pd_task_date').value=pdToday();
  document.getElementById('pd_due_at').value='';
  document.getElementById('pd_customer').value=p.customer||'';
  document.getElementById('pd_model').value=p.model||p.series||p.product_type||'';
  document.getElementById('pd_desc').value='来自 PLM 项目：'+(p.name||'')+'\n客户：'+(p.customer||'-')+'\n型号/系列：'+(p.model||p.series||'-');
  document.getElementById('pd_project_info').textContent='当前 PLM 项目：'+(p.name||'-')+' ｜ 客户：'+(p.customer||'-')+' ｜ ID：'+(p.id||'-');
  document.getElementById('plmDispatchModal').classList.add('show');
  plmDispatchModeChanged();
}
function closePlmDispatch(){const m=document.getElementById('plmDispatchModal'); if(m)m.classList.remove('show');}
async function submitPlmDispatch(){
  const p=typeof cur==='function'?cur():null;
  if(!p){toast('请先选择一个 PLM 项目');return;}
  const mode=document.getElementById('pd_mode').value;
  const payload={
    mode,
    assigned_to:parseInt(document.getElementById('pd_assignee').value||'0',10),
    title:document.getElementById('pd_title').value.trim(),
    description:document.getElementById('pd_desc').value,
    task_date:document.getElementById('pd_task_date').value||pdToday(),
    due_at:document.getElementById('pd_due_at').value,
    customer:document.getElementById('pd_customer').value,
    project:p.name||p.project_name||'',
    product_model:document.getElementById('pd_model').value,
    linked_system:'PLM',
    linked_table:'plm_projects',
    linked_id:String(p.id||''),
    linked_title:p.name||p.project_name||'',
    linked_url:'plm.php?dispatch_plm_id='+encodeURIComponent(p.id||''),
    linked_json:{id:p.id,name:p.name,customer:p.customer,model:p.model,engineer:p.engineer,status:p.status,priority:p.priority}
  };
  if(!payload.title){toast('请填写任务标题');return;}
  if(mode==='dispatch' && !payload.assigned_to){toast('请选择负责人');return;}
  try{
    const r=await fetch('dispatch_api.php?action=create_task',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(payload)});
    const j=await r.json();
    if(!j.ok){toast(j.error||'生成失败');return;}
    closePlmDispatch();
    toast('已生成派工待办');
  }catch(e){toast('生成失败：'+e.message);}
}
JS;

    if (strpos($code, 'load();') !== false) {
        $code = str_replace('load();', $js . "\nload();", $code);
    } else if (strpos($code, '</script>') !== false) {
        $code = str_replace('</script>', $js . "\n</script>", $code);
    } else {
        throw new RuntimeException('没有找到脚本结束位置。');
    }

    if (file_put_contents($plm, $code) === false) {
        copy($backup, $plm);
        throw new RuntimeException('写入 plm.php 失败，已尝试恢复备份。');
    }
    return '安装成功。已备份原文件：' . basename($backup);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $msg = patch_plm($plm);
        $ok = true;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $ok = false;
    }
}
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PLM 派工按钮安装</title>
<style>body{margin:0;background:#f5f7fb;color:#111827;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}.wrap{max-width:880px;margin:42px auto;padding:20px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:26px;padding:28px;box-shadow:0 18px 50px rgba(15,23,42,.08)}h1{margin:0 0 10px;font-size:30px}.muted{color:#667085;line-height:1.7;font-weight:700}.btn{border:0;background:#111827;color:#fff;border-radius:15px;padding:13px 18px;font-weight:900;cursor:pointer;margin-right:10px}.btn2{display:inline-flex;text-decoration:none;background:#e5e7eb;color:#111827}.out{margin-top:18px;border-radius:18px;padding:16px;background:<?= $ok ? '#ecfdf5' : '#fff7ed' ?>;border:1px solid <?= $ok ? '#bbf7d0' : '#fed7aa' ?>;color:<?= $ok ? '#166534' : '#9a3412' ?>;font-weight:900;white-space:pre-wrap}.code{background:#f3f4f6;border-radius:8px;padding:2px 6px}</style></head><body><div class="wrap"><div class="card">
<h1>PLM → 派工待办按钮安装</h1>
<p class="muted">本工具会自动备份并修改 <span class="code">plm.php</span>，在 PLM 项目详情里增加 <b>生成派工</b> 按钮。点击后可以从当前 PLM 项目直接生成派工 / 个人待办 / 私人待办，并自动带入项目、客户、型号。</p>
<form method="post"><button class="btn" type="submit">安装 / 更新 PLM 派工按钮</button><a class="btn btn2" href="plm.php">打开 PLM</a><a class="btn btn2" href="dispatch_todo.php">打开派工待办</a></form>
<?php if($msg): ?><div class="out"><?=h($msg)?></div><?php endif; ?>
</div></div></body></html>
