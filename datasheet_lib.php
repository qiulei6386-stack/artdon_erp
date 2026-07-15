<?php
/**
 * Artdon Datasheet Center V2.0 common library
 * 产品来源：命名系统 naming_models（包含官网同步型号），分类只按命名系统 category 大类。
 */
declare(strict_types=1);
@ini_set('display_errors','0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/includes/artdon_sso_core.php';

const DS_VERSION = '2.1.7';
const DS_MODULE = 'datasheet';
const DS_UPLOAD_ROOT = 'uploads/datasheets';
const DS_WEBSITE_BASE_DEFAULT = 'https://artdonlighting.com';

function ds_s($v, int $max=0): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0) {
        if (function_exists('mb_substr')) $v = mb_substr($v,0,$max,'UTF-8');
        else $v = substr($v,0,$max);
    }
    return $v;
}
function ds_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ds_json($data=null, bool $ok=true, string $msg=''): void {
    @ini_set('display_errors','0');
    while (ob_get_level()>0) @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>$ok,'msg'=>$msg,'data'=>$data), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function ds_fail(string $msg, int $code=200): void { if($code!==200) http_response_code($code); ds_json(null,false,$msg); }
function ds_now(): string { return date('Y-m-d H:i:s'); }

function ds_require_config(): void {
    $sso = __DIR__.'/includes/artdon_sso_core.php';
    if (is_file($sso)) { try { include_once $sso; } catch(Throwable $e){} }
}
function ds_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    ds_require_config();
    foreach(array('crm_plm_pdo','db','get_pdo') as $fn){
        if(function_exists($fn)){
            try { $x=$fn(); if($x instanceof PDO){ $pdo=$x; break; } } catch(Throwable $e){}
        }
    }
    if(!$pdo){
        $host = defined('DB_HOST') ? DB_HOST : (defined('MYSQL_HOST') ? MYSQL_HOST : '127.0.0.1');
        $name = defined('DB_NAME') ? DB_NAME : (defined('MYSQL_DB') ? MYSQL_DB : '');
        $user = defined('DB_USER') ? DB_USER : (defined('MYSQL_USER') ? MYSQL_USER : '');
        $pass = defined('DB_PASS') ? DB_PASS : (defined('MYSQL_PASS') ? MYSQL_PASS : '');
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        if($name==='' || $user==='') throw new RuntimeException('数据库配置不完整：请确认 includes/config.php。');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4'));
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
function ds_table_exists(string $table): bool { $st=ds_db()->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute(array($table)); return (int)$st->fetchColumn()>0; }
function ds_col_exists(string $table,string $col): bool { $st=ds_db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"); $st->execute(array($table,$col)); return (int)$st->fetchColumn()>0; }
function ds_cols(string $table): array { if(!ds_table_exists($table)) return array(); $st=ds_db()->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute(array($table)); return array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)?:array()); }
function ds_add_col(string $table,string $col,string $ddl): void { try{ if(!ds_col_exists($table,$col)) ds_db()->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}"); }catch(Throwable $e){} }
function ds_add_index(string $table,string $idx,string $ddl): void { try{ $st=ds_db()->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?"); $st->execute(array($table,$idx)); if((int)$st->fetchColumn()===0) ds_db()->exec("ALTER TABLE `{$table}` ADD {$ddl}"); }catch(Throwable $e){} }
function ds_pick_col(string $table, array $candidates): string { $cols=ds_cols($table); foreach($candidates as $c){ if(in_array($c,$cols,true)) return $c; } return ''; }
function ds_qid(string $s): string { return '`'.str_replace('`','``',$s).'`'; }

function ds_current_user(bool $sync=true): ?array {
    ds_require_config();
    if(function_exists('plm_auth_current_user')){ try{ $u=plm_auth_current_user(); if(is_array($u)&&$u) return $u; }catch(Throwable $e){} }
    if(function_exists('artdon_sso_current_user')){ try{ $u=artdon_sso_current_user($sync); if(is_array($u)&&$u) return $u; }catch(Throwable $e){} }
    $out=array('id'=>0,'username'=>'','display_name'=>'','role_name'=>'','department'=>'');
    foreach($_SESSION as $k=>$v){
        if(is_array($v)){
            foreach(array('id','user_id','uid') as $kk) if(!empty($v[$kk])&&!$out['id']) $out['id']=(int)$v[$kk];
            foreach(array('username','account','user_name','login_name','email') as $kk) if(!empty($v[$kk])&&$out['username']==='') $out['username']=ds_s($v[$kk],120);
            foreach(array('display_name','real_name','name','nickname') as $kk) if(!empty($v[$kk])&&$out['display_name']==='') $out['display_name']=ds_s($v[$kk],120);
            foreach(array('role_name','role') as $kk) if(!empty($v[$kk])&&$out['role_name']==='') $out['role_name']=ds_s($v[$kk],120);
            foreach(array('department','dept') as $kk) if(!empty($v[$kk])&&$out['department']==='') $out['department']=ds_s($v[$kk],120);
        } elseif(is_scalar($v)) {
            if($out['username']==='' && preg_match('/(username|account|login_name|email)$/i',(string)$k)) $out['username']=ds_s($v,120);
        }
    }
    foreach(array('artdon_username','plm_username','crm_username','username','user_name','account') as $ck){ if($out['username']==='' && !empty($_COOKIE[$ck])) $out['username']=ds_s($_COOKIE[$ck],120); }
    if($out['display_name']==='') $out['display_name']=$out['username'];
    return $out['username']!=='' ? $out : null;
}
function ds_user_id(?array $u=null): int { $u=$u ?: (ds_current_user(false)?:array()); return (int)($u['id']??0); }
function ds_username(?array $u=null): string { $u=$u ?: (ds_current_user(false)?:array()); return ds_s($u['display_name']??($u['username']??'未识别账号'),120); }
function ds_is_admin($u=null): bool {
    $u=is_array($u)?$u:(ds_current_user(false)?:array());
    if(function_exists('artdon_sso_role_is_admin')){ try{ if(artdon_sso_role_is_admin($u)) return true; }catch(Throwable $e){} }
    if(function_exists('plm_auth_can')){ try{ if(!empty($u['id']) && plm_auth_can(DS_MODULE,'admin',(int)$u['id'])) return true; }catch(Throwable $e){} }
    $raw=strtolower(trim((string)($u['username']??'').' '.(string)($u['display_name']??'').' '.(string)($u['role_name']??'').' '.(string)($u['role']??'').' '.(string)($u['department']??'')));
    return in_array(strtolower((string)($u['username']??'')),array('qiulei','boss','admin'),true)||strpos($raw,'boss')!==false||strpos($raw,'admin')!==false||strpos($raw,'owner')!==false||strpos($raw,'老板')!==false||strpos($raw,'管理员')!==false||strpos($raw,'超级')!==false;
}
function ds_require_login(bool $api=true): array {
    $u=ds_current_user(true);
    if(!$u){ if($api) ds_fail('登录已失效，请重新登录',401); header('Location: login.php?redirect='.rawurlencode('datasheet.php')); exit; }
    return $u;
}

function ds_perm_features(): array {
    // V2.1.6：按统一权限中心拆细，前端按钮和 API 同时校验；不是只在页面隐藏。
    return array(
        'view_datasheet'=>array('label'=>'进入资料生成系统','cap'=>'view','group'=>'入口','risk'=>'normal'),
        'view_products'=>array('label'=>'查看产品/搜索产品','cap'=>'view','group'=>'入口','risk'=>'normal'),
        'edit_params'=>array('label'=>'编辑详细参数/产品说明','cap'=>'edit','group'=>'产品资料','risk'=>'normal'),
        'sync_website'=>array('label'=>'拉取当前官网资料缓存','cap'=>'edit','group'=>'产品资料','risk'=>'normal'),

        'upload_file'=>array('label'=>'上传当前产品资料文件','cap'=>'create','group'=>'上传资料','risk'=>'normal'),
        'upload_library_hd'=>array('label'=>'上传公共高清图库','cap'=>'create','group'=>'公共图库','risk'=>'normal'),
        'upload_library_accessory'=>array('label'=>'上传公共配件图库','cap'=>'create','group'=>'公共图库','risk'=>'normal'),
        'upload_library_curve'=>array('label'=>'上传公共配光曲线库','cap'=>'create','group'=>'公共图库','risk'=>'normal'),
        'pair_hd'=>array('label'=>'高清图配对到产品','cap'=>'edit','group'=>'资料配对','risk'=>'normal'),
        'pair_accessory'=>array('label'=>'配件图配对到产品','cap'=>'edit','group'=>'资料配对','risk'=>'normal'),
        'pair_curve'=>array('label'=>'配光曲线配对到产品','cap'=>'edit','group'=>'资料配对','risk'=>'normal'),
        'delete_file'=>array('label'=>'删除上传资料/公共图库资料','cap'=>'delete','group'=>'上传资料','risk'=>'high'),

        'generate_pdf'=>array('label'=>'预览/生成 PDF','cap'=>'export','group'=>'生成输出','risk'=>'normal'),
        'generate_excel'=>array('label'=>'生成 Excel','cap'=>'export','group'=>'生成输出','risk'=>'normal'),
        'generate_zip'=>array('label'=>'生成资料包 ZIP','cap'=>'export','group'=>'生成输出','risk'=>'normal'),
        'download'=>array('label'=>'下载资料文件','cap'=>'download','group'=>'生成输出','risk'=>'normal'),
        'batch_generate'=>array('label'=>'批量生成 PDF/资料包','cap'=>'export','group'=>'生成输出','risk'=>'normal'),
        'record_send'=>array('label'=>'记录客户资料发送/保存自己快照','cap'=>'create','group'=>'快照客户','risk'=>'normal'),
        'view_snapshots'=>array('label'=>'查看自己保存的快照','cap'=>'view','group'=>'快照客户','risk'=>'normal'),
        'view_all_snapshots'=>array('label'=>'查看全部人员快照','cap'=>'admin','group'=>'快照客户','risk'=>'high'),

        'manage_accessory'=>array('label'=>'管理旧配件/配置兼容入口','cap'=>'edit','group'=>'兼容功能','risk'=>'high'),
        'select_material'=>array('label'=>'从 BOM 物料库选择配置','cap'=>'edit','group'=>'兼容功能','risk'=>'normal'),
        'view_online'=>array('label'=>'查看在线人员中心','cap'=>'admin','group'=>'系统管理','risk'=>'high'),
        'view_logs'=>array('label'=>'查看资料日志','cap'=>'admin','group'=>'系统管理','risk'=>'high'),
        'manage_settings'=>array('label'=>'管理资料系统设置/界面/水印','cap'=>'admin','group'=>'系统管理','risk'=>'high'),
        'manage_permissions'=>array('label'=>'管理资料系统权限','cap'=>'admin','group'=>'系统管理','risk'=>'critical')
    );
}
function ds_perm_ensure_schema(): void {
    if(function_exists('artdon_datasheet_ensure_permissions')){
        artdon_datasheet_ensure_permissions();
    }
}
function ds_perm_template(array $u): array {
    $caps=array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0);
    if(ds_is_admin($u)) $caps=array('view'=>1,'create'=>1,'edit'=>1,'delete'=>1,'export'=>1,'admin'=>1);
    $features=array();
    foreach(ds_perm_features() as $k=>$d){ $cap=(string)($d['cap']??'view'); $features[$k]=!empty($caps[$cap])?1:0; }
    return array('caps'=>$caps,'features'=>$features);
}
function ds_perm_upsert_user(array $u, bool $force=false): void {
    ds_perm_ensure_schema();
}
function ds_perm_bootstrap(): void {
    static $done=false; if($done) return; $done=true; ds_perm_ensure_schema();
}
function ds_can(string $feature, ?array $u=null): bool {
    $u=$u ?: (ds_current_user(false)?:array());
    if(!$u) return false;
    if($feature==='view') $feature='view_datasheet';
    ds_perm_ensure_schema();
    if(function_exists('artdon_sso_can_feature')){
        try{ return artdon_sso_can_feature(DS_MODULE,$feature,$u); }catch(Throwable $e){}
    }
    return ds_is_admin($u);
}
function ds_require_perm(string $feature, string $label=''): void { $u=ds_require_login(true); ds_perm_bootstrap(); if(!ds_can($feature,$u)) ds_fail('当前账号没有权限：'.($label ?: (ds_perm_features()[$feature]['label']??$feature)),403); }
function ds_perm_runtime(?array $u=null): array { $u=$u ?: (ds_current_user(false)?:array()); $out=array('is_admin'=>ds_is_admin($u)?1:0,'user'=>ds_username($u),'user_id'=>ds_user_id($u)); foreach(array_keys(ds_perm_features()) as $f) $out[$f]=ds_can($f,$u)?1:0; return $out; }

function ds_ensure_tables(): void {
    $db=ds_db();
    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_files(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      file_type VARCHAR(80) NOT NULL DEFAULT '其它',
      file_title VARCHAR(255) NOT NULL DEFAULT '',
      original_name VARCHAR(255) NOT NULL DEFAULT '',
      file_path VARCHAR(800) NOT NULL DEFAULT '',
      mime_type VARCHAR(120) NOT NULL DEFAULT '',
      size_bytes BIGINT NOT NULL DEFAULT 0,
      visibility VARCHAR(40) NOT NULL DEFAULT 'customer',
      customer_name VARCHAR(255) NOT NULL DEFAULT '',
      note TEXT NULL,
      uploaded_by VARCHAR(120) NOT NULL DEFAULT '',
      uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      is_deleted TINYINT(1) NOT NULL DEFAULT 0,
      KEY idx_model(model_no), KEY idx_type(file_type), KEY idx_uploaded(uploaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array(
      'naming_model_id'=>"`naming_model_id` INT NOT NULL DEFAULT 0",'model_no'=>"`model_no` VARCHAR(120) NOT NULL DEFAULT ''",'file_type'=>"`file_type` VARCHAR(80) NOT NULL DEFAULT '其它'",'file_title'=>"`file_title` VARCHAR(255) NOT NULL DEFAULT ''",'original_name'=>"`original_name` VARCHAR(255) NOT NULL DEFAULT ''",'file_path'=>"`file_path` VARCHAR(800) NOT NULL DEFAULT ''",'mime_type'=>"`mime_type` VARCHAR(120) NOT NULL DEFAULT ''",'size_bytes'=>"`size_bytes` BIGINT NOT NULL DEFAULT 0",'visibility'=>"`visibility` VARCHAR(40) NOT NULL DEFAULT 'customer'",'customer_name'=>"`customer_name` VARCHAR(255) NOT NULL DEFAULT ''",'note'=>"`note` TEXT NULL",'uploaded_by'=>"`uploaded_by` VARCHAR(120) NOT NULL DEFAULT ''",'uploaded_at'=>"`uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'is_deleted'=>"`is_deleted` TINYINT(1) NOT NULL DEFAULT 0") as $c=>$ddl) ds_add_col('datasheet_files',$c,$ddl);

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_overrides(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      title VARCHAR(255) NOT NULL DEFAULT '',
      intro TEXT NULL,
      power VARCHAR(120) NOT NULL DEFAULT '',
      luminous_flux VARCHAR(120) NOT NULL DEFAULT '',
      efficacy VARCHAR(120) NOT NULL DEFAULT '',
      voltage VARCHAR(120) NOT NULL DEFAULT '',
      cct VARCHAR(120) NOT NULL DEFAULT '',
      cri VARCHAR(120) NOT NULL DEFAULT '',
      beam_angle VARCHAR(120) NOT NULL DEFAULT '',
      ip_rating VARCHAR(80) NOT NULL DEFAULT '',
      finish VARCHAR(160) NOT NULL DEFAULT '',
      mounting VARCHAR(160) NOT NULL DEFAULT '',
      dimming VARCHAR(160) NOT NULL DEFAULT '',
      material VARCHAR(160) NOT NULL DEFAULT '',
      remark TEXT NULL,
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_model(model_no), KEY idx_naming(naming_model_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array('naming_model_id'=>"`naming_model_id` INT NOT NULL DEFAULT 0",'model_no'=>"`model_no` VARCHAR(120) NOT NULL DEFAULT ''",'title'=>"`title` VARCHAR(255) NOT NULL DEFAULT ''",'intro'=>"`intro` TEXT NULL",'product_family'=>"`product_family` VARCHAR(255) NOT NULL DEFAULT ''",'model_display'=>"`model_display` VARCHAR(160) NOT NULL DEFAULT ''",'dimensions'=>"`dimensions` VARCHAR(255) NOT NULL DEFAULT ''",'cutout'=>"`cutout` VARCHAR(120) NOT NULL DEFAULT ''",'power'=>"`power` VARCHAR(120) NOT NULL DEFAULT ''",'luminous_flux'=>"`luminous_flux` VARCHAR(120) NOT NULL DEFAULT ''",'efficacy'=>"`efficacy` VARCHAR(120) NOT NULL DEFAULT ''",'voltage'=>"`voltage` VARCHAR(120) NOT NULL DEFAULT ''",'cct'=>"`cct` VARCHAR(120) NOT NULL DEFAULT ''",'cri'=>"`cri` VARCHAR(120) NOT NULL DEFAULT ''",'beam_angle'=>"`beam_angle` VARCHAR(120) NOT NULL DEFAULT ''",'ip_rating'=>"`ip_rating` VARCHAR(80) NOT NULL DEFAULT ''",'finish'=>"`finish` VARCHAR(160) NOT NULL DEFAULT ''",'mounting'=>"`mounting` VARCHAR(160) NOT NULL DEFAULT ''",'dimming'=>"`dimming` VARCHAR(160) NOT NULL DEFAULT ''",'material'=>"`material` VARCHAR(160) NOT NULL DEFAULT ''",'remark'=>"`remark` TEXT NULL",'updated_by'=>"`updated_by` VARCHAR(120) NOT NULL DEFAULT ''",'updated_at'=>"`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") as $c=>$ddl) ds_add_col('datasheet_overrides',$c,$ddl);

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_accessories(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      accessory_type VARCHAR(80) NOT NULL DEFAULT '配件',
      category VARCHAR(120) NOT NULL DEFAULT '',
      brand VARCHAR(120) NOT NULL DEFAULT '',
      name_cn VARCHAR(255) NOT NULL DEFAULT '',
      name_en VARCHAR(255) NOT NULL DEFAULT '',
      model_no VARCHAR(160) NOT NULL DEFAULT '',
      spec TEXT NULL,
      description TEXT NULL,
      image_path VARCHAR(800) NOT NULL DEFAULT '',
      source VARCHAR(40) NOT NULL DEFAULT 'manual',
      bom_material_id BIGINT NOT NULL DEFAULT 0,
      visibility VARCHAR(40) NOT NULL DEFAULT 'customer',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_by VARCHAR(120) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_type(accessory_type), KEY idx_active(is_active), KEY idx_bom(bom_material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_product_accessories(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      accessory_id BIGINT NOT NULL DEFAULT 0,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      note VARCHAR(500) NOT NULL DEFAULT '',
      created_by VARCHAR(120) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_product_accessory(model_no,accessory_id), KEY idx_model(model_no), KEY idx_accessory(accessory_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_product_configs(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      config_type VARCHAR(80) NOT NULL DEFAULT '',
      material_id BIGINT NOT NULL DEFAULT 0,
      material_json MEDIUMTEXT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      note VARCHAR(500) NOT NULL DEFAULT '',
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_model_type(model_no,config_type), KEY idx_material(material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_records(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      record_type VARCHAR(80) NOT NULL DEFAULT '',
      file_title VARCHAR(255) NOT NULL DEFAULT '',
      file_path VARCHAR(800) NOT NULL DEFAULT '',
      file_format VARCHAR(40) NOT NULL DEFAULT '',
      customer_name VARCHAR(255) NOT NULL DEFAULT '',
      customer_email VARCHAR(255) NOT NULL DEFAULT '',
      note TEXT NULL,
      created_by VARCHAR(120) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_model(model_no), KEY idx_type(record_type), KEY idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array('naming_model_id'=>"`naming_model_id` INT NOT NULL DEFAULT 0",'model_no'=>"`model_no` VARCHAR(120) NOT NULL DEFAULT ''",'record_type'=>"`record_type` VARCHAR(80) NOT NULL DEFAULT ''",'file_title'=>"`file_title` VARCHAR(255) NOT NULL DEFAULT ''",'file_path'=>"`file_path` VARCHAR(800) NOT NULL DEFAULT ''",'file_format'=>"`file_format` VARCHAR(40) NOT NULL DEFAULT ''",'customer_name'=>"`customer_name` VARCHAR(255) NOT NULL DEFAULT ''",'customer_email'=>"`customer_email` VARCHAR(255) NOT NULL DEFAULT ''",'note'=>"`note` TEXT NULL",'created_by'=>"`created_by` VARCHAR(120) NOT NULL DEFAULT ''",'created_at'=>"`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP") as $c=>$ddl) ds_add_col('datasheet_records',$c,$ddl);
    ds_add_col('datasheet_records','snapshot_id',"`snapshot_id` BIGINT NOT NULL DEFAULT 0");
    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_snapshots(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      snapshot_title VARCHAR(255) NOT NULL DEFAULT '',
      customer_name VARCHAR(255) NOT NULL DEFAULT '',
      customer_email VARCHAR(255) NOT NULL DEFAULT '',
      send_note TEXT NULL,
      snapshot_json LONGTEXT NULL,
      created_by VARCHAR(120) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_model(model_no), KEY idx_customer(customer_name), KEY idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_logs(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      action VARCHAR(80) NOT NULL DEFAULT '',
      action_label VARCHAR(255) NOT NULL DEFAULT '',
      result VARCHAR(40) NOT NULL DEFAULT 'success',
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(120) NOT NULL DEFAULT '',
      username VARCHAR(120) NOT NULL DEFAULT '',
      display_name VARCHAR(120) NOT NULL DEFAULT '',
      target_type VARCHAR(80) NOT NULL DEFAULT '',
      target_id VARCHAR(120) NOT NULL DEFAULT '',
      target_title VARCHAR(255) NOT NULL DEFAULT '',
      detail_json MEDIUMTEXT NULL,
      ip VARCHAR(80) NOT NULL DEFAULT '',
      user_agent VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_model(model_no), KEY idx_action(action), KEY idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array('model_no'=>"`model_no` VARCHAR(120) NOT NULL DEFAULT ''",'naming_model_id'=>"`naming_model_id` INT NOT NULL DEFAULT 0") as $c=>$ddl) ds_add_col('datasheet_logs',$c,$ddl);
    foreach(array(
      'module_name'=>"`module_name` VARCHAR(120) NOT NULL DEFAULT '资料生成系统'",
      'section_name'=>"`section_name` VARCHAR(120) NOT NULL DEFAULT ''",
      'source_type'=>"`source_type` VARCHAR(80) NOT NULL DEFAULT ''",
      'source_url'=>"`source_url` VARCHAR(1000) NOT NULL DEFAULT ''",
      'file_type'=>"`file_type` VARCHAR(120) NOT NULL DEFAULT ''",
      'quantity'=>"`quantity` INT NOT NULL DEFAULT 0"
    ) as $c=>$ddl) ds_add_col('datasheet_logs',$c,$ddl);

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_online(
      user_id INT NOT NULL DEFAULT 0,
      username VARCHAR(120) NOT NULL DEFAULT '',
      display_name VARCHAR(120) NOT NULL DEFAULT '',
      ip VARCHAR(80) NOT NULL DEFAULT '',
      department VARCHAR(120) NOT NULL DEFAULT '',
      role_name VARCHAR(120) NOT NULL DEFAULT '',
      module_name VARCHAR(120) NOT NULL DEFAULT '资料生成系统',
      page_title VARCHAR(180) NOT NULL DEFAULT '',
      last_seen DATETIME NOT NULL,
      PRIMARY KEY(user_id,username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach(array('department'=>"`department` VARCHAR(120) NOT NULL DEFAULT ''",'role_name'=>"`role_name` VARCHAR(120) NOT NULL DEFAULT ''",'module_name'=>"`module_name` VARCHAR(120) NOT NULL DEFAULT '资料生成系统'",'page_title'=>"`page_title` VARCHAR(180) NOT NULL DEFAULT ''") as $c=>$ddl) ds_add_col('datasheet_online',$c,$ddl);

    $db->exec("CREATE TABLE IF NOT EXISTS datasheet_settings(
      k VARCHAR(120) NOT NULL PRIMARY KEY,
      v MEDIUMTEXT NULL,
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function ds_init(): void { ds_require_login(true); ds_perm_bootstrap(); ds_ensure_tables(); ds_require_perm('view_datasheet','进入资料生成系统'); ds_touch_online(ds_current_user(false)?:array()); }

function ds_ip(): string { foreach(array('HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR') as $k){ if(!empty($_SERVER[$k])) return substr(trim(explode(',',(string)$_SERVER[$k])[0]),0,80); } return ''; }
function ds_log(string $action,string $label,string $result='success',string $modelNo='',int $modelId=0,string $targetType='',string $targetId='',string $targetTitle='',array $detail=array(),?array $u=null): void {
    try{
        $u=$u ?: (ds_current_user(false)?:array());
        $module=ds_s($detail['module'] ?? $detail['module_name'] ?? '资料生成系统',120);
        $section=ds_s($detail['section'] ?? $detail['section_name'] ?? ($_GET['section'] ?? ''),120);
        $sourceType=ds_s($detail['source_type'] ?? '',80);
        $sourceUrl=ds_s($detail['source_url'] ?? $detail['url'] ?? '',1000);
        $fileType=ds_s($detail['file_type'] ?? $detail['type'] ?? '',120);
        $qty=(int)($detail['quantity'] ?? $detail['count'] ?? 0);
        $json=$detail?json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
        $st=ds_db()->prepare("INSERT INTO datasheet_logs(action,action_label,result,naming_model_id,model_no,username,display_name,target_type,target_id,target_title,detail_json,ip,user_agent,module_name,section_name,source_type,source_url,file_type,quantity) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute(array($action,$label,$result,$modelId,$modelNo,ds_s($u['username']??'',120),ds_username($u),$targetType,$targetId,$targetTitle,$json,ds_ip(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),$module,$section,$sourceType,$sourceUrl,$fileType,$qty));
        if(function_exists('audit_log')){
            audit_log($action, 'datasheet', $targetType, $targetId !== '' ? $targetId : null, null, array(
                'label'=>$label,
                'result'=>$result,
                'naming_model_id'=>$modelId,
                'model_no'=>$modelNo,
                'target_title'=>$targetTitle,
                'detail'=>$detail,
            ), $u ?: null);
        }
    }catch(Throwable $e){}
}
function ds_log_product_use(string $section,array $product,array $extra=array()): void {
    $model=ds_s($product['model_no'] ?? ''); $id=(int)($product['id'] ?? 0);
    if($model==='') return;
    ds_log('product.use','使用产品 / 打开版块','success',$model,$id,'product',(string)$id,$model,array_merge(array('section'=>$section,'module'=>'资料生成系统','source_type'=>ds_s($product['source_label']??''),'product_title'=>ds_s($product['title']??'')), $extra));
}
function ds_website_pdf_url_for_row(array $row): string {
    $slug=ds_slug_from_row($row); $source=ds_source_url($row); $sourceId=ds_s($row['source_id']??'');
    if($slug===''){
        $u=ds_s($source); if($u!==''){ $p=@parse_url($u); if(is_array($p) && !empty($p['query'])){ parse_str((string)$p['query'],$q); if(!empty($q['slug'])) $slug=ds_s($q['slug']); if(!empty($q['id']) && $sourceId==='') $sourceId=ds_s($q['id']); } }
    }
    if($slug!=='') return ds_website_base().'/product_pdf.php?slug='.rawurlencode($slug).'&logo=0';
    if($sourceId!=='' && ctype_digit($sourceId)) return ds_website_base().'/product_pdf.php?id='.(int)$sourceId.'&logo=0';
    return '';
}
function ds_touch_online(array $u): void {
    try{
        $dept=ds_s($u['department']??$u['dept']??'',120);
        $role=ds_s($u['role_name']??$u['role']??$u['user_role']??'',120);
        $page=ds_s($_GET['action']??$_POST['action']??'进入资料系统',180);
        ds_db()->prepare("REPLACE INTO datasheet_online(user_id,username,display_name,ip,department,role_name,module_name,page_title,last_seen) VALUES(?,?,?,?,?,?,?,?,NOW())")
            ->execute(array(ds_user_id($u),ds_s($u['username']??'',120),ds_username($u),ds_ip(),$dept,$role,'资料生成系统',$page));
    }catch(Throwable $e){}
}
function ds_online(): array {
    try{
        ds_db()->exec("DELETE FROM datasheet_online WHERE last_seen < DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
        return ds_db()->query("SELECT user_id,username,display_name,ip,department,role_name,module_name,page_title,last_seen,TIMESTAMPDIFF(MINUTE,last_seen,NOW()) AS idle_minutes FROM datasheet_online ORDER BY last_seen DESC LIMIT 100")->fetchAll()?:array();
    }catch(Throwable $e){ return array(); }
}

function ds_current_origin(): string { $host=(string)($_SERVER['HTTP_HOST']??''); if($host==='') return ''; $proto='http'; if((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https') $proto='https'; return $proto.'://'.$host; }
function ds_app_base_path(): string {
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??($_SERVER['PHP_SELF']??'')));
    if($script!=='' && strpos($script,'/')===0){
        $dir=rtrim(str_replace('\\','/',dirname($script)),'/');
        return $dir==='/' ? '' : $dir;
    }
    return basename(__DIR__)==='artdon_erp' ? '/artdon_erp' : '';
}
function ds_website_base(): string { if(defined('ARTDON_WEBSITE_IMAGE_BASE') && ds_s(ARTDON_WEBSITE_IMAGE_BASE)!=='') return rtrim((string)ARTDON_WEBSITE_IMAGE_BASE,'/'); $env=getenv('ARTDON_WEBSITE_IMAGE_BASE'); if($env!==false && ds_s($env)!=='') return rtrim((string)$env,'/'); return DS_WEBSITE_BASE_DEFAULT; }
function ds_encode_path(string $path): string { $path=str_replace('\\','/',$path); $parts=explode('/',$path); $out=array(); foreach($parts as $p){ if($p===''){ $out[]=''; continue; } $out[]=rawurlencode(rawurldecode($p)); } return str_replace('%2F','/',implode('/',$out)); }
function ds_with_base(string $base,string $path,string $query=''): string { $base=rtrim($base,'/'); $path=str_replace('\\','/',$path); if($path==='' || $path[0] !== '/') $path='/'.ltrim($path,'/'); return $base.ds_encode_path($path).$query; }
function ds_asset_url(string $path): string {
    $raw=trim((string)$path); if($raw==='') return ''; $raw=html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'); $raw=str_replace(array('\\',"\0"),array('/',''),$raw);
    if(preg_match('/^(data:|blob:)/i',$raw)) return $raw;
    $website=ds_website_base(); $local=ds_current_origin();
    if(preg_match('#^https?://#i',$raw)){
        $u=@parse_url($raw); if(!$u) return $raw; $p=(string)($u['path']??''); $q=isset($u['query'])&&$u['query']!==''?'?'.$u['query']:''; $host=strtolower((string)($u['host']??''));
        if(strpos($p,'/uploads/website/')===0 || strpos($p,'/uploads/products/')===0) return ds_with_base($website,$p,$q);
        if(strpos($p,'/uploads/naming/')===0) return ds_app_base_path().ds_encode_path($p).$q;
        if(in_array($host,array('43.132.210.162','gallin.cn','www.gallin.cn'),true) && strpos($p,'/uploads/')===0) return ds_with_base($website,$p,$q);
        if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') && strtolower((string)($u['scheme']??''))==='http') return '';
        return $raw;
    }
    $doc=isset($_SERVER['DOCUMENT_ROOT'])?str_replace('\\','/',rtrim((string)$_SERVER['DOCUMENT_ROOT'],'/')):''; $dir=str_replace('\\','/',__DIR__);
    foreach(array($doc,$dir) as $base){ if($base!=='' && strpos($raw,$base.'/')===0){ $raw=substr($raw,strlen($base)+1); break; } }
    $clean=preg_replace('/[?#].*$/','',$raw) ?: $raw; $clean=ltrim(str_replace('\\','/',$clean),'/');
    $pos=strpos($clean,'uploads/website/'); if($pos!==false && $pos>0) $clean=substr($clean,$pos);
    $pos=strpos($clean,'uploads/naming/'); if($pos!==false && $pos>0) $clean=substr($clean,$pos);
    if(strpos($clean,'uploads/website/')===0 || strpos($clean,'uploads/products/')===0) return $website.'/'.ds_encode_path($clean);
    if(strpos($clean,'website/products/')===0) return $website.'/uploads/'.ds_encode_path($clean);
    if(strpos($clean,'uploads/naming/')===0 || strpos($clean,DS_UPLOAD_ROOT.'/')===0 || strpos($clean,'uploads/')===0) {
        return ds_app_base_path().'/'.ds_encode_path($clean);
    }
    return ds_encode_path($raw);
}
function ds_pick_first(array $row,array $keys): string { foreach($keys as $k){ if(isset($row[$k]) && ds_s($row[$k])!=='') return ds_s($row[$k]); } return ''; }
function ds_pick_media(array $row,string $type): string {
    if($type==='drawing') $keys=array('drawing_path','web_dimension_url','web_drawing_url','source_drawing_url','dimension_url','drawing_url','dimension_image_url','source_dimension_url','size_image_url','web_size_image_url','dimension_image','drawing_image','尺寸图');
    else $keys=array('image_path','web_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','cover_image_url','web_cover_url','image','img');
    return ds_asset_url(ds_pick_first($row,$keys));
}
function ds_is_website(array $row): bool { $source=strtolower(ds_s($row['source_system']??'')); if(in_array($source,array('website','web','hongkong_web','hk_web','official_website','artdon_website'),true)) return true; foreach(array('web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','source_url','source_synced_at','web_slug') as $k){ if(isset($row[$k]) && ds_s($row[$k])!=='') return true; } $txt=ds_s($row['remark']??'').' '.ds_s($row['created_by']??'').' '.ds_s($row['updated_by']??''); return (strpos($txt,'官网')!==false && strpos($txt,'同步')!==false); }
function ds_source_label(array $row): string { return ds_is_website($row) ? '官网同步' : '命名同步'; }
function ds_source_class(array $row): string { return ds_is_website($row) ? 'web' : 'naming'; }
function ds_root_category(string $category): string { $s=trim($category); if($s==='') return '未分类'; $s=preg_replace('/型号命名|灯具命名|命名规则|规则|命名/u','',$s); $s=preg_replace('/\s+/u',' ',trim((string)$s)); return $s!==''?$s:'未分类'; }
function ds_type_name(array $row): string { return ds_s($row['item_name']??($row['lamp_type']??($row['web_size_name']??''))) ?: '未分类'; }
function ds_series(array $row): string { foreach(array('web_series','series_name','product_name','website_display_name','series') as $k){ if(isset($row[$k]) && ds_s($row[$k])!=='') return ds_s($row[$k]); } return '未分系列'; }
function ds_model_prefix(string $modelNo): string { $modelNo=strtoupper(trim($modelNo)); if(preg_match('/^([A-Z0-9]{1,10})[.\-]/',$modelNo,$m)) return $m[1]; if(preg_match('/^([0-9]{2})/',$modelNo,$m)) return $m[1]; return ''; }
function ds_mm($v): string { $v=ds_s($v); if($v==='') return ''; $v=preg_replace('/\s*mm$/i','',$v); if(preg_match('/^\d+(?:\.\d+)?$/',$v)){ $v=preg_replace('/^0+(?=\d)/','',$v); if($v==='') $v='0'; if(strpos($v,'.')!==false) $v=rtrim(rtrim($v,'0'),'.'); } return $v.'mm'; }
function ds_dim_text(array $r): string {
    $model=ds_s($r['model_no']??''); $size=ds_s($r['size_code']??''); if($size==='' && strpos($model,'.')!==false){ $digits=preg_replace('/\D+/','',explode('.',$model,2)[1]??''); if(strlen($digits)>=3) $size=substr($digits,0,3); }
    $cat=ds_s($r['category']??'').' '.ds_s($r['item_name']??'').' '.ds_s($r['lamp_type']??''); $isEmbed=(bool)preg_match('/嵌入|有边|无边|recess/i',$cat);
    $open=ds_s($r['dim_opening']??''); $od=ds_s($r['dim_outer_d']??''); $l=ds_s($r['dim_length']??''); $w=ds_s($r['dim_width']??''); $h=ds_s($r['dim_height']??'');
    if($isEmbed && $open==='' && $size!=='') $open=$size;
    $parts=array(); if($isEmbed && $open!=='') $parts[]='开孔 '.ds_mm($open); if($od!=='') $parts[]='直径 '.ds_mm($od); if($l!=='') $parts[]='长 '.ds_mm($l); if($w!=='') $parts[]='宽 '.ds_mm($w); if($h!=='') $parts[]='高 '.ds_mm($h);
    if($parts) return implode(' / ',$parts);
    foreach(array('web_dimensions','dimensions','size_text','dimension') as $k){ if(isset($r[$k]) && ds_s($r[$k])!=='') return ds_s($r[$k]); }
    return $size!=='' ? '尺寸 '.ds_mm($size) : '尺寸未填';
}
function ds_source_url(array $row): string { $u=ds_s($row['source_url']??''); if($u!=='') return ds_asset_url($u); $slug=ds_s($row['web_slug']??''); return $slug!=='' ? ds_website_base().'/product.php?slug='.rawurlencode($slug) : ''; }
function ds_naming_cols_select(): array {
    if(!ds_table_exists('naming_models')) return array();
    $wanted=array('id','model_no','rule_id','category','item_name','lamp_type','prefix','size_code','serial_no','product_name','series_name','customer','status','remark','image_path','drawing_path','dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','opening','cutout','diameter','diameter_mm','length','length_mm','width','width_mm','height','height_mm','power','power_text','cct','cri','color','finish','beam_angle','source_system','source_id','source_url','source_synced_at','source_image_url','source_drawing_url','web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','web_drawing_url','web_size_image_url','web_slug','website_variant_id','created_at','updated_at','created_by','updated_by');
    $cols=ds_cols('naming_models'); $out=array(); foreach($wanted as $c){ if(in_array($c,$cols,true)) $out[]=$c; } if(!$out) $out=$cols; return $out;
}
function ds_naming_row(int $id=0,string $modelNo=''): ?array { if(!ds_table_exists('naming_models')) return null; $cols=ds_naming_cols_select(); if(!$cols) return null; if($id>0){ $st=ds_db()->prepare('SELECT '.implode(',',array_map('ds_qid',$cols)).' FROM naming_models WHERE id=? LIMIT 1'); $st->execute(array($id)); } else { $st=ds_db()->prepare('SELECT '.implode(',',array_map('ds_qid',$cols)).' FROM naming_models WHERE model_no=? LIMIT 1'); $st->execute(array($modelNo)); } $r=$st->fetch(); return $r ?: null; }
function ds_override(string $modelNo): array { try{ $st=ds_db()->prepare('SELECT * FROM datasheet_overrides WHERE model_no=? LIMIT 1'); $st->execute(array($modelNo)); return $st->fetch()?:array(); }catch(Throwable $e){ return array(); } }
function ds_files(string $modelNo,string $type=''): array { $where='model_no=? AND is_deleted=0'; $args=array($modelNo); if($type!==''){ $where.=' AND file_type=?'; $args[]=$type; } $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE '.$where.' ORDER BY uploaded_at DESC,id DESC'); $st->execute($args); $rows=$st->fetchAll()?:array(); foreach($rows as &$r){ $r['url']=ds_asset_url($r['file_path']); } return $rows; }
function ds_records(string $modelNo): array { $st=ds_db()->prepare('SELECT * FROM datasheet_records WHERE model_no=? ORDER BY created_at DESC,id DESC LIMIT 150'); $st->execute(array($modelNo)); $rows=$st->fetchAll()?:array(); foreach($rows as &$r){ $r['url']=ds_asset_url($r['file_path']); } return $rows; }
function ds_count_maps(array $models): array { $out=array(); if(!$models) return $out; $models=array_values(array_unique(array_filter($models))); $ph=implode(',',array_fill(0,count($models),'?')); $st=ds_db()->prepare("SELECT model_no,COUNT(*) c FROM datasheet_files WHERE is_deleted=0 AND model_no IN ($ph) GROUP BY model_no"); $st->execute($models); foreach($st->fetchAll()?:array() as $r) $out[$r['model_no']]['files']=(int)$r['c']; $st=ds_db()->prepare("SELECT model_no,COUNT(*) c FROM datasheet_records WHERE model_no IN ($ph) GROUP BY model_no"); $st->execute($models); foreach($st->fetchAll()?:array() as $r) $out[$r['model_no']]['records']=(int)$r['c']; return $out; }

function ds_json_any($v): array {
    if(is_array($v)) return $v;
    $s=trim((string)$v); if($s==='') return array();
    $j=json_decode($s,true); return is_array($j)?$j:array();
}
function ds_text_list($v): string {
    if(is_array($v)){ $out=array(); foreach($v as $x){ if(is_array($x)) continue; $x=ds_s($x); if($x!=='') $out[]=$x; } return implode(' / ',array_values(array_unique($out))); }
    $s=ds_s($v); if($s!=='' && (($s[0]??'')==='[' || ($s[0]??'')==='{')){ $j=ds_json_any($s); if($j) return ds_text_list($j); }
    return $s;
}
function ds_slug_from_row(array $row): string {
    foreach(array('web_slug','slug') as $k){ if(ds_s($row[$k]??'')!=='') return ds_s($row[$k]); }
    $u=ds_s($row['source_url']??''); if($u!==''){
        $p=@parse_url($u); if(is_array($p)){
            if(!empty($p['query'])){ parse_str((string)$p['query'],$q); if(!empty($q['slug'])) return ds_s($q['slug']); }
            $path=trim((string)($p['path']??''),'/'); if($path!==''){ $parts=explode('/',$path); $last=end($parts); if($last && !preg_match('/\.php$/i',$last)) return ds_s($last); }
        }
    }
    return '';
}
function ds_web_variant_for_naming(array $row): array {
    if(!ds_table_exists('web_product_variants')) return array();
    $cols=ds_cols('web_product_variants'); if(!$cols) return array();
    $try=array(); $args=array();
    $sourceId=ds_s($row['source_id']??''); if($sourceId!=='' && ctype_digit($sourceId) && in_array('id',$cols,true)){ $try[]='id=?'; $args[]=(int)$sourceId; }
    $slug=ds_slug_from_row($row); if($slug!=='' && in_array('slug',$cols,true)){ $try[]='slug=?'; $args[]=$slug; }
    $model=ds_s($row['model_no']??''); if($model!=='' && in_array('model_code',$cols,true)){ $try[]='model_code=?'; $args[]=$model; }
    if(!$try) return array();
    $sql='SELECT * FROM web_product_variants WHERE '.implode(' OR ',$try).' ORDER BY id DESC LIMIT 1';
    try{ $st=ds_db()->prepare($sql); $st->execute($args); $v=$st->fetch()?:array(); }catch(Throwable $e){ $v=array(); }
    return is_array($v)?$v:array();
}
function ds_web_series_for_variant(array $v): array {
    if(!$v || !ds_table_exists('web_products')) return array();
    $sid=(int)($v['series_id']??0); if($sid<=0) return array();
    try{ $st=ds_db()->prepare('SELECT * FROM web_products WHERE id=? LIMIT 1'); $st->execute(array($sid)); return $st->fetch()?:array(); }catch(Throwable $e){ return array(); }
}
function ds_web_array_field(array $row, array $keys): array {
    foreach($keys as $k){ if(array_key_exists($k,$row)){ $j=ds_json_any($row[$k]); if($j) return $j; } }
    return array();
}
function ds_norm_img_item($item,string $defaultLabel=''): array {
    if(is_string($item)) return array('image'=>ds_asset_url($item),'label'=>$defaultLabel,'alt'=>$defaultLabel);
    if(!is_array($item)) return array();
    $img=ds_pick_first($item,array('image','url','src','path','file','file_path','img'));
    if($img==='') return array();
    return array('image'=>ds_asset_url($img),'label'=>ds_s($item['label']??($item['title']??($item['name']??$defaultLabel))),'alt'=>ds_s($item['alt']??($item['label']??$defaultLabel)));
}

function ds_remote_asset_url(string $src): string {
    $src=html_entity_decode(trim($src),ENT_QUOTES|ENT_HTML5,'UTF-8'); if($src==='') return '';
    if(preg_match('#^https?://#i',$src)) return ds_asset_url($src);
    if(strpos($src,'//')===0) return 'https:'.$src;
    $base=ds_website_base();
    if($src[0]==='/') return ds_with_base($base,$src);
    return ds_with_base($base,$src);
}

function ds_website_cache_table(): void {
    ds_db()->exec("CREATE TABLE IF NOT EXISTS datasheet_website_cache(
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        model_no VARCHAR(120) NOT NULL DEFAULT '',
        naming_model_id INT NOT NULL DEFAULT 0,
        slug VARCHAR(255) NOT NULL DEFAULT '',
        source_id VARCHAR(120) NOT NULL DEFAULT '',
        cache_json LONGTEXT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_model(model_no), KEY idx_naming(naming_model_id), KEY idx_slug(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ds_add_col('datasheet_website_cache','source_id',"`source_id` VARCHAR(120) NOT NULL DEFAULT ''");
}
function ds_website_cache_empty(): array { return array('params'=>array(),'intro'=>'','photometric_images'=>array(),'accessory_items'=>array()); }
function ds_website_cache_get(array $row): array {
    try{
        ds_website_cache_table();
        $model=ds_s($row['model_no']??''); $id=(int)($row['id']??0); $slug=ds_slug_from_row($row); $sourceId=ds_s($row['source_id']??'');
        $where=array(); $args=array();
        if($model!==''){ $where[]='model_no=?'; $args[]=$model; }
        if($id>0){ $where[]='naming_model_id=?'; $args[]=$id; }
        if($slug!==''){ $where[]='slug=?'; $args[]=$slug; }
        if($sourceId!=='' && ds_col_exists('datasheet_website_cache','source_id')){ $where[]='source_id=?'; $args[]=$sourceId; }
        if(!$where) return ds_website_cache_empty();
        $st=ds_db()->prepare('SELECT cache_json FROM datasheet_website_cache WHERE '.implode(' OR ',$where).' ORDER BY updated_at DESC LIMIT 1');
        $st->execute($args); $raw=(string)($st->fetchColumn()?:'');
        $j=ds_json_any($raw); return is_array($j)?array_merge(ds_website_cache_empty(),$j):ds_website_cache_empty();
    }catch(Throwable $e){ return ds_website_cache_empty(); }
}
function ds_website_cache_save(array $row, array $data): void {
    ds_website_cache_table();
    $model=ds_s($row['model_no']??''); $id=(int)($row['id']??0); $slug=ds_slug_from_row($row); $sourceId=ds_s($row['source_id']??'');
    if($model==='' && $id<=0 && $slug==='' && $sourceId==='') return;
    $old=ds_website_cache_get($row);
    $merged=array_merge(ds_website_cache_empty(),$old,$data);
    $json=json_encode($merged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    ds_db()->prepare('INSERT INTO datasheet_website_cache(model_no,naming_model_id,slug,source_id,cache_json,fetched_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE naming_model_id=VALUES(naming_model_id), slug=VALUES(slug), source_id=VALUES(source_id), cache_json=VALUES(cache_json), fetched_at=NOW(), updated_at=NOW()')
        ->execute(array($model,$id,$slug,$sourceId,$json));
}
function ds_refresh_website_cache_for_product(array $row): array {
    $url=ds_website_pdf_url_for_row($row);
    $data=ds_web_detail_from_remote_pdf($row);
    $data['_download_meta']=array('source_url'=>$url,'source_type'=>'official_website_pdf','downloaded_at'=>ds_now());
    ds_website_cache_save($row,$data);
    return $data;
}

function ds_web_detail_from_remote_pdf(array $row): array {
    $slug=ds_slug_from_row($row); $source=ds_source_url($row); $sourceId=ds_s($row['source_id']??'');
    if($slug===''){
        $u=ds_s($source); if($u!==''){ $p=@parse_url($u); if(is_array($p) && !empty($p['query'])){ parse_str((string)$p['query'],$q); if(!empty($q['slug'])) $slug=ds_s($q['slug']); if(!empty($q['id']) && $sourceId==='') $sourceId=ds_s($q['id']); } }
    }
    if($slug!=='') $url=ds_website_base().'/product_pdf.php?slug='.rawurlencode($slug).'&logo=0';
    elseif($sourceId!=='' && ctype_digit($sourceId)) $url=ds_website_base().'/product_pdf.php?id='.(int)$sourceId.'&logo=0';
    else return array('params'=>array(),'intro'=>'','photometric_images'=>array(),'accessory_items'=>array());
    $ctx=stream_context_create(array('http'=>array('timeout'=>4,'ignore_errors'=>true,'header'=>"User-Agent: ArtdonDatasheet/2.0\r\n"),'ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false)));
    $html=@file_get_contents($url,false,$ctx); if(!is_string($html) || strlen($html)<200) return array('params'=>array(),'intro'=>'','photometric_images'=>array(),'accessory_items'=>array());
    $out=array('params'=>array(),'intro'=>'','photometric_images'=>array(),'accessory_items'=>array());
    if(preg_match('~<div class="sheet-intro"[^>]*>(.*?)</div>~is',$html,$m)) $out['intro']=trim(html_entity_decode(strip_tags(str_replace(array('<br>','<br/>','<br />'),"\n",$m[1])),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(preg_match_all('~<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*</tr>~is',$html,$mm,PREG_SET_ORDER)){
        foreach($mm as $r){ $k=trim(html_entity_decode(strip_tags($r[1]),ENT_QUOTES|ENT_HTML5,'UTF-8')); $v=trim(html_entity_decode(strip_tags($r[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')); if($k!=='' && $v!=='') $out['params'][$k]=$v; }
    }
    if(preg_match_all('~<figure class="curve-card"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>.*?(?:<figcaption>(.*?)</figcaption>)?.*?</figure>~is',$html,$mm,PREG_SET_ORDER)){
        foreach($mm as $r){ $img=ds_remote_asset_url($r[1]??''); if($img!=='') $out['photometric_images'][]=array('image'=>$img,'label'=>trim(html_entity_decode(strip_tags($r[2]??''),ENT_QUOTES|ENT_HTML5,'UTF-8')),'alt'=>'Photometric curve'); }
    }
    if(preg_match_all('~<article class="accessory-card"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>.*?(?:<p class="accessory-code[^>]*>(.*?)</p>)?.*?(?:<h3 class="accessory-name"[^>]*>(.*?)</h3>)?.*?</article>~is',$html,$mm,PREG_SET_ORDER)){
        foreach($mm as $r){ $img=ds_remote_asset_url($r[1]??''); if($img==='') continue; $code=trim(html_entity_decode(strip_tags($r[2]??''),ENT_QUOTES|ENT_HTML5,'UTF-8')); $name=trim(html_entity_decode(strip_tags($r[3]??''),ENT_QUOTES|ENT_HTML5,'UTF-8')); if($name==='') $name='Accessory'; $out['accessory_items'][]=array('image_url'=>$img,'model_no'=>$code==='&nbsp;'?'':$code,'name_en'=>$name,'name_cn'=>'','source'=>'website'); }
    }
    return $out;
}

function ds_website_detail_for_product(array $namingRow): array {
    $v=ds_web_variant_for_naming($namingRow); $s=ds_web_series_for_variant($v); $out=array('variant'=>$v,'series'=>$s,'params'=>array(),'intro'=>'','photometric_images'=>array(),'accessory_items'=>array());
    // V2.0.4：详情页不再实时访问官网。优先读本地官网表，其次读 datasheet_website_cache。
    // 需要更新官网 PDF 参数/配光/配件时，由前端按钮手动触发 sync_website_cache。
    if(!$v){ return array_merge($out, ds_website_cache_get($namingRow)); }
    $seriesName=ds_s($s['series_name']??($s['name']??''));
    $out['intro']=ds_s($v['detail_intro']??'') ?: (ds_s($v['full_description']??'') ?: ds_s($v['short_description']??''));
    $params=array(
      'Product family'=>$seriesName,
      'Model'=>ds_s($v['model_code']??''),
      'Dimensions'=>ds_s($v['dimensions']??''),
      'Cut-out'=>ds_s($v['cutout_text']??''),
      'Power'=>ds_s($v['power_text']??''),
      'Luminous flux'=>ds_s($v['lumen_text']??''),
      'Efficacy'=>ds_s($v['efficacy_text']??''),
      'Voltage'=>ds_text_list($v['voltage']??''),
      'CCT'=>ds_text_list($v['cct']??''),
      'CRI'=>ds_text_list($v['cri']??''),
      'Beam angle'=>ds_text_list($v['beam_angle']??''),
      'IP rating'=>ds_s($v['ip_rating']??''),
      'Finish'=>ds_text_list($v['finish']??''),
      'Mounting'=>ds_text_list($v['mounting']??''),
      'Dimming'=>ds_text_list($v['dimming']??'')
    );
    $custom=array();
    foreach(array_merge(ds_web_array_field($v,array('extra_specs','extra_specs_json')), ds_web_array_field($v,array('spec_rows','spec_rows_json'))) as $r){
        if(!is_array($r)) continue; if(isset($r['active']) && !$r['active']) continue;
        $lab=ds_s($r['label']??($r['name']??($r['key']??''))); $val=ds_text_list($r['value']??($r['text']??($r['content']??'')));
        if($lab!=='' || $val!=='') $custom[$lab!==''?$lab:'Specification']=$val;
    }
    if($custom) $params=$custom;
    $out['params']=array_filter($params,fn($x)=>ds_s($x)!=='');
    $photos=ds_web_array_field($v,array('photometric_images','photometric_images_json','photometric_json','curves_json'));
    foreach($photos as $it){ $x=ds_norm_img_item($it,'Photometric curve'); if($x) $out['photometric_images'][]=$x; }
    $accs=ds_web_array_field($v,array('accessory_items','accessory_items_json','accessories_json'));
    foreach($accs as $it){
        if(is_string($it)) continue; if(!is_array($it)) continue;
        $img=ds_pick_first($it,array('image','url','src','path','file','file_path','img')); if($img==='') continue;
        $out['accessory_items'][]=array('image_url'=>ds_asset_url($img),'model_no'=>ds_s($it['model']??($it['code']??'')),'name_en'=>ds_s($it['title']??($it['name']??'Accessory')),'name_cn'=>ds_s($it['title_cn']??''),'description'=>ds_s($it['description']??''),'source'=>'website');
    }
    if(ds_s($v['cover_image']??'')!=='') $out['cover_image']=ds_asset_url(ds_s($v['cover_image']));
    if(ds_s($v['dimension_image']??'')!=='') $out['dimension_image']=ds_asset_url(ds_s($v['dimension_image']));
    if(!$out['params'] || !$out['photometric_images'] || !$out['accessory_items']){
        $cache=ds_website_cache_get($namingRow);
        if(!$out['params'] && !empty($cache['params'])) $out['params']=$cache['params'];
        if($out['intro']==='' && !empty($cache['intro'])) $out['intro']=$cache['intro'];
        if(!$out['photometric_images'] && !empty($cache['photometric_images'])) $out['photometric_images']=$cache['photometric_images'];
        if(!$out['accessory_items'] && !empty($cache['accessory_items'])) $out['accessory_items']=$cache['accessory_items'];
    }
    return $out;
}

function ds_apply_naming_fields(array &$p, array $row, string $image='', string $drawing=''): void {
    $p['naming_model_id']=(int)($row['id']??0);
    $p['product_category']=$p['category']??ds_root_category(ds_s($row['category']??''));
    $p['product_series']=$p['series']??ds_series($row);
    $p['product_image_url']=$image;
    $p['drawing_image_url']=$drawing;
    $p['cutout']=ds_pick_first($row,array('dim_opening','opening','cutout','cutout_size','opening_size'));
    $p['diameter']=ds_pick_first($row,array('dim_outer_d','diameter','diameter_mm','outer_d','outer_diameter'));
    $p['length']=ds_pick_first($row,array('dim_length','length','length_mm'));
    $p['width']=ds_pick_first($row,array('dim_width','width','width_mm'));
    $p['height']=ds_pick_first($row,array('dim_height','height','height_mm'));
    $p['power']=ds_pick_first($row,array('power','power_text'));
    $p['cct']=ds_pick_first($row,array('cct'));
    $p['cri']=ds_pick_first($row,array('cri'));
    $p['color']=ds_pick_first($row,array('color','finish'));
    $p['beam_angle']=ds_pick_first($row,array('beam_angle'));
    $p['website_synced']=!empty($p['is_website']) ? 1 : 0;
    $p['website_slug']=$p['web_slug']??ds_slug_from_row($row);
    $p['website_url']=$p['source_url']??ds_source_url($row);
}

function ds_product_out(array $row, array $counts=array(), bool $detail=false): array {
    $model=ds_s($row['model_no']??''); $ov=ds_override($model); $web=$detail ? ds_website_detail_for_product($row) : array();
    $image=ds_pick_media($row,'image'); $drawing=ds_pick_media($row,'drawing');
    if($detail && !empty($web['cover_image'])) $image=$web['cover_image'];
    if($detail && !empty($web['dimension_image'])) $drawing=$web['dimension_image'];
    $p=array('id'=>(int)($row['id']??0),'model_no'=>$model,'title'=>ds_s($ov['title']??'') ?: (ds_s($row['product_name']??'') ?: $model),'series'=>ds_series($row),'category_raw'=>ds_s($row['category']??''),'category'=>ds_root_category(ds_s($row['category']??'')),'item_name'=>ds_type_name($row),'type_name'=>ds_type_name($row),'dimension_text'=>ds_dim_text($row),'image_url'=>$image,'drawing_url'=>$drawing,'source_label'=>ds_source_label($row),'source_class'=>ds_source_class($row),'is_website'=>ds_is_website($row)?1:0,'source_url'=>ds_source_url($row),'source_id'=>ds_s($row['source_id']??''),'web_slug'=>ds_slug_from_row($row),'created_at'=>ds_s($row['created_at']??''),'updated_at'=>ds_s($row['updated_at']??''),'file_count'=>$counts[$model]['files']??0,'record_count'=>$counts[$model]['records']??0,'override'=>$ov);
    ds_apply_naming_fields($p,$row,$image,$drawing);
    if($detail){
        if(!empty($web['params'])) $p['website_params']=$web['params'];
        if(!empty($web['intro'])) $p['website_intro']=$web['intro'];
        if(!empty($web['photometric_images'])) $p['website_photometric_images']=$web['photometric_images'];
        if(!empty($web['accessory_items'])) $p['website_accessories']=$web['accessory_items'];
        $p['files']=ds_files($model); $p['records']=ds_records($model); $p['photometric_images']=ds_photometric_images($model); $p['manual_accessories']=ds_manual_accessory_images($model); $p['highres_images']=ds_highres_images($model); $p['accessories']=ds_product_accessories($model); $p['configs']=ds_product_configs($model); $p['params']=ds_product_params($p);
    }
    return $p;
}
function ds_product_detail(int $id=0,string $modelNo=''): array { $row=ds_naming_row($id,$modelNo); if(!$row) throw new RuntimeException('未找到命名系统型号。'); return ds_product_out($row,ds_count_maps(array(ds_s($row['model_no']??''))),true); }


/* V2.0.5: FAST SELECT MODE
 * 产品点击只读命名系统 + 本地补充 + 本地官网缓存，不扫官网产品表，不抓官网，不查大资料。
 * 需要官网完整参数/配光/配件时，用户手动点“拉取当前官网资料”。
 */
function ds_product_out_fast(array $row, array $counts=array(), bool $withRelated=false): array {
    $model=ds_s($row['model_no']??'');
    $ov=ds_override($model);
    $cache=ds_website_cache_get($row);
    $image=ds_pick_media($row,'image');
    $drawing=ds_pick_media($row,'drawing');
    if(!empty($cache['cover_image'])) $image=$cache['cover_image'];
    if(!empty($cache['dimension_image'])) $drawing=$cache['dimension_image'];
    $p=array(
        'id'=>(int)($row['id']??0),
        'model_no'=>$model,
        'title'=>ds_s($ov['title']??'') ?: (ds_s($row['product_name']??'') ?: $model),
        'series'=>ds_series($row),
        'category_raw'=>ds_s($row['category']??''),
        'category'=>ds_root_category(ds_s($row['category']??'')),
        'item_name'=>ds_type_name($row),
        'type_name'=>ds_type_name($row),
        'dimension_text'=>ds_dim_text($row),
        'image_url'=>$image,
        'drawing_url'=>$drawing,
        'source_label'=>ds_source_label($row),
        'source_class'=>ds_source_class($row),
        'is_website'=>ds_is_website($row)?1:0,
        'source_url'=>ds_source_url($row),
        'created_at'=>ds_s($row['created_at']??''),
        'updated_at'=>ds_s($row['updated_at']??''),
        'file_count'=>$counts[$model]['files']??0,
        'record_count'=>$counts[$model]['records']??0,
        'override'=>$ov,
        'website_cache_status'=>empty($cache['params']) && empty($cache['photometric_images']) && empty($cache['accessory_items']) ? 'empty' : 'cached'
    );
    ds_apply_naming_fields($p,$row,$image,$drawing);
    if(!empty($cache['params'])) $p['website_params']=$cache['params'];
    if(!empty($cache['intro'])) $p['website_intro']=$cache['intro'];
    if(!empty($cache['photometric_images'])) $p['website_photometric_images']=$cache['photometric_images'];
    if(!empty($cache['accessory_items'])) $p['website_accessories']=$cache['accessory_items'];
    if($withRelated){
        $p['files']=ds_files($model);
        $p['records']=ds_records($model);
        $p['photometric_images']=ds_photometric_images($model);
        $p['manual_accessories']=ds_manual_accessory_images($model);
        $p['highres_images']=ds_highres_images($model);
        $p['snapshots']=ds_snapshots($model);
        $p['accessories']=ds_product_accessories($model);
        $p['configs']=ds_product_configs($model);
    } else {
        $p['files']=array(); $p['records']=array(); $p['photometric_images']=array(); $p['accessories']=array(); $p['configs']=array();
    }
    $p['params']=ds_product_params($p);
    return $p;
}
function ds_product_detail_fast(int $id=0,string $modelNo='', bool $withRelated=false): array {
    $row=ds_naming_row($id,$modelNo);
    if(!$row) throw new RuntimeException('未找到命名系统型号。');
    return ds_product_out_fast($row,ds_count_maps(array(ds_s($row['model_no']??''))),$withRelated);
}
function ds_product_related_fast(int $id=0,string $modelNo=''): array {
    $row=ds_naming_row($id,$modelNo);
    if(!$row) throw new RuntimeException('未找到命名系统型号。');
    $model=ds_s($row['model_no']??'');
    return array(
        'id'=>(int)($row['id']??0),
        'model_no'=>$model,
        'files'=>ds_files($model),
        'records'=>ds_records($model),
        'photometric_images'=>ds_photometric_images($model),
        'manual_accessories'=>ds_manual_accessory_images($model),
        'highres_images'=>ds_highres_images($model),
        'snapshots'=>ds_snapshots($model),
        'accessories'=>ds_product_accessories($model),
        'configs'=>ds_product_configs($model)
    );
}

function ds_product_where(array $f, array &$args): string {
    $cols=ds_cols('naming_models'); $w=array();
    $kw=ds_s($f['kw']??''); if($kw!==''){ $or=array(); foreach(array('model_no','product_name','series_name','web_series','item_name','category','remark','web_dimensions') as $c){ if(in_array($c,$cols,true)){ $or[]="COALESCE(`{$c}`,'') LIKE ?"; $args[]='%'.$kw.'%'; } } $compact=strtoupper(preg_replace('/[^A-Z0-9]+/','',$kw)); if($compact!=='' && in_array('model_no',$cols,true)){ $or[]="REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`model_no`,'')),'.',''),'-',''),' ','') LIKE ?"; $args[]='%'.$compact.'%'; } if($or) $w[]='('.implode(' OR ',$or).')'; }
    $cat=ds_s($f['category']??''); if($cat!=='' && in_array('category',$cols,true)){ if($cat==='未分类') $w[]="(category='' OR category IS NULL)"; else { $w[]="category LIKE ?"; $args[]='%'.$cat.'%'; } }
    $item=ds_s($f['item_name']??''); if($item!=='' && in_array('item_name',$cols,true)){ $w[]="item_name=?"; $args[]=$item; }
    $source=ds_s($f['source']??''); if($source==='website') $w[]=ds_website_condition_sql($cols); if($source==='naming') $w[]='NOT '.ds_website_condition_sql($cols);
    return $w ? ' WHERE '.implode(' AND ',$w) : '';
}
function ds_website_condition_sql(array $cols): string { $or=array(); if(in_array('source_system',$cols,true)) $or[]="LOWER(source_system) IN ('website','web','hongkong_web','hk_web','official_website','artdon_website')"; foreach(array('web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','source_url','source_synced_at','web_slug') as $c){ if(in_array($c,$cols,true)) $or[]="(`{$c}` IS NOT NULL AND `{$c}`<>'')"; } return $or?'('.implode(' OR ',$or).')':'0=1'; }
function ds_home_categories(): array {
    if(!ds_table_exists('naming_models')) return array();
    $cols=ds_cols('naming_models'); $catCol=in_array('category',$cols,true)?'category':''; if($catCol==='') return array();
    $rows=ds_db()->query("SELECT category,COUNT(*) c FROM naming_models GROUP BY category ORDER BY c DESC, category ASC LIMIT 80")->fetchAll()?:array(); $out=array();
    foreach($rows as $r){ $root=ds_root_category((string)$r['category']); if(!isset($out[$root])) $out[$root]=array('category'=>$root,'raw'=>array(),'count'=>0); $out[$root]['raw'][]=(string)$r['category']; $out[$root]['count']+=(int)$r['c']; }
    return array_values($out);
}
function ds_home_samples(string $root,int $limit=8): array { $cols=ds_naming_cols_select(); if(!$cols) return array(); $args=array(); $where=''; if($root!=='' && $root!=='全部产品'){ $where=' WHERE category LIKE ?'; $args[]='%'.$root.'%'; } $order=in_array('updated_at',$cols,true)?'updated_at DESC,id DESC':'id DESC'; $st=ds_db()->prepare('SELECT '.implode(',',array_map('ds_qid',$cols)).' FROM naming_models'.$where.' ORDER BY '.$order.' LIMIT '.max(1,min(24,$limit))); $st->execute($args); $rows=$st->fetchAll()?:array(); $counts=ds_count_maps(array_map(fn($r)=>ds_s($r['model_no']??''),$rows)); return array_map(fn($r)=>ds_product_out($r,$counts,false),$rows); }
function ds_products(array $f): array { $cols=ds_naming_cols_select(); if(!$cols) return array('rows'=>array(),'total'=>0,'page'=>1,'per_page'=>20); $page=max(1,(int)($f['page']??1)); $per=max(8,min(80,(int)($f['per_page']??20))); $args=array(); $where=ds_product_where($f,$args); $st=ds_db()->prepare('SELECT COUNT(*) FROM naming_models'.$where); $st->execute($args); $total=(int)$st->fetchColumn(); $offset=($page-1)*$per; $order=in_array('updated_at',$cols,true)?'updated_at DESC,id DESC':'id DESC'; $sql='SELECT '.implode(',',array_map('ds_qid',$cols)).' FROM naming_models'.$where.' ORDER BY '.$order.' LIMIT '.$offset.','.$per; $st=ds_db()->prepare($sql); $st->execute($args); $rows=$st->fetchAll()?:array(); $counts=ds_count_maps(array_map(fn($r)=>ds_s($r['model_no']??''),$rows)); return array('rows'=>array_map(fn($r)=>ds_product_out($r,$counts,false),$rows),'total'=>$total,'page'=>$page,'per_page'=>$per); }
function ds_stats(): array { $total=ds_table_exists('naming_models')?(int)ds_db()->query('SELECT COUNT(*) FROM naming_models')->fetchColumn():0; $files=ds_table_exists('datasheet_files')?(int)ds_db()->query('SELECT COUNT(*) FROM datasheet_files WHERE is_deleted=0')->fetchColumn():0; $recs=ds_table_exists('datasheet_records')?(int)ds_db()->query('SELECT COUNT(*) FROM datasheet_records')->fetchColumn():0; $acc=ds_table_exists('datasheet_accessories')?(int)ds_db()->query('SELECT COUNT(*) FROM datasheet_accessories WHERE is_active=1')->fetchColumn():0; return array('products'=>$total,'files'=>$files,'records'=>$recs,'accessories'=>$acc); }

function ds_fmt_bytes(int $bytes): string {
    if($bytes<=0) return '0 B';
    $units=array('B','KB','MB','GB','TB'); $i=0; $v=(float)$bytes;
    while($v>=1024 && $i<count($units)-1){ $v/=1024; $i++; }
    return ($i===0?number_format($v,0):number_format($v,2)).' '.$units[$i];
}
function ds_dir_size_safe(string $dir): int {
    if($dir==='' || !is_dir($dir)) return 0;
    $bytes=0;
    try{
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach($it as $f){ if($f->isFile()) $bytes += (int)$f->getSize(); }
    }catch(Throwable $e){}
    return $bytes;
}
function ds_storage_overview(): array {
    ds_ensure_tables();
    $out=array('summary'=>array(),'groups'=>array(),'recent'=>array(),'warnings'=>array());
    $uploadDir=__DIR__.'/'.DS_UPLOAD_ROOT;
    $uploadBytes=ds_dir_size_safe($uploadDir);
    $out['summary']['upload_bytes']=$uploadBytes;
    $out['summary']['upload_text']=ds_fmt_bytes($uploadBytes);
    $tables=array('datasheet_files','datasheet_logs','datasheet_snapshots','datasheet_records','datasheet_website_cache','datasheet_overrides');
    foreach($tables as $t){
        $rows=0; $data=0; $index=0;
        try{ $rows=(int)ds_db()->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn(); }catch(Throwable $e){}
        try{ $st=ds_db()->prepare("SELECT DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1"); $st->execute(array($t)); $r=$st->fetch()?:array(); $data=(int)($r['DATA_LENGTH']??0); $index=(int)($r['INDEX_LENGTH']??0); }catch(Throwable $e){}
        $out['tables'][$t]=array('table'=>$t,'rows'=>$rows,'bytes'=>$data+$index,'text'=>ds_fmt_bytes($data+$index),'data_text'=>ds_fmt_bytes($data),'index_text'=>ds_fmt_bytes($index));
    }
    $typeRows=array();
    if(ds_table_exists('datasheet_files')){
        try{ $typeRows=ds_db()->query("SELECT file_type,COUNT(*) cnt,COALESCE(SUM(size_bytes),0) bytes FROM datasheet_files WHERE is_deleted=0 GROUP BY file_type ORDER BY bytes DESC,cnt DESC")->fetchAll()?:array(); }catch(Throwable $e){}
    }
    $map=array('高清图'=>'高清|图片|image|photo|hd','配光曲线'=>'配光|曲线|photometric|curve','配件图'=>'配件|附件|accessor|honeycomb|shutter|louver','IES/LDT'=>'ies|ldt','测试资料'=>'测试|报告|test|report','证书'=>'证书|certificate','PDF/Excel/ZIP'=>'pdf|excel|xlsx|zip|资料包','其它'=>'');
    $bucket=array(); foreach($map as $k=>$re) $bucket[$k]=array('name'=>$k,'count'=>0,'bytes'=>0,'types'=>array());
    foreach($typeRows as $r){
        $t=(string)($r['file_type']??''); $cnt=(int)($r['cnt']??0); $b=(int)($r['bytes']??0); $hit='其它';
        foreach($map as $k=>$re){ if($re!=='' && preg_match('/'.$re.'/iu',$t)){ $hit=$k; break; } }
        $bucket[$hit]['count']+=$cnt; $bucket[$hit]['bytes']+=$b; $bucket[$hit]['types'][]=array('file_type'=>$t,'count'=>$cnt,'bytes'=>$b,'text'=>ds_fmt_bytes($b));
    }
    foreach($bucket as &$b){ $b['text']=ds_fmt_bytes((int)$b['bytes']); } unset($b);
    $out['groups']=array_values($bucket);
    $snapRows=0; $snapBytes=0; try{ $snapRows=(int)ds_db()->query('SELECT COUNT(*) FROM datasheet_snapshots')->fetchColumn(); $snapBytes=(int)ds_db()->query('SELECT COALESCE(SUM(OCTET_LENGTH(snapshot_json)),0) FROM datasheet_snapshots')->fetchColumn(); }catch(Throwable $e){}
    $logRows=0; $logBytes=0; try{ $logRows=(int)ds_db()->query('SELECT COUNT(*) FROM datasheet_logs')->fetchColumn(); $logBytes=(int)ds_db()->query('SELECT COALESCE(SUM(OCTET_LENGTH(detail_json)),0) FROM datasheet_logs')->fetchColumn(); }catch(Throwable $e){}
    $out['summary']['snapshot_rows']=$snapRows; $out['summary']['snapshot_bytes']=$snapBytes; $out['summary']['snapshot_text']=ds_fmt_bytes($snapBytes);
    $out['summary']['log_rows']=$logRows; $out['summary']['log_bytes']=$logBytes; $out['summary']['log_text']=ds_fmt_bytes($logBytes);
    $out['summary']['total_known_bytes']=$uploadBytes+$snapBytes+$logBytes; $out['summary']['total_known_text']=ds_fmt_bytes($uploadBytes+$snapBytes+$logBytes);
    if($uploadBytes>1024*1024*1024) $out['warnings'][]='上传资料已超过 1GB，建议清理无用高清图和压缩包。';
    if($logRows>50000) $out['warnings'][]='日志超过 5 万条，建议后续加入日志归档。';
    if($snapRows>5000) $out['warnings'][]='快照超过 5000 条，建议后续加入快照归档/按年归档。';
    try{ $out['recent']=ds_db()->query("SELECT id,file_type,file_title,original_name,model_no,size_bytes,uploaded_by,uploaded_at FROM datasheet_files WHERE is_deleted=0 ORDER BY uploaded_at DESC,id DESC LIMIT 30")->fetchAll()?:array(); foreach($out['recent'] as &$r){ $r['text']=ds_fmt_bytes((int)($r['size_bytes']??0)); } }catch(Throwable $e){}
    return $out;
}
function ds_extract_archive_images_to_library(array $zipFile, string $type, string $title, string $note, string $visibility, string $model, int $modelId): array {
    $path=(string)($zipFile['path']??''); $full=__DIR__.'/'.$path; $saved=array();
    if(!class_exists('ZipArchive')) return array('saved'=>array(),'message'=>'服务器未启用 ZipArchive，压缩包本身已保存，但不能自动解压预览。');
    if(!is_file($full) || !preg_match('/\.zip$/i',$full)) return array('saved'=>array(),'message'=>'不是 ZIP 文件，不需要解压。');
    $zip=new ZipArchive(); if($zip->open($full)!==true) return array('saved'=>array(),'message'=>'压缩包无法打开，已只保存原文件。');
    $relDir=ds_upload_rel_dir('files/'.$type.'/extracted'); $max=80; $count=0;
    for($i=0;$i<$zip->numFiles && $count<$max;$i++){
        $name=$zip->getNameIndex($i); if(!$name || substr($name,-1)==='/') continue;
        $base=basename($name); if(!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i',$base)) continue;
        $stream=$zip->getStream($name); if(!$stream) continue;
        $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION)); $newRel=$relDir.'/'.date('Ymd_His').'_zip_'.$count.'_'.bin2hex(random_bytes(3)).'.'.$ext; $out=@fopen(__DIR__.'/'.$newRel,'wb'); if(!$out){ if(is_resource($stream)) fclose($stream); continue; }
        $bytes=0; while(!feof($stream)){ $buf=fread($stream,8192); $bytes+=strlen($buf); fwrite($out,$buf); }
        fclose($out); fclose($stream); if($bytes<=0){ @unlink(__DIR__.'/'.$newRel); continue; }
        ds_db()->prepare('INSERT INTO datasheet_files(naming_model_id,model_no,file_type,file_title,original_name,file_path,mime_type,size_bytes,visibility,note,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($modelId,$model,$type,$title!==''?$title:$base,$base,$newRel,'image/'.$ext,$bytes,$visibility,trim($note.' 从压缩包解压预览：'.$zipFile['name']),ds_username()));
        $saved[]=array('name'=>$base,'path'=>$newRel,'size'=>$bytes,'url'=>ds_asset_url($newRel)); $count++;
    }
    $zip->close();
    return array('saved'=>$saved,'message'=>'压缩包已解压图片 '.$count.' 张，可预览、可搜索、可配对。');
}


function ds_safe_name(string $s): string { $s=preg_replace('/[^A-Za-z0-9\-_.一-龥]+/u','_',$s); $s=trim($s,'_'); return $s!==''?$s:'file'; }
function ds_upload_rel_dir(string $sub): string {
    $parts=array_values(array_filter(array_map(function($p){ return ds_safe_name($p); }, explode('/', str_replace('\\','/',$sub))), function($p){ return $p!=='' && $p!=='.' && $p!=='..'; }));
    $rel=DS_UPLOAD_ROOT.'/'.implode('/',$parts).'/'.date('Ym');
    $dir=__DIR__.'/'.$rel;
    if(!is_dir($dir)){
        $old=umask(0002);
        $ok=@mkdir($dir,0775,true);
        $err=error_get_last();
        umask($old);
        if(!$ok && !is_dir($dir)) throw new RuntimeException('上传目录创建失败：'.$rel.(!empty($err['message'])?'（'.$err['message'].'）':''));
    }
    if(!is_writable($dir)) throw new RuntimeException('上传目录不可写：'.$rel);
    return $rel;
}
function ds_local_path(string $rel): string { $rel=trim(str_replace('\\','/',$rel)); if($rel==='' || preg_match('#^https?://#i',$rel)) return ''; $full=realpath(__DIR__.'/'.$rel); $base=realpath(__DIR__.'/'.DS_UPLOAD_ROOT); if(!$full || !$base || strpos($full,$base)!==0) return ''; return $full; }
function ds_upload_one_file(array $file,string $sub='files'): array { if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败'); $name=(string)($file['name']??'file'); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION)); if($ext==='') $ext='dat'; $rel=ds_upload_rel_dir($sub).'/'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext; if(!@move_uploaded_file((string)$file['tmp_name'],__DIR__.'/'.$rel)) throw new RuntimeException('保存上传文件失败'); return array('path'=>$rel,'name'=>$name,'mime'=>(string)($file['type']??''),'size'=>(int)($file['size']??0)); }

function ds_material_group(string $txt): string { $s=mb_strtolower($txt,'UTF-8'); if(preg_match('/芯片|光源|灯珠|cob|smd|led|cree|osram|bridgelux|citizen/u',$s)) return '芯片'; if(preg_match('/光学|透镜|反光|光杯|lens|optic|reflector/u',$s)) return '光学'; if(preg_match('/电源|驱动|driver|dali|triac|0-10/u',$s)) return '电源'; if(preg_match('/配件|附件|蜂巢|蜂窝|四叶|格栅|防眩|accessor|honeycomb|louver|shutter/u',$s)) return '配件'; if(preg_match('/型材|外壳|散热|铝|profile|housing/u',$s)) return '结构'; if(preg_match('/包装|纸箱|carton|box/u',$s)) return '包装'; return '其它'; }
function ds_bom_materials(array $f=array()): array { if(!ds_table_exists('bom_materials')) return array('rows'=>array(),'total'=>0); $cols=ds_cols('bom_materials'); $select=array(); foreach(array('id','category','brand','name','model','spec','price','unit','supplier','keyword','image','is_active','updated_at') as $c){ if(in_array($c,$cols,true)) $select[]=$c; } if(!$select) $select=$cols; $where=array(); $args=array(); if(in_array('is_active',$cols,true)) $where[]='(is_active=1 OR is_active IS NULL)'; $kw=ds_s($f['kw']??''); if($kw!==''){ $or=array(); foreach(array('category','brand','name','model','spec','supplier','keyword') as $c){ if(in_array($c,$cols,true)){ $or[]="COALESCE(`{$c}`,'') LIKE ?"; $args[]='%'.$kw.'%'; } } if($or) $where[]='('.implode(' OR ',$or).')'; } $group=ds_s($f['group']??''); $limit=max(20,min(300,(int)($f['limit']??80))); $sql='SELECT '.implode(',',array_map('ds_qid',$select)).' FROM bom_materials'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.(in_array('updated_at',$cols,true)?'updated_at DESC,':'').'id DESC LIMIT '.$limit; $st=ds_db()->prepare($sql); $st->execute($args); $rows=$st->fetchAll()?:array(); $out=array(); foreach($rows as $r){ $txt=implode(' ',array($r['category']??'',$r['brand']??'',$r['name']??'',$r['model']??'',$r['spec']??'')); $g=ds_material_group($txt); if($group!=='' && $group!=='全部' && $g!==$group) continue; $out[]=array('id'=>(int)($r['id']??0),'group'=>$g,'category'=>ds_s($r['category']??''),'brand'=>ds_s($r['brand']??''),'name'=>ds_s($r['name']??''),'model'=>ds_s($r['model']??''),'spec'=>ds_s($r['spec']??''),'price'=>(float)($r['price']??0),'unit'=>ds_s($r['unit']??'PCS'),'supplier'=>ds_s($r['supplier']??''),'keyword'=>ds_s($r['keyword']??''),'image'=>ds_asset_url(ds_s($r['image']??'')),'updated_at'=>ds_s($r['updated_at']??'')); } return array('rows'=>$out,'total'=>count($out)); }


function ds_photometric_images(string $modelNo): array {
    $rows=ds_files($modelNo); $out=array();
    foreach($rows as $f){
        $t=ds_s($f['file_type']??''); $path=ds_s($f['file_path']??'');
        if(preg_match('/配光|曲线|photometric|curve/i',$t) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i',$path)){
            $out[]=array('id'=>(int)($f['id']??0),'image'=>$f['url']??ds_asset_url($path),'url'=>$f['url']??ds_asset_url($path),'label'=>ds_s($f['file_title']??$f['original_name']??''),'file_title'=>ds_s($f['file_title']??''),'original_name'=>ds_s($f['original_name']??''),'source'=>'upload');
        }
    }
    return array_slice($out,0,12);
}
function ds_curve_library(array $f=array()): array {
    if(!ds_table_exists('datasheet_files')) return array();
    $kw=ds_s($f['kw']??''); $where="is_deleted=0 AND file_type REGEXP '配光|曲线|photometric|curve' AND file_path REGEXP '\.(jpg|jpeg|png|webp|gif)$'"; $args=array();
    if($kw!==''){ $where.=" AND (model_no LIKE ? OR file_title LIKE ? OR original_name LIKE ? OR note LIKE ?)"; for($i=0;$i<4;$i++) $args[]='%'.$kw.'%'; }
    $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE '.$where.' ORDER BY uploaded_at DESC,id DESC LIMIT 80'); $st->execute($args); $rows=$st->fetchAll()?:array();
    foreach($rows as &$r){ $r['url']=ds_asset_url($r['file_path']??''); }
    return $rows;
}


function ds_image_file_library(array $f=array(), string $kind=''): array {
    if(!ds_table_exists('datasheet_files')) return array();
    $kw=ds_s($f['kw']??'');
    $typeRe='高清|图片|image|配件|accessor|配光|曲线|photometric|curve';
    if($kind==='高清图片') $typeRe='高清|高清|图片|image|photo|hd';
    if($kind==='配件图片') $typeRe='配件|附件|accessor|honeycomb|shutter|louver';
    if($kind==='配光曲线') $typeRe='配光|曲线|photometric|curve';
    $where="is_deleted=0 AND file_type REGEXP ? AND file_path REGEXP '\\.(jpg|jpeg|png|webp|gif)$'";
    $args=array($typeRe);
    if($kw!==''){
        $where.=" AND (model_no LIKE ? OR file_title LIKE ? OR original_name LIKE ? OR note LIKE ? OR file_type LIKE ?)";
        for($i=0;$i<5;$i++) $args[]='%'.$kw.'%';
    }
    $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE '.$where.' ORDER BY uploaded_at DESC,id DESC LIMIT 120');
    $st->execute($args);
    $rows=$st->fetchAll()?:array();
    foreach($rows as &$r){ $r['url']=ds_asset_url($r['file_path']??''); }
    return $rows;
}
function ds_manual_accessory_images(string $modelNo): array {
    $rows=ds_files($modelNo); $out=array();
    foreach($rows as $f){
        $t=ds_s($f['file_type']??''); $path=ds_s($f['file_path']??'');
        if(preg_match('/配件|附件|accessor|honeycomb|shutter|louver/i',$t) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i',$path)){
            $title=ds_s($f['file_title']??'') ?: ds_s($f['original_name']??'Accessory');
            $out[]=array('id'=>(int)($f['id']??0),'image_url'=>$f['url']??ds_asset_url($path),'name_en'=>$title,'name_cn'=>$title,'model_no'=>ds_s($f['note']??''),'source'=>'upload','file_title'=>$title,'original_name'=>ds_s($f['original_name']??''));
        }
    }
    return array_slice($out,0,12);
}
function ds_highres_images(string $modelNo): array {
    $rows=ds_files($modelNo); $out=array();
    foreach($rows as $f){
        $t=ds_s($f['file_type']??''); $path=ds_s($f['file_path']??'');
        if(preg_match('/高清|高清|图片|image|photo|hd/i',$t) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i',$path)){
            $out[]=array('id'=>(int)($f['id']??0),'url'=>$f['url']??ds_asset_url($path),'file_title'=>ds_s($f['file_title']??''),'original_name'=>ds_s($f['original_name']??''),'uploaded_at'=>ds_s($f['uploaded_at']??''));
        }
    }
    return array_slice($out,0,24);
}

function ds_product_accessories(string $modelNo): array { $st=ds_db()->prepare("SELECT pa.*,a.* FROM datasheet_product_accessories pa JOIN datasheet_accessories a ON a.id=pa.accessory_id WHERE pa.model_no=? AND pa.enabled=1 AND a.is_active=1 ORDER BY pa.sort_order ASC, pa.id ASC"); $st->execute(array($modelNo)); $rows=$st->fetchAll()?:array(); foreach($rows as &$r){ $r['image_url']=ds_asset_url($r['image_path']??''); } return $rows; }
function ds_product_configs(string $modelNo,string $type=''): array { $where='model_no=? AND enabled=1'; $args=array($modelNo); if($type!==''){ $where.=' AND config_type=?'; $args[]=$type; } $st=ds_db()->prepare('SELECT * FROM datasheet_product_configs WHERE '.$where.' ORDER BY sort_order ASC,id ASC'); $st->execute($args); $rows=$st->fetchAll()?:array(); foreach($rows as &$r){ $m=json_decode((string)($r['material_json']??''),true); $r['material']=is_array($m)?$m:array(); if(!empty($r['material']['image'])) $r['material']['image']=ds_asset_url($r['material']['image']); } return $rows; }
function ds_accessory_list(array $f=array()): array { $where='is_active=1'; $args=array(); $type=ds_s($f['type']??''); if($type!==''){ $where.=' AND accessory_type=?'; $args[]=$type; } $kw=ds_s($f['kw']??''); if($kw!==''){ $where.=' AND (name_cn LIKE ? OR name_en LIKE ? OR model_no LIKE ? OR brand LIKE ? OR category LIKE ?)'; for($i=0;$i<5;$i++) $args[]='%'.$kw.'%'; } $st=ds_db()->prepare('SELECT * FROM datasheet_accessories WHERE '.$where.' ORDER BY updated_at DESC,id DESC LIMIT 300'); $st->execute($args); $rows=$st->fetchAll()?:array(); foreach($rows as &$r){ $r['image_url']=ds_asset_url($r['image_path']??''); } return $rows; }
function ds_product_params(array $p): array { $ov=$p['override']??array(); $wp=$p['website_params']??array(); $base=array('Product family'=>$p['series']??'','Model'=>$p['model_no']??'','Dimensions'=>$p['dimension_text']??''); foreach((array)$wp as $k=>$v){ if(ds_s($v)!=='') $base[$k]=$v; } $map=array('Product family'=>'product_family','Model'=>'model_display','Dimensions'=>'dimensions','Cut-out'=>'cutout','Power'=>'power','Luminous flux'=>'luminous_flux','Efficacy'=>'efficacy','Voltage'=>'voltage','CCT'=>'cct','CRI'=>'cri','Beam angle'=>'beam_angle','IP rating'=>'ip_rating','Finish'=>'finish','Mounting'=>'mounting','Dimming'=>'dimming','Material'=>'material'); foreach($map as $label=>$key){ $v=ds_s($ov[$key]??''); if($v!=='') $base[$label]=$v; elseif(!isset($base[$label])) $base[$label]=''; } return array_filter($base,fn($v)=>ds_s($v)!==''); }

function ds_create_snapshot(string $modelNo,int $modelId=0,string $customerName='',string $customerEmail='',string $note='',string $trigger='manual'): int {
    ds_ensure_tables();
    $p=ds_product_detail_fast($modelId,$modelNo,true);
    $payload=array('version'=>DS_VERSION,'trigger'=>$trigger,'product'=>$p,'params'=>ds_product_params($p),'files'=>$p['files']??array(),'photometric_images'=>$p['photometric_images']??array(),'manual_accessories'=>$p['manual_accessories']??array(),'highres_images'=>$p['highres_images']??array(),'accessories'=>$p['accessories']??array(),'website_accessories'=>$p['website_accessories']??array(),'website_photometric_images'=>$p['website_photometric_images']??array(),'configs'=>$p['configs']??array(),'created_at'=>ds_now(),'created_by'=>ds_username());
    $title=($customerName!==''?($customerName.' · '):'').$modelNo.' 资料快照';
    $st=ds_db()->prepare('INSERT INTO datasheet_snapshots(naming_model_id,model_no,snapshot_title,customer_name,customer_email,send_note,snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute(array($modelId,$modelNo,$title,$customerName,$customerEmail,$note,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ds_username()));
    return (int)ds_db()->lastInsertId();
}
function ds_snapshots(string $modelNo): array {
    if(!ds_table_exists('datasheet_snapshots')) return array();
    $st=ds_db()->prepare('SELECT id,naming_model_id,model_no,snapshot_title,customer_name,customer_email,send_note,created_by,created_at FROM datasheet_snapshots WHERE model_no=? ORDER BY created_at DESC,id DESC LIMIT 80');
    $st->execute(array($modelNo)); return $st->fetchAll()?:array();
}


function ds_setting(string $key, $default=null) {
    ds_ensure_tables();
    try { $st=ds_db()->prepare('SELECT v FROM datasheet_settings WHERE k=? LIMIT 1'); $st->execute(array($key)); $v=$st->fetchColumn(); if($v===false||$v===null||$v==='') return $default; $j=json_decode((string)$v,true); return is_array($j)?$j:$v; } catch(Throwable $e) { return $default; }
}
function ds_default_settings(): array {
    return array(
        'ui'=>array('font_scale'=>'0.88','density'=>'compact','theme'=>'blue','cat_card_width'=>'190','product_list_width'=>'360','detail_width'=>'520','pdf_width'=>'720','pdf_height'=>'820','home_view'=>'pro','list_density'=>'compact','show_online_top'=>'1'),
        'category_order'=>array(),
        'header'=>array('title'=>'资料生成系统','subtitle'=>'Datasheet Center · PDF预览 / 批量PDF / IES / 高清图 / 公共图库','company'=>'Artdon Lighting Limited','custom_header_enabled'=>'1','custom_header_html'=>'','pdf_header_enabled'=>'0','pdf_header_title'=>'Artdon Lighting Limited','pdf_header_subtitle'=>'Architectural Lighting Datasheet','pdf_footer_text'=>'Artdon Lighting Limited'),
        'watermark'=>array('enabled'=>0,'text'=>'Artdon Lighting','opacity'=>'0.08','position'=>'center')
    );
}
function ds_settings_all(): array {
    $d=ds_default_settings();
    $saved=ds_setting('datasheet_settings_v211',array());
    if(is_array($saved)){
        foreach($saved as $k=>$v){ if(is_array($v)&&isset($d[$k])&&is_array($d[$k])) $d[$k]=array_replace($d[$k],$v); else $d[$k]=$v; }
    }
    return $d;
}
function ds_save_settings(array $data): array {
    ds_require_perm('manage_settings','管理资料系统设置');
    $cur=ds_settings_all();
    foreach(array('ui','header','watermark') as $k){ if(isset($data[$k])&&is_array($data[$k])) $cur[$k]=array_replace((array)($cur[$k]??array()),$data[$k]); }
    if(isset($data['category_order'])){
        if(is_string($data['category_order'])) $cur['category_order']=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/', $data['category_order']))));
        elseif(is_array($data['category_order'])) $cur['category_order']=array_values(array_filter(array_map('strval',$data['category_order'])));
    }
    ds_db()->prepare("INSERT INTO datasheet_settings(k,v,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_by=VALUES(updated_by),updated_at=NOW()")
        ->execute(array('datasheet_settings_v211',json_encode($cur,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ds_username()));
    ds_log('settings.save','保存资料系统设置','success','',0,'settings','datasheet_settings_v211','系统设置',$cur);
    return $cur;
}
function ds_snapshots_all(array $f=array()): array {
    ds_ensure_tables(); $where='1=1'; $args=array();
    $kw=ds_s($f['kw']??''); if($kw!==''){ $where.=' AND (model_no LIKE ? OR snapshot_title LIKE ? OR customer_name LIKE ? OR customer_email LIKE ? OR send_note LIKE ?)'; for($i=0;$i<5;$i++) $args[]='%'.$kw.'%'; }
    $model=ds_s($f['model_no']??''); if($model!==''){ $where.=' AND model_no=?'; $args[]=$model; }
    $limit=(int)($f['limit']??300); if($limit<20)$limit=20; if($limit>1000)$limit=1000;
    $st=ds_db()->prepare('SELECT id,naming_model_id,model_no,snapshot_title,customer_name,customer_email,send_note,created_by,created_at FROM datasheet_snapshots WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT '.$limit);
    $st->execute($args); return $st->fetchAll()?:array();
}
function ds_snapshot_detail(int $id): array {
    ds_ensure_tables(); $st=ds_db()->prepare('SELECT * FROM datasheet_snapshots WHERE id=? LIMIT 1'); $st->execute(array($id)); $r=$st->fetch(); if(!$r) throw new RuntimeException('快照不存在。'); $r['snapshot']=json_decode((string)($r['snapshot_json']??''),true) ?: array(); return $r;
}

function ds_record_generated(string $modelNo,int $modelId,string $type,string $title,string $path,string $format,string $note=''): void { ds_db()->prepare('INSERT INTO datasheet_records(naming_model_id,model_no,record_type,file_title,file_path,file_format,note,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute(array($modelId,$modelNo,$type,$title,$path,$format,$note,ds_username())); }
function ds_find_cmd(array $names): string { foreach($names as $n){ $p=trim((string)@shell_exec('command -v '.escapeshellarg($n).' 2>/dev/null')); if($p!=='') return $p; } return ''; }
function ds_html_to_pdf(string $htmlPath,string $pdfPath): bool { $cmd=ds_find_cmd(array('chromium','chromium-browser','google-chrome','google-chrome-stable')); if($cmd==='') return false; $shell=escapeshellarg($cmd).' --headless --disable-gpu --no-sandbox --print-to-pdf='.escapeshellarg($pdfPath).' '.escapeshellarg('file://'.$htmlPath).' 2>/dev/null'; @shell_exec($shell); return is_file($pdfPath) && filesize($pdfPath)>1000; }
?>
