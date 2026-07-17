<?php
/**
 * Artdon Office 统一账号兼容层 V2.0
 * 账号密码唯一来源：artdon_users
 * Session：ARTDON_SYS
 * 本文件保留旧 plm_auth_* 函数名，避免 PLM / 用户权限页 / 安全中心失效。
 */
require_once __DIR__.'/includes/artdon_sso_core.php';

if (!function_exists('plm_auth_val')) {
function plm_auth_val($names,$default=''){
    foreach((array)$names as $n){
        if(defined($n) && constant($n)!=='') return constant($n);
        if(isset($GLOBALS[$n]) && $GLOBALS[$n]!=='') return $GLOBALS[$n];
    }
    return $default;
}}
function plm_auth_pdo(){ return artdon_sso_db(); }
function plm_auth_table_exists($t){ return artdon_sso_table_exists((string)$t); }
function plm_auth_col_exists($t,$c){ return artdon_sso_has_col((string)$t,(string)$c); }
function plm_auth_ensure_col($t,$c,$def){
    if(plm_auth_table_exists($t) && !plm_auth_col_exists($t,$c)){
        plm_auth_pdo()->exec('ALTER TABLE `'.str_replace('`','``',(string)$t).'` ADD COLUMN `'.str_replace('`','``',(string)$c).'` '.$def);
    }
}
function plm_auth_now(){ return artdon_sso_now(); }
function plm_auth_modules(){ return artdon_sso_module_defs(); }
function plm_auth_caps(){ return array('view'=>'查看','create'=>'新增','edit'=>'修改','delete'=>'删除','export'=>'导出/下载','admin'=>'管理'); }

