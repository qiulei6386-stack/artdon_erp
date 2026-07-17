<?php
/**
 * Artdon CRM / PLM 账号角色管理 V9.1.2
 *
 * 解决：
 * - PLM账号登录 CRM 后，不能把账号设置为 老板 / 管理员
 * - CRM 权限页只能勾模块，不能改账号角色
 *
 * 说明：
 * - 直接管理 artdon_users 里的角色字段。
 * - 如果 artdon_users 没有 role/type/user_role/is_admin/admin/level 字段，会自动新增 role 字段。
 * - 设置老板/管理员后，CRM 会把该账号识别为管理员，并可进入权限勾选管理。
 */
declare(strict_types=1);
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('admin');
/* ARTDON_SSO_GATE_V2_END */


ini_set('display_errors','1');
ini_set('log_errors','1');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();

$config = __DIR__ . '/config.php';
if (file_exists($config)) require_once $config;

function ah($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function aj(array $data, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function abody(): array {
    $raw = file_get_contents('php://input');
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? array_merge($_GET, $_POST, $j) : array_merge($_GET, $_POST);
}
function asafe($v, int $max=5000): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0 && mb_strlen($v,'UTF-8') > $max) $v = mb_substr($v,0,$max,'UTF-8');
    return $v;
}
function apdo(): PDO {
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }
    $host = defined('DB_HOST') ? DB_HOST : (defined('MYSQL_HOST') ? MYSQL_HOST : 'localhost');
    $name = defined('DB_NAME') ? DB_NAME : (defined('MYSQL_DB') ? MYSQL_DB : '');
    $user = defined('DB_USER') ? DB_USER : (defined('MYSQL_USER') ? MYSQL_USER : '');
    $pass = defined('DB_PASS') ? DB_PASS : (defined('MYSQL_PASS') ? MYSQL_PASS : '');
    if ($name === '' || $user === '') throw new RuntimeException('数据库配置不完整，请检查 config.php。');
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4'
    ]);
}
function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['COLUMN_NAME']] = $r;
    return $out;
}
function choose_col(array $cols, array $names): ?string {
    foreach ($names as $n) if (isset($cols[$n])) return $n;
    return null;
}
function is_num_type(array $col): bool {
    $t = strtolower((string)($col['DATA_TYPE'] ?? $col['COLUMN_TYPE'] ?? ''));
    return in_array($t, ['tinyint','smallint','mediumint','int','integer','bigint','decimal','float','double'], true);
}
function ensure_role_col(PDO $pdo): void {
    if (!table_exists($pdo, 'artdon_users')) throw new RuntimeException('没有找到 artdon_users 表。');
    $c = cols($pdo, 'artdon_users');
    $role = choose_col($c, ['role','type','user_role','is_admin','admin','level']);
    if (!$role) {
        $pdo->exec("ALTER TABLE `artdon_users` ADD COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'staff' COMMENT 'boss/admin/staff/sales/engineer'");
    }
}
function spec(PDO $pdo): array {
    ensure_role_col($pdo);
    $c = cols($pdo, 'artdon_users');
    $username = choose_col($c, ['username','account','user_name','login','email','name']);
    $name = choose_col($c, ['real_name','display_name','name','nickname','full_name','username','account']);
    $role = choose_col($c, ['role','type','user_role','is_admin','admin','level']);
    $active = choose_col($c, ['is_active','active','enabled','status']);
    $dept = choose_col($c, ['department','dept','group_name','team']);
    $id = choose_col($c, ['id','user_id','uid']);
    if (!$username) throw new RuntimeException('artdon_users 找不到账号字段 username/account/email/name。');
    if (!$role) throw new RuntimeException('无法创建或识别角色字段。');
    return compact('c','username','name','role','active','dept','id');
}
function active_row(array $row, ?string $col): bool {
    if (!$col || !array_key_exists($col,$row)) return true;
    $v = strtolower(trim((string)$row[$col]));
    if ($v === '' || $v === '1' || $v === 'true' || $v === 'yes' || $v === 'active' || $v === 'enabled' || $v === '启用' || $v === '正常') return true;
    if ($v === '0' || $v === 'false' || $v === 'no' || $v === 'disabled' || $v === 'disable' || $v === '停用' || $v === '禁用') return false;
    return true;
}
function norm_role($raw, string $username=''): string {
    $s = strtolower(trim((string)$raw));
    $u = strtolower($username);
    if (in_array($u, ['boss','admin','administrator','owner','qiulei'], true)) {
        if ($s === '' || $s === '0' || $s === 'staff') return 'boss';
    }
    if (in_array($s, ['1','boss','owner'], true) || str_contains((string)$raw,'老板') || str_contains($s,'boss') || str_contains($s,'owner')) return 'boss';
    if (in_array($s, ['admin','administrator'], true) || str_contains((string)$raw,'管理员') || str_contains($s,'admin')) return 'admin';
    if (str_contains((string)$raw,'经理') || str_contains($s,'manager')) return 'manager';
    if (str_contains($s,'sales') || str_contains((string)$raw,'业务') || str_contains((string)$raw,'销售')) return 'sales';
    if (str_contains($s,'engineer') || str_contains((string)$raw,'工程')) return 'engineer';
    if (str_contains($s,'finance') || str_contains((string)$raw,'财务')) return 'finance';
    return 'staff';
}
function role_label(string $r): string {
    return [
        'boss'=>'老板',
        'admin'=>'管理员',
        'manager'=>'经理',
        'sales'=>'业务',
        'engineer'=>'工程',
        'finance'=>'财务',
        'staff'=>'员工',
    ][$r] ?? $r;
}
function role_db_value(PDO $pdo, string $roleKey) {
    $sp = spec($pdo);
    $col = $sp['c'][$sp['role']];
    $roleField = $sp['role'];
    $isNum = is_num_type($col);

    if ($isNum || in_array($roleField, ['is_admin','admin','level'], true)) {
        // 数字型字段无法区分老板/管理员/经理，统一：管理类=1，普通类=0。
        return in_array($roleKey, ['boss','admin','manager'], true) ? 1 : 0;
    }

    // 文本字段尽量写中文，CRM 的识别函数能识别“老板/管理员”。
    return [
        'boss' => '老板',
        'admin' => '管理员',
        'manager' => '经理',
        'sales' => 'sales',
        'engineer' => 'engineer',
        'finance' => 'finance',
        'staff' => 'staff',
    ][$roleKey] ?? 'staff';
}
function list_users(PDO $pdo): array {
    $sp = spec($pdo);
    $rows = $pdo->query("SELECT * FROM `artdon_users`")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $username = asafe($r[$sp['username']] ?? '', 120);
        if ($username === '') continue;
        $name = asafe($sp['name'] ? ($r[$sp['name']] ?? '') : '', 120);
        if ($name === '') $name = $username;
        $raw = (string)($r[$sp['role']] ?? '');
        $key = norm_role($raw, $username);
        $out[] = [
            'id' => $sp['id'] ? (int)($r[$sp['id']] ?? 0) : 0,
            'username' => $username,
            'name' => $name,
            'department' => asafe($sp['dept'] ? ($r[$sp['dept']] ?? '') : '', 120),
            'role_raw' => $raw,
            'role_key' => $key,
            'role_label' => role_label($key),
            'active' => active_row($r, $sp['active']) ? 1 : 0,
        ];
    }
    usort($out, fn($a,$b)=>strcmp($a['username'],$b['username']));
    return $out;
}
function set_role(PDO $pdo, string $username, string $roleKey): void {
    $allowed = ['boss','admin','manager','sales','engineer','finance','staff'];
    if (!in_array($roleKey, $allowed, true)) throw new RuntimeException('角色不合法：'.$roleKey);
    $sp = spec($pdo);
    $val = role_db_value($pdo, $roleKey);
    $sql = "UPDATE `artdon_users` SET `{$sp['role']}`=? WHERE `{$sp['username']}`=?";
    $st = $pdo->prepare($sql);
    $st->execute([$val, $username]);
    if ($st->rowCount() < 1) {
        // 如果本身就是同一个值，rowCount 会是 0；再确认用户存在。
        $chk = $pdo->prepare("SELECT COUNT(*) FROM `artdon_users` WHERE `{$sp['username']}`=?");
        $chk->execute([$username]);
        if ((int)$chk->fetchColumn() < 1) throw new RuntimeException('找不到账号：'.$username);
    }
}
function ensure_crm_permission_for_role(PDO $pdo, string $username, string $roleKey): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `crm_module_permissions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(80) NOT NULL DEFAULT '',
        `module` VARCHAR(80) NOT NULL DEFAULT '',
        `can_view` TINYINT(1) NOT NULL DEFAULT 0,
        `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_crm_module_user` (`username`,`module`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $modules = [
        'dashboard','sales','customers','customer360','contacts','projects','samples','followups','mail','whatsapp','marketing','plmDocs','quotes','orders','permissions','settings'
    ];
    if (in_array($roleKey, ['boss','admin','manager'], true)) {
        $st = $pdo->prepare("INSERT INTO crm_module_permissions(username,module,can_view,can_edit,created_at,updated_at)
            VALUES(?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_edit=VALUES(can_edit),updated_at=NOW()");
        foreach ($modules as $m) $st->execute([$username,$m,1,1]);
    }
}
try {
    $pdo = apdo();
    $data = abody();
    $action = asafe($data['action'] ?? '', 50);

    if ($action === 'ping') {
        $sp = spec($pdo);
        aj(['ok'=>true,'data'=>['message'=>'crm_plm_role_manager.php 正常','role_field'=>$sp['role'],'username_field'=>$sp['username']]]);
    }
    if ($action === 'list') {
        $sp = spec($pdo);
        aj(['ok'=>true,'data'=>['users'=>list_users($pdo),'role_field'=>$sp['role'],'username_field'=>$sp['username']]]);
    }
    if ($action === 'set_role') {
        $username = asafe($data['username'] ?? '', 120);
        $role = asafe($data['role'] ?? '', 30);
        if ($username === '') throw new RuntimeException('缺少 username');
        set_role($pdo, $username, $role);
        ensure_crm_permission_for_role($pdo, $username, $role);
        aj(['ok'=>true,'data'=>['msg'=>'已把 '.$username.' 设置为：'.role_label($role)]]);
    }
} catch (Throwable $e) {
    if (($action ?? '') !== '') aj(['ok'=>false,'msg'=>$e->getMessage()], 500);
    $pageError = $e->getMessage();
}
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CRM / PLM 账号角色管理</title>
<style>
:root{--bg:#f3f6fb;--card:#fff;--line:#dbe4ef;--text:#111827;--muted:#64748b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;color:var(--text)}
.wrap{max-width:1280px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid var(--line);border-radius:26px;box-shadow:0 18px 50px rgba(15,23,42,.08);padding:20px;margin-bottom:16px}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}h1{margin:0 0 8px;font-size:30px}.muted{color:var(--muted);font-weight:800;line-height:1.7}
.btn{border:1px solid var(--line);background:#fff;color:#111827;border-radius:13px;padding:10px 14px;font-weight:1000;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.btn.primary{background:#111827;color:#fff;border-color:#111827}.btn.blue{background:#2563eb;color:#fff;border-color:#2563eb}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.search{border:1px solid var(--line);border-radius:13px;padding:10px 12px;min-width:280px}
table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff}th,td{border-bottom:1px solid #e5edf6;padding:12px;text-align:left}th{background:#f8fafc;color:#475569;font-size:13px}tr:last-child td{border-bottom:0}
.tag{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:1000;background:#e2e8f0;color:#334155}.tag.boss{background:#fee2e2;color:#991b1b}.tag.admin{background:#ffedd5;color:#9a3412}.tag.staff{background:#e2e8f0;color:#334155}.tag.sales{background:#dbeafe;color:#1d4ed8}.tag.engineer{background:#dcfce7;color:#166534}
select{border:1px solid var(--line);border-radius:12px;padding:9px 10px;background:#fff;font-weight:900}.msg{display:none;margin-top:12px;border-radius:14px;padding:12px;font-weight:900}.msg.ok{display:block;background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}.msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.notice{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:18px;padding:12px;margin-top:12px;font-weight:900;line-height:1.6}
@media(max-width:760px){.wrap{padding:12px}.head{display:block}.search{width:100%;min-width:0}table{font-size:12px}th,td{padding:8px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="head">
      <div>
        <h1>CRM / PLM 账号角色管理</h1>
        <div class="muted">这里直接修改 PLM 权限中心账号表 <b>artdon_users</b> 的角色。设置为“老板/管理员”后，CRM 会识别为管理账号，并自动给 CRM 全部模块权限。</div>
        <div class="notice">你现在的问题是：CRM 模块权限能勾，但账号本身不能改成老板/管理员。这页就是专门补这个功能。</div>
      </div>
      <div class="toolbar">
        <a class="btn" href="crm_permissions_manager.php">CRM权限勾选</a>
        <a class="btn" href="crm.php?ver=912">返回CRM</a>
        <button class="btn blue" onclick="loadUsers()">刷新</button>
      </div>
    </div>
    <?php if (!empty($pageError)): ?><div class="msg err" style="display:block"><?=ah($pageError)?></div><?php endif; ?>
  </div>

  <div class="card">
    <div class="toolbar">
      <input id="kw" class="search" placeholder="搜索账号 / 姓名 / 部门，如 qiulei" oninput="render()">
      <span id="fieldInfo" class="tag">字段检测中...</span>
    </div>
    <div id="msg" class="msg"></div>
    <div style="overflow:auto;margin-top:14px">
      <table>
        <thead><tr><th>账号</th><th>姓名</th><th>部门</th><th>当前角色</th><th>设置角色</th><th>操作</th></tr></thead>
        <tbody id="rows"><tr><td colspan="6">正在加载...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
<script>
let USERS=[], ROLE_FIELD='', USERNAME_FIELD='';
function esc(s){return String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;')}
async function api(action,data={}){
  const r=await fetch('crm_plm_role_manager.php?action='+encodeURIComponent(action),{
    method:Object.keys(data).length?'POST':'GET',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:Object.keys(data).length?JSON.stringify(data):null
  });
  const txt=await r.text(); let j;
  try{j=JSON.parse(txt)}catch(e){throw new Error('接口不是JSON：'+txt.slice(0,220))}
  if(!j.ok) throw new Error(j.msg||j.error||'接口错误');
  return j.data;
}
function msg(t,ok=true){const el=document.getElementById('msg');el.className='msg '+(ok?'ok':'err');el.textContent=t}
function roleClass(r){return ['boss','admin','sales','engineer','staff'].includes(r)?r:'staff'}
function roleLabel(r){return {boss:'老板',admin:'管理员',manager:'经理',sales:'业务',engineer:'工程',finance:'财务',staff:'员工'}[r]||r}
async function loadUsers(){
  try{
    const d=await api('list');
    USERS=d.users||[]; ROLE_FIELD=d.role_field||''; USERNAME_FIELD=d.username_field||'';
    document.getElementById('fieldInfo').textContent='账号字段：'+USERNAME_FIELD+' ｜ 角色字段：'+ROLE_FIELD;
    render();
  }catch(e){
    document.getElementById('rows').innerHTML='<tr><td colspan="6">加载失败：'+esc(e.message)+'</td></tr>';
  }
}
function render(){
  const kw=String(document.getElementById('kw').value||'').toLowerCase();
  const arr=USERS.filter(u=>[u.username,u.name,u.department,u.role_raw,u.role_label].join(' ').toLowerCase().includes(kw));
  document.getElementById('rows').innerHTML=arr.map(u=>`
    <tr>
      <td><b>${esc(u.username)}</b></td>
      <td>${esc(u.name)}</td>
      <td>${esc(u.department||'-')}</td>
      <td><span class="tag ${roleClass(u.role_key)}">${esc(u.role_label)}</span><br><small style="color:#64748b">原值：${esc(u.role_raw||'')}</small></td>
      <td>
        <select id="role_${esc(u.username)}">
          ${['boss','admin','manager','sales','engineer','finance','staff'].map(r=>`<option value="${r}" ${u.role_key===r?'selected':''}>${roleLabel(r)}</option>`).join('')}
        </select>
      </td>
      <td>
        <button class="btn primary" onclick="saveRole('${esc(u.username)}')">保存角色</button>
        <button class="btn" onclick="quickAdmin('${esc(u.username)}','boss')">设为老板</button>
        <button class="btn" onclick="quickAdmin('${esc(u.username)}','admin')">设为管理员</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="6">没有找到账号</td></tr>';
}
async function saveRole(username){
  const role=document.getElementById('role_'+username).value;
  try{
    const d=await api('set_role',{username,role});
    msg(d.msg||'已保存',true);
    await loadUsers();
  }catch(e){msg('保存失败：'+e.message,false)}
}
async function quickAdmin(username,role){
  try{
    const d=await api('set_role',{username,role});
    msg(d.msg||'已保存',true);
    await loadUsers();
  }catch(e){msg('保存失败：'+e.message,false)}
}
loadUsers();
</script>
</body>
</html>
