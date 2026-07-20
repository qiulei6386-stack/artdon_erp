<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
if (!current_user()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>false,'error'=>'AUTH_REQUIRED'), JSON_UNESCAPED_UNICODE);
    exit;
}
artdon_bom_ensure_permissions();
artdon_plm_ensure_permissions();
/* ARTDON_SSO_GATE_V2_END */

ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$__bom_deep_uid = trim((string)($_GET['project_uid'] ?? ''));
$__bom_deep_plm = $__bom_deep_uid !== '' && !artdon_sso_can('bom','view') && artdon_sso_can('plm','view');
if (!artdon_sso_can('bom','view') && !$__bom_deep_plm) {
    http_response_code(403);
    echo json_encode(array('ok'=>false,'error'=>'当前账号没有进入 BOM 系统的权限','error_code'=>'PERMISSION_DENIED','module'=>'bom'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 第三阶段：BOM 细分权限。页面入口仍由统一登录控制，具体 API 再按功能拦截。
$__bom_perm_map=array(
    'ping'=>null,'login'=>null,'logout'=>null,'me'=>null,
    'auth_debug'=>'manage_users','bootstrap'=>'view_dashboard','naming_models'=>'view_library',
    'create_from_naming'=>'edit_bom','bind_naming_to_project'=>'edit_bom','unbind_naming_from_project'=>'edit_bom','naming_sync_check'=>'view_dashboard','naming_sync_apply'=>'edit_bom',
    'list_users'=>'manage_users','save_user'=>'manage_users','disable_user'=>'manage_users',
    'save_project'=>'edit_bom','save_list'=>'edit_bom','delete_project'=>'delete_bom',
    'sync_weight_profiles_to_bom'=>'manage_materials','import_materials_bulk'=>'import_materials',
    'save_material'=>'manage_materials','delete_material'=>'delete_materials'
);
if (!$__bom_deep_plm || !in_array((string)$action, array('ping','me','bootstrap'), true)) {
    artdon_perm_require_action('bom',(string)$action,$__bom_perm_map,'view_dashboard');
}

function json_out($arr){
    while(ob_get_level()>0){ @ob_end_clean(); }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function body_json(){
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $_POST;
}
function uid(){ return 'BOM-'.date('YmdHis').'-'.substr(md5(uniqid('', true)),0,6); }
function pdo_safe(){
    if(function_exists('db')){
        $x = db();
        if($x instanceof PDO){
            $x->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $x->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $x;
        }
    }
    $host = defined('DB_HOST') ? DB_HOST : (isset($GLOBALS['db_host']) ? $GLOBALS['db_host'] : 'localhost');
    $name = defined('DB_NAME') ? DB_NAME : (defined('DB_DATABASE') ? DB_DATABASE : (isset($GLOBALS['db_name']) ? $GLOBALS['db_name'] : 'artdon_erp'));
    $user = defined('DB_USER') ? DB_USER : (defined('DB_USERNAME') ? DB_USERNAME : (isset($GLOBALS['db_user']) ? $GLOBALS['db_user'] : 'artdon_erp'));
    $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : (isset($GLOBALS['db_pass']) ? $GLOBALS['db_pass'] : (isset($GLOBALS['db_password']) ? $GLOBALS['db_password'] : '')));
    $pdo = new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4', $user, $pass, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    return $pdo;
}
function table_exists($pdo,$t){$st=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');$st->execute(array($t));return (bool)$st->fetchColumn();}
function cols($pdo,$t){static $c=array(); if(isset($c[$t]))return $c[$t]; if(!table_exists($pdo,$t))return $c[$t]=array(); $st=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'); $st->execute(array($t)); return $c[$t]=$st->fetchAll(PDO::FETCH_COLUMN);}
function reset_cols_cache($t=null){ /* columns are only added early in request; kept for compatibility */ }
function hascol($pdo,$t,$c){return in_array($c, cols($pdo,$t));}
function qid($x){ return '`'.str_replace('`','``',$x).'`'; }

function bom_num_zero($v){
    if($v === null) return 0;
    if(is_string($v)){
        $v = trim($v);
        if($v === '') return 0;
        $v = str_replace(array(',', '￥', '¥', 'RMB', 'rmb', 'USD', 'usd', '元'), '', $v);
        $v = trim($v);
    }
    return is_numeric($v) ? (float)$v : 0;
}


/* ARTDON_BOM_MATERIAL_DUP_SIMILAR_GUARD_START
 * 共享物料库：物料名称 + 型号 + 规格 三字段组合不允许重复。
 * 新增物料时若发现类似件，先返回 need_confirm_similar，由前端弹窗确认后再保存。
 */
function bom_material_trim($v){
    $v = trim((string)($v ?? ''));
    $v = preg_replace('/\s+/u', ' ', $v);
    return $v === null ? '' : $v;
}
function bom_material_norm_key($v){
    $v = bom_material_trim($v);
    $v = mb_strtolower($v, 'UTF-8');
    return $v;
}
function bom_material_norm_compact($v){
    $v = mb_strtolower((string)($v ?? ''), 'UTF-8');
    $v = preg_replace('/[￥¥]\s*\d+(?:\.\d+)?/u', ' ', $v);
    $v = preg_replace('/\b(?:rmb|usd)\s*\d+(?:\.\d+)?/iu', ' ', $v);
    $v = preg_replace('/\b\d+(?:\.\d+)?\s*(?:rmb|usd|元)\b/iu', ' ', $v);
    $v = str_replace(array('adapter','adaptor','适配器'), 'adapter', $v);
    $v = str_replace(array('two wire','two-wire','2 wire','2-wire','两线','二线'), '2wire', $v);
    $v = preg_replace('/[^\p{L}\p{N}]+/u', '', $v);
    return $v ?: '';
}
function bom_material_select_cols(PDO $pdo){
    $wanted = array('id','category','brand','name','model','spec','price','unit','supplier','keyword','image','created_at','updated_at');
    $cols = array();
    foreach($wanted as $c){ if(hascol($pdo,'bom_materials',$c)) $cols[] = $c; }
    return $cols ? implode(',', array_map('qid',$cols)) : '*';
}
function bom_material_active_where(PDO $pdo){
    return hascol($pdo,'bom_materials','is_active') ? "(is_active=1 OR is_active IS NULL OR is_active='')" : "1=1";
}
function bom_material_public_row($r, $score=null, $reason=''){
    $out = array(
        'id'=>(int)($r['id'] ?? 0),
        'category'=>(string)($r['category'] ?? ''),
        'brand'=>(string)($r['brand'] ?? ''),
        'name'=>(string)($r['name'] ?? ''),
        'model'=>(string)($r['model'] ?? ''),
        'spec'=>(string)($r['spec'] ?? ''),
        'price'=>isset($r['price']) ? (float)$r['price'] : 0,
        'unit'=>(string)($r['unit'] ?? ''),
        'supplier'=>(string)($r['supplier'] ?? ''),
        'keyword'=>(string)($r['keyword'] ?? ''),
        'image'=>(string)($r['image'] ?? ''),
        'created_at'=>(string)($r['created_at'] ?? ''),
        'updated_at'=>(string)($r['updated_at'] ?? '')
    );
    if($score !== null) $out['similar_score'] = (float)$score;
    if($reason !== '') $out['similar_reason'] = $reason;
    return $out;
}
function bom_hide_sensitive_row(array $row, bool $canCost, bool $canSupplier): array
{
    if(!$canCost){
        foreach(array('price','cost','unit_price','amount','subtotal','total','labor','other','process','finishCost','finish_cost','material_cost','total_cost','profit','margin') as $k){
            if(array_key_exists($k,$row)) $row[$k] = '';
        }
    }
    if(!$canSupplier){
        foreach(array('supplier','vendor','supplier_name','factory','factory_name') as $k){
            if(array_key_exists($k,$row)) $row[$k] = '';
        }
    }
    return $row;
}
function bom_hide_sensitive_list(array $rows, bool $canCost, bool $canSupplier): array
{
    foreach($rows as &$row){
        if(is_array($row)) $row = bom_hide_sensitive_row($row, $canCost, $canSupplier);
    }
    unset($row);
    return $rows;
}
function bom_material_exact_duplicates(PDO $pdo, $name, $model, $spec, $excludeId=0){
    if(!table_exists($pdo,'bom_materials')) return array();
    $where = array(bom_material_active_where($pdo), "LOWER(TRIM(COALESCE(name,'')))=?", "LOWER(TRIM(COALESCE(model,'')))=?", "LOWER(TRIM(COALESCE(spec,'')))=?");
    $args = array(bom_material_norm_key($name), bom_material_norm_key($model), bom_material_norm_key($spec));
    if((int)$excludeId > 0){ $where[] = 'id<>?'; $args[] = (int)$excludeId; }
    $sql = 'SELECT '.bom_material_select_cols($pdo).' FROM bom_materials WHERE '.implode(' AND ', $where).' ORDER BY id DESC LIMIT 20';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r){ return bom_material_public_row($r); }, $rows ?: array());
}
function bom_material_similar_score($input, $row){
    $score = 0; $reason = array();
    $iname = bom_material_norm_compact($input['name'] ?? '');
    $imodel = bom_material_norm_compact($input['model'] ?? '');
    $ispec = bom_material_norm_compact($input['spec'] ?? '');
    $icategory = bom_material_norm_key($input['category'] ?? '');
    $ibrand = bom_material_norm_key($input['brand'] ?? '');
    $rname = bom_material_norm_compact($row['name'] ?? '');
    $rmodel = bom_material_norm_compact($row['model'] ?? '');
    $rspec = bom_material_norm_compact($row['spec'] ?? '');
    $rcategory = bom_material_norm_key($row['category'] ?? '');
    $rbrand = bom_material_norm_key($row['brand'] ?? '');
    if($imodel !== '' && $rmodel !== ''){
        if($imodel === $rmodel){ $score += 85; $reason[]='型号相同'; }
        elseif(mb_strpos($imodel,$rmodel)!==false || mb_strpos($rmodel,$imodel)!==false){ $score += 55; $reason[]='型号接近'; }
    }
    if($iname !== '' && $rname !== ''){
        if($iname === $rname){ $score += 55; $reason[]='物料名称相同'; }
        elseif(mb_strpos($iname,$rname)!==false || mb_strpos($rname,$iname)!==false){ $score += 38; $reason[]='物料名称接近'; }
    }
    if($ispec !== '' && $rspec !== ''){
        if($ispec === $rspec){ $score += 35; $reason[]='规格相同'; }
        elseif(mb_strpos($ispec,$rspec)!==false || mb_strpos($rspec,$ispec)!==false){ $score += 22; $reason[]='规格接近'; }
    }
    $ifull = bom_material_norm_compact(($input['name'] ?? '').' '.($input['model'] ?? '').' '.($input['spec'] ?? ''));
    $rfull = bom_material_norm_compact(($row['name'] ?? '').' '.($row['model'] ?? '').' '.($row['spec'] ?? '').' '.($row['keyword'] ?? ''));
    if($ifull !== '' && $rfull !== ''){
        if(mb_strlen($ifull,'UTF-8') >= 5 && (mb_strpos($rfull,$ifull)!==false || mb_strpos($ifull,$rfull)!==false)){ $score += 26; $reason[]='关键词包含'; }
        if(function_exists('similar_text') && mb_strlen($ifull,'UTF-8') >= 4 && mb_strlen($rfull,'UTF-8') >= 4){
            $pct = 0; similar_text($ifull, $rfull, $pct);
            if($pct >= 78){ $score += 24; $reason[]='整体相似'; }
            elseif($pct >= 62){ $score += 12; $reason[]='整体接近'; }
        }
    }
    if($icategory !== '' && $icategory === $rcategory){ $score += 8; }
    if($ibrand !== '' && $ibrand === $rbrand){ $score += 8; $reason[]='品牌相同'; }
    return array($score, implode('、', array_values(array_unique($reason))));
}
function bom_material_find_similars(PDO $pdo, array $input, $excludeId=0, $limit=8){
    if(!table_exists($pdo,'bom_materials')) return array();
    $where = array(bom_material_active_where($pdo));
    if((int)$excludeId > 0) $where[] = 'id<>'.(int)$excludeId;
    $sql = 'SELECT '.bom_material_select_cols($pdo).' FROM bom_materials WHERE '.implode(' AND ', $where).' ORDER BY updated_at DESC, id DESC LIMIT 2000';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $scored = array();
    foreach($rows as $r){
        list($score,$reason) = bom_material_similar_score($input,$r);
        if($score >= 45){ $r['_score']=$score; $r['_reason']=$reason ?: '资料接近'; $scored[]=$r; }
    }
    usort($scored, function($a,$b){
        if(($a['_score'] ?? 0) == ($b['_score'] ?? 0)) return (int)($b['id'] ?? 0) - (int)($a['id'] ?? 0);
        return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
    });
    $out=array();
    foreach(array_slice($scored,0,max(1,(int)$limit)) as $r){ $out[] = bom_material_public_row($r,$r['_score'] ?? 0,$r['_reason'] ?? ''); }
    return $out;
}
/* ARTDON_BOM_MATERIAL_DUP_SIMILAR_GUARD_END */
function col_any($pdo,$t,$names){ foreach($names as $c){ if(hascol($pdo,$t,$c)) return $c; } return ''; }
function truthy_val($v){ $s=mb_strtolower(trim((string)$v),'UTF-8'); return in_array($s,array('1','true','yes','y','on','启用','允许','有','√','是','active','enabled'),true); }
function user_pk_col($pdo,$t){ return col_any($pdo,$t,array('id','user_id','uid','account_id')); }
function user_username_col($pdo,$t){ return col_any($pdo,$t,array('username','account','login_name','user_name','user','name','email','mobile')); }
function user_password_cols($pdo,$t){
    $out=array();
    foreach(array('password_hash','pass_hash','pwd_hash','hash','password','pass','pwd','user_pass','passwd') as $c){ if(hascol($pdo,$t,$c)) $out[]=$c; }
    return $out;
}
function user_display_col($pdo,$t){ foreach(array('real_name','display_name','fullname','full_name','nickname','nick','name','username','account') as $c){ if(hascol($pdo,$t,$c)) return $c; } return user_username_col($pdo,$t); }
function user_status_col($pdo,$t){ foreach(array('status','state','is_active','enabled','is_enabled','active') as $c){ if(hascol($pdo,$t,$c)) return $c; } return ''; }
function user_role_col($pdo,$t){ return col_any($pdo,$t,array('role','role_name','user_role','type','level')); }
function user_perm_col($pdo,$t){ return col_any($pdo,$t,array('permissions','perms','permission','auth','authorities','modules','rights')); }
function active_sql($pdo,$t){
    $c = user_status_col($pdo,$t);
    if($c==='') return '1=1';
    if(in_array($c,array('is_active','enabled','is_enabled','active'),true)) return '('.qid($c).'=1 OR '.qid($c).' IS NULL)';
    return '('.qid($c)."='active' OR ".qid($c)."='enabled' OR ".qid($c)."='启用' OR ".qid($c)."='正常' OR ".qid($c)."='1' OR ".qid($c)." IS NULL OR ".qid($c)."='')";
}
function row_is_active($pdo,$t,$r){
    $c=user_status_col($pdo,$t);
    if($c==='' || !array_key_exists($c,$r)) return true;
    $v=$r[$c];
    if(in_array($c,array('is_active','enabled','is_enabled','active'),true)) return intval($v)===1 || $v===null || $v==='';
    return in_array((string)$v,array('active','enabled','启用','正常','1',''),true) || $v===null;
}
function all_user_like_tables($pdo){
    $priority=array('office_users','plm_users','users','system_users','sys_users','admin_users','app_users','crm_users','bom_users','profiles');
    $found=array();
    foreach($priority as $t){ if(table_exists($pdo,$t)) $found[]=$t; }
    try{
        $st=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('username','account','login_name','user_name','password','password_hash','pass','pwd') GROUP BY TABLE_NAME");
        $st->execute();
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $t){ if(!in_array($t,$found,true)) $found[]=$t; }
    }catch(Throwable $e){}
    $ok=array();
    foreach($found as $t){
        if(!table_exists($pdo,$t)) continue;
        if(user_username_col($pdo,$t)==='' || count(user_password_cols($pdo,$t))===0) continue;
        $ok[]=$t;
    }
    return $ok;
}
function shared_user_table($pdo){
    $tables=all_user_like_tables($pdo);
    return count($tables)?$tables[0]:'bom_users';
}
function user_label($u){ return trim((string)($u['display_name'] ?? $u['real_name'] ?? $u['fullname'] ?? $u['name'] ?? '')) ?: trim((string)($u['username'] ?? $u['account'] ?? '')); }

function ensure_bom_user_schema($pdo){
    // 统一账号版：优先使用用户管理已有账号表，不主动改它的结构。
    $tables=all_user_like_tables($pdo);
    if(count($tables)>0) return;
    if(!table_exists($pdo,'bom_users')){
        $pdo->exec("CREATE TABLE bom_users(
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL,
            password_hash VARCHAR(255) NOT NULL DEFAULT '',
            real_name VARCHAR(120) NOT NULL DEFAULT '',
            role VARCHAR(40) NOT NULL DEFAULT 'engineer',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            permissions TEXT NULL,
            last_login DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bom_users_username(username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO bom_users(username,password_hash,real_name,role,status,permissions) VALUES(?,?,?,?,?,?)")
            ->execute(array('boss',password_hash('123456', PASSWORD_DEFAULT),'Boss / 管理员','boss','active','all'));
    }
}
function safe_user_row($r){
    if(!$r) return null;
    $active = 1;
    foreach(array('is_active','enabled','is_enabled','active') as $c){ if(isset($r[$c])) $active = (int)(truthy_val($r[$c]) || intval($r[$c])===1); }
    if(isset($r['status'])) $active = in_array((string)$r['status'], array('active','enabled','启用','正常','1',''), true) ? 1 : 0;
    if(isset($r['state'])) $active = in_array((string)$r['state'], array('active','enabled','启用','正常','1',''), true) ? 1 : 0;
    return array(
        'id'=>(int)($r['_user_id'] ?? $r['id'] ?? $r['user_id'] ?? $r['uid'] ?? 0),
        'username'=>(string)($r['username'] ?? $r['account'] ?? $r['login_name'] ?? $r['user_name'] ?? ''),
        'display_name'=>(string)($r['display_name'] ?? $r['real_name'] ?? $r['fullname'] ?? $r['full_name'] ?? $r['name'] ?? ''),
        'role'=>(string)($r['role'] ?? $r['role_name'] ?? $r['user_role'] ?? ''),
        'permissions'=>(string)($r['permissions'] ?? $r['perms'] ?? $r['permission'] ?? $r['auth'] ?? $r['modules'] ?? ''),
        'is_active'=>$active,
        'last_login'=>(string)($r['last_login'] ?? ''),
        'user_table'=>(string)($r['_user_table'] ?? '')
    );
}
function bom_current_user($pdo){
    $id = isset($_SESSION['office_user_id']) ? (int)$_SESSION['office_user_id'] : (isset($_SESSION['bom_user_id']) ? (int)$_SESSION['bom_user_id'] : (isset($_SESSION['plm_user_id']) ? (int)$_SESSION['plm_user_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0)));
    if($id<=0) return null;
    $t = isset($_SESSION['office_user_table']) ? $_SESSION['office_user_table'] : shared_user_table($pdo);
    $pk = isset($_SESSION['office_user_pk']) ? $_SESSION['office_user_pk'] : user_pk_col($pdo,$t);
    if($t==='' || !table_exists($pdo,$t) || $pk==='') return null;
    $st = $pdo->prepare("SELECT * FROM ".qid($t)." WHERE ".qid($pk)."=? LIMIT 1");
    $st->execute(array($id));
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if(!$u || !row_is_active($pdo,$t,$u)){ unset($_SESSION['office_user_id'], $_SESSION['bom_user_id'], $_SESSION['office_user_table'], $_SESSION['office_user_pk']); return null; }
    $u['_user_table']=$t; $u['_pk_col']=$pk; $u['_user_id']=$id;
    return $u;
}
function parse_perm_strings($s){
    $s=(string)$s; if(trim($s)==='') return array();
    $lower=mb_strtolower($s,'UTF-8');
    $tokens=array();
    $json=json_decode($s,true);
    if(is_array($json)){
        $flat=mb_strtolower(json_encode($json,JSON_UNESCAPED_UNICODE),'UTF-8');
        foreach(array('all','admin','users','user_admin','bom','bom_view','bom_dashboard','bom_edit','bom_library','bom_materials','view','read','add','create','edit','update','delete','export','manage') as $k){ if(mb_strpos($flat,$k)!==false) $tokens[]=$k; }
    }
    $lower=str_replace(array('，',';','；','|','/','\n','\r','\t',' '), ',', $lower);
    foreach(array_filter(array_map('trim', explode(',', $lower)), 'strlen') as $p){ $tokens[]=$p; }
    return array_values(array_unique($tokens));
}
function perm_tokens($u){
    $tokens=array();
    foreach(array('permissions','perms','permission','auth','authorities','modules','rights') as $c){ if(isset($u[$c])) $tokens=array_merge($tokens,parse_perm_strings($u[$c])); }
    return array_values(array_unique($tokens));
}
function role_is_admin($u){
    if(!empty($u['is_super_admin'])) return true;
    $role = mb_strtolower((string)($u['role'] ?? $u['role_name'] ?? $u['user_role'] ?? $u['type'] ?? ''),'UTF-8');
    return in_array($role, array('boss','admin','administrator','super_admin','超级管理员','管理员','超级管理'), true);
}
function direct_bom_cols_can($u,$perm){
    if(role_is_admin($u)) return true;
    foreach(array('can_bom','bom','bom_access','allow_bom','module_bom') as $c){ if(isset($u[$c]) && truthy_val($u[$c])) return true; }
    $map=array(
        'dashboard'=>array('bom_view','can_bom_view','view_bom','bom_read'),
        'edit'=>array('bom_edit','can_bom_edit','edit_bom','bom_add','bom_create','bom_update'),
        'library'=>array('bom_library','can_bom_library'),
        'materials'=>array('bom_materials','can_bom_materials'),
        'users'=>array('bom_users','can_bom_users','user_admin','can_admin')
    );
    foreach(($map[$perm] ?? array()) as $c){ if(isset($u[$c]) && truthy_val($u[$c])) return true; }
    return false;
}
function perm_table_can($pdo,$u,$perm){
    $uid=(int)($u['_user_id'] ?? $u['id'] ?? $u['user_id'] ?? $u['uid'] ?? 0); if($uid<=0) return false;
    $permTables=array();
    try{
        $rs=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE '%perm%' OR TABLE_NAME LIKE '%auth%' OR TABLE_NAME LIKE '%right%')")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rs as $t){ $permTables[]=$t; }
    }catch(Throwable $e){}
    foreach($permTables as $t){
        if(!table_exists($pdo,$t)) continue;
        $uc=col_any($pdo,$t,array('user_id','uid','account_id','userId','userid')); if($uc==='') continue;
        $mc=col_any($pdo,$t,array('module_key','module','module_code','app_key','app','menu_key','menu','resource','resource_key','system_key')); if($mc==='') continue;
        try{
            $sql="SELECT * FROM ".qid($t)." WHERE ".qid($uc)."=? AND LOWER(".qid($mc).") IN ('bom','bom.php','bom_cost','bom成本','bom 成本') LIMIT 20";
            $st=$pdo->prepare($sql); $st->execute(array($uid)); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        }catch(Throwable $e){ $rows=array(); }
        foreach($rows as $r){
            foreach(array('allowed','allow','enabled','is_enabled','is_active','checked') as $c){ if(isset($r[$c]) && !truthy_val($r[$c])) continue 2; }
            $actionCols=array('can_view','view','read','browse','can_add','can_create','add','create','can_edit','edit','update','modify','can_delete','delete','remove','can_export','export','download','can_manage','manage','admin');
            $hasActionCol=false; foreach($actionCols as $c){ if(array_key_exists($c,$r)){ $hasActionCol=true; break; } }
            if(!$hasActionCol) return true;
            if($perm==='dashboard') foreach(array('can_view','view','read','browse','can_manage','manage','admin') as $c){ if(isset($r[$c]) && truthy_val($r[$c])) return true; }
            if($perm==='edit') foreach(array('can_add','can_create','add','create','can_edit','edit','update','modify','can_delete','delete','remove','can_manage','manage','admin') as $c){ if(isset($r[$c]) && truthy_val($r[$c])) return true; }
            if($perm==='library' || $perm==='materials') foreach(array('can_view','view','read','browse','can_export','export','download','can_manage','manage','admin') as $c){ if(isset($r[$c]) && truthy_val($r[$c])) return true; }
            if($perm==='users') foreach(array('can_manage','manage','admin') as $c){ if(isset($r[$c]) && truthy_val($r[$c])) return true; }
        }
    }
    return false;
}
function user_can($u,$perm){
    if(!$u) return false;
    if(role_is_admin($u)) return true;
    // 统一权限中心保存过 BOM 后，旧 BOM 的按钮权限也同步以中央细分权限为准。
    try{
        $centralId=(int)($_SESSION['artdon_user_id']??($_SESSION['user_id']??0));
        if($centralId>0 && function_exists('artdon_perm_module_has_explicit') && artdon_perm_module_has_explicit($centralId,'bom')){
            $map=array(
                'dashboard'=>array('view_dashboard'),
                'edit'=>array('edit_bom','manage_materials','import_materials'),
                'library'=>array('view_library'),
                'materials'=>array('view_library','manage_materials'),
                'users'=>array('manage_users')
            );
            return artdon_sso_can_any_feature('bom',$map[$perm]??array('view_dashboard'),artdon_sso_current_user(false));
        }
    }catch(Throwable $e){}
    if(direct_bom_cols_can($u,$perm)) return true;
    $tokens = perm_tokens($u);
    if(in_array('all',$tokens,true) || in_array('admin',$tokens,true)) return true;
    if($perm==='users' && (in_array('users',$tokens,true) || in_array('user_admin',$tokens,true))) return true;
    if(in_array($perm,$tokens,true) || in_array('bom_'.$perm,$tokens,true)) return true;
    if(in_array('bom',$tokens,true) && in_array($perm, array('dashboard','edit','library','materials'), true)) return true;
    try{ if(isset($GLOBALS['pdo_for_perm']) && perm_table_can($GLOBALS['pdo_for_perm'],$u,$perm)) return true; }catch(Throwable $e){}
    return false;
}
function bom_require_login($pdo){
    $u = bom_current_user($pdo);
    if(!$u) json_out(array('ok'=>false,'need_login'=>true,'error'=>'请先登录统一账号'));
    return $u;
}
function bom_require_perm($u,$perm){
    if(!user_can($u,$perm)) json_out(array('ok'=>false,'error'=>'当前账号没有 BOM 权限：'.$perm.'。请在用户管理里勾选 BOM 成本，或给该账号增加 bom/all 权限。'));
}
function user_select_by_username($pdo,$username){
    foreach(all_user_like_tables($pdo) as $t){
        $uc=user_username_col($pdo,$t); $pk=user_pk_col($pdo,$t); if($uc==='' || $pk==='') continue;
        $st=$pdo->prepare("SELECT * FROM ".qid($t)." WHERE ".qid($uc)."=? LIMIT 1");
        $st->execute(array($username));
        $u=$st->fetch(PDO::FETCH_ASSOC);
        if($u){ $u['_user_table']=$t; $u['_pk_col']=$pk; $u['_username_col']=$uc; $u['_user_id']=(int)$u[$pk]; return $u; }
    }
    return null;
}
function verify_user_password($pdo,$u,$password){
    if(!$u || !row_is_active($pdo,$u['_user_table'],$u)) return false;
    foreach(user_password_cols($pdo,$u['_user_table']) as $c){
        if(!isset($u[$c]) || (string)$u[$c]==='') continue;
        $stored=(string)$u[$c];
        if(password_verify($password,$stored)) return true;
        if(strlen($stored)===32 && hash_equals(strtolower($stored), md5($password))) return true;
        if(strlen($stored)===40 && hash_equals(strtolower($stored), sha1($password))) return true;
        if(hash_equals($stored,$password)) return true;
    }
    return false;
}
function user_table_rows($pdo){
    $t=shared_user_table($pdo); if(!table_exists($pdo,$t)) return array();
    $pk=user_pk_col($pdo,$t); $order=$pk!=='' ? qid($pk).' ASC' : '1';
    $rows=$pdo->query("SELECT * FROM ".qid($t)." ORDER BY ".$order)->fetchAll(PDO::FETCH_ASSOC);
    $pk=$pk ?: 'id';
    foreach($rows as &$r){ $r['_user_table']=$t; $r['_pk_col']=$pk; if(isset($r[$pk])) $r['_user_id']=(int)$r[$pk]; }
    unset($r);
    return array_map('safe_user_row',$rows);
}
function save_shared_user($pdo,$d){
    // 账号统一由用户管理 users.php 维护；这里仅保留旧版兼容，避免误改 PLM 用户结构。
    json_out(array('ok'=>false,'error'=>'请到统一“用户管理”页面新增或修改账号权限。'));
}
function disable_shared_user($pdo,$id){
    json_out(array('ok'=>false,'error'=>'请到统一“用户管理”页面停用账号。'));
}
function ensure_bom_schema($pdo){
    if(!table_exists($pdo,'bom_projects')){
        $pdo->exec("CREATE TABLE IF NOT EXISTS bom_projects(
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            project_uid VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            customer VARCHAR(160) NOT NULL DEFAULT '',
            model VARCHAR(160) NOT NULL DEFAULT '',
            product_type VARCHAR(160) NOT NULL DEFAULT '',
            currency VARCHAR(20) NOT NULL DEFAULT 'RMB',
            product_image VARCHAR(500) NOT NULL DEFAULT '',
            labor DECIMAL(14,4) NOT NULL DEFAULT 0,
            other DECIMAL(14,4) NOT NULL DEFAULT 0,
            profit_rate DECIMAL(10,4) NOT NULL DEFAULT 30,
            quote_mode VARCHAR(40) NOT NULL DEFAULT 'markup',
            exchange_rate DECIMAL(14,6) NOT NULL DEFAULT 1,
            note LONGTEXT NULL,
            rows_json LONGTEXT NULL,
            linked_system VARCHAR(40) NOT NULL DEFAULT '',
            linked_id VARCHAR(80) NOT NULL DEFAULT '',
            linked_title VARCHAR(255) NOT NULL DEFAULT '',
            linked_json LONGTEXT NULL,
            naming_id INT NULL DEFAULT NULL,
            naming_model_no VARCHAR(80) NOT NULL DEFAULT '',
            naming_snapshot_json LONGTEXT NULL,
            naming_sync_hash VARCHAR(64) NOT NULL DEFAULT '',
            naming_synced_at DATETIME NULL,
            naming_source_updated_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(120) NULL,
            updated_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_project_uid(project_uid),
            KEY idx_model(model),
            KEY idx_naming_id(naming_id),
            KEY idx_updated(updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if(!table_exists($pdo,'bom_materials')){
        $pdo->exec("CREATE TABLE IF NOT EXISTS bom_materials(
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(120) NOT NULL DEFAULT '',
            brand VARCHAR(120) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL DEFAULT '',
            model VARCHAR(160) NOT NULL DEFAULT '',
            spec VARCHAR(500) NOT NULL DEFAULT '',
            price DECIMAL(14,4) NOT NULL DEFAULT 0,
            unit VARCHAR(40) NOT NULL DEFAULT '',
            supplier VARCHAR(160) NOT NULL DEFAULT '',
            keyword VARCHAR(500) NOT NULL DEFAULT '',
            image MEDIUMTEXT NULL,
            weight_kg_per_m DECIMAL(12,4) NULL,
            raw_bar_length_m DECIMAL(10,3) NULL,
            material_grade VARCHAR(120) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_name(name),
            KEY idx_model(model),
            KEY idx_category(category),
            KEY idx_updated(updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if(table_exists($pdo,'bom_materials')){
        $imageTypeStmt = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bom_materials' AND COLUMN_NAME = 'image' LIMIT 1");
        $imageTypeStmt->execute();
        $imageType = strtolower((string)$imageTypeStmt->fetchColumn());
        if($imageType !== '' && !in_array($imageType, array('text','mediumtext','longtext'), true)) {
            $pdo->exec("ALTER TABLE bom_materials MODIFY COLUMN image MEDIUMTEXT NULL");
        }
        if(!hascol($pdo,'bom_materials','is_active')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        if(!hascol($pdo,'bom_materials','created_at')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        if(!hascol($pdo,'bom_materials','updated_at')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        // Weight V1.2：BOM 型材库与重量计算页共用字段，安全补列。
        if(!hascol($pdo,'bom_materials','weight_kg_per_m')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN weight_kg_per_m DECIMAL(12,4) NULL COMMENT '重量页：每米净重 kg/m'");
        if(!hascol($pdo,'bom_materials','raw_bar_length_m')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN raw_bar_length_m DECIMAL(10,3) NULL COMMENT '重量页：原材料长度 m'");
        if(!hascol($pdo,'bom_materials','material_grade')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN material_grade VARCHAR(120) NOT NULL DEFAULT '' COMMENT '重量页：材质牌号'");
    }
    if(table_exists($pdo,'bom_projects')){
        if(!hascol($pdo,'bom_projects','is_active')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        if(!hascol($pdo,'bom_projects','updated_at')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        if(!hascol($pdo,'bom_projects','created_at')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        if(!hascol($pdo,'bom_projects','rows_json')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN rows_json LONGTEXT NULL");
        if(!hascol($pdo,'bom_projects','created_by')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN created_by VARCHAR(120) NULL");
        if(!hascol($pdo,'bom_projects','updated_by')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN updated_by VARCHAR(120) NULL");
        // V68：命名系统 / PLM 来源追踪字段，安全补列，不影响旧数据。
        if(!hascol($pdo,'bom_projects','linked_system')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN linked_system VARCHAR(40) NOT NULL DEFAULT ''");
        if(!hascol($pdo,'bom_projects','linked_id')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN linked_id VARCHAR(80) NOT NULL DEFAULT ''");
        if(!hascol($pdo,'bom_projects','linked_title')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN linked_title VARCHAR(255) NOT NULL DEFAULT ''");
        if(!hascol($pdo,'bom_projects','linked_json')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN linked_json LONGTEXT NULL");
        // V78：命名系统基础资料同步快照。只用于检测/同步型号基础资料，不影响 BOM 物料行和成本。
        if(!hascol($pdo,'bom_projects','naming_snapshot_json')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN naming_snapshot_json LONGTEXT NULL");
        if(!hascol($pdo,'bom_projects','naming_sync_hash')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN naming_sync_hash VARCHAR(64) NOT NULL DEFAULT ''");
        if(!hascol($pdo,'bom_projects','naming_synced_at')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN naming_synced_at DATETIME NULL");
        if(!hascol($pdo,'bom_projects','naming_source_updated_at')) $pdo->exec("ALTER TABLE bom_projects ADD COLUMN naming_source_updated_at DATETIME NULL");
    }
    // V78：命名同步日志，记录谁在何时把哪些基础字段同步到 BOM。
    if(!table_exists($pdo,'bom_naming_sync_logs')){
        $pdo->exec("CREATE TABLE bom_naming_sync_logs(
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            project_uid VARCHAR(100) NOT NULL DEFAULT '',
            naming_id INT NOT NULL DEFAULT 0,
            action VARCHAR(60) NOT NULL DEFAULT '',
            diff_json LONGTEXT NULL,
            operator VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_project(project_uid), KEY idx_naming(naming_id), KEY idx_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if(!table_exists($pdo,'bom_base_lists')){
        $pdo->exec("CREATE TABLE bom_base_lists(list_key VARCHAR(80) NOT NULL PRIMARY KEY,list_json LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,is_active TINYINT(1) DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}


// Weight V1.2：把重量计算页旧型材库 weight_profiles 强制同步到 BOM 共享物料库。
function bom_sync_weight_profiles_to_materials($pdo){
    $ret = array('ok'=>true,'source'=>'weight_profiles','inserted'=>0,'updated'=>0,'skipped'=>0,'message'=>'');
    try{
        if(!table_exists($pdo,'weight_profiles')){ $ret['message']='没有 weight_profiles 旧型材表'; return $ret; }
        if(!table_exists($pdo,'bom_materials')){ $ret['message']='没有 bom_materials 表'; return $ret; }
        if(!hascol($pdo,'bom_materials','weight_kg_per_m')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN weight_kg_per_m DECIMAL(12,4) NULL COMMENT '重量页：每米净重 kg/m'");
        if(!hascol($pdo,'bom_materials','raw_bar_length_m')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN raw_bar_length_m DECIMAL(10,3) NULL COMMENT '重量页：原材料长度 m'");
        if(!hascol($pdo,'bom_materials','material_grade')) $pdo->exec("ALTER TABLE bom_materials ADD COLUMN material_grade VARCHAR(120) NOT NULL DEFAULT '' COMMENT '重量页：材质牌号'");
        $activeWhere = hascol($pdo,'weight_profiles','is_active') ? " WHERE (is_active=1 OR is_active IS NULL OR is_active='')" : "";
        $rows = $pdo->query("SELECT * FROM weight_profiles".$activeWhere." ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $p){
            $model = trim((string)($p['model'] ?? ''));
            if($model===''){ $ret['skipped']++; continue; }
            $kgm = is_numeric($p['kgm'] ?? null) ? (float)$p['kgm'] : 0;
            $raw = ($p['raw_bar_length_m'] ?? null); if($raw==='' || $raw===null) $raw=null;
            $material = trim((string)($p['material'] ?? ''));
            $remark = trim((string)($p['remark'] ?? ''));
            $keyword = trim('型材 重量页 '.$model.' '.$material.' '.$kgm.'kg/m '.$remark.($raw!==null?' 原材料长度 '.$raw.'m':''));
            // 不能只按 category LIKE 型材 找，否则旧数据分类不规范时会重复；这里按 name/model 全库去重。
            $st = $pdo->prepare("SELECT id FROM bom_materials WHERE (is_active=1 OR is_active IS NULL OR is_active='') AND (model=? OR name=?) ORDER BY id DESC LIMIT 1");
            $st->execute(array($model,$model));
            $id = (int)$st->fetchColumn();
            if($id>0){
                $u=$pdo->prepare("UPDATE bom_materials SET category='型材', name=?, model=?, spec=IF(TRIM(COALESCE(spec,''))='',?,spec), keyword=?, unit=IF(TRIM(COALESCE(unit,''))='' OR unit='PCS','KG',unit), weight_kg_per_m=?, raw_bar_length_m=?, material_grade=?, is_active=1, updated_at=NOW() WHERE id=?");
                $u->execute(array($model,$model,$remark,$keyword,$kgm,$raw,$material,$id));
                $ret['updated']++;
            }else{
                $i=$pdo->prepare("INSERT INTO bom_materials(category,brand,name,model,spec,price,unit,supplier,keyword,image,weight_kg_per_m,raw_bar_length_m,material_grade,is_active) VALUES('型材','Artdon',?,?,?,0,'KG','',?,'',?,?,?,1)");
                $i->execute(array($model,$model,$remark,$keyword,$kgm,$raw,$material));
                $ret['inserted']++;
            }
        }
        $ret['message']='已同步重量型材到 BOM 共享物料库';
    }catch(Throwable $e){ $ret['ok']=false; $ret['message']=$e->getMessage(); }
    return $ret;
}

function bom_norm_text($v){
    $v = mb_strtolower((string)($v ?? ''), 'UTF-8');
    $v = preg_replace('/[￥¥]\s*\d+(?:\.\d+)?/u', ' ', $v);
    $v = preg_replace('/\b(?:rmb|usd)\s*\d+(?:\.\d+)?/iu', ' ', $v);
    $v = preg_replace('/\b\d+(?:\.\d+)?\s*(?:rmb|usd|元)\b/iu', ' ', $v);
    $v = str_replace(array('pcs','PCS'), ' ', $v);
    $v = preg_replace('/[^\p{L}\p{N}]+/u', '', $v);
    return $v ?: '';
}
function bom_join_unique($parts, $sep=' / '){
    $out = array();
    foreach($parts as $x){
        $x = trim((string)($x ?? ''));
        if($x==='') continue;
        $dup = false;
        $nx = bom_norm_text($x);
        foreach($out as $y){
            $ny = bom_norm_text($y);
            if($nx===$ny || ($ny!=='' && mb_strpos($nx,$ny)!==false) || ($nx!=='' && mb_strpos($ny,$nx)!==false)){ $dup=true; break; }
        }
        if(!$dup) $out[] = $x;
    }
    return implode($sep, $out);
}
function bom_material_display_name($m){
    $brand = trim((string)($m['brand'] ?? ''));
    $name = trim((string)($m['name'] ?? ''));
    $model = trim((string)($m['model'] ?? ''));
    if($name==='') return $brand ?: $model;
    if($brand!=='' && mb_strpos(bom_norm_text($name), bom_norm_text($brand)) !== false) return $name;
    return $brand!=='' ? ($brand.' / '.$name) : $name;
}
function bom_material_display_spec($m){
    $model = trim((string)($m['model'] ?? ''));
    $spec = trim((string)($m['spec'] ?? ''));
    if($spec==='') return $model;
    if($model!=='' && mb_strpos(bom_norm_text($spec), bom_norm_text($model)) !== false) return $spec;
    return $model!=='' ? ($model.' / '.$spec) : $spec;
}
function bom_bad_plm_text($r){
    $name = (string)($r['name'] ?? '');
    $spec = (string)($r['spec'] ?? '');
    $both = $name.' '.$spec;
    if($name !== '' && $spec !== '' && bom_norm_text($name) === bom_norm_text($spec)) return true;
    if(strpos($both, '¥') !== false || strpos($both, '￥') !== false) return true;
    if(substr_count($name, '/') >= 2 || substr_count($spec, '/') >= 2) return true;
    return false;
}
function bom_load_materials_for_match($pdo){
    if(!table_exists($pdo,'bom_materials')) return array();
    $where = hascol($pdo,'bom_materials','is_active') ? " WHERE is_active=1" : "";
    $rows = $pdo->query("SELECT * FROM bom_materials".$where." ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}
function bom_best_material_match($row, $materials){
    if(!$materials) return null;
    $rid = isset($row['materialId']) ? intval($row['materialId']) : (isset($row['material_id']) ? intval($row['material_id']) : 0);
    if($rid>0){ foreach($materials as $m){ if(intval($m['id'] ?? 0)===$rid) return $m; } }
    $text = bom_norm_text(bom_join_unique(array($row['name'] ?? '', $row['spec'] ?? '', $row['model'] ?? '', $row['material_name'] ?? '', $row['materialName'] ?? '', $row['brand'] ?? '', $row['supplier'] ?? ''), ' '));
    if($text==='') return null;
    $best = null; $bestScore = 0;
    foreach($materials as $m){
        $score = 0;
        $model = bom_norm_text($m['model'] ?? ''); $name  = bom_norm_text($m['name'] ?? ''); $brand = bom_norm_text($m['brand'] ?? ''); $spec  = bom_norm_text($m['spec'] ?? ''); $keyword = bom_norm_text($m['keyword'] ?? '');
        if($model !== '' && mb_strpos($text,$model)!==false) $score += 90 + min(30, mb_strlen($model, 'UTF-8'));
        if($name !== '' && mb_strpos($text,$name)!==false) $score += 70;
        if($brand !== '' && mb_strpos($text,$brand)!==false) $score += 20;
        if($spec !== '' && mb_strpos($text,$spec)!==false) $score += 12;
        if($keyword !== '' && mb_strpos($text,$keyword)!==false) $score += 10;
        if($name !== '' && mb_strpos($name,$text)!==false) $score += 40;
        if($model !== '' && mb_strpos($model,$text)!==false) $score += 50;
        if($score > $bestScore){ $bestScore=$score; $best=$m; }
    }
    return $bestScore >= 70 ? $best : null;
}

function bom_should_check_key_material_row($r){
    if(!is_array($r)) return false;
    $txt = mb_strtolower(trim((string)($r['category'] ?? '').' '.(string)($r['name'] ?? '').' '.(string)($r['spec'] ?? '')), 'UTF-8');
    if($txt === '') return false;
    foreach(array('电源','驱动','芯片','光源','cob','smd','led','光学','透镜','反光','光杯','镜片','配件','附件','接头','端子') as $term){
        if(mb_strpos($txt, mb_strtolower($term,'UTF-8')) !== false) return true;
    }
    return false;
}

function bom_normalize_rows_with_materials($pdo, $rows){
    if(!is_array($rows)) $rows = array();
    $materials = bom_load_materials_for_match($pdo);
    foreach($rows as &$r){
        if(!is_array($r)) $r = array();
        if(!bom_should_check_key_material_row($r)) continue;
        $m = bom_best_material_match($r, $materials);
        if(!$m) continue;
        $bad = bom_bad_plm_text($r);
        $oldPrice = isset($r['price']) ? floatval($r['price']) : 0;
        $matPrice = isset($m['price']) ? floatval($m['price']) : 0;
        $r['materialId'] = (string)($m['id'] ?? '');
        if(trim((string)($r['category'] ?? '')) === '') $r['category'] = $m['category'] ?? '';
        if($oldPrice <= 0 && $matPrice > 0) $r['price'] = $matPrice;
        if($bad || trim((string)($r['name'] ?? '')) === '') $r['name'] = bom_material_display_name($m);
        if($bad || trim((string)($r['spec'] ?? '')) === '') $r['spec'] = bom_material_display_spec($m);
        if(!isset($r['qty']) || floatval($r['qty'])<=0) $r['qty'] = 1;
    }
    unset($r);
    return $rows;
}


/* ARTDON_BOM_V77_7_IMAGE_DISPLAY_ONLY_START
 * 只给前端返回可显示图片。读取 naming_models 图片字段，按 naming_clean.php 方案转 URL。
 * 不更新 bom_projects，不动 rows_json，不动成本。
 */
function bom_v777_s($v){ return trim((string)($v ?? '')); }
function bom_v777_encode_path($path){
    $path = str_replace('\\','/',(string)$path);
    $parts = explode('/',$path); $out=array();
    foreach($parts as $p){ if($p===''){ $out[]=''; continue; } $out[]=rawurlencode(rawurldecode($p)); }
    return str_replace('%2F','/',implode('/',$out));
}
function bom_v777_public_origin(){
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if($host==='') return '';
    $proto = 'http';
    if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') $proto='https';
    return $proto.'://'.$host;
}
function bom_v777_website_base(){
    if(defined('ARTDON_WEBSITE_IMAGE_BASE') && trim((string)ARTDON_WEBSITE_IMAGE_BASE)!=='') return rtrim((string)ARTDON_WEBSITE_IMAGE_BASE,'/');
    $env=getenv('ARTDON_WEBSITE_IMAGE_BASE'); if($env!==false && trim((string)$env)!=='') return rtrim((string)$env,'/');
    return 'https://artdonlighting.com';
}
function bom_v777_url_with_base($base,$path,$query=''){
    $base=rtrim((string)$base,'/');
    $path=str_replace('\\','/',(string)$path);
    if($path==='' || $path[0] !== '/') $path='/'.ltrim($path,'/');
    return $base.bom_v777_encode_path($path).(string)$query;
}
function bom_v777_normalize_media_url($raw,$imgBase=null){
    $raw=trim((string)$raw); if($raw==='') return '';
    if(preg_match('/^data:image\//i',$raw) || preg_match('/^blob:/i',$raw)) return $raw;
    $raw=html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8');
    if(strpos($raw,'naming_media')!==false || strpos($raw,'media_proxy')!==false){
        $q=parse_url($raw,PHP_URL_QUERY); if($q){parse_str($q,$params); foreach(array('u','url','src') as $k){ if(!empty($params[$k])){ $raw=rawurldecode((string)$params[$k]); break; } }}
    }
    $websiteBase=rtrim((string)($imgBase ?: bom_v777_website_base()),'/'); if($websiteBase==='')$websiteBase='https://artdonlighting.com';
    $localBase=bom_v777_public_origin();
    if(preg_match('#^https?://#i',$raw)){
        $u=parse_url($raw); $path=(string)($u['path']??''); $query=isset($u['query'])&&$u['query']!==''?'?'.$u['query']:'';
        if(strpos($path,'/uploads/website/')===0) return bom_v777_url_with_base($websiteBase,$path,$query);
        if(strpos($path,'/uploads/naming/')===0) return $localBase!=='' ? bom_v777_url_with_base($localBase,$path,$query) : bom_v777_encode_path($path).$query;
        $scheme=strtolower((string)($u['scheme']??''));
        if($scheme==='http' && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) return '';
        return $raw;
    }
    $clean=preg_replace('/[?#].*$/','',$raw); if($clean===null)$clean=$raw;
    $clean=ltrim(str_replace('\\','/',$clean),'/');
    $pos=strpos($clean,'uploads/website/'); if($pos!==false&&$pos>0)$clean=substr($clean,$pos);
    $pos=strpos($clean,'uploads/naming/'); if($pos!==false&&$pos>0)$clean=substr($clean,$pos);
    if(strpos($clean,'uploads/website/')===0) return bom_v777_url_with_base($websiteBase,$clean);
    if(strpos($clean,'uploads/naming/')===0) return $localBase!=='' ? bom_v777_url_with_base($localBase,$clean) : '/'.bom_v777_encode_path($clean);
    if(strpos($clean,'website/products/')===0) return bom_v777_url_with_base($websiteBase,'uploads/'.$clean);
    if(strpos($clean,'uploads/')===0) return $localBase!=='' ? bom_v777_url_with_base($localBase,$clean) : '/'.bom_v777_encode_path($clean);
    return bom_v777_encode_path($raw);
}
function bom_v777_is_website_row($r){
    if(!is_array($r)) return false;
    $source=strtolower(bom_v777_s($r['source_system']??''));
    if(in_array($source,array('website','web','hongkong_web','hk_web','official_website','artdon_website'),true)) return true;
    foreach(array('source_id','source_url','source_synced_at','web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','cover_image_url','dimension_image_url') as $k){ if(bom_v777_s($r[$k]??'')!=='') return true; }
    $remark=bom_v777_s($r['remark']??'');
    return (strpos($remark,'官网')!==false && (strpos($remark,'同步')!==false || strpos($remark,'自动读取')!==false));
}
function bom_v777_pick_media($r,$kind='image'){
    if(!is_array($r)) return '';
    $isWeb=bom_v777_is_website_row($r);
    if($kind==='drawing'){
        $web=array('web_dimension_url','dimension_image_url','source_drawing_url','source_dimension_url','drawing_url','dimension_url','size_image_url','web_drawing_url','drawing_path');
        $local=array('drawing_path','web_dimension_url','dimension_image_url','source_drawing_url','dimension_url');
    }else{
        $web=array('web_image_url','cover_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','image_path');
        $local=array('image_path','web_image_url','cover_image_url','source_image_url','image_url','main_image','cover_image');
    }
    foreach(($isWeb?$web:$local) as $k){ $v=bom_v777_s($r[$k]??''); if($v!=='') return bom_v777_normalize_media_url($v); }
    return '';
}
function bom_v777_json_array($raw){ if(is_array($raw))return $raw; $raw=trim((string)($raw??'')); if($raw==='')return array(); $j=json_decode($raw,true); return is_array($j)?$j:array(); }
function bom_v777_norm_key($v){ $v=strtoupper(trim((string)($v??''))); $v=preg_replace('/\s+/u','',$v); $v=preg_replace('/[^A-Z0-9\.\-_]+/u','',$v); return (string)$v; }
function bom_v777_keys_from_text($txt){
    $out=array(); $s=(string)$txt; if($s==='')return $out;
    if(preg_match_all('/[A-Z]{0,4}\d{1,3}\.\d{3,8}[A-Z0-9\-_]*/iu',$s,$m)){ foreach($m[0] as $x){$k=bom_v777_norm_key($x); if($k!=='')$out[$k]=1;} }
    $k=bom_v777_norm_key($s); if($k!=='' && strlen($k)>=4 && strlen($k)<=80)$out[$k]=1;
    return array_keys($out);
}
function bom_v777_project_candidates(array $p){
    $txts=array($p['model']??'', $p['linked_title']??'', $p['name']??'', $p['product_type']??'', $p['note']??'');
    foreach(array('linked_json','naming_snapshot_json') as $k){ $j=bom_v777_json_array($p[$k]??''); if($j){ foreach(array('model_no','model','product_model','code','source_id','product_name') as $jk){ if(!empty($j[$jk]))$txts[]=$j[$jk]; } } }
    $out=array(); foreach($txts as $t){ foreach(bom_v777_keys_from_text($t) as $k)$out[$k]=1; }
    return array_keys($out);
}
function bom_v777_naming_image_index(PDO $pdo){
    static $cache=null; if($cache!==null)return $cache;
    $cache=array('by_id'=>array(),'by_key'=>array());
    if(!table_exists($pdo,'naming_models')) return $cache;
    try{ $rs=$pdo->query('SELECT * FROM naming_models ORDER BY id DESC LIMIT 8000')->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e){ return $cache; }
    foreach($rs as $r){
        $img=bom_v777_pick_media($r,'image'); if($img==='') continue;
        $id=(string)($r['id']??''); if($id!=='') $cache['by_id'][$id]=$img;
        $fields=array('model_no','model','product_model','code','product_code','name_code','sku','source_id','product_name','item_name','name');
        foreach($fields as $f){ if(isset($r[$f]) && trim((string)$r[$f])!==''){ foreach(bom_v777_keys_from_text($r[$f]) as $k){ if($k!=='' && !isset($cache['by_key'][$k]))$cache['by_key'][$k]=$img; } } }
    }
    return $cache;
}
function bom_v777_project_display_image(PDO $pdo,array $p){
    $raw=bom_v777_s($p['product_image']??'');
    if(preg_match('/^data:image\//i',$raw)) return $raw;
    foreach(array('linked_json','naming_snapshot_json') as $k){ $j=bom_v777_json_array($p[$k]??''); $img=bom_v777_pick_media($j,'image'); if($img!=='')return $img; }
    $idx=bom_v777_naming_image_index($pdo);
    $sys=strtoupper(bom_v777_s($p['linked_system']??'')); $nid=bom_v777_s($p['linked_id']??'');
    if($sys==='NAMING' && $nid!=='' && isset($idx['by_id'][$nid])) return $idx['by_id'][$nid];
    foreach(bom_v777_project_candidates($p) as $cand){
        if(isset($idx['by_key'][$cand])) return $idx['by_key'][$cand];
        foreach($idx['by_key'] as $k=>$img){
            if($cand!=='' && $k!=='' && (strpos($k,$cand)!==false || strpos($cand,$k)!==false)) return $img;
        }
    }
    return bom_v777_normalize_media_url($raw);
}
/* ARTDON_BOM_V77_7_IMAGE_DISPLAY_ONLY_END */


/* ARTDON_BOM_V68_NAMING_LINK_START */
function bom_v68_digits($v){ return preg_replace('/\D+/', '', (string)$v); }
function bom_v68_parse_size_code($modelNo, $fallback=''){
    $modelNo = (string)$modelNo;
    if(strpos($modelNo,'.')!==false){
        $after = explode('.', $modelNo, 2)[1];
        $d = substr(bom_v68_digits($after), 0, 3);
        if($d !== '') return str_pad($d, 3, '0', STR_PAD_LEFT);
    }
    $fb = bom_v68_digits($fallback);
    return $fb !== '' ? str_pad(substr($fb,0,3), 3, '0', STR_PAD_LEFT) : '';
}
function bom_v68_select_existing(PDO $pdo, string $table, array $fields){
    $out=array();
    foreach($fields as $f){ if(hascol($pdo,$table,$f)) $out[] = $f; }
    return $out;
}
function bom_v68_naming_columns(PDO $pdo){
    return bom_v68_select_existing($pdo, 'naming_models', array(
        'id','model_no','rule_id','category','item_name','prefix','size_code','serial_no','product_name','customer','status','remark',
        'source_system','source_id','source_url','source_synced_at','web_series','web_size_name','web_dimensions',
        'web_image_url','cover_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','image_path',
        'web_dimension_url','dimension_image_url','source_drawing_url','source_dimension_url','drawing_url','dimension_url','size_image_url','web_drawing_url','drawing_path',
        'dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height',
        'bom_template_type','bom_modules_json','bom_allowed','bom_unit','bom_head_count','bom_ready_note',
        'created_at','updated_at','created_by','updated_by'
    ));
}
function bom_v68_dimension_text(array $m){
    $size = bom_v68_parse_size_code($m['model_no'] ?? '', $m['size_code'] ?? '');
    $dt = (string)($m['dimension_type'] ?? '');
    $cat = (string)($m['category'] ?? '') . ' ' . (string)($m['item_name'] ?? '');
    $open = trim((string)($m['dim_opening'] ?? ''));
    $od = trim((string)($m['dim_outer_d'] ?? ''));
    $L = trim((string)($m['dim_length'] ?? ''));
    $W = trim((string)($m['dim_width'] ?? ''));
    $H = trim((string)($m['dim_height'] ?? ''));
    if($dt === 'embedded_round') return '圆形｜开孔 '.($open ?: $size ?: '-').($od ? '｜直径 '.$od : '').($H ? '｜高 '.$H : '');
    if($dt === 'embedded_square') return '方形｜开孔 '.($open ?: $size ?: '-').(($L||$W||$H) ? '｜'.implode(' × ', array_filter(array($L?'长 '.$L:'', $W?'宽 '.$W:'', $H?'高 '.$H:''))) : '');
    if($dt === 'opening' || mb_strpos($cat,'嵌入')!==false || mb_strpos($cat,'有边')!==false || mb_strpos($cat,'无边')!==false){
        return '开孔 '.($open ?: $size ?: '-').(($od||$L||$W||$H) ? '｜'.implode(' × ', array_filter(array($od?'直径 '.$od:'', $L?'长 '.$L:'', $W?'宽 '.$W:'', $H?'高 '.$H:''))) : '');
    }
    if($dt === 'diameter' || mb_strpos($cat,'导轨')!==false || mb_strpos($cat,'筒')!==false || mb_strpos($cat,'射灯')!==false){
        return '直径 '.($od ?: $size ?: '-').($H ? '｜高 '.$H : '');
    }
    return ($size ? '尺寸 '.$size : '尺寸 -').(($L||$W||$H) ? '｜'.implode(' × ', array_filter(array($L?'长 '.$L:'', $W?'宽 '.$W:'', $H?'高 '.$H:''))) : '');
}
function bom_v68_naming_format(PDO $pdo, array $m){
    $m['size_code_parsed'] = bom_v68_parse_size_code($m['model_no'] ?? '', $m['size_code'] ?? '');
    $m['dimension_text'] = bom_v68_dimension_text($m);
    $img = bom_v777_pick_media($m,'image');
    $drawing = bom_v777_pick_media($m,'drawing');
    if($img !== '') $m['image_path'] = $img;
    if($drawing !== '') $m['drawing_path'] = $drawing;
    $m['image_display_url'] = $img;
    $m['drawing_display_url'] = $drawing;
    $m['is_website_sync'] = bom_v777_is_website_row($m) ? 1 : 0;
    if(isset($m['bom_modules_json'])){
        $mods = json_decode((string)$m['bom_modules_json'], true);
        if(is_array($mods)) $m['bom_modules'] = $mods;
    }
    return $m;
}
function bom_v68_naming_search(PDO $pdo, array $d){
    if(!table_exists($pdo,'naming_models')) return array('available'=>false,'models'=>array(),'error'=>'未找到命名系统表 naming_models，请先确认 naming.php 已安装。');
    $cols = bom_v68_naming_columns($pdo);
    if(!$cols) return array('available'=>false,'models'=>array(),'error'=>'naming_models 字段不完整。');
    $where = array(); $args = array();
    $kw = trim((string)($d['kw'] ?? ''));
    if($kw !== ''){
        $parts=array();
        foreach(array('model_no','product_name','customer','item_name','category','remark') as $c){ if(in_array($c,$cols,true)){$parts[] = qid($c).' LIKE ?'; $args[]='%'.$kw.'%';} }
        if($parts) $where[]='('.implode(' OR ',$parts).')';
    }
    foreach(array('category'=>'category','item_name'=>'item_name','prefix'=>'prefix','status'=>'status') as $k=>$c){
        $v=trim((string)($d[$k] ?? ''));
        if($v!=='' && in_array($c,$cols,true)){ $where[] = qid($c).'=?'; $args[]=$v; }
    }
    $size = bom_v68_digits($d['size_code'] ?? '');
    if($size!=='' && in_array('size_code',$cols,true)){ $where[]='size_code=?'; $args[]=str_pad(substr($size,0,3),3,'0',STR_PAD_LEFT); }
    if(!empty($d['bom_only']) && in_array('bom_allowed',$cols,true)){ $where[]='bom_allowed=1'; }
    if(!empty($d['exclude_disabled']) && in_array('status',$cols,true)){ $where[]="status<>'停用'"; }
    $limit=(int)($d['limit'] ?? 80); if($limit<20)$limit=20; if($limit>500)$limit=500;
    $order = in_array('updated_at',$cols,true) ? 'updated_at DESC,id DESC' : 'id DESC';
    $sql='SELECT '.implode(',', array_map('qid',$cols)).' FROM naming_models'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.$order.' LIMIT '.$limit;
    $st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $out=array(); foreach($rows as $r) $out[]=bom_v68_naming_format($pdo,$r);
    return array('available'=>true,'models'=>$out,'count'=>count($out));
}
function bom_v68_naming_by_id(PDO $pdo, int $id){
    if($id<=0 || !table_exists($pdo,'naming_models')) return null;
    $cols=bom_v68_naming_columns($pdo); if(!$cols) return null;
    $st=$pdo->prepare('SELECT '.implode(',',array_map('qid',$cols)).' FROM naming_models WHERE id=? LIMIT 1');
    $st->execute(array($id)); $m=$st->fetch(PDO::FETCH_ASSOC);
    return $m ? bom_v68_naming_format($pdo,$m) : null;
}
function bom_v68_module_category($module){
    $s=mb_strtolower((string)$module,'UTF-8');
    if(mb_strpos($s,'光源')!==false || mb_strpos($s,'芯片')!==false || mb_strpos($s,'cob')!==false || mb_strpos($s,'smd')!==false) return '芯片';
    if(mb_strpos($s,'光学')!==false || mb_strpos($s,'透镜')!==false || mb_strpos($s,'反光')!==false || mb_strpos($s,'光杯')!==false) return '光学';
    if(mb_strpos($s,'电源')!==false || mb_strpos($s,'驱动')!==false) return '电源';
    if(mb_strpos($s,'型材')!==false || mb_strpos($s,'外壳')!==false || mb_strpos($s,'散热')!==false) return '型材';
    if(mb_strpos($s,'包装')!==false || mb_strpos($s,'纸箱')!==false) return '包装';
    return '配件';
}
function bom_v68_default_modules($template, $category='', $item=''){
    $t=(string)$template.' '.$category.' '.$item;
    if(mb_strpos($t,'线性')!==false) return array('COB/SMD光源','光学/扩散件','型材/外壳','电源','端盖/配件','包装');
    if(mb_strpos($t,'导轨')!==false) return array('COB光源','透镜/反光杯','电源','导轨接头','外壳/散热器','包装');
    if(mb_strpos($t,'磁吸')!==false) return array('COB/SMD光源','光学件','电源','磁吸接头/配件','外壳/散热器','包装');
    if(mb_strpos($t,'户外')!==false) return array('COB/SMD光源','光学件','电源','外壳/散热器','防水圈/密封件','螺丝包','包装');
    return array('COB光源','透镜/反光杯','电源','外壳/散热器','弹片/五金','接线端子','螺丝包','包装');
}
function bom_v68_modules_from_naming(array $m){
    $mods=array();
    if(!empty($m['bom_modules']) && is_array($m['bom_modules'])) $mods=$m['bom_modules'];
    if(!$mods && !empty($m['bom_modules_json'])){ $j=json_decode((string)$m['bom_modules_json'],true); if(is_array($j)) $mods=$j; }
    $clean=array();
    foreach($mods as $x){ if(is_array($x)) $x=($x['name']??$x['label']??''); $x=trim((string)$x); if($x!=='') $clean[]=$x; }
    if(!$clean) $clean = bom_v68_default_modules($m['bom_template_type'] ?? '', $m['category'] ?? '', $m['item_name'] ?? '');
    return array_values(array_unique($clean));
}
function bom_v68_rows_from_naming(array $m){
    $head = max(1, (int)($m['bom_head_count'] ?? 1));
    $dim = $m['dimension_text'] ?? bom_v68_dimension_text($m);
    $rows=array();
    foreach(bom_v68_modules_from_naming($m) as $module){
        $cat=bom_v68_module_category($module);
        $qty=1;
        if(in_array($cat,array('芯片','光学'),true)) $qty=$head;
        if(mb_strpos($module,'螺丝')!==false) $qty=1;
        $rows[]=array(
            'category'=>$cat,
            'name'=>'待选 '.$module,
            'spec'=>trim(($m['model_no'] ?? '').' ｜ '.($m['product_name'] ?? '').' ｜ '.$dim),
            'qty'=>$qty,
            'process'=>0,
            'finish'=>'',
            'finishCost'=>0,
            'price'=>0,
            'materialId'=>'',
            'source'=>'naming',
            'source_model_no'=>$m['model_no'] ?? '',
            'source_naming_id'=>(string)($m['id'] ?? '')
        );
    }
    return $rows;
}
function bom_v68_create_project_from_naming(PDO $pdo, array $user, array $d){
    $id=(int)($d['naming_id'] ?? 0);
    $m=bom_v68_naming_by_id($pdo,$id);
    if(!$m) json_out(array('ok'=>false,'error'=>'命名型号不存在或 naming_models 未初始化'));
    if(isset($m['bom_allowed']) && (string)$m['bom_allowed']!=='' && intval($m['bom_allowed'])!==1){
        json_out(array('ok'=>false,'error'=>'这个命名型号未开启“允许生成 BOM”。请先在命名系统里开启。'));
    }
    $who = user_label($user) ?: 'unknown';
    $projectUid = 'BOM-NAMING-'.$id.'-'.date('YmdHis');
    $modelNo=(string)($m['model_no'] ?? '');
    $product=(string)($m['product_name'] ?? '');
    $name = trim('BOM '.$modelNo.($product!==''?' · '.$product:''));
    $customer=(string)($m['customer'] ?? '');
    $ptype=trim((string)($m['category'] ?? '').((string)($m['item_name'] ?? '')!==''?' / '.(string)$m['item_name']:''));
    $rows=bom_v68_rows_from_naming($m);
    $note='来源：型号命名系统' . "\n" . '型号：'.$modelNo . "\n" . '尺寸：'.($m['dimension_text'] ?? '') . "\n" . 'BOM模板：'.($m['bom_template_type'] ?? '') . "\n" . '灯头数量：'.($m['bom_head_count'] ?? 1) . "\n" . '备注：'.($m['remark'] ?? '');
    $stmt=$pdo->prepare("INSERT INTO bom_projects
      (project_uid,name,customer,model,product_type,currency,product_image,labor,other,profit_rate,quote_mode,exchange_rate,note,rows_json,created_by,updated_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute(array($projectUid,$name,$customer,$modelNo,$ptype,'RMB',$m['image_display_url'] ?? ($m['image_path'] ?? ''),0,0,30,'markup',1,$note,json_encode($rows,JSON_UNESCAPED_UNICODE),$who,$who));
    if(table_exists($pdo,'bom_projects') && hascol($pdo,'bom_projects','linked_system')){
        $pdo->prepare("UPDATE bom_projects SET linked_system='NAMING',linked_id=?,linked_title=?,linked_json=? WHERE project_uid=?")
            ->execute(array((string)$id,$modelNo,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$projectUid));
        $snap=bom_v78_naming_snapshot($m);
        $pdo->prepare("UPDATE bom_projects SET naming_snapshot_json=?,naming_sync_hash=?,naming_synced_at=NOW(),naming_source_updated_at=? WHERE project_uid=?")
            ->execute(array(json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),bom_v78_naming_hash($snap),$snap['updated_at']??null,$projectUid));
    }
    return array('project_uid'=>$projectUid,'model'=>$m,'rows'=>$rows);
}

function bom_v771_bind_project_to_naming(PDO $pdo, array $user, array $d){
    $uid = trim((string)($d['project_uid'] ?? $d['id'] ?? ''));
    $id = (int)($d['naming_id'] ?? 0);
    if($uid === '') json_out(array('ok'=>false,'error'=>'缺少 BOM ID。'));
    if($id <= 0) json_out(array('ok'=>false,'error'=>'缺少命名型号 ID。'));
    if(!table_exists($pdo,'bom_projects')) json_out(array('ok'=>false,'error'=>'未找到 bom_projects 表。'));
    $m = bom_v68_naming_by_id($pdo, $id);
    if(!$m) json_out(array('ok'=>false,'error'=>'命名型号不存在或 naming_models 未初始化。'));
    $st = $pdo->prepare("SELECT project_uid,name,model,product_type,product_image FROM bom_projects WHERE project_uid=? LIMIT 1");
    $st->execute(array($uid));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if(!$p) json_out(array('ok'=>false,'error'=>'要绑定的 BOM 不存在。'));

    $modelNo = trim((string)($m['model_no'] ?? ''));
    $product = trim((string)($m['product_name'] ?? ''));
    $ptype = trim((string)($m['category'] ?? '') . ((string)($m['item_name'] ?? '') !== '' ? ' / ' . (string)$m['item_name'] : ''));
    $img = trim((string)($m['image_display_url'] ?? ($m['image_path'] ?? '')));
    if($img === '') $img = trim((string)($m['drawing_display_url'] ?? ($m['drawing_path'] ?? '')));
    $who = user_label($user) ?: 'unknown';

    // 只同步基础资料；不动 rows_json、不动物料明细、不动数量/价格。
    $sql = "UPDATE bom_projects SET
        model=?,
        product_type=?,
        product_image=CASE WHEN ?<>'' THEN ? ELSE product_image END,
        linked_system='NAMING',
        linked_id=?,
        linked_title=?,
        linked_json=?,
        naming_snapshot_json=?,
        naming_sync_hash=?,
        naming_synced_at=NOW(),
        naming_source_updated_at=?,
        updated_by=?,
        updated_at=NOW()
      WHERE project_uid=?";
    $pdo->prepare($sql)->execute(array(
        $modelNo,
        $ptype,
        $img,
        $img,
        (string)$id,
        $modelNo !== '' ? $modelNo : (string)$id,
        json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(bom_v78_naming_snapshot($m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        bom_v78_naming_hash(bom_v78_naming_snapshot($m)),
        $m['updated_at'] ?? null,
        $who,
        $uid
    ));

    return array('project_uid'=>$uid,'model'=>$m);
}

function bom_v771_unbind_project_from_naming(PDO $pdo, array $user, array $d){
    $uid = trim((string)($d['project_uid'] ?? $d['id'] ?? ''));
    if($uid === '') json_out(array('ok'=>false,'error'=>'缺少 BOM ID。'));
    if(!table_exists($pdo,'bom_projects')) json_out(array('ok'=>false,'error'=>'未找到 bom_projects 表。'));
    $who = user_label($user) ?: 'unknown';
    $pdo->prepare("UPDATE bom_projects SET linked_system='',linked_id='',linked_title='',linked_json=NULL,naming_snapshot_json=NULL,naming_sync_hash='',naming_synced_at=NULL,naming_source_updated_at=NULL,updated_by=?,updated_at=NOW() WHERE project_uid=?")
        ->execute(array($who,$uid));
    return array('project_uid'=>$uid);
}

/* ARTDON_BOM_V78_NAMING_SYNC_START */
function bom_v78_naming_snapshot(array $m){
    $keys=array('id','model_no','product_name','customer','category','item_name','size_code','dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','image_path','drawing_path','remark','bom_template_type','bom_head_count','updated_at');
    $out=array(); foreach($keys as $k){ $out[$k]=isset($m[$k])?(string)$m[$k]:''; }
    $out['dimension_text']=isset($m['dimension_text'])?(string)$m['dimension_text']:bom_v68_dimension_text($m);
    $out['product_type']=trim(($out['category']!==''?$out['category']:'').($out['item_name']!==''?' / '.$out['item_name']:''));
    return $out;
}
function bom_v78_naming_hash(array $snap){ return md5(json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
function bom_v78_snapshot_from_project(array $p){
    $raw='';
    if(!empty($p['naming_snapshot_json'])) $raw=(string)$p['naming_snapshot_json'];
    elseif(!empty($p['linked_json'])) $raw=(string)$p['linked_json'];
    $j=$raw!==''?json_decode($raw,true):null;
    if(is_array($j)) return bom_v78_naming_snapshot($j);
    return array();
}
function bom_v78_naming_diffs(array $old,array $new){
    $map=array('model_no'=>'型号','product_name'=>'产品名称','customer'=>'客户/项目','category'=>'产品大类','item_name'=>'产品类型','product_type'=>'产品分类','dimension_text'=>'尺寸信息','dim_opening'=>'开孔','dim_outer_d'=>'直径','dim_length'=>'长','dim_width'=>'宽','dim_height'=>'高','image_path'=>'产品图','drawing_path'=>'尺寸图','remark'=>'备注');
    $diff=array();
    foreach($map as $k=>$label){
        $a=trim((string)($old[$k]??'')); $b=trim((string)($new[$k]??''));
        if($a!==$b) $diff[]=array('field'=>$k,'label'=>$label,'old'=>$a,'new'=>$b);
    }
    return $diff;
}
function bom_v78_project_row(PDO $pdo,$uid){
    $st=$pdo->prepare('SELECT * FROM bom_projects WHERE project_uid=? LIMIT 1'); $st->execute(array($uid)); $p=$st->fetch(PDO::FETCH_ASSOC); return $p?:null;
}
function bom_v78_naming_sync_check(PDO $pdo,array $p){
    $sys=strtoupper(trim((string)($p['linked_system']??''))); $nid=(int)($p['linked_id']??0);
    if($sys!=='NAMING' || $nid<=0) return array('linked'=>false,'has_update'=>false,'diffs'=>array(),'message'=>'当前 BOM 未绑定命名型号');
    $m=bom_v68_naming_by_id($pdo,$nid);
    if(!$m) return array('linked'=>true,'missing'=>true,'has_update'=>true,'diffs'=>array(),'message'=>'命名型号已不存在或无法读取');
    $old=bom_v78_snapshot_from_project($p); $new=bom_v78_naming_snapshot($m); $diff=bom_v78_naming_diffs($old,$new);
    $oldHash=trim((string)($p['naming_sync_hash']??'')); if($oldHash==='') $oldHash=bom_v78_naming_hash($old);
    $newHash=bom_v78_naming_hash($new);
    return array('linked'=>true,'missing'=>false,'has_update'=>($oldHash!==$newHash || count($diff)>0),'diffs'=>$diff,'current'=>$new,'snapshot'=>$old,'hash'=>$newHash,'source_updated_at'=>$new['updated_at']??'','synced_at'=>$p['naming_synced_at']??'','message'=>count($diff)?('命名资料有 '.count($diff).' 项变化'):'命名基础资料已同步');
}
function bom_v78_should_update_name($name,$oldModel,$newModel){
    $name=trim((string)$name); if($name==='') return true;
    if($oldModel!=='' && mb_strpos($name,$oldModel)!==false) return true;
    if($newModel!=='' && preg_match('/^BOM\s+/i',$name)) return true;
    return false;
}
function bom_v78_log_sync(PDO $pdo,$uid,$nid,$action,$diffs,$who){
    try{ if(table_exists($pdo,'bom_naming_sync_logs')) $pdo->prepare('INSERT INTO bom_naming_sync_logs(project_uid,naming_id,action,diff_json,operator) VALUES(?,?,?,?,?)')->execute(array((string)$uid,(int)$nid,(string)$action,json_encode($diffs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)$who)); }catch(Throwable $e){}
}
function bom_v78_naming_sync_apply(PDO $pdo,array $user,array $p){
    $check=bom_v78_naming_sync_check($pdo,$p);
    if(empty($check['linked']) || !empty($check['missing'])) return $check + array('ok'=>false);
    $nid=(int)$p['linked_id']; $m=bom_v68_naming_by_id($pdo,$nid); $snap=bom_v78_naming_snapshot($m); $diffs=$check['diffs']??array();
    $who=user_label($user) ?: 'unknown';
    $modelNo=trim((string)($snap['model_no']??'')); $product=trim((string)($snap['product_name']??'')); $ptype=trim((string)($snap['product_type']??''));
    $img=trim((string)($m['image_display_url']??($snap['image_path']??''))); if($img==='') $img=trim((string)($m['drawing_display_url']??($snap['drawing_path']??'')));
    $newName=trim('BOM '.$modelNo.($product!==''?' · '.$product:''));
    $oldSnap=bom_v78_snapshot_from_project($p); $oldModel=trim((string)($oldSnap['model_no']??($p['linked_title']??'')));
    $setName=bom_v78_should_update_name($p['name']??'',$oldModel,$modelNo) ? $newName : (string)($p['name']??'');
    $sql="UPDATE bom_projects SET name=?, customer=CASE WHEN ?<>'' THEN ? ELSE customer END, model=?, product_type=?, product_image=CASE WHEN ?<>'' THEN ? ELSE product_image END, linked_system='NAMING', linked_id=?, linked_title=?, linked_json=?, naming_snapshot_json=?, naming_sync_hash=?, naming_synced_at=NOW(), naming_source_updated_at=?, updated_by=?, updated_at=NOW() WHERE project_uid=?";
    $pdo->prepare($sql)->execute(array($setName,(string)($snap['customer']??''),(string)($snap['customer']??''),$modelNo,$ptype,$img,$img,(string)$nid,$modelNo,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),bom_v78_naming_hash($snap),($snap['updated_at']??null),$who,(string)$p['project_uid']));
    bom_v78_log_sync($pdo,(string)$p['project_uid'],$nid,'sync_basic',$diffs,$who);
    return array('ok'=>true,'project_uid'=>(string)$p['project_uid'],'model'=>$m,'diffs'=>$diffs,'message'=>'已同步命名基础资料；BOM 物料、成本、价格没有改动');
}
/* ARTDON_BOM_V78_NAMING_SYNC_END */

/* ARTDON_BOM_V68_NAMING_LINK_END */

try{
    $pdo = pdo_safe();
    $GLOBALS['pdo_for_perm']=$pdo;
    ensure_bom_schema($pdo);
    ensure_bom_user_schema($pdo);

    if($action === 'ping'){
        json_out(array('ok'=>true,'message'=>'BOM API connected','login'=> bom_current_user($pdo)?true:false));
    }


    if($action === 'auth_debug'){
        $username = trim((string)($_GET['username'] ?? ''));
        $tables = array();
        foreach(all_user_like_tables($pdo) as $t){
            $tables[] = array('table'=>$t,'pk'=>user_pk_col($pdo,$t),'username_col'=>user_username_col($pdo,$t),'password_cols'=>user_password_cols($pdo,$t),'status_col'=>user_status_col($pdo,$t),'role_col'=>user_role_col($pdo,$t),'perm_col'=>user_perm_col($pdo,$t));
        }
        $found = null;
        if($username!==''){
            $u=user_select_by_username($pdo,$username);
            if($u){ $found=array('table'=>$u['_user_table'],'pk'=>$u['_pk_col'],'id'=>$u['_user_id'],'active'=>row_is_active($pdo,$u['_user_table'],$u),'role'=>(string)($u[user_role_col($pdo,$u['_user_table'])] ?? ''),'can_bom_dashboard'=>user_can($u,'dashboard'),'can_bom_edit'=>user_can($u,'edit')); }
        }
        json_out(array('ok'=>true,'tables'=>$tables,'found'=>$found));
    }

    if($action === 'login'){
        $d = body_json();
        $username = trim((string)($d['username'] ?? ''));
        $password = (string)($d['password'] ?? '');
        if($username==='' || $password==='') json_out(array('ok'=>false,'error'=>'请输入用户名和密码'));
        $u = user_select_by_username($pdo,$username);
        if(!$u) json_out(array('ok'=>false,'error'=>'没有找到这个统一账号。请确认账号字段是不是 qiulei，而不是姓名。'));
        if(!row_is_active($pdo,$u['_user_table'],$u)) json_out(array('ok'=>false,'error'=>'账号未启用，请在用户管理里启用。'));
        $ok = verify_user_password($pdo,$u,$password);
        if(!$ok) json_out(array('ok'=>false,'error'=>'密码不正确。请在用户管理里给该账号重新设置一次新密码后再登录 BOM。'));
        session_regenerate_id(true);
        $pk = $u['_pk_col'];
        $uid = (int)$u['_user_id'];
        $_SESSION['office_user_id'] = $uid;
        $_SESSION['office_user_table'] = $u['_user_table'];
        $_SESSION['office_user_pk'] = $pk;
        $_SESSION['bom_user_id'] = $uid;
        $_SESSION['artdon_login_until'] = time() + 86400;
        try{ if(hascol($pdo,$u['_user_table'],'last_login')) $pdo->prepare("UPDATE ".qid($u['_user_table'])." SET last_login=NOW() WHERE ".qid($pk)."=?")->execute(array($uid)); }catch(Throwable $e){}
        $st = $pdo->prepare("SELECT * FROM ".qid($u['_user_table'])." WHERE ".qid($pk)."=? LIMIT 1"); $st->execute(array($uid)); $u = $st->fetch(PDO::FETCH_ASSOC); $u['_user_table']=$_SESSION['office_user_table']; $u['_pk_col']=$pk; $u['_user_id']=$uid;
        json_out(array('ok'=>true,'user'=>safe_user_row($u),'can'=>array(
            'dashboard'=>user_can($u,'dashboard'), 'edit'=>user_can($u,'edit'), 'library'=>user_can($u,'library'), 'materials'=>user_can($u,'materials'), 'users'=>user_can($u,'users')
        )));
    }

    if($action === 'logout'){
        $_SESSION = array();
        if(ini_get('session.use_cookies')){
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        json_out(array('ok'=>true));
    }

    if($action === 'me'){
        $u = bom_current_user($pdo);
        if(!$u) json_out(array('ok'=>true,'login'=>false));
        json_out(array('ok'=>true,'login'=>true,'user'=>safe_user_row($u),'can'=>array(
            'dashboard'=>$__bom_deep_plm ? true : user_can($u,'dashboard'), 'edit'=>$__bom_deep_plm ? false : user_can($u,'edit'), 'library'=>$__bom_deep_plm ? false : user_can($u,'library'), 'materials'=>$__bom_deep_plm ? false : user_can($u,'materials'), 'users'=>$__bom_deep_plm ? false : user_can($u,'users')
        )));
    }

    $user = bom_require_login($pdo);

    if($action === 'bootstrap'){
        if(!$__bom_deep_plm) bom_require_perm($user,'dashboard');
        $canCost = artdon_sso_can('bom','cost_view');
        $canSupplier = artdon_sso_can('bom','supplier_view');
        if($__bom_deep_plm){
            $activeWhere = (table_exists($pdo,'bom_projects') && hascol($pdo,'bom_projects','is_active')) ? " AND is_active=1" : "";
            $st=$pdo->prepare("SELECT * FROM bom_projects WHERE project_uid=?".$activeWhere." ORDER BY updated_at DESC LIMIT 1");
            $st->execute(array($__bom_deep_uid));
            $projects=$st->fetchAll();
        }else{
            $projectWhere = (table_exists($pdo,'bom_projects') && hascol($pdo,'bom_projects','is_active')) ? " WHERE is_active=1" : "";
            $projects = $pdo->query("SELECT * FROM bom_projects".$projectWhere." ORDER BY updated_at DESC")->fetchAll();
        }
        foreach($projects as &$p){
            $p['rows'] = json_decode($p['rows_json'] ?: '[]', true) ?: array();
            $p['rows'] = bom_hide_sensitive_list($p['rows'], $canCost, $canSupplier);
            $p = bom_hide_sensitive_row($p, $canCost, $canSupplier);
            if(!$canCost && isset($p['rows_json'])) $p['rows_json'] = json_encode($p['rows'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $p['naming_sync'] = bom_v78_naming_sync_check($pdo,$p);
            // V77.7：仅返回前端显示图；不写库、不改 BOM 明细/成本。
            $p['product_image_display'] = bom_v777_project_display_image($pdo,$p);
        }
        unset($p);
        // Weight V1.2：进入 BOM 时也自动把重量页旧型材同步进共享物料库，避免两边数量不一致。
        bom_sync_weight_profiles_to_materials($pdo);
        $matWhere = (table_exists($pdo,'bom_materials') && hascol($pdo,'bom_materials','is_active')) ? " WHERE is_active=1" : "";
        $materials = $__bom_deep_plm ? array() : (table_exists($pdo,'bom_materials') ? $pdo->query("SELECT * FROM bom_materials".$matWhere." ORDER BY updated_at DESC, id DESC")->fetchAll() : array());
        $materials = bom_hide_sensitive_list($materials, $canCost, $canSupplier);
        $lists = array();
        foreach($pdo->query("SELECT * FROM bom_base_lists")->fetchAll() as $r){ $lists[$r['list_key']] = json_decode($r['list_json'], true) ?: array(); }
        json_out(array('ok'=>true,'projects'=>$projects,'materials'=>$materials,'lists'=>$lists,'user'=>safe_user_row($user),'can'=>array(
            'dashboard'=>$__bom_deep_plm ? true : user_can($user,'dashboard'), 'edit'=>$__bom_deep_plm ? false : user_can($user,'edit'), 'library'=>$__bom_deep_plm ? false : user_can($user,'library'), 'materials'=>$__bom_deep_plm ? false : user_can($user,'materials'), 'users'=>$__bom_deep_plm ? false : user_can($user,'users'), 'cost_view'=>$canCost, 'supplier_view'=>$canSupplier, 'naming_link'=>$__bom_deep_plm ? false : artdon_sso_can('bom','naming_link')
        )));
    }

    if($action === 'naming_models'){
        bom_require_perm($user,'dashboard');
        $d = body_json();
        $d['exclude_disabled'] = 1;
        json_out(array('ok'=>true) + bom_v68_naming_search($pdo,$d));
    }

    if($action === 'create_from_naming'){
        bom_require_perm($user,'edit');
        $d = body_json();
        $res = bom_v68_create_project_from_naming($pdo,$user,$d);
        json_out(array('ok'=>true) + $res);
    }

    if($action === 'bind_naming_to_project'){
        bom_require_perm($user,'edit');
        $d = body_json();
        $res = bom_v771_bind_project_to_naming($pdo,$user,$d);
        json_out(array('ok'=>true) + $res);
    }

    if($action === 'unbind_naming_from_project'){
        bom_require_perm($user,'edit');
        $d = body_json();
        $res = bom_v771_unbind_project_from_naming($pdo,$user,$d);
        json_out(array('ok'=>true) + $res);
    }

    if($action === 'naming_sync_check'){
        bom_require_perm($user,'dashboard');
        $d=body_json(); $uid=trim((string)($d['project_uid'] ?? $d['id'] ?? ''));
        if($uid==='') json_out(array('ok'=>false,'error'=>'缺少 BOM ID'));
        $p=bom_v78_project_row($pdo,$uid); if(!$p) json_out(array('ok'=>false,'error'=>'BOM 不存在'));
        json_out(array('ok'=>true) + bom_v78_naming_sync_check($pdo,$p));
    }

    if($action === 'naming_sync_apply'){
        bom_require_perm($user,'edit');
        $d=body_json(); $uid=trim((string)($d['project_uid'] ?? $d['id'] ?? ''));
        if($uid==='') json_out(array('ok'=>false,'error'=>'缺少 BOM ID'));
        $p=bom_v78_project_row($pdo,$uid); if(!$p) json_out(array('ok'=>false,'error'=>'BOM 不存在'));
        $res=bom_v78_naming_sync_apply($pdo,$user,$p);
        json_out(array('ok'=>true) + $res);
    }

    if($action === 'list_users'){
        bom_require_perm($user,'users');
        json_out(array('ok'=>true,'users'=>user_table_rows($pdo),'user_table'=>shared_user_table($pdo)));
    }

    if($action === 'save_user'){
        bom_require_perm($user,'users');
        $id = save_shared_user($pdo, body_json());
        json_out(array('ok'=>true,'id'=>$id,'user_table'=>shared_user_table($pdo)));
    }

    if($action === 'disable_user'){
        bom_require_perm($user,'users');
        $d = body_json();
        $id = intval($d['id'] ?? 0);
        if($id<=0) json_out(array('ok'=>false,'error'=>'用户ID不正确'));
        if($id === (int)$user['id']) json_out(array('ok'=>false,'error'=>'不能停用当前登录账号'));
        disable_shared_user($pdo,$id);
        json_out(array('ok'=>true));
    }

    if($action === 'save_project'){
        bom_require_perm($user,'edit');
        $d = body_json();
        $uid = $d['project_uid'] ?? $d['id'] ?? uid();
        $normalizedRows = bom_normalize_rows_with_materials($pdo, $d['rows'] ?? array());
        $rows = json_encode($normalizedRows, JSON_UNESCAPED_UNICODE);
        $who = user_label($user) ?: 'unknown';
        $createdBy = trim((string)($d['created_by'] ?? '')) ?: $who;
        $stmt = $pdo->prepare("INSERT INTO bom_projects
          (project_uid,name,customer,model,product_type,currency,product_image,labor,other,profit_rate,quote_mode,exchange_rate,note,rows_json,created_by,updated_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
          name=VALUES(name),customer=VALUES(customer),model=VALUES(model),product_type=VALUES(product_type),currency=VALUES(currency),
          product_image=VALUES(product_image),labor=VALUES(labor),other=VALUES(other),profit_rate=VALUES(profit_rate),
          quote_mode=VALUES(quote_mode),exchange_rate=VALUES(exchange_rate),note=VALUES(note),rows_json=VALUES(rows_json),
          updated_by=VALUES(updated_by),updated_at=NOW()");
        $stmt->execute(array(
            $uid, $d['name']??'', $d['customer']??'', $d['model']??'', $d['product_type']??'', $d['currency']??'RMB',
            $d['product_image']??'', $d['labor']??0, $d['other']??0, $d['profit_rate']??30, $d['quote_mode']??'markup',
            $d['exchange_rate']??1, $d['note']??'', $rows, $createdBy, $who
        ));
        json_out(array('ok'=>true,'project_uid'=>$uid));
    }

    if($action === 'delete_project'){
        bom_require_perm($user,'edit');
        $d = body_json();
        if(table_exists($pdo,'bom_projects') && hascol($pdo,'bom_projects','is_active')){
            $stmt = $pdo->prepare("UPDATE bom_projects SET is_active=0,updated_at=NOW() WHERE project_uid=?");
        }else{
            $stmt = $pdo->prepare("DELETE FROM bom_projects WHERE project_uid=?");
        }
        $stmt->execute(array($d['project_uid'] ?? ''));
        json_out(array('ok'=>true));
    }



    if($action === 'sync_weight_profiles_to_bom'){
        bom_require_perm($user,'materials');
        ensure_bom_schema($pdo);
        $res = bom_sync_weight_profiles_to_materials($pdo);
        json_out($res);
    }

    if($action === 'import_materials_bulk'){
        bom_require_perm($user,'materials');
        $d = body_json();
        $rows = isset($d['rows']) && is_array($d['rows']) ? $d['rows'] : array();
        $mode = (string)($d['mode'] ?? 'upsert');
        $inserted=0; $updated=0; $skipped=0; $duplicates=0; $lastId=0;
        if(count($rows)>3000) json_out(array('ok'=>false,'error'=>'一次最多导入 3000 行，请分批导入。'));
        foreach($rows as $r){
            if(!is_array($r)){ $skipped++; continue; }
            $category=bom_material_trim($r['category'] ?? '');
            $brand=bom_material_trim($r['brand'] ?? '');
            $name=bom_material_trim($r['name'] ?? '');
            $model=bom_material_trim($r['model'] ?? '');
            $spec=bom_material_trim($r['spec'] ?? '');
            $price=bom_num_zero($r['price'] ?? 0);
            $unit=bom_material_trim($r['unit'] ?? 'PCS') ?: 'PCS';
            $supplier=bom_material_trim($r['supplier'] ?? '');
            $keyword=bom_material_trim($r['keyword'] ?? '');
            $image=trim((string)($r['image'] ?? ''));
            if($name==='' && $model===''){ $skipped++; continue; }

            // 三字段组合唯一：物料名称 + 型号 + 规格。导入更新时按这三个字段找旧物料；追加时遇到重复直接跳过。
            $id=0;
            $dupes = bom_material_exact_duplicates($pdo,$name,$model,$spec,0);
            if($dupes){
                if($mode==='upsert'){
                    $id=(int)($dupes[0]['id'] ?? 0);
                }else{
                    $duplicates++; $skipped++; continue;
                }
            }
            if($id>0){
                $stmt=$pdo->prepare("UPDATE bom_materials SET category=?,brand=?,name=?,model=?,spec=?,price=?,unit=?,supplier=?,keyword=?,image=?,updated_at=NOW() WHERE id=?");
                $stmt->execute(array($category,$brand,$name,$model,$spec,$price,$unit,$supplier,$keyword,$image,$id));
                $updated++; $lastId=$id;
            }else{
                $stmt=$pdo->prepare("INSERT INTO bom_materials(category,brand,name,model,spec,price,unit,supplier,keyword,image) VALUES(?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute(array($category,$brand,$name,$model,$spec,$price,$unit,$supplier,$keyword,$image));
                $lastId=(int)$pdo->lastInsertId(); $inserted++;
            }
        }
        json_out(array('ok'=>true,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped,'duplicates'=>$duplicates,'last_id'=>$lastId));
    }

    if($action === 'save_material'){
        bom_require_perm($user,'materials');
        $d = body_json();
        $id = intval($d['id'] ?? 0);
        $price = bom_num_zero($d['price'] ?? 0); // V77.1：空单价按 0 保存，避免 MySQL 1366 Incorrect decimal value
        $category = bom_material_trim($d['category'] ?? '');
        $brand = bom_material_trim($d['brand'] ?? '');
        $name = bom_material_trim($d['name'] ?? '');
        $model = bom_material_trim($d['model'] ?? '');
        $spec = bom_material_trim($d['spec'] ?? '');
        $unit = bom_material_trim($d['unit'] ?? 'PCS') ?: 'PCS';
        $supplier = bom_material_trim($d['supplier'] ?? '');
        $keyword = bom_material_trim($d['keyword'] ?? '');
        $image = trim((string)($d['image'] ?? ''));
        if($name==='') json_out(array('ok'=>false,'error'=>'物料名称不能为空。'));

        // 三字段组合唯一：物料名称 + 型号 + 规格。编辑时排除自身；重复时绝不保存。
        $dupes = bom_material_exact_duplicates($pdo,$name,$model,$spec,$id);
        if(count($dupes)>0){
            json_out(array(
                'ok'=>false,
                'duplicate_material'=>true,
                'error'=>'物料名称 + 型号 + 规格 已存在，不能重复保存。',
                'duplicates'=>$dupes
            ));
        }

        // 新增物料时做类似件提醒。用户确认“仍然保存”后会带 confirm_similar=1 再次提交。
        if($id<=0 && empty($d['confirm_similar'])){
            $similars = bom_material_find_similars($pdo,array('category'=>$category,'brand'=>$brand,'name'=>$name,'model'=>$model,'spec'=>$spec,'supplier'=>$supplier,'keyword'=>$keyword),0,10);
            if(count($similars)>0){
                json_out(array(
                    'ok'=>false,
                    'need_confirm_similar'=>true,
                    'error'=>'发现类似物料，请先确认是否已经存在。',
                    'similars'=>$similars
                ));
            }
        }

        if($id>0){
            $stmt=$pdo->prepare("UPDATE bom_materials SET category=?,brand=?,name=?,model=?,spec=?,price=?,unit=?,supplier=?,keyword=?,image=?,updated_at=NOW() WHERE id=?");
            $stmt->execute(array($category, $brand, $name, $model, $spec, $price, $unit, $supplier, $keyword, $image, $id));
        }else{
            $stmt=$pdo->prepare("INSERT INTO bom_materials(category,brand,name,model,spec,price,unit,supplier,keyword,image) VALUES(?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute(array($category, $brand, $name, $model, $spec, $price, $unit, $supplier, $keyword, $image));
            $id = intval($pdo->lastInsertId());
        }
        json_out(array('ok'=>true,'id'=>$id));
    }

    if($action === 'delete_material'){
        bom_require_perm($user,'materials');
        $d = body_json();
        if(table_exists($pdo,'bom_materials') && hascol($pdo,'bom_materials','is_active')){
            $stmt=$pdo->prepare("UPDATE bom_materials SET is_active=0,updated_at=NOW() WHERE id=?");
        }else{
            $stmt=$pdo->prepare("DELETE FROM bom_materials WHERE id=?");
        }
        $stmt->execute(array(intval($d['id']??0)));
        json_out(array('ok'=>true));
    }

    if($action === 'save_list'){
        bom_require_perm($user,'materials');
        $d = body_json();
        $key = $d['key'] ?? '';
        $list = json_encode($d['list'] ?? array(), JSON_UNESCAPED_UNICODE);
        if(!in_array($key, array('categories','brands','suppliers','productTypes'))) json_out(array('ok'=>false,'error'=>'invalid key'));
        $stmt=$pdo->prepare("INSERT INTO bom_base_lists(list_key,list_json) VALUES(?,?) ON DUPLICATE KEY UPDATE list_json=VALUES(list_json)");
        $stmt->execute(array($key,$list));
        json_out(array('ok'=>true));
    }

    json_out(array('ok'=>false,'error'=>'unknown action'));
}catch(Throwable $e){
    json_out(array('ok'=>false,'error'=>$e->getMessage()));
}
?>