function plm_auth_schema(){
    $db=plm_auth_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS artdon_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(120) DEFAULT '',
        department VARCHAR(120) DEFAULT '',
        role_name VARCHAR(80) DEFAULT '',
        status VARCHAR(30) DEFAULT 'active',
        must_change_password TINYINT DEFAULT 0,
        last_login_at DATETIME NULL,
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        role VARCHAR(50) NOT NULL DEFAULT 'staff'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS artdon_user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        module_key VARCHAR(60) NOT NULL,
        can_view TINYINT DEFAULT 0,
        can_create TINYINT DEFAULT 0,
        can_edit TINYINT DEFAULT 0,
        can_delete TINYINT DEFAULT 0,
        can_export TINYINT DEFAULT 0,
        can_admin TINYINT DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_module(user_id,module_key), KEY idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS artdon_login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT 0, username VARCHAR(100) DEFAULT '', action VARCHAR(60) DEFAULT '',
        ip VARCHAR(80) DEFAULT '', user_agent VARCHAR(255) DEFAULT '', result VARCHAR(60) DEFAULT '',
        detail TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user(user_id), KEY idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS artdon_user_aliases (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        artdon_user_id INT NOT NULL,
        source_system VARCHAR(40) NOT NULL DEFAULT '',
        source_table VARCHAR(80) NOT NULL DEFAULT '',
        source_user_id VARCHAR(80) NOT NULL DEFAULT '',
        source_username VARCHAR(120) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        metadata_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id), UNIQUE KEY uq_alias_source(source_table,source_user_id),
        KEY idx_alias_user(artdon_user_id), KEY idx_alias_username(source_username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function plm_auth_client_ip(){ return artdon_sso_ip(); }
function plm_auth_log($user_id,$username,$action,$result,$detail=''){ artdon_sso_log($user_id,$username,$action,$result,$detail); }
function plm_auth_public_user($u){
    if(!is_array($u)) return null;
    unset($u['password_hash']);
    return $u;
}
function plm_auth_user_by_id($id){ return plm_auth_public_user(artdon_sso_user_by_id((int)$id)); }
function plm_auth_current_user(){ return plm_auth_public_user(artdon_sso_current_user(true)); }
function plm_auth_permissions($uid){ return artdon_sso_permissions((int)$uid); }
function plm_auth_can($module,$cap='view',$user_id=null){
    $u=$user_id?artdon_sso_user_by_id((int)$user_id):artdon_sso_current_user(true);
    return artdon_sso_can($module,$cap,$u);
}
function plm_auth_payload(){
    $u=plm_auth_current_user();
    return $u
        ? array('logged_in'=>true,'user'=>$u,'permissions'=>plm_auth_permissions((int)$u['id']),'modules'=>plm_auth_modules(),'sso'=>true)
        : array('logged_in'=>false,'user'=>null,'permissions'=>array(),'modules'=>plm_auth_modules(),'sso'=>true);
}
function plm_auth_redirect_login(){ header('Location: '.artdon_sso_login_url()); exit; }
function plm_auth_forbidden($message='没有权限访问这个模块'){
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>无权限</title><body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,Arial;background:#f6f9fd;padding:40px"><div style="max-width:680px;margin:auto;background:white;border:1px solid #dbe4ef;border-radius:20px;padding:26px;box-shadow:0 14px 36px rgba(15,23,42,.08)"><h1>没有权限</h1><p>'.htmlspecialchars((string)$message,ENT_QUOTES,'UTF-8').'</p><p><a href="artdon_portal.php">返回系统首页</a> ｜ <a href="logout.php">退出登录</a></p></div></body>';
    exit;
}
function plm_auth_require($module='plm',$cap='view'){
    plm_auth_schema();
    $u=artdon_sso_current_user(true);
    if(!$u) plm_auth_redirect_login();
    if(!artdon_sso_can($module,$cap,$u)) plm_auth_forbidden('当前账号没有「'.artdon_sso_canonical_module($module).' / '.$cap.'」权限。');
    return plm_auth_public_user($u);
}
function plm_auth_set_perm($uid,$module,$p){
    $p=array_merge(array('view'=>0,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0,'admin'=>0),is_array($p)?$p:array());
    $module=artdon_sso_canonical_module($module);
    $sql='INSERT INTO artdon_user_permissions(user_id,module_key,can_view,can_create,can_edit,can_delete,can_export,can_admin,updated_at) VALUES(?,?,?,?,?,?,?,?,?) '
        .'ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_create=VALUES(can_create),can_edit=VALUES(can_edit),can_delete=VALUES(can_delete),can_export=VALUES(can_export),can_admin=VALUES(can_admin),updated_at=VALUES(updated_at)';
    plm_auth_pdo()->prepare($sql)->execute(array((int)$uid,$module,(int)!empty($p['view']),(int)!empty($p['create']),(int)!empty($p['edit']),(int)!empty($p['delete']),(int)!empty($p['export']),(int)!empty($p['admin']),plm_auth_now()));
}
function plm_auth_template($role){
    $mods=array_keys(plm_auth_modules());
    $p=array(); foreach($mods as $m)$p[$m]=array('view'=>0,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0,'admin'=>0);
    $set=function($m,$v)use(&$p){$p[$m]=array_merge($p[$m]??array(),$v);};
    $role=strtolower(trim((string)$role));
    if(in_array($role,array('boss','admin','超级管理员','老板'),true)){
        foreach($mods as $m)$p[$m]=array('view'=>1,'create'=>1,'edit'=>1,'delete'=>1,'export'=>1,'admin'=>1);
    }elseif(in_array($role,array('engineer','工程','研发'),true)){
        $set('dashboard',array('view'=>1));
        foreach(array('plm','naming','bom','dispatch') as $m)$set($m,array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0));
    }elseif(in_array($role,array('sales','业务','销售'),true)){
        $set('dashboard',array('view'=>1));
        $set('plm',array('view'=>1,'export'=>1)); $set('naming',array('view'=>1));
        foreach(array('crm','quote','dispatch') as $m)$set($m,array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0));
    }elseif(in_array($role,array('warehouse','仓库','材料'),true)){
        $set('dashboard',array('view'=>1));
        foreach(array('bom','weight','dispatch') as $m)$set($m,array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1));
    }elseif(in_array($role,array('finance','财务'),true)){
        $set('dashboard',array('view'=>1)); $set('reconcile',array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1));
        $set('quote',array('view'=>1,'export'=>1)); $set('crm',array('view'=>1,'export'=>1));
    }else{
        $set('dashboard',array('view'=>1)); $set('plm',array('view'=>1)); $set('dispatch',array('view'=>1));
    }
    return $p;
}
function plm_auth_apply_template($uid,$role){ foreach(plm_auth_template($role) as $m=>$p) plm_auth_set_perm($uid,$m,$p); }
function plm_auth_dispatch_table_exists_safe($t){ return plm_auth_table_exists($t); }
function plm_auth_dispatch_role($role,$username=''){ return artdon_sso_role_is_admin(array('role_name'=>$role,'username'=>$username))?'boss':'staff'; }
function plm_auth_bridge_dispatch_user($u){
    $full=artdon_sso_user_by_id((int)($u['id']??0));
    if($full) return artdon_sso_dispatch_user_id($full,true);
    return 0;
}
function plm_auth_touch_one_day(){ artdon_sso_touch_cookie(); }
function plm_auth_login($username,$password){
    $r=artdon_sso_login($username,$password);
    if(!empty($r['ok'])) $r['auth']=plm_auth_payload();
    return $r;
}
function plm_auth_logout(){ artdon_sso_logout(); }

function plm_auth_action_rule($action){
    $public=array('auth_login','auth_me'); if(in_array($action,$public,true)) return null;
    if($action==='auth_logout') return array('logged'=>1);
    if(preg_match('/^user_/',(string)$action)) return array('module'=>'admin','feature'=>'manage_users','cap'=>'admin');
    $map=array(
        'admin_overview'=>array('admin','manage_security','admin'),
        'recent_activity'=>array('admin','view_audit','admin'),
        'backup_preview'=>array('dashboard','view_backup_center','admin'),
        'backup_download'=>array('dashboard','view_backup_center','admin'),
        'save_bridge_config'=>array('admin','manage_security','admin'),
        'bridge_detect'=>array('admin','manage_security','admin'),
        'bridge_summary'=>array('admin','manage_security','admin'),
        'refresh_bridge'=>array('admin','manage_security','admin'),
        'db_overview'=>array('admin','manage_security','admin'),
        'install'=>array('admin','manage_security','admin'),
        'new_project'=>array('plm','new_project','create'),
        'save_project'=>array('plm','edit_project','edit'),
        'upload_project_image'=>array('plm','edit_project','edit'),
        'delete_project'=>array('plm','delete_project','delete'),
        'save_model'=>array('plm','edit_model','edit'),
        'upload_model_image'=>array('plm','edit_model','edit'),
        'create_sample_version'=>array('plm','new_model','create'),
        'freeze_sample'=>array('plm','edit_model','edit'),
        'delete_model'=>array('plm','delete_model','delete'),
        'prepare_sample_flow'=>array('plm','edit_flow','edit'),
        'ensure_default_steps'=>array('plm','edit_flow','edit'),
        'delete_all_steps'=>array('plm','edit_flow','edit'),
        'apply_route_template'=>array('plm','edit_flow','edit'),
        'save_step'=>array('plm','edit_flow','edit'),
        'reorder_steps'=>array('plm','edit_flow','edit'),
        'delete_step'=>array('plm','edit_flow','edit'),
        'save_test'=>array('plm','edit_test','edit'),
        'delete_test'=>array('plm','delete_test','delete'),
        'upload_file'=>array('plm','upload_file','edit'),
        'update_file_meta'=>array('plm','upload_file','edit'),
        'delete_file'=>array('plm','delete_file','delete'),
        'create_doc_package'=>array('plm','export_package','export'),
        'get_doc_package'=>array('plm','export_package','export'),
        'search_docs'=>array('plm','view_project','view'),
        'review_history'=>array('plm','view_project','view'),
        'handoffs'=>array('plm','view_project','view'),
        'search_materials'=>array('plm','view_bom_cost','view'),
        'one_click_bom'=>array('plm','create_bom','create'),
        'create_bom'=>array('plm','create_bom','create'),
        'sync_bom'=>array('plm','create_bom','edit'),
        'one_click_quote'=>array('quote',null,'create'),
        'create_quote'=>array('quote',null,'create'),
        'sync_quote'=>array('quote',null,'edit')
    );
    if(isset($map[$action])) return array('module'=>$map[$action][0],'feature'=>$map[$action][1],'cap'=>$map[$action][2]);
    return array('module'=>'plm','feature'=>'view_project','cap'=>'view');
}
function plm_auth_api_fail($msg,$extra=array()){
    $arr=array_merge(array('ok'=>false,'error'=>$msg,'msg'=>$msg),$extra);
    if(function_exists('plm_json')) plm_json($arr);
    artdon_sso_json_error($msg,!empty($extra['login_required'])?401:403,$extra);
}
function plm_auth_require_api_action($action){
    $rule=plm_auth_action_rule($action); if($rule===null)return;
    $u=artdon_sso_current_user(true);
    if(!$u) plm_auth_api_fail('请先登录系统',array('login_required'=>true,'need_login'=>true));
    if(!empty($rule['logged']))return;
    $ok=false;
    if(!empty($rule['feature']) && function_exists('artdon_sso_can_feature')) $ok=artdon_sso_can_feature($rule['module'],$rule['feature'],$u);
    else $ok=artdon_sso_can($rule['module'],$rule['cap'],$u);
    if(!$ok) plm_auth_api_fail('没有权限执行：'.$action.'（需要 '.$rule['module'].'/'.($rule['feature']??$rule['cap']).'）',array('forbidden'=>true));
}
function plm_auth_handle_api($action){
    if($action==='auth_login'){
        $d=function_exists('plm_input')?plm_input():$_POST;
        $r=plm_auth_login($d['username']??'',$d['password']??'');
        if(function_exists('plm_json')) plm_json($r); return true;
    }
    if($action==='auth_logout'){ plm_auth_logout(); if(function_exists('plm_json'))plm_json(array('ok'=>true)); return true; }
    if($action==='auth_me'){ if(function_exists('plm_json'))plm_json(array('ok'=>true,'auth'=>plm_auth_payload())); return true; }
    if(strpos((string)$action,'user_')===0){ plm_auth_require_api_action($action); plm_auth_users_api($action); return true; }
    return false;
}
function plm_auth_users_api($action){
    $db=plm_auth_pdo(); $d=function_exists('plm_input')?plm_input():$_POST;
    if($action==='user_list'){
        $users=$db->query('SELECT id,username,display_name,department,role_name,status,must_change_password,last_login_at,note,created_at,updated_at,role FROM artdon_users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        foreach($users as &$u){ $u['permissions']=plm_auth_permissions((int)$u['id']); }
        if(function_exists('plm_json'))plm_json(array('ok'=>true,'users'=>$users,'modules'=>plm_auth_modules(),'caps'=>plm_auth_caps(),'templates'=>array('boss'=>'老板/管理员：全部权限','engineer'=>'工程：PLM+命名+BOM+派工','sales'=>'业务：PLM查看+CRM+报价+派工','warehouse'=>'仓库：BOM+重量+派工','finance'=>'财务：对账+报价查看','readonly'=>'只读：系统首页+PLM+派工')));
    }
    if($action==='user_save'){
        $id=(int)($d['id']??0); $username=trim((string)($d['username']??''));
        if($username===''){ if(function_exists('plm_json'))plm_json(array('ok'=>false,'error'=>'账号不能为空')); }
        $display=trim((string)($d['display_name']??'')); $dept=trim((string)($d['department']??'')); $roleName=trim((string)($d['role_name']??''));
        $status=(($d['status']??'active')==='disabled')?'disabled':'active'; $note=trim((string)($d['note']??'')); $password=(string)($d['password']??'');
        $role=plm_auth_dispatch_role($roleName,$username)==='boss'?'boss':(stripos($roleName,'业务')!==false?'sales':(stripos($roleName,'工程')!==false?'engineer':'staff'));
        try{
            $dup=$db->prepare('SELECT id FROM artdon_users WHERE LOWER(TRIM(username))=LOWER(TRIM(?)) AND id<>? LIMIT 1'); $dup->execute(array($username,$id));
            if($dup->fetchColumn()) throw new RuntimeException('账号已存在，请换一个账号名');
            if($id>0){
                $sql='UPDATE artdon_users SET username=?,display_name=?,department=?,role_name=?,role=?,status=?,note=?,updated_at=?';
                $vals=array($username,$display,$dept,$roleName,$role,$status,$note,plm_auth_now());
                if($password!==''){ $sql.=',password_hash=?,must_change_password=1'; $vals[]=password_hash($password,PASSWORD_DEFAULT); }
                $sql.=' WHERE id=?'; $vals[]=$id; $db->prepare($sql)->execute($vals);
            }else{
                if($password==='')$password='123456';
                $db->prepare('INSERT INTO artdon_users(username,password_hash,display_name,department,role_name,role,status,must_change_password,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(array($username,password_hash($password,PASSWORD_DEFAULT),$display,$dept,$roleName,$role,$status,1,$note,plm_auth_now(),plm_auth_now()));
                $id=(int)$db->lastInsertId();
            }
        }catch(Throwable $e){ if(function_exists('plm_json'))plm_json(array('ok'=>false,'error'=>'保存用户失败：'.$e->getMessage())); }
        $perms=$d['permissions']??null;
        if(is_array($perms)){ foreach(plm_auth_modules() as $m=>$info)plm_auth_set_perm($id,$m,$perms[$m]??array()); }
        elseif(!empty($d['template']))plm_auth_apply_template($id,(string)$d['template']);
        artdon_sso_sync_account_by_id($id);
        if(function_exists('plm_audit'))@plm_audit('保存用户权限','user',$id,0,$username.' / '.$roleName);
        if(function_exists('plm_json'))plm_json(array('ok'=>true,'id'=>$id));
    }
    if($action==='user_apply_template'){
        $id=(int)($d['id']??0); if(!$id){if(function_exists('plm_json'))plm_json(array('ok'=>false,'error'=>'缺少用户ID'));}
        plm_auth_apply_template($id,(string)($d['template']??'readonly')); artdon_sso_sync_account_by_id($id);
        if(function_exists('plm_json'))plm_json(array('ok'=>true));
    }
    if($action==='user_set_status' || $action==='user_delete'){
        $id=(int)($d['id']??0); $status=$action==='user_delete'?'disabled':((($d['status']??'active')==='disabled')?'disabled':'active');
        if($id===(int)($_SESSION['artdon_user_id']??0) && $status==='disabled'){if(function_exists('plm_json'))plm_json(array('ok'=>false,'error'=>'不能停用当前登录账号'));}
        $db->prepare('UPDATE artdon_users SET status=?,updated_at=? WHERE id=?')->execute(array($status,plm_auth_now(),$id));
        artdon_sso_sync_account_by_id($id);
        if(function_exists('plm_json'))plm_json(array('ok'=>true));
    }
    if(function_exists('plm_json'))plm_json(array('ok'=>false,'error'=>'未知用户操作'));
}
?>
