<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_api('plm');
/* ARTDON_SSO_GATE_V2_END */

/* Artdon PLM 8.4 API - 文件/测试/项目卡片优化 */
ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once __DIR__ . '/plm_config.php';
require_once __DIR__ . '/plm_auth.php';

function plm_json($arr) {
    if (ob_get_length()) @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        if (ob_get_length()) @ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok'=>false,'error'=>'PLM接口致命错误：'.$e['message'].' line '.$e['line']), JSON_UNESCAPED_UNICODE);
    }
});
function plm_input() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $_POST;
}
function plm_val($names, $default = '') {
    foreach ($names as $n) {
        if (defined($n) && constant($n) !== '') return constant($n);
        if (isset($GLOBALS[$n]) && $GLOBALS[$n] !== '') return $GLOBALS[$n];
    }
    return $default;
}
function plm_pdo() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (function_exists('artdon_sso_db')) {
        $x = artdon_sso_db();
        if ($x instanceof PDO) { $pdo = $x; return $pdo; }
    }
    foreach (array('db','get_pdo','get_db','connect_db','database') as $fn) {
        if (function_exists($fn)) {
            $x = $fn();
            if ($x instanceof PDO) { $pdo = $x; return $pdo; }
        }
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) { $pdo = $GLOBALS['pdo']; return $pdo; }
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) { $pdo = $GLOBALS['db']; return $pdo; }

    $host = plm_val(array('DB_HOST','DB_HOSTNAME','MYSQL_HOST','db_host','host','hostname','mysql_host','PLM_DB_HOST'), PLM_DB_HOST);
    $name = plm_val(array('DB_NAME','DB_DATABASE','MYSQL_DATABASE','db_name','dbname','database','mysql_database','PLM_DB_NAME'), PLM_DB_NAME);
    $user = plm_val(array('DB_USER','DB_USERNAME','MYSQL_USER','db_user','user','username','mysql_user','PLM_DB_USER'), PLM_DB_USER);
    $pass = plm_val(array('DB_PASS','DB_PASSWORD','MYSQL_PASS','MYSQL_PASSWORD','db_pass','db_password','pass','password','pwd','mysql_pass','mysql_password','PLM_DB_PASS'), PLM_DB_PASS);
    if (strpos($name, '你的') !== false || strpos($user, '你的') !== false) {
        throw new Exception('请先修改 plm_config.php 里的数据库名称、用户名、密码，或确认根目录 config.php 可用。');
    }
    $charset = defined('PLM_DB_CHARSET') ? PLM_DB_CHARSET : 'utf8mb4';
    $dsn = 'mysql:host='.$host.';dbname='.$name.';charset='.$charset;
    $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    return $pdo;
}
function plm_now(){ return date('Y-m-d H:i:s'); }
function plm_table_exists($t) { $st = plm_pdo()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute(array($t)); return (int)$st->fetchColumn() > 0; }
function plm_cols($t) { if(!isset($GLOBALS['plm_cols_cache'])) $GLOBALS['plm_cols_cache']=array(); if(isset($GLOBALS['plm_cols_cache'][$t])) return $GLOBALS['plm_cols_cache'][$t]; if(!plm_table_exists($t)) return array(); $rs=plm_pdo()->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(); $GLOBALS['plm_cols_cache'][$t]=array_column($rs,'Field'); return $GLOBALS['plm_cols_cache'][$t]; }
function plm_has_col($t,$c){ return in_array($c, plm_cols($t), true); }
function plm_ensure_col($t,$c,$def) { if (plm_table_exists($t) && !plm_has_col($t,$c)) { plm_pdo()->exec('ALTER TABLE `'.$t.'` ADD COLUMN `'.$c.'` '.$def); if(isset($GLOBALS['plm_cols_cache'][$t])) unset($GLOBALS['plm_cols_cache'][$t]); } }

function plm_index_exists($t,$idx) {
    if (!plm_table_exists($t)) return false;
    try { $st=plm_pdo()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'); $st->execute(array($t,$idx)); return (int)$st->fetchColumn() > 0; }
    catch(Exception $e){ return false; }
}
function plm_drop_index_if_exists($t,$idx) {
    if (!plm_table_exists($t)) return false;
    if (!plm_index_exists($t,$idx)) return false;
    try { plm_pdo()->exec('ALTER TABLE `'.$t.'` DROP INDEX `'.$idx.'`'); return true; }
    catch(Exception $e){ return false; }
}
function plm_fix_flow_unique_indexes() {
    // 7.8.1：旧版项目级流程曾建立 UNIQUE(project_id, step_name)，会阻止同一项目下不同样品拥有同名步骤。
    // 样品级流程必须允许：项目A-样品1-温升测试、项目A-样品2-温升测试 同时存在。
    foreach (array('plm_flow_steps','plm_steps') as $t) {
        if (!plm_table_exists($t)) continue;
        foreach (array('uk_project_step','uniq_project_step','project_step_unique','uk_step_project','uk_project_step_name') as $idx) {
            plm_drop_index_if_exists($t,$idx);
        }
    }
}
function plm_dyn_insert($t,$data) { $data = plm_fit_data($t,$data); $cols = array_values(array_intersect(array_keys($data), plm_cols($t))); if(!$cols) return 0; $sql='INSERT INTO `'.$t.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')'; $st=plm_pdo()->prepare($sql); $vals=array(); foreach($cols as $c) $vals[]=$data[$c]; $st->execute($vals); return (int)plm_pdo()->lastInsertId(); }
function plm_dyn_update($t,$data,$where,$params=array()) { $data = plm_fit_data($t,$data); $cols = array_values(array_intersect(array_keys($data), plm_cols($t))); if(!$cols) return false; $sets=array(); $vals=array(); foreach($cols as $c){ $sets[]='`'.$c.'`=?'; $vals[]=$data[$c]; } $sql='UPDATE `'.$t.'` SET '.implode(',',$sets).' WHERE '.$where; $st=plm_pdo()->prepare($sql); return $st->execute(array_merge($vals,$params)); }
function plm_all($t,$order='id DESC') { if(!plm_table_exists($t)) return array(); return plm_pdo()->query('SELECT * FROM `'.$t.'` ORDER BY '.$order)->fetchAll(); }
function plm_row($sql,$params=array()) { $st=plm_pdo()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ? $r : null; }
function plm_rows($sql,$params=array()) { $st=plm_pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function plm_first_col($t,$arr,$fallback=null){ foreach($arr as $c) if(plm_has_col($t,$c)) return $c; return $fallback; }
function plm_pick_col($cols,$arr,$fallback=null){ foreach($arr as $c) if(in_array($c,$cols,true)) return $c; return $fallback; }

function plm_col_info($t) {
    static $cache = array();
    if (isset($cache[$t])) return $cache[$t];
    $cache[$t] = array();
    if (!plm_table_exists($t)) return $cache[$t];
    try {
        $rs = plm_pdo()->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll();
        foreach ($rs as $r) {
            if (isset($r['Field'])) $cache[$t][$r['Field']] = strtolower((string)($r['Type'] ?? ''));
        }
    } catch (Exception $e) {}
    return $cache[$t];
}
function plm_str_limit_from_type($type) {
    if (preg_match('/^(var)?char\((\d+)\)/i', $type, $m)) return (int)$m[2];
    return 0;
}
function plm_clip_text($v, $max) {
    if ($v === null || $max <= 0 || !is_string($v)) return $v;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($v, 'UTF-8') > $max ? mb_substr($v, 0, $max, 'UTF-8') : $v;
    }
    return strlen($v) > $max ? substr($v, 0, $max) : $v;
}
function plm_fit_data($t, $data) {
    $cols = plm_cols($t);
    if (!$cols || !is_array($data)) return is_array($data) ? $data : array();
    $info = plm_col_info($t);
    $out = array();
    foreach ($data as $k=>$v) {
        if (!in_array($k, $cols, true)) continue;
        $type = $info[$k] ?? '';
        $lim = plm_str_limit_from_type($type);
        if ($lim > 0) $v = plm_clip_text((string)$v, $lim);
        $out[$k] = $v;
    }
    return $out;
}

function plm_schema() {
    $db = plm_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS plm_projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_no VARCHAR(80) DEFAULT '',
        name VARCHAR(255) NOT NULL DEFAULT '',
        customer VARCHAR(255) DEFAULT '',
        series VARCHAR(255) DEFAULT '',
        model VARCHAR(255) DEFAULT '',
        product_type VARCHAR(120) DEFAULT '',
        source VARCHAR(80) DEFAULT '工厂自行开发',
        engineer VARCHAR(100) DEFAULT '',
        priority VARCHAR(50) DEFAULT 'P2 正常',
        status VARCHAR(50) DEFAULT '开发中',
        stage VARCHAR(80) DEFAULT '',
        image_path VARCHAR(500) DEFAULT '',
        start_date DATE NULL,
        due_date DATE NULL,
        amount DECIMAL(12,2) DEFAULT 0,
        currency VARCHAR(20) DEFAULT 'USD',
        crm_customer_id INT DEFAULT 0,
        bom_project_id INT DEFAULT 0,
        quote_id INT DEFAULT 0,
        note TEXT NULL,
        is_active TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_name(name), KEY idx_customer(customer), KEY idx_status(status), KEY idx_model(model)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pcols = array(
        'project_no'=>"VARCHAR(80) DEFAULT ''", 'name'=>"VARCHAR(255) NOT NULL DEFAULT ''", 'customer'=>"VARCHAR(255) DEFAULT ''", 'series'=>"VARCHAR(255) DEFAULT ''", 'model'=>"VARCHAR(255) DEFAULT ''", 'product_type'=>"VARCHAR(120) DEFAULT ''", 'source'=>"VARCHAR(80) DEFAULT '工厂自行开发'", 'engineer'=>"VARCHAR(100) DEFAULT ''", 'priority'=>"VARCHAR(50) DEFAULT 'P2 正常'", 'status'=>"VARCHAR(50) DEFAULT '开发中'", 'stage'=>"VARCHAR(80) DEFAULT ''", 'image_path'=>"VARCHAR(500) DEFAULT ''", 'start_date'=>"DATE NULL", 'due_date'=>"DATE NULL", 'amount'=>"DECIMAL(12,2) DEFAULT 0", 'currency'=>"VARCHAR(20) DEFAULT 'USD'", 'crm_customer_id'=>"INT DEFAULT 0", 'bom_project_id'=>"INT DEFAULT 0", 'quote_id'=>"INT DEFAULT 0", 'note'=>"TEXT NULL", 'is_active'=>"TINYINT DEFAULT 1", 'created_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP", 'updated_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP"
    );
    foreach($pcols as $c=>$d) plm_ensure_col('plm_projects',$c,$d);

    $db->exec("CREATE TABLE IF NOT EXISTS plm_models (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        name VARCHAR(255) DEFAULT '样品01',
        model VARCHAR(255) DEFAULT '',
        sample_no VARCHAR(120) DEFAULT '',
        power VARCHAR(80) DEFAULT '',
        beam VARCHAR(80) DEFAULT '',
        cct VARCHAR(80) DEFAULT '',
        cri VARCHAR(80) DEFAULT '',
        qty INT DEFAULT 1,
        status VARCHAR(50) DEFAULT '开发中',
        image_path VARCHAR(500) DEFAULT '',
        led VARCHAR(255) DEFAULT '',
        driver VARCHAR(255) DEFAULT '',
        optic VARCHAR(255) DEFAULT '',
        connector VARCHAR(255) DEFAULT '',
        shell VARCHAR(255) DEFAULT '',
        finish VARCHAR(255) DEFAULT '',
        package_info VARCHAR(255) DEFAULT '',
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','name'=>"VARCHAR(255) DEFAULT '样品01'",'model'=>"VARCHAR(255) DEFAULT ''",'sample_no'=>"VARCHAR(120) DEFAULT ''",'power'=>"VARCHAR(80) DEFAULT ''",'beam'=>"VARCHAR(80) DEFAULT ''",'cct'=>"VARCHAR(80) DEFAULT ''",'cri'=>"VARCHAR(80) DEFAULT ''",'qty'=>'INT DEFAULT 1','status'=>"VARCHAR(50) DEFAULT '开发中'",'image_path'=>"VARCHAR(500) DEFAULT ''",'led'=>"VARCHAR(255) DEFAULT ''",'driver'=>"VARCHAR(255) DEFAULT ''",'optic'=>"VARCHAR(255) DEFAULT ''",'connector'=>"VARCHAR(255) DEFAULT ''",'shell'=>"VARCHAR(255) DEFAULT ''",'finish'=>"VARCHAR(255) DEFAULT ''",'package_info'=>"VARCHAR(255) DEFAULT ''",'note'=>'TEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_models',$c,$d);
    // PLM 8.4：样品评审、客户确认、版本冻结字段。只加 PLM 自己的样品字段，不动 BOM / 报价 / CRM 原表。
    foreach(array('review_status'=>"VARCHAR(80) DEFAULT '待评审'",'reviewer'=>"VARCHAR(100) DEFAULT ''",'review_note'=>'TEXT NULL','score_appearance'=>'DECIMAL(5,2) DEFAULT 0','score_structure'=>'DECIMAL(5,2) DEFAULT 0','score_optic'=>'DECIMAL(5,2) DEFAULT 0','score_temp'=>'DECIMAL(5,2) DEFAULT 0','score_cost'=>'DECIMAL(5,2) DEFAULT 0','score_process'=>'DECIMAL(5,2) DEFAULT 0','score_customer'=>'DECIMAL(5,2) DEFAULT 0','score_total'=>'DECIMAL(5,2) DEFAULT 0','sent_date'=>'DATE NULL','tracking_no'=>"VARCHAR(120) DEFAULT ''",'feedback_date'=>'DATE NULL','customer_feedback'=>'TEXT NULL','customer_result'=>"VARCHAR(80) DEFAULT ''",'is_recommended'=>'TINYINT DEFAULT 0','is_frozen'=>'TINYINT DEFAULT 0','frozen_at'=>'DATETIME NULL','frozen_by'=>"VARCHAR(100) DEFAULT ''",'version_no'=>"VARCHAR(50) DEFAULT 'V1'",'frozen_snapshot'=>'MEDIUMTEXT NULL') as $c=>$d) plm_ensure_col('plm_models',$c,$d);

    $db->exec("CREATE TABLE IF NOT EXISTS plm_flow_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        step_name VARCHAR(120) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        status VARCHAR(50) NOT NULL DEFAULT '未开始',
        operator VARCHAR(100) DEFAULT '',
        planned_start DATETIME NULL,
        planned_end DATETIME NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        actual_hours DECIMAL(10,2) DEFAULT 0,
        rework_count INT DEFAULT 0,
        last_reason TEXT NULL,
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_order(project_id,model_id,sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','step_name'=>"VARCHAR(120) NOT NULL DEFAULT ''",'sort_order'=>'INT NOT NULL DEFAULT 0','status'=>"VARCHAR(50) NOT NULL DEFAULT '未开始'",'operator'=>"VARCHAR(100) DEFAULT ''",'planned_start'=>'DATETIME NULL','planned_end'=>'DATETIME NULL','started_at'=>'DATETIME NULL','finished_at'=>'DATETIME NULL','actual_hours'=>'DECIMAL(10,2) DEFAULT 0','rework_count'=>'INT DEFAULT 0','last_reason'=>'TEXT NULL','note'=>'TEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_flow_steps',$c,$d);
    plm_fix_flow_unique_indexes();

    $db->exec("CREATE TABLE IF NOT EXISTS plm_flow_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        step_id INT NOT NULL DEFAULT 0,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        action VARCHAR(50) DEFAULT '',
        reason TEXT NULL,
        operator VARCHAR(100) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_step(step_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('step_id'=>'INT NOT NULL DEFAULT 0','project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','action'=>"VARCHAR(50) DEFAULT ''",'reason'=>'TEXT NULL','operator'=>"VARCHAR(100) DEFAULT ''",'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_flow_logs',$c,$d);

    $db->exec("CREATE TABLE IF NOT EXISTS plm_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        test_type VARCHAR(60) DEFAULT '温升',
        title VARCHAR(255) DEFAULT '',
        status VARCHAR(50) DEFAULT '待测',
        result VARCHAR(50) DEFAULT '',
        tester VARCHAR(100) DEFAULT '',
        test_date DATE NULL,
        ambient_temp DECIMAL(10,2) NULL,
        led_temp DECIMAL(10,2) NULL,
        driver_temp DECIMAL(10,2) NULL,
        shell_temp DECIMAL(10,2) NULL,
        max_temp DECIMAL(10,2) NULL,
        ies_angle VARCHAR(80) DEFAULT '',
        ies_lumen VARCHAR(80) DEFAULT '',
        ies_power VARCHAR(80) DEFAULT '',
        ies_efficacy VARCHAR(80) DEFAULT '',
        ip_level VARCHAR(80) DEFAULT '',
        aging_hours VARCHAR(80) DEFAULT '',
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_type(test_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','test_type'=>"VARCHAR(60) DEFAULT '温升'",'title'=>"VARCHAR(255) DEFAULT ''",'status'=>"VARCHAR(50) DEFAULT '待测'",'result'=>"VARCHAR(50) DEFAULT ''",'tester'=>"VARCHAR(100) DEFAULT ''",'test_date'=>'DATE NULL','ambient_temp'=>'DECIMAL(10,2) NULL','led_temp'=>'DECIMAL(10,2) NULL','driver_temp'=>'DECIMAL(10,2) NULL','shell_temp'=>'DECIMAL(10,2) NULL','max_temp'=>'DECIMAL(10,2) NULL','ies_angle'=>"VARCHAR(80) DEFAULT ''",'ies_lumen'=>"VARCHAR(80) DEFAULT ''",'ies_power'=>"VARCHAR(80) DEFAULT ''",'ies_efficacy'=>"VARCHAR(80) DEFAULT ''",'ip_level'=>"VARCHAR(80) DEFAULT ''",'aging_hours'=>"VARCHAR(80) DEFAULT ''",'note'=>'TEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_tests',$c,$d);

    $db->exec("CREATE TABLE IF NOT EXISTS plm_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        test_id INT NOT NULL DEFAULT 0,
        category VARCHAR(80) DEFAULT '其它',
        title VARCHAR(255) DEFAULT '',
        file_name VARCHAR(255) DEFAULT '',
        original_name VARCHAR(255) DEFAULT '',
        file_path VARCHAR(500) DEFAULT '',
        mime_type VARCHAR(120) DEFAULT '',
        file_size INT DEFAULT 0,
        customer_visible TINYINT DEFAULT 1,
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_test(test_id), KEY idx_category(category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','test_id'=>'INT NOT NULL DEFAULT 0','category'=>"VARCHAR(80) DEFAULT '其它'",'title'=>"VARCHAR(255) DEFAULT ''",'file_name'=>"VARCHAR(255) DEFAULT ''",'original_name'=>"VARCHAR(255) DEFAULT ''",'file_path'=>"VARCHAR(500) DEFAULT ''",'mime_type'=>"VARCHAR(120) DEFAULT ''",'file_size'=>'INT DEFAULT 0','customer_visible'=>'TINYINT DEFAULT 1','note'=>'TEXT NULL','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_files',$c,$d);
    $db->exec("CREATE TABLE IF NOT EXISTS plm_doc_packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_code VARCHAR(80) NOT NULL DEFAULT '',
        project_id INT NOT NULL DEFAULT 0,
        title VARCHAR(255) DEFAULT '',
        customer VARCHAR(255) DEFAULT '',
        note TEXT NULL,
        selected_json MEDIUMTEXT NULL,
        share_text MEDIUMTEXT NULL,
        status VARCHAR(50) DEFAULT '已生成',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_package_code(package_code),
        KEY idx_project(project_id), KEY idx_customer(customer)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('package_code'=>"VARCHAR(80) NOT NULL DEFAULT ''",'project_id'=>'INT NOT NULL DEFAULT 0','title'=>"VARCHAR(255) DEFAULT ''",'customer'=>"VARCHAR(255) DEFAULT ''",'note'=>'TEXT NULL','selected_json'=>'MEDIUMTEXT NULL','share_text'=>'MEDIUMTEXT NULL','status'=>"VARCHAR(50) DEFAULT '已生成'",'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_doc_packages',$c,$d);
    $db->exec("CREATE TABLE IF NOT EXISTS plm_doc_package_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        package_id INT NOT NULL DEFAULT 0,
        project_id INT NOT NULL DEFAULT 0,
        file_id INT NOT NULL DEFAULT 0,
        test_id INT NOT NULL DEFAULT 0,
        source VARCHAR(80) DEFAULT '',
        category VARCHAR(80) DEFAULT '',
        title VARCHAR(255) DEFAULT '',
        file_path VARCHAR(800) DEFAULT '',
        customer_visible TINYINT DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_package(package_id), KEY idx_project(project_id), KEY idx_file(file_id), KEY idx_test(test_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('package_id'=>'INT NOT NULL DEFAULT 0','project_id'=>'INT NOT NULL DEFAULT 0','file_id'=>'INT NOT NULL DEFAULT 0','test_id'=>'INT NOT NULL DEFAULT 0','source'=>"VARCHAR(80) DEFAULT ''",'category'=>"VARCHAR(80) DEFAULT ''",'title'=>"VARCHAR(255) DEFAULT ''",'file_path'=>"VARCHAR(800) DEFAULT ''",'customer_visible'=>'TINYINT DEFAULT 1','sort_order'=>'INT DEFAULT 0','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_doc_package_items',$c,$d);

    // PLM 8.4：样品评审历史与版本冻结历史。
    $db->exec("CREATE TABLE IF NOT EXISTS plm_model_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        review_status VARCHAR(80) DEFAULT '待评审',
        reviewer VARCHAR(100) DEFAULT '',
        review_note TEXT NULL,
        score_appearance DECIMAL(5,2) DEFAULT 0,
        score_structure DECIMAL(5,2) DEFAULT 0,
        score_optic DECIMAL(5,2) DEFAULT 0,
        score_temp DECIMAL(5,2) DEFAULT 0,
        score_cost DECIMAL(5,2) DEFAULT 0,
        score_process DECIMAL(5,2) DEFAULT 0,
        score_customer DECIMAL(5,2) DEFAULT 0,
        score_total DECIMAL(5,2) DEFAULT 0,
        sent_date DATE NULL,
        tracking_no VARCHAR(120) DEFAULT '',
        feedback_date DATE NULL,
        customer_result VARCHAR(80) DEFAULT '',
        customer_feedback TEXT NULL,
        is_recommended TINYINT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_status(review_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','review_status'=>"VARCHAR(80) DEFAULT '待评审'",'reviewer'=>"VARCHAR(100) DEFAULT ''",'review_note'=>'TEXT NULL','score_appearance'=>'DECIMAL(5,2) DEFAULT 0','score_structure'=>'DECIMAL(5,2) DEFAULT 0','score_optic'=>'DECIMAL(5,2) DEFAULT 0','score_temp'=>'DECIMAL(5,2) DEFAULT 0','score_cost'=>'DECIMAL(5,2) DEFAULT 0','score_process'=>'DECIMAL(5,2) DEFAULT 0','score_customer'=>'DECIMAL(5,2) DEFAULT 0','score_total'=>'DECIMAL(5,2) DEFAULT 0','sent_date'=>'DATE NULL','tracking_no'=>"VARCHAR(120) DEFAULT ''",'feedback_date'=>'DATE NULL','customer_result'=>"VARCHAR(80) DEFAULT ''",'customer_feedback'=>'TEXT NULL','is_recommended'=>'TINYINT DEFAULT 0','created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_model_reviews',$c,$d);

    $db->exec("CREATE TABLE IF NOT EXISTS plm_model_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        source_model_id INT NOT NULL DEFAULT 0,
        version_no VARCHAR(50) DEFAULT 'V1',
        title VARCHAR(255) DEFAULT '',
        note TEXT NULL,
        snapshot_json MEDIUMTEXT NULL,
        is_frozen TINYINT DEFAULT 0,
        created_by VARCHAR(100) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_source(source_model_id), KEY idx_version(version_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach(array('project_id'=>'INT NOT NULL DEFAULT 0','model_id'=>'INT NOT NULL DEFAULT 0','source_model_id'=>'INT NOT NULL DEFAULT 0','version_no'=>"VARCHAR(50) DEFAULT 'V1'",'title'=>"VARCHAR(255) DEFAULT ''",'note'=>'TEXT NULL','snapshot_json'=>'MEDIUMTEXT NULL','is_frozen'=>'TINYINT DEFAULT 0','created_by'=>"VARCHAR(100) DEFAULT ''",'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP') as $c=>$d) plm_ensure_col('plm_model_versions',$c,$d);

    $base = __DIR__ . '/uploads/plm'; if(!is_dir($base)) @mkdir($base,0777,true);
}

function plm_base_step_names(){
    return array('制定方案','3D图','CAD图','加工图','粗坯样品','CNC/五金加工','表面处理','成品组装','温升测试','IES测试','EMC测试','老化测试','客户确认','BOM/报价','量产移交');
}
function plm_default_steps($pid, $model_id=0) {
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid) return;
    plm_fix_flow_unique_indexes();
    // 7.8.2：补齐逻辑不能因为已有1个步骤就直接退出；应逐项检查，缺什么补什么。
    $arr = plm_base_step_names();
    $i=1; foreach($arr as $stepName){
        $same = plm_row('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=? AND step_name=? LIMIT 1', array($pid,$model_id,$stepName));
        if (!$same) {
            try {
                plm_dyn_insert('plm_flow_steps', array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$stepName,'sort_order'=>$i*10,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now()));
            } catch(Exception $e) {
                // 如果服务器仍残留旧唯一索引，先修复索引，再重试一次；失败则跳过该重复步骤，避免整个页面卡死。
                plm_fix_flow_unique_indexes();
                try { plm_dyn_insert('plm_flow_steps', array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$stepName,'sort_order'=>$i*10,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now())); } catch(Exception $e2) {}
            }
        }
        $i++;
    }
}
function plm_seed_model_steps($pid, $model_id, $copy_progress=false) {
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid || !$model_id) return;
    $exists = plm_row('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=? LIMIT 1', array($pid,$model_id));
    if ($exists) return;
    $legacy = plm_rows('SELECT * FROM plm_flow_steps WHERE project_id=? AND (model_id=0 OR model_id IS NULL) ORDER BY sort_order,id', array($pid));
    if ($legacy && count($legacy)) {
        foreach($legacy as $r){
            $row = array(
                'project_id'=>$pid, 'model_id'=>$model_id, 'step_name'=>$r['step_name'] ?? '', 'sort_order'=>(int)($r['sort_order'] ?? 0),
                'status'=>'未开始', 'operator'=>'', 'planned_start'=>$r['planned_start'] ?? null, 'planned_end'=>$r['planned_end'] ?? null,
                'note'=>$r['note'] ?? '', 'created_at'=>plm_now(), 'updated_at'=>plm_now()
            );
            if($copy_progress){
                foreach(array('status','operator','started_at','finished_at','actual_hours','rework_count','last_reason') as $k){ if(array_key_exists($k,$r)) $row[$k]=$r[$k]; }
            }
            $same = plm_row('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=? AND step_name=? LIMIT 1', array($pid,$model_id,$row['step_name']));
            if (!$same) {
                try { plm_dyn_insert('plm_flow_steps', $row); }
                catch(Exception $e) { plm_fix_flow_unique_indexes(); try { plm_dyn_insert('plm_flow_steps', $row); } catch(Exception $e2) {} }
            }
        }
        return;
    }
    plm_default_steps($pid, $model_id);
}
function plm_ensure_model_steps_complete($pid, $model_id) {
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid || !$model_id) return 0;
    plm_fix_flow_unique_indexes();
    $cnt = plm_row('SELECT COUNT(*) c FROM plm_flow_steps WHERE project_id=? AND model_id=?', array($pid,$model_id));
    if(!(int)($cnt['c'] ?? 0)) {
        // 没有任何样品级流程时，优先复制旧版项目级流程；没有旧流程则生成默认流程。
        plm_seed_model_steps($pid,$model_id,true);
    }
    // 再逐项补齐默认节点，避免旧数据只有部分节点导致导航图空白或不完整。
    $i=1;
    foreach(plm_base_step_names() as $stepName){
        $same = plm_row('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=? AND step_name=? LIMIT 1', array($pid,$model_id,$stepName));
        if(!$same){
            try { plm_dyn_insert('plm_flow_steps', array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$stepName,'sort_order'=>$i*10,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now())); }
            catch(Exception $e){ plm_fix_flow_unique_indexes(); try { plm_dyn_insert('plm_flow_steps', array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$stepName,'sort_order'=>$i*10,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now())); } catch(Exception $e2) {} }
        }
        $i++;
    }
    $row=plm_row('SELECT COUNT(*) c FROM plm_flow_steps WHERE project_id=? AND model_id=?', array($pid,$model_id));
    return (int)($row['c'] ?? 0);
}

function plm_route_template_steps($key){
    $key = trim((string)$key);
    $map = array(
        'standard' => plm_base_step_names(),
        'embedded' => array('制定方案','外观/尺寸确认','3D图','CAD图','加工图','粗坯样品','CNC/五金加工','表面处理','成品组装','温升测试','IES测试','客户确认','BOM/报价','量产移交'),
        'track' => array('制定方案','导轨系统适配','外径/高度确认','3D图','CAD图','加工图','粗坯样品','CNC/五金加工','表面处理','成品组装','导轨上电测试','IES测试','EMC测试','客户确认','BOM/报价','量产移交'),
        'magnetic' => array('制定方案','磁吸系统适配','磁吸安装结构确认','3D图','CAD图','加工图','样品加工','表面处理','成品组装','上电测试','温升测试','IES测试','客户确认','BOM/报价','量产移交'),
        'outdoor' => array('制定方案','结构防水方案','3D图','CAD图','加工图','样品加工','表面处理','成品组装','IP测试','盐雾/耐候确认','温升测试','IES测试','客户确认','BOM/报价','量产移交'),
        'optical' => array('光学方案','LED/透镜/反杯确认','结构方案','3D图','CAD图','光学样品加工','光斑测试','IES测试','温升测试','修正/重来','客户确认','BOM/报价','量产移交'),
        'simple' => array('制定方案','3D图','加工图','样品加工','表面处理','成品组装','测试确认','BOM/报价','量产移交'),
        'blank' => array()
    );
    return $map[$key] ?? array();
}
function plm_delete_model_steps_all($pid,$model_id){
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid || !$model_id) throw new Exception('缺少项目ID或样品ID');
    $m=plm_row('SELECT id FROM plm_models WHERE id=? AND project_id=?',array($model_id,$pid));
    if(!$m) throw new Exception('找不到当前样品');
    $ids=plm_rows('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=?',array($pid,$model_id));
    $count=count($ids);
    foreach($ids as $r){
        $sid=(int)($r['id']??0);
        if($sid) plm_pdo()->prepare('DELETE FROM plm_flow_logs WHERE step_id=?')->execute(array($sid));
    }
    plm_pdo()->prepare('DELETE FROM plm_flow_steps WHERE project_id=? AND model_id=?')->execute(array($pid,$model_id));
    plm_audit('清空样品流程','step',0,$pid,'样品ID '.$model_id.' ｜ 删除 '.$count.' 个步骤');
    return $count;
}
function plm_apply_route_steps($pid,$model_id,$names,$replace=true){
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid || !$model_id) throw new Exception('缺少项目ID或样品ID');
    $m=plm_row('SELECT id FROM plm_models WHERE id=? AND project_id=?',array($model_id,$pid));
    if(!$m) throw new Exception('找不到当前样品');
    if(!is_array($names)) $names=array();
    $clean=array();
    foreach($names as $n){
        $n=trim((string)$n);
        if($n!=='' && !in_array($n,$clean,true)) $clean[]=$n;
    }
    if($replace) plm_delete_model_steps_all($pid,$model_id);
    $row=plm_row('SELECT COALESCE(MAX(sort_order),0) v FROM plm_flow_steps WHERE project_id=? AND model_id=?',array($pid,$model_id));
    $sort=(int)($row['v']??0);
    $added=0; $skipped=0;
    foreach($clean as $name){
        if(!$replace){
            $same=plm_row('SELECT id FROM plm_flow_steps WHERE project_id=? AND model_id=? AND step_name=? LIMIT 1',array($pid,$model_id,$name));
            if($same){ $skipped++; continue; }
        }
        $sort+=10;
        try{
            plm_dyn_insert('plm_flow_steps',array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$name,'sort_order'=>$sort,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now()));
            $added++;
        }catch(Exception $e){
            plm_fix_flow_unique_indexes();
            try{ plm_dyn_insert('plm_flow_steps',array('project_id'=>$pid,'model_id'=>$model_id,'step_name'=>$name,'sort_order'=>$sort,'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now())); $added++; }
            catch(Exception $e2){ throw new Exception('写入步骤失败：'.$e2->getMessage()); }
        }
    }
    plm_audit($replace?'套用工艺路线':'追加工艺路线','step',0,$pid,'样品ID '.$model_id.' ｜ 新增 '.$added.' 个步骤'.($skipped?' ｜ 跳过重复 '.$skipped.' 个':''));
    return array('added'=>$added,'skipped'=>$skipped,'total'=>plm_flow_step_count($pid,$model_id));
}

function plm_prepare_sample_flow($pid, $model_id=0) {
    $pid=(int)$pid; $model_id=(int)$model_id;
    if(!$pid) throw new Exception('缺少项目ID');
    $p = plm_row('SELECT * FROM plm_projects WHERE id=?', array($pid));
    if(!$p) throw new Exception('找不到项目');
    if($model_id){
        $m = plm_row('SELECT * FROM plm_models WHERE id=? AND project_id=?', array($model_id,$pid));
        if(!$m) throw new Exception('找不到当前样品');
    } else {
        $m = plm_row('SELECT * FROM plm_models WHERE project_id=? ORDER BY id ASC LIMIT 1', array($pid));
        if(!$m){
            $name = trim((string)($p['model'] ?? '')) ?: trim((string)($p['name'] ?? '')) ?: '样品1';
            $data = array(
                'project_id'=>$pid,
                'name'=>$name,
                'model'=>($p['model'] ?? ''),
                'sample_no'=>$name,
                'qty'=>1,
                'status'=>'开发中',
                'note'=>'由开发导航图自动创建，用于绑定独立样品流程',
                'created_at'=>plm_now(),
                'updated_at'=>plm_now()
            );
            $model_id = plm_dyn_insert('plm_models',$data);
            $m = plm_row('SELECT * FROM plm_models WHERE id=?', array($model_id));
            plm_audit('自动创建样品流程','model',$model_id,$pid,'开发导航图自动创建样品并绑定流程');
        } else {
            $model_id=(int)$m['id'];
        }
    }
    $count = plm_ensure_model_steps_complete($pid,$model_id);
    return array('model_id'=>$model_id,'step_count'=>$count,'message'=>'当前样品流程已建立/补齐：'.$count.' 个步骤');
}
function plm_model_title($model_id){
    $model_id=(int)$model_id;
    if(!$model_id) return '项目公共资料';
    $m=plm_row('SELECT * FROM plm_models WHERE id=?',array($model_id));
    if(!$m) return '样品#'.$model_id;
    $parts=array();
    foreach(array('name','model','power','beam') as $k){ if(!empty($m[$k])) $parts[]=$m[$k]; }
    return $parts ? implode(' / ', $parts) : ('样品#'.$model_id);
}
function plm_test_step_name($type){
    $type=trim((string)$type);
    if($type==='温升') return '温升测试';
    if($type==='IES') return 'IES测试';
    if($type==='EMC') return 'EMC测试';
    if($type==='IP') return 'IP测试';
    if($type==='老化') return '老化测试';
    return $type ? ($type.'测试') : '';
}
function plm_sync_test_to_step($test_id){
    $test_id=(int)$test_id;
    if(!$test_id) return;
    $t=plm_row('SELECT * FROM plm_tests WHERE id=?',array($test_id));
    if(!$t) return;
    $pid=(int)($t['project_id']??0); $mid=(int)($t['model_id']??0);
    if(!$pid || !$mid) return; // 7.9：只联动具体样品，不再改项目级流程
    $stepName=plm_test_step_name($t['test_type']??'');
    if(!$stepName) return;
    plm_ensure_model_steps_complete($pid,$mid);
    $st=plm_row('SELECT * FROM plm_flow_steps WHERE project_id=? AND model_id=? AND step_name=? LIMIT 1',array($pid,$mid,$stepName));
    if(!$st){
        $max=plm_row('SELECT COALESCE(MAX(sort_order),0)+10 v FROM plm_flow_steps WHERE project_id=? AND model_id=?',array($pid,$mid));
        $sid=plm_dyn_insert('plm_flow_steps',array('project_id'=>$pid,'model_id'=>$mid,'step_name'=>$stepName,'sort_order'=>(int)($max['v']??10),'status'=>'未开始','created_at'=>plm_now(),'updated_at'=>plm_now()));
        $st=plm_row('SELECT * FROM plm_flow_steps WHERE id=?',array($sid));
    }
    $status=(string)($t['status']??'');
    $data=array('updated_at'=>plm_now());
    $action=''; $reason='';
    if(in_array($status,array('通过','PASS','已完成'),true)){
        $data['status']='已完成';
        if(empty($st['started_at'])) $data['started_at']=plm_now();
        if(empty($st['finished_at'])) $data['finished_at']=plm_now();
        $action='测试通过自动完成';
    } elseif(in_array($status,array('测试中'),true)){
        $data['status']='进行中';
        if(empty($st['started_at'])) $data['started_at']=plm_now();
        $action='测试中自动开始';
    } elseif(in_array($status,array('失败','整改'),true)){
        $data['status']='重来';
        $data['last_reason']='测试结果：'.$status;
        $data['rework_count']=(int)($st['rework_count']??0)+1;
        $action='测试未通过自动重来';
        $reason='测试结果：'.$status;
    }
    if($action){
        plm_dyn_update('plm_flow_steps',$data,'id=?',array((int)$st['id']));
        plm_dyn_insert('plm_flow_logs',array('step_id'=>(int)$st['id'],'project_id'=>$pid,'model_id'=>$mid,'action'=>$action,'reason'=>$reason,'operator'=>$t['tester']??'','created_at'=>plm_now()));
    }
}

function plm_migrate_flow_per_model_once(){
    try {
        if(!plm_table_exists('plm_bridge_config')) plm_bridge_schema();
        $row = plm_row('SELECT config_value FROM plm_bridge_config WHERE config_key=?', array('flow_model_migrated_78'));
        if($row) return;
        $models = plm_rows('SELECT id,project_id FROM plm_models ORDER BY project_id,id');
        $seenProjects = array();
        foreach($models as $m){
            $pid=(int)$m['project_id']; $isFirst = !isset($seenProjects[$pid]); $seenProjects[$pid]=1;
            plm_seed_model_steps($pid, (int)$m['id'], $isFirst);
        }
        $st=plm_pdo()->prepare('REPLACE INTO plm_bridge_config(config_key,config_value,updated_at) VALUES(?,?,?)');
        $st->execute(array('flow_model_migrated_78','1',plm_now()));
    } catch(Exception $e) { /* migration must never block PLM */ }
}
function plm_norm_project($p) { return array(
    'id'=>(int)($p['id']??0), 'project_no'=>$p['project_no']??'', 'name'=>$p['name']??'', 'customer'=>$p['customer']??'', 'series'=>$p['series']??'', 'model'=>$p['model']??'', 'product_type'=>$p['product_type']??'', 'source'=>$p['source']??'工厂自行开发', 'engineer'=>$p['engineer']??'', 'priority'=>$p['priority']??'P2 正常', 'status'=>$p['status']??'开发中', 'stage'=>$p['stage']??'', 'image_path'=>$p['image_path']??'', 'start_date'=>$p['start_date']??'', 'due_date'=>$p['due_date']??'', 'amount'=>$p['amount']??0, 'currency'=>$p['currency']??'USD', 'crm_customer_id'=>$p['crm_customer_id']??0, 'bom_project_id'=>$p['bom_project_id']??0, 'quote_id'=>$p['quote_id']??0, 'note'=>$p['note']??'', 'created_at'=>$p['created_at']??'', 'updated_at'=>$p['updated_at']??''
); }
function plm_norm_model($m) { return array(
    'id'=>(int)($m['id']??0), 'project_id'=>(int)($m['project_id']??0), 'name'=>$m['name']??'', 'model'=>$m['model']??'', 'sample_no'=>$m['sample_no']??'', 'power'=>$m['power']??'', 'beam'=>$m['beam']??'', 'cct'=>$m['cct']??'', 'cri'=>$m['cri']??'', 'qty'=>$m['qty']??1, 'status'=>$m['status']??'', 'image_path'=>$m['image_path']??'', 'led'=>$m['led']??'', 'driver'=>$m['driver']??'', 'optic'=>$m['optic']??'', 'connector'=>$m['connector']??'', 'shell'=>$m['shell']??'', 'finish'=>$m['finish']??'', 'package_info'=>$m['package_info']??'', 'note'=>$m['note']??'',
    'review_status'=>$m['review_status']??'待评审','reviewer'=>$m['reviewer']??'','review_note'=>$m['review_note']??'','score_appearance'=>$m['score_appearance']??0,'score_structure'=>$m['score_structure']??0,'score_optic'=>$m['score_optic']??0,'score_temp'=>$m['score_temp']??0,'score_cost'=>$m['score_cost']??0,'score_process'=>$m['score_process']??0,'score_customer'=>$m['score_customer']??0,'score_total'=>$m['score_total']??0,'sent_date'=>$m['sent_date']??'','tracking_no'=>$m['tracking_no']??'','feedback_date'=>$m['feedback_date']??'','customer_feedback'=>$m['customer_feedback']??'','customer_result'=>$m['customer_result']??'','is_recommended'=>(int)($m['is_recommended']??0),'is_frozen'=>(int)($m['is_frozen']??0),'frozen_at'=>$m['frozen_at']??'','frozen_by'=>$m['frozen_by']??'','version_no'=>$m['version_no']??'V1',
    'created_at'=>$m['created_at']??'', 'updated_at'=>$m['updated_at']??''
); }
function plm_norm_file($f) { return array(
    'id'=>(int)($f['id']??0),'project_id'=>(int)($f['project_id']??0),'model_id'=>(int)($f['model_id']??0),'test_id'=>(int)($f['test_id']??0),'category'=>$f['category']??'','title'=>$f['title']??($f['original_name']??''),'file_name'=>$f['file_name']??'','original_name'=>$f['original_name']??'','file_path'=>$f['file_path']??'','mime_type'=>$f['mime_type']??'','file_size'=>$f['file_size']??0,'customer_visible'=>$f['customer_visible']??1,'note'=>$f['note']??'','created_at'=>$f['created_at']??''
); }

function plm_review_score_total($d) {
    $keys = array('score_appearance','score_structure','score_optic','score_temp','score_cost','score_process','score_customer');
    $sum = 0; $n = 0;
    foreach($keys as $k) { if(isset($d[$k]) && $d[$k] !== '' && is_numeric($d[$k])) { $sum += (float)$d[$k]; $n++; } }
    return $n ? round($sum / $n, 2) : 0;
}
function plm_norm_date_or_null($v) { $v=trim((string)$v); return $v==='' ? null : $v; }
function plm_model_snapshot($model_id) {
    $model_id = (int)$model_id;
    $m = plm_row('SELECT * FROM plm_models WHERE id=?', array($model_id));
    if(!$m) return null;
    $p = plm_row('SELECT * FROM plm_projects WHERE id=?', array((int)$m['project_id']));
    $tests = plm_rows('SELECT * FROM plm_tests WHERE model_id=? ORDER BY id ASC', array($model_id));
    $files = plm_rows('SELECT id,category,title,file_path,original_name,customer_visible,created_at FROM plm_files WHERE model_id=? ORDER BY id ASC', array($model_id));
    $steps = plm_rows('SELECT * FROM plm_flow_steps WHERE model_id=? ORDER BY sort_order,id', array($model_id));
    $bridge = function_exists('plm_bridge_summary') ? plm_bridge_summary((int)$m['project_id']) : array();
    return array('project'=>$p,'model'=>$m,'tests'=>$tests,'files'=>$files,'steps'=>$steps,'bridge'=>$bridge,'snapshot_at'=>plm_now());
}
function plm_next_version_no($current) {
    $current = trim((string)$current);
    if($current==='' || !preg_match('/V\s*(\d+)/i', $current, $mm)) return 'V2';
    return 'V'.((int)$mm[1] + 1);
}
function plm_save_sample_review($d) {
    $id=(int)($d['model_id']??$d['id']??0); if(!$id) return array('ok'=>false,'error'=>'缺少样品ID');
    $m=plm_row('SELECT * FROM plm_models WHERE id=?',array($id)); if(!$m) return array('ok'=>false,'error'=>'找不到样品');
    $pid=(int)$m['project_id'];
    $total = plm_review_score_total($d);
    $data = array('updated_at'=>plm_now(),'score_total'=>$total);
    foreach(array('review_status','reviewer','review_note','score_appearance','score_structure','score_optic','score_temp','score_cost','score_process','score_customer','tracking_no','customer_feedback','customer_result','is_recommended') as $k){ if(array_key_exists($k,$d)) $data[$k]=$d[$k]; }
    foreach(array('sent_date','feedback_date') as $k){ if(array_key_exists($k,$d)) $data[$k]=plm_norm_date_or_null($d[$k]); }
    if(isset($data['is_recommended'])) $data['is_recommended']=(int)$data['is_recommended'];
    plm_dyn_update('plm_models',$data,'id=?',array($id));
    $log = $data; $log['project_id']=$pid; $log['model_id']=$id; $log['created_at']=plm_now(); unset($log['updated_at']);
    plm_dyn_insert('plm_model_reviews',$log);
    plm_audit('保存样品评审','model',$id,$pid,($data['review_status']??'').' ｜ 评分 '.$total.' ｜ '.($data['customer_result']??''),$data['reviewer']??'');
    return array('ok'=>true,'score_total'=>$total);
}
function plm_freeze_sample($d) {
    $id=(int)($d['model_id']??0); if(!$id) return array('ok'=>false,'error'=>'缺少样品ID');
    $m=plm_row('SELECT * FROM plm_models WHERE id=?',array($id)); if(!$m) return array('ok'=>false,'error'=>'找不到样品');
    $pid=(int)$m['project_id'];
    $version = trim((string)($d['version_no']??'')); if($version==='') $version = trim((string)($m['version_no']??'')) ?: 'V1';
    $by = trim((string)($d['frozen_by']??$d['reviewer']??''));
    $note = trim((string)($d['note']??'冻结为正式确认版本'));
    $snapshot = plm_model_snapshot($id);
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    plm_dyn_update('plm_models',array('is_frozen'=>1,'review_status'=>'已冻结','frozen_at'=>plm_now(),'frozen_by'=>$by,'version_no'=>$version,'frozen_snapshot'=>$json,'updated_at'=>plm_now()),'id=?',array($id));
    $vid = plm_dyn_insert('plm_model_versions',array('project_id'=>$pid,'model_id'=>$id,'source_model_id'=>$id,'version_no'=>$version,'title'=>($m['name']??'样品').' '.$version.' 冻结版','note'=>$note,'snapshot_json'=>$json,'is_frozen'=>1,'created_by'=>$by,'created_at'=>plm_now()));
    plm_audit('冻结正式样品版本','model',$id,$pid,$version.' ｜ '.$note,$by);
    return array('ok'=>true,'version_id'=>$vid,'version_no'=>$version,'message'=>'已冻结 '.$version.'，后续修改建议走新版本。');
}
function plm_create_sample_version($d) {
    $id=(int)($d['model_id']??0); if(!$id) return array('ok'=>false,'error'=>'缺少样品ID');
    $m=plm_row('SELECT * FROM plm_models WHERE id=?',array($id)); if(!$m) return array('ok'=>false,'error'=>'找不到源样品');
    $pid=(int)$m['project_id'];
    $newVersion = trim((string)($d['version_no']??'')); if($newVersion==='') $newVersion = plm_next_version_no($m['version_no']??'V1');
    $name = trim((string)($d['name']??'')); if($name==='') $name = trim((string)($m['name']??'样品')).' '.$newVersion;
    $copyKeys = array('project_id','model','sample_no','power','beam','cct','cri','qty','image_path','led','driver','optic','connector','shell','finish','package_info');
    $data = array(); foreach($copyKeys as $k) if(array_key_exists($k,$m)) $data[$k]=$m[$k];
    $data['project_id']=$pid; $data['name']=$name; $data['status']='开发中'; $data['note']='由样品 #'.$id.' 复制生成 '.$newVersion.'。'.trim((string)($d['note']??'')); $data['review_status']='待评审'; $data['version_no']=$newVersion; $data['is_frozen']=0; $data['is_recommended']=0; $data['score_total']=0; $data['created_at']=plm_now(); $data['updated_at']=plm_now();
    $newId = plm_dyn_insert('plm_models',$data);
    plm_seed_model_steps($pid,$newId,false);
    $snapshot = plm_model_snapshot($id);
    plm_dyn_insert('plm_model_versions',array('project_id'=>$pid,'model_id'=>$newId,'source_model_id'=>$id,'version_no'=>$newVersion,'title'=>$name,'note'=>trim((string)($d['note']??'从上一版本复制新样品')),'snapshot_json'=>json_encode($snapshot, JSON_UNESCAPED_UNICODE),'is_frozen'=>0,'created_by'=>trim((string)($d['created_by']??'')),'created_at'=>plm_now()));
    plm_audit('创建样品新版本','model',$newId,$pid,'来源样品 #'.$id.' → '.$newVersion,trim((string)($d['created_by']??'')));
    return array('ok'=>true,'id'=>$newId,'version_no'=>$newVersion,'message'=>'已创建新版本 '.$newVersion.'。');
}
function plm_review_history($model_id){ return plm_rows('SELECT * FROM plm_model_reviews WHERE model_id=? ORDER BY id DESC LIMIT 50',array((int)$model_id)); }
function plm_version_history($model_id){ return plm_rows('SELECT * FROM plm_model_versions WHERE model_id=? OR source_model_id=? ORDER BY id DESC LIMIT 50',array((int)$model_id,(int)$model_id)); }

function plm_doc_abs_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    return $path;
}
function plm_package_code(){ return 'DPK'.date('YmdHis').strtoupper(bin2hex(random_bytes(3))); }
function plm_create_doc_package($docs, $project_id=0, $title='', $note='') {
    plm_schema();
    if (!is_array($docs) || !count($docs)) return array('ok'=>false,'error'=>'请先勾选要发送/打包的资料。');
    $project_id = (int)$project_id;
    if (!$project_id && isset($docs[0]['project_id'])) $project_id = (int)$docs[0]['project_id'];
    $p = $project_id ? plm_row('SELECT * FROM plm_projects WHERE id=?', array($project_id)) : null;
    $customer = $p ? ($p['customer'] ?? '') : (string)($docs[0]['customer'] ?? '');
    if (trim($title)==='') $title = ($p && !empty($p['name'])) ? ($p['name'].' 资料包') : 'PLM资料包';
    $code = plm_package_code();
    $lines = array();
    $lines[] = '【Artdon PLM资料包】'.$title;
    if ($p) {
        $lines[] = '项目：'.($p['name'] ?? '');
        $lines[] = '客户：'.($p['customer'] ?? '');
        $lines[] = '型号：'.($p['model'] ?? '');
    }
    if (trim($note)!=='') $lines[] = '说明：'.$note;
    $lines[] = '资料清单：';
    $i=1;
    foreach ($docs as $d) {
        $titleLine = trim((string)($d['title'] ?? '资料'));
        $cat = trim((string)($d['category'] ?? ''));
        $path = trim((string)($d['path'] ?? ($d['file_path'] ?? '')));
        $lines[] = $i.'. '.$titleLine.($cat ? '｜'.$cat : '').($path ? '｜'.plm_doc_abs_url($path) : '｜测试/记录项');
        $i++;
    }
    $share = implode("\n", $lines);
    $pkgId = plm_dyn_insert('plm_doc_packages', array(
        'package_code'=>$code, 'project_id'=>$project_id, 'title'=>$title, 'customer'=>$customer, 'note'=>$note,
        'selected_json'=>json_encode($docs, JSON_UNESCAPED_UNICODE), 'share_text'=>$share, 'status'=>'已生成', 'created_at'=>plm_now(), 'updated_at'=>plm_now()
    ));
    $i=1;
    foreach ($docs as $d) {
        plm_dyn_insert('plm_doc_package_items', array(
            'package_id'=>$pkgId, 'project_id'=>(int)($d['project_id'] ?? $project_id), 'file_id'=>(int)($d['file_id'] ?? 0), 'test_id'=>(int)($d['test_id'] ?? 0),
            'source'=>(string)($d['source'] ?? ''), 'category'=>(string)($d['category'] ?? ''), 'title'=>(string)($d['title'] ?? ''), 'file_path'=>(string)($d['path'] ?? ($d['file_path'] ?? '')),
            'customer_visible'=>(int)($d['customer_visible'] ?? 1), 'sort_order'=>$i*10, 'created_at'=>plm_now()
        ));
        $i++;
    }
    $openUrl = 'crm.php?from=plm_package&package_code='.rawurlencode($code).'&project_id='.$project_id;
    return array('ok'=>true,'package_id'=>$pkgId,'package_code'=>$code,'share_text'=>$share,'open_url'=>$openUrl,'count'=>count($docs));
}
function plm_get_doc_package($code) {
    plm_schema();
    $code = trim((string)$code);
    if ($code==='') return null;
    $pkg = plm_row('SELECT * FROM plm_doc_packages WHERE package_code=? LIMIT 1', array($code));
    if (!$pkg) return null;
    $items = plm_rows('SELECT * FROM plm_doc_package_items WHERE package_id=? ORDER BY sort_order,id', array((int)$pkg['id']));
    $pkg['items'] = $items;
    return $pkg;
}

function plm_upload_one($key='file') {
    if (empty($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) return array(null, '没有收到文件，或上传失败。');
    $f = $_FILES[$key];
    if ($f['size'] > PLM_UPLOAD_MAX_BYTES) return array(null, '文件太大，请在 PHP 和系统中提高上传限制。');
    $orig = $f['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = array('jpg','jpeg','png','gif','webp','pdf','ies','ldt','txt','csv','xlsx','xls','doc','docx','dwg','dxf','zip','rar','7z','mp4','mov');
    if (!in_array($ext, $allowed, true)) return array(null, '不允许上传该类型：'.$ext);
    $dirRel = 'uploads/plm/' . date('Ym');
    $dir = __DIR__ . '/' . $dirRel;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $safe = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $safe;
    if (!move_uploaded_file($f['tmp_name'], $target)) return array(null, '保存文件失败，请检查 uploads/plm 目录权限。');
    $mime = function_exists('mime_content_type') ? @mime_content_type($target) : ($f['type'] ?? '');
    return array(array('file_name'=>$safe,'original_name'=>$orig,'file_path'=>$dirRel.'/'.$safe,'mime_type'=>$mime,'file_size'=>(int)$f['size']), null);
}
function plm_delete_real_file($path) { if ($path && strpos($path, 'uploads/plm/') === 0) { $full = __DIR__.'/'.$path; if(is_file($full)) @unlink($full); } }
function plm_like_param($kw){ return '%'.$kw.'%'; }

function plm_sync_bom($model_id) {
    return plm_create_bom_from_model($model_id, true);
}
function plm_sync_quote($model_id) {
    return plm_create_quote_from_model($model_id, true);
}

function plm_bridge_schema(){
    $db = plm_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS plm_bridge_config (
        config_key VARCHAR(120) PRIMARY KEY,
        config_value MEDIUMTEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS plm_bom_handoffs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        target_type VARCHAR(30) DEFAULT 'bom',
        target_table VARCHAR(120) DEFAULT '',
        target_id INT DEFAULT 0,
        payload MEDIUMTEXT NULL,
        status VARCHAR(80) DEFAULT '待处理',
        open_url VARCHAR(800) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_type(target_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $db->exec("ALTER TABLE plm_bom_handoffs MODIFY status VARCHAR(500) DEFAULT '待处理'"); } catch (Exception $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS plm_external_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL DEFAULT 0,
        model_id INT NOT NULL DEFAULT 0,
        link_type VARCHAR(30) DEFAULT 'bom',
        external_table VARCHAR(120) DEFAULT '',
        external_id INT DEFAULT 0,
        external_code VARCHAR(120) DEFAULT '',
        open_url VARCHAR(800) DEFAULT '',
        note VARCHAR(500) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_project(project_id), KEY idx_model(model_id), KEY idx_link(link_type,external_table,external_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function plm_config_get_bridge(){
    if(!plm_table_exists('plm_bridge_config')) plm_bridge_schema();
    $row = plm_row('SELECT config_value FROM plm_bridge_config WHERE config_key=?', array('bridge'));
    $cfg = $row ? json_decode($row['config_value'], true) : array();
    if(!is_array($cfg)) $cfg = array();
    $defaults = array(
        'bom_url'=>'bom.php', 'quote_url'=>'quotation.php', 'crm_url'=>'crm.php',
        'bom_table'=>'bom_projects', 'bom_items_table'=>'bom_items',
        'quote_table'=>'quote_products', 'quote_order_table'=>'quote_orders', 'crm_quote_table'=>'crm_quotes',
        'material_table'=>'bom_materials',
        'bom_mode'=>'write', 'quote_mode'=>'write'
    );
    return array_merge($defaults, $cfg);
}
function plm_config_save_bridge($cfg){
    if(!plm_table_exists('plm_bridge_config')) plm_bridge_schema();
    $old = plm_config_get_bridge();
    $cfg = array_merge($old, is_array($cfg)?$cfg:array());
    foreach(array('bom_table','bom_items_table','quote_table','quote_order_table','crm_quote_table','material_table') as $k){
        if(!empty($cfg[$k]) && !plm_safe_ident($cfg[$k])) throw new Exception($k.' 表名不安全。');
    }
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    $st = plm_pdo()->prepare('REPLACE INTO plm_bridge_config(config_key,config_value,updated_at) VALUES(?,?,?)');
    $st->execute(array('bridge',$json,plm_now()));
    return $cfg;
}
function plm_table_list(){
    $rows = plm_pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $out = array(); foreach($rows as $r) if(isset($r[0])) $out[]=$r[0];
    return $out;
}
function plm_safe_ident($s){ return is_string($s) && preg_match('/^[A-Za-z0-9_]+$/', $s); }
function plm_valid_table($t){ return plm_safe_ident($t) && plm_table_exists($t) && strpos($t,'plm_')!==0; }
function plm_candidate_tables($kind){
    $tables = plm_table_list(); $out = array();
    foreach($tables as $t){
        $lt = strtolower($t); $cols = plm_cols($t); $score = 0;
        if($kind==='bom'){
            if($lt==='bom_projects') $score += 100;
            foreach(array('project_uid','name','customer','model','product_image','rows_json') as $c) if(in_array($c,$cols,true)) $score += 9;
            if(strpos($lt,'bom')!==false && (strpos($lt,'project')!==false || strpos($lt,'header')!==false)) $score += 25;
            if($lt==='bom_items' || $lt==='bom_materials') $score -= 40;
        } elseif($kind==='bom_items'){
            if($lt==='bom_items') $score += 100;
            foreach(array('project_uid','line_no','category','material_id','name','spec','qty','price','subtotal','bom_project_id') as $c) if(in_array($c,$cols,true)) $score += 8;
            if(strpos($lt,'bom')!==false && strpos($lt,'item')!==false) $score += 25;
            if($lt==='bom_projects' || $lt==='bom_materials') $score -= 40;
        } elseif($kind==='quote'){
            if($lt==='quote_products') $score += 100;
            foreach(array('code','name','series','power_range','ip','cri','pic','image','price_rmb','price_usd') as $c) if(in_array($c,$cols,true)) $score += 7;
            if(strpos($lt,'quote')!==false && strpos($lt,'product')!==false) $score += 25;
            if(strpos($lt,'order')!==false || strpos($lt,'item')!==false) $score -= 20;
        } elseif($kind==='quote_order'){
            if($lt==='quote_orders') $score += 100;
            foreach(array('quote_no','quote_date','customer_json','product_json','items_json','qty','price','amount','currency') as $c) if(in_array($c,$cols,true)) $score += 7;
            if(strpos($lt,'quote')!==false && strpos($lt,'order')!==false) $score += 25;
        } elseif($kind==='crm_quote'){
            if($lt==='crm_quotes') $score += 100;
            foreach(array('quote_no','customer_name','currency','total_amount','gross_margin','status','owner') as $c) if(in_array($c,$cols,true)) $score += 7;
            if(strpos($lt,'crm')!==false && strpos($lt,'quote')!==false) $score += 25;
        } elseif($kind==='crm'){
            foreach(array('crm','customer','client') as $k) if(strpos($lt,$k)!==false) $score += 4;
        } elseif($kind==='material'){
            if($lt==='bom_materials') $score += 100;
            foreach(array('category','brand','name','model','spec','price','unit','supplier','keyword','image','material_group','currency','last_price') as $c) if(in_array($c,$cols,true)) $score += 7;
            if(strpos($lt,'material')!==false || strpos($lt,'profile')!==false || strpos($lt,'library')!==false) $score += 12;
            if($lt==='bom_projects' || $lt==='bom_items') $score -= 50;
        }
        if(strpos($lt,'plm_')===0) $score -= 100;
        if($score>0) $out[] = array('table'=>$t, 'score'=>$score, 'cols'=>$cols);
    }
    usort($out, function($a,$b){ return $b['score'] <=> $a['score']; });
    return $out;
}
function plm_bridge_detect(){
    return array(
        'database'=>plm_val(array('DB_NAME','DB_DATABASE','MYSQL_DATABASE','db_name','dbname','database','mysql_database','PLM_DB_NAME'), PLM_DB_NAME),
        'config'=>plm_config_get_bridge(),
        'bom'=>plm_candidate_tables('bom'),
        'bom_items'=>plm_candidate_tables('bom_items'),
        'quote'=>plm_candidate_tables('quote'),
        'quote_order'=>plm_candidate_tables('quote_order'),
        'crm_quote'=>plm_candidate_tables('crm_quote'),
        'crm'=>plm_candidate_tables('crm'),
        'material'=>plm_candidate_tables('material'),
        'plm_tables'=>array_values(array_filter(plm_table_list(), function($t){return strpos($t,'plm_')===0;}))
    );
}
function plm_bridge_url($base, $params){
    $base = trim((string)$base); if($base==='') $base='bom.php';
    $sep = (strpos($base,'?')===false) ? '?' : '&';
    return $base.$sep.http_build_query($params);
}
function plm_model_payload($model_id){
    $m = plm_row('SELECT * FROM plm_models WHERE id=?', array((int)$model_id));
    if(!$m) return array(null,null,null);
    $p = plm_row('SELECT * FROM plm_projects WHERE id=?', array((int)$m['project_id']));
    if(!$p) return array(null,null,null);
    $uid = 'PLM-P'.$p['id'].'-M'.$m['id'];
    $payload = array(
        'plm_uid'=>$uid,
        'source'=>'PLM',
        'project_id'=>$p['id'], 'model_id'=>$m['id'],
        'project_no'=>$p['project_no'], 'project_name'=>$p['name'], 'name'=>$p['name'], 'title'=>$p['name'],
        'customer'=>$p['customer'], 'series'=>$p['series'], 'product_type'=>$p['product_type'],
        'project_model'=>$p['model'], 'model'=>$m['model'] ?: $p['model'], 'sample_name'=>$m['name'],
        'power'=>$m['power'], 'beam'=>$m['beam'], 'angle'=>$m['beam'], 'cct'=>$m['cct'], 'cri'=>$m['cri'], 'qty'=>$m['qty'],
        'led'=>$m['led'], 'driver'=>$m['driver'], 'optic'=>$m['optic'], 'shell'=>$m['shell'], 'finish'=>$m['finish'], 'package_info'=>$m['package_info'],
        'image_path'=>$m['image_path'] ?: $p['image_path'],
        'note'=>trim(($p['note']??'')."\n".($m['note']??'')),
        'status'=>'PLM生成', 'created_at'=>plm_now(), 'updated_at'=>plm_now(),
        'currency'=>$p['currency'] ?: 'USD'
    );
    return array($p,$m,$payload);
}
function plm_pick_external_table($kind, $cfg){
    $map = array('bom'=>'bom_table','bom_items'=>'bom_items_table','quote'=>'quote_table','quote_order'=>'quote_order_table','crm_quote'=>'crm_quote_table','material'=>'material_table');
    $key = $map[$kind] ?? ($kind.'_table');
    if(!empty($cfg[$key]) && plm_valid_table($cfg[$key])) return $cfg[$key];
    $safe = array(
        'bom'=>array('bom_projects','bom_headers','bom_header','boms','bom'),
        'bom_items'=>array('bom_items','bom_lines','bom_details'),
        'quote'=>array('quote_products','quotation_products','quotes_products'),
        'quote_order'=>array('quote_orders','quotation_orders','quotes'),
        'crm_quote'=>array('crm_quotes'),
        'material'=>array('bom_materials','shared_materials','material_library','materials')
    );
    foreach(($safe[$kind] ?? array()) as $t){ if(plm_valid_table($t)) return $t; }
    return '';
}
function plm_col($table,$names){ return plm_first_col($table,$names,null); }
function plm_filter_data($table,$data){
    return plm_fit_data($table,$data);
}
function plm_find_external_by_key($table,$key,$val){
    if(!$key || !plm_has_col($table,$key)) return null;
    return plm_row('SELECT * FROM `'.$table.'` WHERE `'.$key.'`=? LIMIT 1', array($val));
}
function plm_upsert_external($table,$data,$keys){
    if(!$table || !plm_valid_table($table)) return 0;
    $data = plm_filter_data($table,$data);
    if(!$data) return 0;
    $row=null; $foundKey=null; $foundVal=null;
    foreach($keys as $k=>$v){
        if($v!=='' && $v!==null && plm_has_col($table,$k)) { $row=plm_find_external_by_key($table,$k,$v); if($row){$foundKey=$k;$foundVal=$v;break;} }
    }
    if($row && isset($row['id'])){
        plm_dyn_update($table,$data,'id=?',array((int)$row['id']));
        return (int)$row['id'];
    }
    return plm_dyn_insert($table,$data);
}
function plm_num($v){ return is_numeric($v) ? (float)$v : 0; }
function plm_first_nonempty(){ foreach(func_get_args() as $v){ if(trim((string)$v)!=='') return $v; } return ''; }
function plm_material_tables() {
    $cfg = plm_config_get_bridge();
    $preferred = array();
    if(!empty($cfg['material_table']) && plm_valid_table($cfg['material_table'])) $preferred[] = $cfg['material_table'];
    foreach(array('bom_materials','shared_materials','bom_shared_materials','material_library','materials','material_items','item_materials','weight_profiles','profiles') as $t) {
        if (plm_valid_table($t) && !in_array($t,$preferred,true)) $preferred[] = $t;
    }
    try {
        $rs = plm_pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($rs as $r) {
            $t = $r[0];
            if (!plm_valid_table($t)) continue;
            $lt = strtolower($t);
            if (in_array($t,array('bom_projects','bom_items','quote_products','quote_orders','crm_quotes'),true)) continue;
            if (strpos($lt,'material') !== false || strpos($lt,'profile') !== false || strpos($lt,'library') !== false) {
                if (!in_array($t, $preferred, true)) $preferred[] = $t;
            }
        }
    } catch (Exception $e) {}
    return $preferred;
}
function plm_material_lookup($text,$category=''){
    $text=trim((string)$text); if($text==='') return null;
    $table = plm_material_tables()[0] ?? '';
    if(!$table) return null;
    $cols = plm_cols($table);
    $idCol = in_array('id',$cols,true) ? 'id' : plm_pick_col($cols, array('material_id','item_id','uid'));
    $nameCol = plm_pick_col($cols, array('name','material_name','item_name','title','product_name','part_name','cn_name','chinese_name'));
    $modelCol = plm_pick_col($cols, array('model','material_model','item_model','code','sku','part_no','part_number','profile_no','item_code'));
    $catCol = plm_pick_col($cols, array('category','material_category','type','material_type','class','group_name','material_group','cat_name'));
    $brandCol = plm_pick_col($cols, array('brand','material_brand','manufacturer','vendor','supplier_brand','supplier'));
    $specCol = plm_pick_col($cols, array('spec','specification','size','description','remark','note'));
    $unitCol = plm_pick_col($cols, array('unit','uom'));
    $priceCol = plm_pick_col($cols, array('price','last_price','unit_price','cost','cost_price','purchase_price'));
    $searchCols = array_values(array_filter(array_unique(array($nameCol,$modelCol,$catCol,$brandCol,$specCol,'keyword','series'))));
    $searchCols = array_values(array_filter($searchCols, function($c) use ($cols){ return in_array($c,$cols,true); }));
    if(!$searchCols) return null;
    $selects=array(); foreach(array('id'=>$idCol,'name'=>$nameCol,'model'=>$modelCol,'category'=>$catCol,'brand'=>$brandCol,'spec'=>$specCol,'unit'=>$unitCol,'price'=>$priceCol) as $a=>$c){ $selects[] = $c ? '`'.$c.'` AS `'.$a.'`' : "'' AS `".$a."`"; }
    $where=array(); $params=array();
    if($modelCol){ $where[]='`'.$modelCol.'`=?'; $params[]=$text; }
    if($nameCol){ $where[]='`'.$nameCol.'`=?'; $params[]=$text; }
    $sql='SELECT '.implode(',',$selects).' FROM `'.$table.'`';
    if($where) $sql.=' WHERE '.implode(' OR ',$where).' LIMIT 1';
    try { if($where){ $r=plm_row($sql,$params); if($r){ $r['_table']=$table; return $r; } } } catch(Exception $e){}
    $params=array('%'.$text.'%');
    $concat='CONCAT_WS(" ",`'.implode('`,`',$searchCols).'`)';
    $sql='SELECT '.implode(',',$selects).' FROM `'.$table.'` WHERE '.$concat.' LIKE ?';
    if($catCol && trim($category)!=='') { $sql.=' AND `'.$catCol.'` LIKE ?'; $params[]='%'.$category.'%'; }
    $sql.=' LIMIT 1';
    try { $r=plm_row($sql,$params); if($r){ $r['_table']=$table; return $r; } } catch(Exception $e){}
    return null;
}
function plm_bom_items_from_payload($payload){
    $defs = array(
        array('LED/COB','led','LED/COB'),
        array('电源','driver','电源'),
        array('光学/透镜/反光杯','optic','光学'),
        array('外壳/型材','shell','型材'),
        array('表面处理','finish','表面处理'),
        array('包装/配件','package_info','包装')
    );
    $items=array(); $line=10;
    foreach($defs as $d){
        $text=trim((string)($payload[$d[1]] ?? '')); if($text==='') continue;
        $mat=plm_material_lookup($text,$d[2]);
        $price=$mat ? plm_num($mat['price'] ?? 0) : 0;
        $name=$mat ? plm_first_nonempty($mat['name'] ?? '', $text) : $text;
        $spec=$mat ? plm_first_nonempty($mat['model'] ?? '', $mat['spec'] ?? '', $text) : $text;
        $items[] = array(
            'line_no'=>$line, 'category'=>$d[0], 'material_id'=>$mat ? (int)($mat['id'] ?? 0) : 0,
            'name'=>$name, 'spec'=>$spec, 'qty'=>1, 'unit'=>$mat ? ($mat['unit'] ?? '') : '', 'price'=>$price, 'subtotal'=>$price,
            'process'=>$d[0]==='表面处理' ? $text : '', 'finish'=>$d[0]==='表面处理' ? $text : '', 'finish_cost'=>0,
            'source_text'=>$text, 'material_table'=>$mat ? ($mat['_table'] ?? '') : ''
        );
        $line += 10;
    }
    return $items;
}
function plm_write_bom_project($p,$m,$payload,$items,$cfg){
    $table=plm_pick_external_table('bom',$cfg); if(!$table) return array('ok'=>false,'error'=>'未找到 BOM 主表。');
    $total=0; foreach($items as $it) $total += plm_num($it['subtotal'] ?? 0);
    $data=array(
        'project_uid'=>$payload['plm_uid'], 'name'=>$payload['project_name'], 'customer'=>$payload['customer'], 'model'=>$payload['model'],
        'product_type'=>$payload['product_type'], 'current_'=>$payload['status'], 'product_image'=>$payload['image_path'],
        'labor'=>0, 'other'=>0, 'profit_mode'=>'倍率', 'exchange_rate'=>1, 'note'=>$payload['note'],
        'rows_json'=>json_encode($items,JSON_UNESCAPED_UNICODE), 'is_active'=>1, 'source_type'=>'PLM',
        'created_by'=>'PLM', 'created_at'=>plm_now(), 'updated_at'=>plm_now()
    );
    $id=plm_upsert_external($table,$data,array('project_uid'=>$payload['plm_uid']));
    if(!$id) return array('ok'=>false,'error'=>'BOM主表字段无法匹配，未写入。','target_table'=>$table);
    return array('ok'=>true,'target_table'=>$table,'target_id'=>$id,'total'=>$total);
}
function plm_write_bom_items($bom_id,$p,$m,$payload,$items,$cfg){
    $table=plm_pick_external_table('bom_items',$cfg); if(!$table) return array('ok'=>false,'error'=>'未找到 BOM 明细表。','count'=>0);
    $written=0; $now=plm_now();
    foreach($items as $it){
        $data=array(
            'project_uid'=>$payload['plm_uid'], 'bom_project_id'=>$bom_id, 'project_id'=>$bom_id,
            'line_no'=>$it['line_no'], 'sort_order'=>$it['line_no'], 'category'=>$it['category'], 'material_id'=>$it['material_id'],
            'name'=>$it['name'], 'spec'=>$it['spec'], 'qty'=>$it['qty'], 'unit'=>$it['unit'],
            'process'=>$it['process'], 'finish'=>$it['finish'], 'finish_cost'=>$it['finish_cost'], 'finishCost'=>$it['finish_cost'],
            'price'=>$it['price'], 'subtotal'=>$it['subtotal'], 'is_active'=>1, 'created_at'=>$now, 'updated_at'=>$now
        );
        $existing=null;
        if(plm_has_col($table,'project_uid') && plm_has_col($table,'line_no')) $existing=plm_row('SELECT * FROM `'.$table.'` WHERE project_uid=? AND line_no=? LIMIT 1',array($payload['plm_uid'],$it['line_no']));
        elseif(plm_has_col($table,'bom_project_id') && plm_has_col($table,'line_no')) $existing=plm_row('SELECT * FROM `'.$table.'` WHERE bom_project_id=? AND line_no=? LIMIT 1',array($bom_id,$it['line_no']));
        if($existing && isset($existing['id'])){ plm_dyn_update($table,plm_filter_data($table,$data),'id=?',array((int)$existing['id'])); $written++; }
        else { $rid=plm_dyn_insert($table,plm_filter_data($table,$data)); if($rid) $written++; }
    }
    if(plm_has_col($table,'project_uid') && plm_has_col($table,'line_no') && plm_has_col($table,'is_active')){
        $maxLine = count($items) ? max(array_map(function($x){return (int)$x['line_no'];},$items)) : 0;
        plm_pdo()->prepare('UPDATE `'.$table.'` SET is_active=0, updated_at=? WHERE project_uid=? AND line_no>?')->execute(array($now,$payload['plm_uid'],$maxLine));
    }
    return array('ok'=>true,'target_table'=>$table,'count'=>$written);
}
function plm_record_handoff($type, $p, $m, $payload, $cfg, $sync){
    $base = ($type==='quote') ? ($cfg['quote_url']??'quotation.php') : ($cfg['bom_url']??'bom.php');
    $id = plm_dyn_insert('plm_bom_handoffs', array(
        'project_id'=>$p['id'], 'model_id'=>$m['id'], 'target_type'=>$type, 'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        'status'=>$sync?'已生成交接单':'待处理', 'created_at'=>plm_now(), 'updated_at'=>plm_now()
    ));
    $url = plm_bridge_url($base, array('from'=>'plm','plm_handoff_id'=>$id,'plm_project_id'=>$p['id'],'plm_model_id'=>$m['id'],'project_uid'=>$payload['plm_uid'],'project'=>$p['name'],'customer'=>$p['customer'],'model'=>$payload['model']));
    plm_dyn_update('plm_bom_handoffs', array('open_url'=>$url), 'id=?', array($id));
    return array('handoff_id'=>$id,'open_url'=>$url);
}
function plm_create_bom_from_model($model_id, $sync=true){
    plm_bridge_schema();
    list($p,$m,$payload) = plm_model_payload($model_id);
    if(!$p || !$m) return array('ok'=>false,'error'=>'找不到项目或样品，不能生成BOM。');
    $cfg = plm_config_get_bridge();
    $items = plm_bom_items_from_payload($payload);
    $payload['items']=$items;
    $handoff = plm_record_handoff('bom',$p,$m,$payload,$cfg,$sync);
    try { $main = plm_write_bom_project($p,$m,$payload,$items,$cfg); }
    catch(Exception $e) { $main = array('ok'=>false,'error'=>$e->getMessage()); }
    if(!$main['ok']){
        plm_dyn_update('plm_bom_handoffs', array('status'=>'仅交接：'.$main['error'],'target_table'=>$main['target_table']??''), 'id=?', array($handoff['handoff_id']));
        return array('ok'=>true,'message'=>'已生成BOM交接单；未写入BOM主表：'.$main['error'],'mode'=>'handoff','handoff_id'=>$handoff['handoff_id'],'open_url'=>$handoff['open_url'],'needs_config'=>true);
    }
    try { $lines = plm_write_bom_items($main['target_id'],$p,$m,$payload,$items,$cfg); }
    catch(Exception $e) { $lines = array('ok'=>false,'error'=>$e->getMessage(),'count'=>0); }
    $status = '已写入BOM主表+明细 '.$lines['count'].' 行';
    if(!$lines['ok']) $status = '已写入BOM主表，明细未写入';
    plm_dyn_update('plm_bom_handoffs', array('target_table'=>$main['target_table'],'target_id'=>$main['target_id'],'status'=>$status,'open_url'=>$handoff['open_url']), 'id=?', array($handoff['handoff_id']));
    plm_dyn_insert('plm_external_links', array('project_id'=>$p['id'],'model_id'=>$m['id'],'link_type'=>'bom','external_table'=>$main['target_table'],'external_id'=>$main['target_id'],'external_code'=>$payload['plm_uid'],'open_url'=>$handoff['open_url'],'note'=>'PLM 7.2 一键生成BOM，明细'.$lines['count'].'行','created_at'=>plm_now(),'updated_at'=>plm_now()));
    plm_dyn_update('plm_projects', array('bom_project_id'=>$main['target_id'],'updated_at'=>plm_now()), 'id=?', array($p['id']));
    return array('ok'=>true,'message'=>'已生成BOM：'.$main['target_table'].' #'.$main['target_id'].'，明细 '.$lines['count'].' 行','mode'=>'external_table','bom_project_id'=>$main['target_id'],'target_table'=>$main['target_table'],'items_table'=>$lines['target_table']??'','item_count'=>$lines['count'],'handoff_id'=>$handoff['handoff_id'],'open_url'=>$handoff['open_url']);
}
function plm_write_quote_product($p,$m,$payload,$cfg){
    $table=plm_pick_external_table('quote',$cfg); if(!$table) return array('ok'=>false,'error'=>'未找到报价产品表。');
    $code = trim((string)$payload['model']); if($code==='') $code=$payload['plm_uid'];
    $data=array(
        'type'=>$payload['product_type'], 'source'=>'PLM', 'category'=>$payload['product_type'], 'series'=>$payload['series'],
        'install'=>'', 'supplier'=>'Artdon', 'tags'=>'PLM,'.$payload['project_name'], 'code'=>$code, 'name'=>plm_first_nonempty($payload['sample_name'],$payload['project_name']),
        'size'=>'', 'cutout'=>'', 'power_range'=>$payload['power'], 'ip'=>'', 'cri'=>$payload['cri'], 'pic'=>$payload['image_path'], 'image'=>$payload['image_path'],
        'moq'=>1, 'cost_rmb'=>round(plm_num($payload['bom_total'] ?? 0),2), 'bom_cost'=>round(plm_num($payload['bom_total'] ?? 0),2), 'price_rmb'=>plm_num($payload['bom_total'] ?? 0)>0?round(plm_num($payload['bom_total'] ?? 0)*1.5,2):0, 'price_usd'=>0, 'price_note'=>plm_num($payload['bom_total'] ?? 0)>0?('PLM按BOM回流成本估算：成本'.round(plm_num($payload['bom_total'] ?? 0),2).'，默认倍率1.5，请在报价系统确认。'):'PLM生成，待BOM核价', 'need_comment'=>'', 'status'=>'PLM生成', 'sort_order'=>0,
        'note'=>$payload['note'], 'is_active'=>1, 'created_at'=>plm_now(), 'updated_at'=>plm_now()
    );
    $keys = array();
    if(plm_has_col($table,'source') && plm_has_col($table,'code')){
        $r=plm_row('SELECT * FROM `'.$table.'` WHERE code=? AND source=? LIMIT 1',array($code,'PLM'));
        if($r && isset($r['id'])){ plm_dyn_update($table,plm_filter_data($table,$data),'id=?',array((int)$r['id'])); return (int)$r['id']; }
    }
    $keys['code']=$code; $keys['plm_uid']=$payload['plm_uid'];
    $id=plm_upsert_external($table,$data,$keys);
    return $id ? array('ok'=>true,'target_table'=>$table,'target_id'=>$id,'code'=>$code) : array('ok'=>false,'error'=>'报价产品表字段无法匹配。','target_table'=>$table);
}
function plm_write_quote_order($p,$m,$payload,$productRes,$cfg){
    $table=plm_pick_external_table('quote_order',$cfg); if(!$table) return array('ok'=>false,'error'=>'未找到报价订单表。');
    $quoteNo = 'PLM-Q-'.$p['id'].'-'.$m['id'];
    $productJson = array('plm_uid'=>$payload['plm_uid'],'product_id'=>$productRes['target_id']??0,'code'=>$productRes['code']??$payload['model'],'name'=>plm_first_nonempty($payload['sample_name'],$payload['project_name']),'model'=>$payload['model'],'power'=>$payload['power'],'beam'=>$payload['beam'],'cct'=>$payload['cct'],'cri'=>$payload['cri'],'image'=>$payload['image_path']);
    $data=array(
        'quote_no'=>$quoteNo, 'quote_date'=>date('Y-m-d'), 'user_name'=>'PLM', 'customer_id'=>0, 'customer_json'=>json_encode(array('name'=>$payload['customer']),JSON_UNESCAPED_UNICODE),
        'header_id'=>0, 'bank_id'=>0, 'template_id'=>0, 'product_type'=>$payload['product_type'], 'product_id'=>$productRes['target_id']??0,
        'product_json'=>json_encode($productJson,JSON_UNESCAPED_UNICODE), 'parts_json'=>json_encode(array(),JSON_UNESCAPED_UNICODE), 'items_json'=>json_encode(array(),JSON_UNESCAPED_UNICODE),
        'qty'=>plm_num($payload['qty']) ?: 1, 'price'=>plm_num($payload['bom_total'] ?? 0)>0?round(plm_num($payload['bom_total'] ?? 0)*1.5,2):0, 'amount'=>plm_num($payload['bom_total'] ?? 0)>0?round((plm_num($payload['qty']) ?: 1)*plm_num($payload['bom_total'] ?? 0)*1.5,2):0, 'currency'=>$payload['currency'] ?: 'USD', 'exchange_rate'=>1, 'moq'=>1, 'color'=>'', 'extra_spec'=>trim(($payload['note'] ?? '')."
BOM状态：".($payload['bom_status'] ?? '')."；BOM行数：".($payload['bom_item_count'] ?? 0)."；BOM成本：".($payload['bom_total'] ?? 0)),
        'created_at'=>plm_now(), 'updated_at'=>plm_now()
    );
    $id=plm_upsert_external($table,$data,array('quote_no'=>$quoteNo));
    return $id ? array('ok'=>true,'target_table'=>$table,'target_id'=>$id,'quote_no'=>$quoteNo) : array('ok'=>false,'error'=>'报价订单表字段无法匹配。','target_table'=>$table);
}
function plm_write_crm_quote($p,$m,$payload,$orderRes,$cfg){
    $table=plm_pick_external_table('crm_quote',$cfg); if(!$table) return array('ok'=>false,'error'=>'未找到CRM报价表。');
    $quoteNo = $orderRes['quote_no'] ?? ('PLM-Q-'.$p['id'].'-'.$m['id']);
    $data=array('quote_no'=>$quoteNo,'customer_name'=>$payload['customer'],'currency'=>$payload['currency'] ?: 'USD','total_amount'=>0,'gross_margin'=>0,'status'=>'PLM生成','owner'=>'PLM','created_at'=>plm_now(),'updated_at'=>plm_now());
    $id=plm_upsert_external($table,$data,array('quote_no'=>$quoteNo));
    return $id ? array('ok'=>true,'target_table'=>$table,'target_id'=>$id) : array('ok'=>false,'error'=>'CRM报价表字段无法匹配。','target_table'=>$table);
}
function plm_create_quote_from_model($model_id, $sync=true){
    plm_bridge_schema();
    list($p,$m,$payload) = plm_model_payload($model_id);
    if(!$p || !$m) return array('ok'=>false,'error'=>'找不到项目或样品，不能生成报价。');
    $cfg = plm_config_get_bridge();
    $bomBack = plm_model_bom_summary($p,$m,$cfg);
    $payload['bom_total'] = $bomBack['total_cost'] ?? 0;
    $payload['bom_item_count'] = $bomBack['item_count'] ?? 0;
    $payload['bom_status'] = $bomBack['status'] ?? '';
    $handoff = plm_record_handoff('quote',$p,$m,$payload,$cfg,$sync);
    try { $prod = plm_write_quote_product($p,$m,$payload,$cfg); }
    catch(Exception $e) { $prod = array('ok'=>false,'error'=>$e->getMessage()); }
    if(!$prod['ok']){
        plm_dyn_update('plm_bom_handoffs', array('status'=>'仅交接：'.$prod['error'],'target_table'=>$prod['target_table']??''), 'id=?', array($handoff['handoff_id']));
        return array('ok'=>true,'message'=>'已生成报价交接单；未写入报价表：'.$prod['error'],'mode'=>'handoff','handoff_id'=>$handoff['handoff_id'],'open_url'=>$handoff['open_url'],'needs_config'=>true);
    }
    $order = plm_write_quote_order($p,$m,$payload,$prod,$cfg);
    $crm = plm_write_crm_quote($p,$m,$payload,$order,$cfg);
    $status='已写入报价产品';
    if(!empty($order['ok'])) $status.=' + 报价单';
    if(!empty($crm['ok'])) $status.=' + CRM报价';
    plm_dyn_update('plm_bom_handoffs', array('target_table'=>$prod['target_table'],'target_id'=>$prod['target_id'],'status'=>$status,'open_url'=>$handoff['open_url']), 'id=?', array($handoff['handoff_id']));
    plm_dyn_insert('plm_external_links', array('project_id'=>$p['id'],'model_id'=>$m['id'],'link_type'=>'quote','external_table'=>$prod['target_table'],'external_id'=>$prod['target_id'],'external_code'=>$payload['plm_uid'],'open_url'=>$handoff['open_url'],'note'=>$status,'created_at'=>plm_now(),'updated_at'=>plm_now()));
    plm_dyn_update('plm_projects', array('quote_id'=>$prod['target_id'],'updated_at'=>plm_now()), 'id=?', array($p['id']));
    return array('ok'=>true,'message'=>'已生成报价：'.$status,'mode'=>'external_table','quote_product_id'=>$prod['target_id'],'target_table'=>$prod['target_table'],'quote_order_id'=>$order['target_id']??0,'crm_quote_id'=>$crm['target_id']??0,'handoff_id'=>$handoff['handoff_id'],'open_url'=>$handoff['open_url']);
}

function plm_model_uid($p, $m){ return 'PLM-P'.(int)($p['id'] ?? 0).'-M'.(int)($m['id'] ?? 0); }
function plm_select_scalar($sql,$params=array()){
    try { $st=plm_pdo()->prepare($sql); $st->execute($params); return $st->fetchColumn(); } catch(Exception $e){ return null; }
}
function plm_find_bom_for_model($p,$m,$cfg){
    $uid = plm_model_uid($p,$m);
    $table = plm_pick_external_table('bom',$cfg);
    $row = null;
    if($table){
        try{
            if(plm_has_col($table,'project_uid')) $row = plm_row('SELECT * FROM `'.$table.'` WHERE project_uid=? LIMIT 1',array($uid));
            if(!$row && plm_has_col($table,'plm_uid')) $row = plm_row('SELECT * FROM `'.$table.'` WHERE plm_uid=? LIMIT 1',array($uid));
            if(!$row && plm_has_col($table,'name') && plm_has_col($table,'model')) {
                $model = trim((string)($m['model'] ?: ($p['model'] ?? '')));
                if($model!=='') $row = plm_row('SELECT * FROM `'.$table.'` WHERE name=? AND model=? ORDER BY id DESC LIMIT 1',array($p['name'] ?? '', $model));
            }
        }catch(Exception $e){ $row=null; }
    }
    $link = null;
    if(plm_table_exists('plm_external_links')){
        $link = plm_row('SELECT * FROM plm_external_links WHERE project_id=? AND model_id=? AND link_type="bom" ORDER BY id DESC LIMIT 1',array((int)$p['id'],(int)$m['id']));
        if(!$row && $link && $table && !empty($link['external_id']) && plm_has_col($table,'id')) {
            $row = plm_row('SELECT * FROM `'.$table.'` WHERE id=? LIMIT 1',array((int)$link['external_id']));
        }
    }
    return array($table,$row,$link,$uid);
}
function plm_bom_items_summary($itemsTable,$uid,$bomId){
    $sum = array('count'=>0,'total'=>0,'updated_at'=>'');
    if(!$itemsTable || !plm_valid_table($itemsTable)) return $sum;
    $conds=array(); $params=array();
    if(plm_has_col($itemsTable,'project_uid')){ $conds[]='project_uid=?'; $params[]=$uid; }
    if($bomId && plm_has_col($itemsTable,'bom_project_id')){ $conds[]='bom_project_id=?'; $params[]=(int)$bomId; }
    if($bomId && plm_has_col($itemsTable,'project_id')){ $conds[]='project_id=?'; $params[]=(int)$bomId; }
    if(!$conds) return $sum;
    $where='('.implode(' OR ',$conds).')';
    if(plm_has_col($itemsTable,'is_active')) $where.=' AND COALESCE(is_active,1)<>0';
    try{
        $sum['count']=(int)plm_select_scalar('SELECT COUNT(*) FROM `'.$itemsTable.'` WHERE '.$where,$params);
        if(plm_has_col($itemsTable,'subtotal')) $sum['total']=(float)plm_select_scalar('SELECT COALESCE(SUM(COALESCE(subtotal,0)),0) FROM `'.$itemsTable.'` WHERE '.$where,$params);
        elseif(plm_has_col($itemsTable,'price') && plm_has_col($itemsTable,'qty')) $sum['total']=(float)plm_select_scalar('SELECT COALESCE(SUM(COALESCE(price,0)*COALESCE(qty,0)),0) FROM `'.$itemsTable.'` WHERE '.$where,$params);
        if(plm_has_col($itemsTable,'updated_at')) $sum['updated_at']=(string)plm_select_scalar('SELECT MAX(updated_at) FROM `'.$itemsTable.'` WHERE '.$where,$params);
        elseif(plm_has_col($itemsTable,'created_at')) $sum['updated_at']=(string)plm_select_scalar('SELECT MAX(created_at) FROM `'.$itemsTable.'` WHERE '.$where,$params);
    }catch(Exception $e){}
    return $sum;
}
function plm_row_first_value($row,$names,$default=''){
    if(!$row) return $default;
    foreach($names as $n){ if(array_key_exists($n,$row) && trim((string)$row[$n])!=='') return $row[$n]; }
    return $default;
}
function plm_model_bom_summary($p,$m,$cfg=null){
    if(!$cfg) $cfg=plm_config_get_bridge();
    list($table,$row,$link,$uid)=plm_find_bom_for_model($p,$m,$cfg);
    $bomId = $row && isset($row['id']) ? (int)$row['id'] : (int)($link['external_id'] ?? 0);
    $itemsTable = plm_pick_external_table('bom_items',$cfg);
    $is = plm_bom_items_summary($itemsTable,$uid,$bomId);
    $rowTotal = $row ? (float)plm_row_first_value($row,array('total_cost','cost_total','grand_total','total','amount','cost','subtotal'),0) : 0;
    $total = $is['total']>0 ? $is['total'] : $rowTotal;
    $rowStatus = (string)plm_row_first_value($row,array('bom_status','status','current_','state'), '');
    if(!$bomId && !$row) $status='未生成';
    elseif($is['count']>6) $status='BOM已补全';
    elseif($is['count']>0) $status='已生成待补全';
    else $status='已生成待补全';
    if($rowStatus && !in_array($rowStatus,array('PLM生成','待处理'),true) && (function_exists('mb_strlen') ? mb_strlen($rowStatus,'UTF-8') : strlen($rowStatus))<30) $status=$rowStatus;
    $open = $link['open_url'] ?? '';
    if(!$open) $open = plm_bridge_url($cfg['bom_url']??'bom.php', array('from'=>'plm','project_uid'=>$uid,'bom_project_id'=>$bomId,'project'=>$p['name']??'','customer'=>$p['customer']??'','model'=>$m['model']?:($p['model']??'')));
    $updated = $is['updated_at'] ?: (string)plm_row_first_value($row,array('updated_at','modified_at','created_at'),'');
    return array('exists'=>($bomId||$row)?1:0,'status'=>$status,'bom_project_id'=>$bomId,'project_uid'=>$uid,'table'=>$table,'items_table'=>$itemsTable,'item_count'=>$is['count'],'total_cost'=>round($total,2),'updated_at'=>$updated,'open_url'=>$open);
}
function plm_find_quote_for_model($p,$m,$cfg){
    $uid=plm_model_uid($p,$m); $qTable=plm_pick_external_table('quote',$cfg); $qoTable=plm_pick_external_table('quote_order',$cfg); $cqTable=plm_pick_external_table('crm_quote',$cfg);
    $quoteNo='PLM-Q-'.(int)$p['id'].'-'.(int)$m['id']; $prod=null; $order=null; $crm=null; $link=null;
    if(plm_table_exists('plm_external_links')){
        $link=plm_row('SELECT * FROM plm_external_links WHERE project_id=? AND model_id=? AND link_type="quote" ORDER BY id DESC LIMIT 1',array((int)$p['id'],(int)$m['id']));
    }
    try{
        if($qTable){
            if($link && !empty($link['external_id']) && plm_has_col($qTable,'id')) $prod=plm_row('SELECT * FROM `'.$qTable.'` WHERE id=? LIMIT 1',array((int)$link['external_id']));
            if(!$prod && plm_has_col($qTable,'plm_uid')) $prod=plm_row('SELECT * FROM `'.$qTable.'` WHERE plm_uid=? LIMIT 1',array($uid));
            if(!$prod && plm_has_col($qTable,'code')) { $code=trim((string)($m['model'] ?: $uid)); if($code!=='') $prod=plm_row('SELECT * FROM `'.$qTable.'` WHERE code=? ORDER BY id DESC LIMIT 1',array($code)); }
        }
        if($qoTable && plm_has_col($qoTable,'quote_no')) $order=plm_row('SELECT * FROM `'.$qoTable.'` WHERE quote_no=? LIMIT 1',array($quoteNo));
        if($cqTable && plm_has_col($cqTable,'quote_no')) $crm=plm_row('SELECT * FROM `'.$cqTable.'` WHERE quote_no=? LIMIT 1',array($quoteNo));
    }catch(Exception $e){}
    return array($qTable,$qoTable,$cqTable,$prod,$order,$crm,$link,$quoteNo,$uid);
}
function plm_model_quote_summary($p,$m,$cfg=null){
    if(!$cfg) $cfg=plm_config_get_bridge();
    list($qTable,$qoTable,$cqTable,$prod,$order,$crm,$link,$quoteNo,$uid)=plm_find_quote_for_model($p,$m,$cfg);
    $exists = ($prod||$order||$crm||$link) ? 1 : 0;
    $status = $exists ? '已生成报价' : '未生成';
    $st = plm_row_first_value($order,array('status','state'), '') ?: plm_row_first_value($prod,array('status','state'), '') ?: plm_row_first_value($crm,array('status','state'), '');
    if($st) $status=$st;
    $amount = (float)(plm_row_first_value($order,array('amount','total_amount','grand_total','price'),0) ?: plm_row_first_value($crm,array('total_amount','amount'),0) ?: plm_row_first_value($prod,array('price_rmb','price','price_usd'),0));
    $currency = (string)(plm_row_first_value($order,array('currency'),'') ?: plm_row_first_value($crm,array('currency'),'') ?: 'RMB');
    $qid = (int)(($prod['id'] ?? 0) ?: ($order['id'] ?? 0) ?: ($crm['id'] ?? 0) ?: ($link['external_id'] ?? 0));
    $updated = (string)(plm_row_first_value($order,array('updated_at','created_at'),'') ?: plm_row_first_value($prod,array('updated_at','created_at'),'') ?: plm_row_first_value($crm,array('updated_at','created_at'),''));
    $open = $link['open_url'] ?? '';
    if(!$open) $open=plm_bridge_url($cfg['quote_url']??'quotation.php',array('from'=>'plm','quote_no'=>$quoteNo,'project_uid'=>$uid,'quote_id'=>$qid,'project'=>$p['name']??'','customer'=>$p['customer']??'','model'=>$m['model']?:($p['model']??'')));
    return array('exists'=>$exists,'status'=>$status,'quote_id'=>$qid,'quote_no'=>$quoteNo,'table'=>$qTable,'order_table'=>$qoTable,'crm_table'=>$cqTable,'amount'=>round($amount,2),'currency'=>$currency,'updated_at'=>$updated,'open_url'=>$open);
}
function plm_bridge_summary($project_id=0){
    $cfg=plm_config_get_bridge(); $out=array('projects'=>array(),'models'=>array());
    $where=''; $params=array(); if($project_id){$where=' WHERE project_id=?'; $params[]=(int)$project_id;}
    $ms=plm_rows('SELECT * FROM plm_models'.$where.' ORDER BY project_id ASC,id ASC',$params);
    foreach($ms as $m){
        $p=plm_row('SELECT * FROM plm_projects WHERE id=?',array((int)$m['project_id'])); if(!$p) continue;
        $bom=plm_model_bom_summary($p,$m,$cfg); $quote=plm_model_quote_summary($p,$m,$cfg);
        $mid=(int)$m['id']; $pid=(int)$p['id'];
        $out['models'][$mid]=array('model_id'=>$mid,'project_id'=>$pid,'name'=>$m['name']??'','model'=>$m['model']??'','bom'=>$bom,'quote'=>$quote);
        if(!isset($out['projects'][$pid])) $out['projects'][$pid]=array('project_id'=>$pid,'bom_count'=>0,'bom_done'=>0,'quote_count'=>0,'model_count'=>0,'total_cost'=>0,'last_update'=>'','models'=>array());
        $out['projects'][$pid]['model_count']++;
        if(!empty($bom['exists'])) $out['projects'][$pid]['bom_count']++;
        if(!empty($bom['exists']) && $bom['item_count']>6) $out['projects'][$pid]['bom_done']++;
        if(!empty($quote['exists'])) $out['projects'][$pid]['quote_count']++;
        $out['projects'][$pid]['total_cost'] += (float)$bom['total_cost'];
        foreach(array($bom['updated_at']??'', $quote['updated_at']??'') as $u){ if($u && $u>$out['projects'][$pid]['last_update']) $out['projects'][$pid]['last_update']=$u; }
        $out['projects'][$pid]['models'][]=$mid;
    }
    foreach($out['projects'] as $pid=>$v){ $out['projects'][$pid]['total_cost']=round($v['total_cost'],2); }
    return $out;
}

function plm_search_materials($kw='', $category='', $limit=50) {
    $limit = max(5, min(80, (int)$limit));
    $kw = trim((string)$kw);
    $category = trim((string)$category);
    $out = array();
    $seen = array();
    foreach (plm_material_tables() as $t) {
        $cols = plm_cols($t);
        if (!$cols) continue;
        $idCol = in_array('id',$cols,true) ? 'id' : plm_pick_col($cols, array('material_id','item_id','uid'));
        $nameCol = plm_pick_col($cols, array('name','material_name','item_name','title','product_name','part_name','cn_name','chinese_name'));
        $modelCol = plm_pick_col($cols, array('model','material_model','item_model','code','sku','part_no','part_number','profile_no','item_code'));
        $catCol = plm_pick_col($cols, array('category','material_category','type','material_type','class','group_name','material_group','cat_name'));
        $brandCol = plm_pick_col($cols, array('brand','material_brand','manufacturer','vendor','supplier_brand','supplier'));
        $specCol = plm_pick_col($cols, array('spec','specification','size','description','remark','note'));
        $unitCol = plm_pick_col($cols, array('unit','uom'));
        $priceCol = plm_pick_col($cols, array('price','last_price','unit_price','cost','cost_price','purchase_price'));
        $weightCol = plm_pick_col($cols, array('weight_per_meter','meter_weight','net_weight','kg_per_meter','weight','unit_weight','kgm'));
        $searchCols = array_values(array_unique(array_filter(array($nameCol,$modelCol,$catCol,$brandCol,$specCol,'keyword','series'))));
        $searchCols = array_values(array_filter($searchCols, function($c) use ($cols){ return in_array($c,$cols,true); }));
        if (!$searchCols) continue;
        $selects = array();
        $aliases = array('id'=>$idCol,'name'=>$nameCol,'model'=>$modelCol,'category'=>$catCol,'brand'=>$brandCol,'spec'=>$specCol,'unit'=>$unitCol,'price'=>$priceCol,'weight'=>$weightCol);
        foreach ($aliases as $alias=>$col) $selects[] = $col ? '`'.$col.'` AS `'.$alias.'`' : "'' AS `".$alias."`";
        $where = array(); $params = array();
        if ($kw !== '') { $where[] = 'CONCAT_WS(" ",`'.implode('`,`',$searchCols).'`) LIKE ?'; $params[] = '%'.$kw.'%'; }
        if ($category !== '' && $catCol) {
            $terms = preg_split('/[\s\/，,]+/u', $category, -1, PREG_SPLIT_NO_EMPTY);
            if ($terms) { $parts = array(); foreach ($terms as $term) { $parts[] = '`'.$catCol.'` LIKE ?'; $params[] = '%'.$term.'%'; } $where[] = '(' . implode(' OR ', $parts) . ')'; }
        }
        $sql = 'SELECT '.implode(',', $selects).' FROM `'.$t.'`';
        if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
        if ($idCol) $sql .= ' ORDER BY `'.$idCol.'` DESC';
        $sql .= ' LIMIT '.$limit;
        try { $rows = plm_rows($sql, $params); } catch (Exception $e) { continue; }
        foreach ($rows as $r) {
            $labelParts = array(); foreach (array('model','name','brand','spec') as $k) if (trim((string)($r[$k]??'')) !== '') $labelParts[] = trim((string)$r[$k]);
            if (trim((string)($r['price']??'')) !== '') $labelParts[] = '￥'.trim((string)$r['price']);
            if (trim((string)($r['weight']??'')) !== '') $labelParts[] = trim((string)$r['weight']).'kg/m';
            $label = trim(implode(' ｜ ', $labelParts)); if($label==='') $label=$t.'#'.($r['id']??'');
            $key = $t.'|'.($r['id']??'').'|'.$label; if(isset($seen[$key])) continue; $seen[$key]=1;
            $out[] = array('table'=>$t,'id'=>$r['id']??'','name'=>$r['name']??'','model'=>$r['model']??'','category'=>$r['category']??'','brand'=>$r['brand']??'','spec'=>$r['spec']??'','unit'=>$r['unit']??'','price'=>$r['price']??'','weight'=>$r['weight']??'','label'=>$label);
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}



/* =========================
   PLM 7.6 安全中心：备份 / 操作日志 / 健康检查
   只新增 PLM 自己的审计表，不修改 BOM / 报价 / CRM 原表。
========================= */
function plm_admin_schema() {
    try {
        plm_pdo()->exec("CREATE TABLE IF NOT EXISTS plm_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(120) DEFAULT '',
            target_type VARCHAR(80) DEFAULT '',
            target_id INT DEFAULT 0,
            project_id INT DEFAULT 0,
            operator VARCHAR(100) DEFAULT '',
            detail TEXT NULL,
            ip VARCHAR(80) DEFAULT '',
            user_agent VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created(created_at), KEY idx_project(project_id), KEY idx_action(action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}
function plm_client_ip() {
    foreach(array('HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k])) return substr((string)$_SERVER[$k],0,80);
    }
    return '';
}
function plm_audit($action, $target_type='', $target_id=0, $project_id=0, $detail='', $operator='') {
    try {
        if (!plm_table_exists('plm_audit_logs')) plm_admin_schema();
        plm_dyn_insert('plm_audit_logs', array(
            'action'=>$action,
            'target_type'=>$target_type,
            'target_id'=>(int)$target_id,
            'project_id'=>(int)$project_id,
            'operator'=>$operator,
            'detail'=>$detail,
            'ip'=>plm_client_ip(),
            'user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255),
            'created_at'=>plm_now()
        ));
    } catch (Exception $e) {}
}
function plm_table_count_safe($t) {
    if (!plm_safe_ident($t) || !plm_table_exists($t)) return 0;
    try { return (int)plm_pdo()->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn(); } catch (Exception $e) { return 0; }
}
function plm_admin_tables() {
    $defs = array(
        'plm_projects'=>'PLM项目主表',
        'plm_models'=>'PLM样品/型号',
        'plm_flow_steps'=>'开发流程节点',
        'plm_flow_logs'=>'流程操作日志',
        'plm_tests'=>'测试记录',
        'plm_files'=>'项目附件记录',
        'plm_doc_packages'=>'资料包',
        'plm_doc_package_items'=>'资料包明细',
        'plm_model_reviews'=>'样品评审历史',
        'plm_model_versions'=>'样品版本/冻结历史',
        'plm_bom_handoffs'=>'一键BOM/报价交接',
        'plm_external_links'=>'BOM/报价外部链接',
        'plm_bridge_config'=>'安全联动配置',
        'plm_audit_logs'=>'PLM审计日志'
    );
    $out = array();
    foreach($defs as $t=>$note) {
        $exists = plm_table_exists($t);
        $out[] = array('table'=>$t,'note'=>$note,'exists'=>$exists,'rows'=>$exists ? plm_table_count_safe($t) : 0);
    }
    return $out;
}
function plm_admin_overview() {
    $tables = plm_admin_tables();
    $summary = array(
        'projects'=>plm_table_count_safe('plm_projects'),
        'models'=>plm_table_count_safe('plm_models'),
        'files'=>plm_table_count_safe('plm_files'),
        'logs'=>plm_table_count_safe('plm_audit_logs') + plm_table_count_safe('plm_flow_logs')
    );
    $health = array();
    foreach(array('plm_projects','plm_models','plm_flow_steps','plm_tests','plm_files') as $t) {
        $health[] = array('level'=>plm_table_exists($t)?'ok':'bad','title'=>$t,'message'=>plm_table_exists($t)?'表存在，行数：'.plm_table_count_safe($t):'表不存在，请确认 plm_api.php 是否正常初始化');
    }
    $orphModels = 0; $orphFiles = 0; $orphTests = 0;
    try { if(plm_table_exists('plm_models')) $orphModels=(int)plm_pdo()->query('SELECT COUNT(*) FROM plm_models m LEFT JOIN plm_projects p ON p.id=m.project_id WHERE p.id IS NULL')->fetchColumn(); } catch(Exception $e){}
    try { if(plm_table_exists('plm_files')) $orphFiles=(int)plm_pdo()->query('SELECT COUNT(*) FROM plm_files f LEFT JOIN plm_projects p ON p.id=f.project_id WHERE p.id IS NULL')->fetchColumn(); } catch(Exception $e){}
    try { if(plm_table_exists('plm_tests')) $orphTests=(int)plm_pdo()->query('SELECT COUNT(*) FROM plm_tests t LEFT JOIN plm_projects p ON p.id=t.project_id WHERE p.id IS NULL')->fetchColumn(); } catch(Exception $e){}
    $health[] = array('level'=>($orphModels+$orphFiles+$orphTests)>0?'warn':'ok','title'=>'孤立数据检查','message'=>'孤立样品 '.$orphModels.'，孤立附件 '.$orphFiles.'，孤立测试 '.$orphTests.'。');
    $cfg = function_exists('plm_bridge_config') ? plm_bridge_config() : array();
    $health[] = array('level'=>!empty($cfg['bom_table'])?'ok':'warn','title'=>'BOM联动配置','message'=>!empty($cfg['bom_table'])?'已配置 BOM 主表：'.$cfg['bom_table']:'未检测到 BOM 主表配置，可能影响一键BOM/回流。');
    $health[] = array('level'=>!empty($cfg['material_table'])?'ok':'warn','title'=>'共享物料库配置','message'=>!empty($cfg['material_table'])?'已配置共享物料库：'.$cfg['material_table']:'未检测到共享物料库配置，可能影响物料检索。');
    return array('ok'=>true,'summary'=>$summary,'tables'=>$tables,'health'=>$health,'server_time'=>plm_now());
}
function plm_recent_activity($limit=150) {
    $limit = max(20, min(300, (int)$limit));
    $logs = array();
    if (plm_table_exists('plm_audit_logs')) {
        $rs = plm_rows('SELECT a.*,p.name project_name FROM plm_audit_logs a LEFT JOIN plm_projects p ON p.id=a.project_id ORDER BY a.id DESC LIMIT '.$limit);
        foreach($rs as $r) $logs[] = array('time'=>$r['created_at']??'', 'action'=>$r['action']??'', 'project'=>$r['project_name']??'', 'operator'=>$r['operator']??'', 'detail'=>$r['detail']??'', 'source'=>'audit');
    }
    if (plm_table_exists('plm_flow_logs')) {
        $rs = plm_rows('SELECT l.*,p.name project_name,m.name model_name,s.step_name FROM plm_flow_logs l LEFT JOIN plm_projects p ON p.id=l.project_id LEFT JOIN plm_models m ON m.id=l.model_id LEFT JOIN plm_flow_steps s ON s.id=l.step_id ORDER BY l.id DESC LIMIT '.$limit);
        foreach($rs as $r) $logs[] = array('time'=>$r['created_at']??'', 'action'=>'流程：'.($r['action']??''), 'project'=>$r['project_name']??'', 'model'=>$r['model_name']??'', 'operator'=>$r['operator']??'', 'detail'=>trim(($r['model_name']??'').' ｜ '.($r['step_name']??'').' '.($r['reason']??'')), 'source'=>'flow');
    }
    if (plm_table_exists('plm_files')) {
        $rs = plm_rows('SELECT f.*,p.name project_name FROM plm_files f LEFT JOIN plm_projects p ON p.id=f.project_id ORDER BY f.id DESC LIMIT 60');
        foreach($rs as $r) $logs[] = array('time'=>$r['created_at']??'', 'action'=>'上传附件', 'project'=>$r['project_name']??'', 'operator'=>'', 'detail'=>($r['category']??'').' ｜ '.($r['title']?:($r['original_name']??'')), 'source'=>'file');
    }
    if (plm_table_exists('plm_doc_packages')) {
        $rs = plm_rows('SELECT d.*,p.name project_name FROM plm_doc_packages d LEFT JOIN plm_projects p ON p.id=d.project_id ORDER BY d.id DESC LIMIT 60');
        foreach($rs as $r) $logs[] = array('time'=>$r['created_at']??'', 'action'=>'生成资料包', 'project'=>$r['project_name']??'', 'operator'=>$r['created_by']??'', 'detail'=>($r['package_code']??'').' ｜ '.($r['title']??''), 'source'=>'doc_package');
    }
    if (plm_table_exists('plm_bom_handoffs')) {
        $rs = plm_rows('SELECT h.*,p.name project_name,m.name model_name FROM plm_bom_handoffs h LEFT JOIN plm_projects p ON p.id=h.project_id LEFT JOIN plm_models m ON m.id=h.model_id ORDER BY h.id DESC LIMIT 80');
        foreach($rs as $r) $logs[] = array('time'=>$r['created_at']??'', 'action'=>'BOM/报价联动', 'project'=>$r['project_name']??'', 'model'=>$r['model_name']??'', 'operator'=>$r['created_by']??'', 'detail'=>($r['status']??'').' ｜ '.($r['note']??''), 'source'=>'handoff');
    }
    usort($logs, function($a,$b){ return strcmp((string)($b['time']??''), (string)($a['time']??'')); });
    return array('ok'=>true,'logs'=>array_slice($logs,0,$limit));
}
function plm_backup_tables() {
    return array('plm_projects','plm_models','plm_flow_steps','plm_flow_logs','plm_tests','plm_files','plm_doc_packages','plm_doc_package_items','plm_model_reviews','plm_model_versions','plm_bom_handoffs','plm_external_links','plm_bridge_config','plm_audit_logs');
}
function plm_backup_data($preview=false) {
    $data = array('meta'=>array('system'=>'Artdon PLM','version'=>'7.6','created_at'=>plm_now(),'host'=>$_SERVER['HTTP_HOST'] ?? ''),'tables'=>array());
    foreach(plm_backup_tables() as $t) {
        if (!plm_table_exists($t)) { $data['tables'][$t] = array('exists'=>false,'count'=>0,'rows'=>$preview?null:array()); continue; }
        $count = plm_table_count_safe($t);
        $data['tables'][$t] = array('exists'=>true,'count'=>$count,'rows'=>$preview?null:plm_rows('SELECT * FROM `'.$t.'` ORDER BY id ASC'));
    }
    return $data;
}
function plm_backup_download() {
    $data = plm_backup_data(false);
    $filename = 'artdon_plm_backup_'.date('Ymd_His').'.json';
    if (ob_get_length()) @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

try {
    plm_schema();
    plm_admin_schema();
    plm_bridge_schema();
    plm_auth_schema();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    if (function_exists('plm_auth_handle_api') && plm_auth_handle_api($action)) { exit; }
    if (function_exists('plm_auth_require_api_action')) plm_auth_require_api_action($action);
    if ($action === 'bootstrap' || $action === '') {
        $projects = array_map('plm_norm_project', plm_all('plm_projects','id DESC'));
        $models = array_map('plm_norm_model', plm_all('plm_models','project_id ASC,id ASC'));
        $tests = plm_all('plm_tests','id DESC');
        $files = array_map('plm_norm_file', plm_all('plm_files','id DESC'));
        $steps = plm_all('plm_flow_steps','project_id ASC,model_id ASC,sort_order ASC,id ASC');
        $logs = plm_all('plm_flow_logs','id DESC');
        $link_summary = plm_bridge_summary(0);
        $reviews = plm_all('plm_model_reviews','id DESC');
        $versions = plm_all('plm_model_versions','id DESC');
        plm_json(array('ok'=>true,'projects'=>$projects,'models'=>$models,'tests'=>$tests,'files'=>$files,'steps'=>$steps,'flow_logs'=>$logs,'reviews'=>$reviews,'versions'=>$versions,'link_summary'=>$link_summary,'auth'=>function_exists('plm_auth_payload')?plm_auth_payload():null));
    }
    if ($action === 'backup_download') { plm_backup_download(); }
    if ($action === 'admin_overview') { plm_json(plm_admin_overview()); }
    if ($action === 'recent_activity') { $d=plm_input(); plm_json(plm_recent_activity((int)($d['limit']??150))); }
    if ($action === 'backup_preview') { plm_json(array('ok'=>true,'preview'=>plm_backup_data(true))); }
    if ($action === 'install') { plm_json(array('ok'=>true,'message'=>'PLM 7.1 数据表/安全联动表已创建/升级完成。')); }
    if ($action === 'new_project') {
        $now=plm_now();
        $id = plm_dyn_insert('plm_projects', array('project_no'=>'PLM-'.date('Ymd-His'),'name'=>'新开发项目','source'=>'工厂自行开发','priority'=>'P2 正常','status'=>'开发中','currency'=>'USD','created_at'=>$now,'updated_at'=>$now));
        $mid = plm_dyn_insert('plm_models', array('project_id'=>$id,'name'=>'样品01','qty'=>1,'status'=>'开发中','created_at'=>$now,'updated_at'=>$now));
        plm_default_steps($id, $mid);
        plm_audit('新增项目','project',$id,$id,'创建默认项目和样品');
        plm_json(array('ok'=>true,'id'=>$id));
    }
    if ($action === 'save_project') {
        $d=plm_input(); $id=(int)($d['id']??0); if(!$id) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        $allow=array('project_no','name','customer','series','model','product_type','source','engineer','priority','status','stage','start_date','due_date','amount','currency','crm_customer_id','bom_project_id','quote_id','note');
        $data=array('updated_at'=>plm_now()); foreach($allow as $k){ if(array_key_exists($k,$d)) $data[$k]=($d[$k]==='' && in_array($k,array('start_date','due_date')))?null:$d[$k]; }
        plm_dyn_update('plm_projects',$data,'id=?',array($id));
        plm_audit('保存项目','project',$id,$id,'更新项目基本资料');
        plm_json(array('ok'=>true));
    }
    if ($action === 'delete_project') {
        $d=plm_input(); $id=(int)($d['id']??0); if(!$id) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        $fs=plm_rows('SELECT file_path FROM plm_files WHERE project_id=?',array($id)); foreach($fs as $f) plm_delete_real_file($f['file_path']);
        foreach(array('plm_files','plm_tests','plm_flow_logs','plm_flow_steps','plm_models','plm_projects') as $t){ plm_pdo()->prepare('DELETE FROM `'.$t.'` WHERE '.($t==='plm_projects'?'id':'project_id').'=?')->execute(array($id)); }
        plm_audit('删除项目','project',$id,$id,'删除项目及PLM相关资料记录');
        plm_json(array('ok'=>true));
    }
    if ($action === 'upload_project_image') {
        $pid=(int)($_POST['project_id']??0); if(!$pid) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        list($file,$err)=plm_upload_one('file'); if($err) plm_json(array('ok'=>false,'error'=>$err));
        plm_dyn_update('plm_projects',array('image_path'=>$file['file_path'],'updated_at'=>plm_now()),'id=?',array($pid));
        plm_dyn_insert('plm_files', array_merge($file,array('project_id'=>$pid,'category'=>'项目图片','title'=>'项目主图','customer_visible'=>1,'created_at'=>plm_now())));
        plm_audit('上传项目主图','file',0,$pid,$file['original_name'] ?? '');
        plm_json(array('ok'=>true,'path'=>$file['file_path']));
    }
    if ($action === 'save_model') {
        $d=plm_input(); $id=(int)($d['id']??0); $pid=(int)($d['project_id']??0); if(!$pid) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        $allow=array('project_id','name','model','sample_no','power','beam','cct','cri','qty','status','led','driver','optic','connector','shell','finish','package_info','note'); $data=array('updated_at'=>plm_now()); foreach($allow as $k) if(array_key_exists($k,$d)) $data[$k]=$d[$k];
        if($id) { plm_dyn_update('plm_models',$data,'id=?',array($id)); plm_audit('保存样品','model',$id,$pid,'更新样品/型号'); }
        else { $data['created_at']=plm_now(); $id=plm_dyn_insert('plm_models',$data); plm_audit('新增样品','model',$id,$pid,'新增样品/型号；开发流程留空，可在导航图中用工艺路线编制生成'); }
        plm_json(array('ok'=>true,'id'=>$id));
    }
    if ($action === 'delete_model') {
        $d=plm_input(); $id=(int)($d['id']??0); $pid=0; if($id){ $m=plm_row('SELECT project_id FROM plm_models WHERE id=?',array($id)); $pid=(int)($m['project_id']??0); plm_pdo()->prepare('DELETE FROM plm_flow_logs WHERE model_id=?')->execute(array($id)); plm_pdo()->prepare('DELETE FROM plm_flow_steps WHERE model_id=?')->execute(array($id)); plm_pdo()->prepare('DELETE FROM plm_model_reviews WHERE model_id=?')->execute(array($id)); plm_pdo()->prepare('DELETE FROM plm_model_versions WHERE model_id=? OR source_model_id=?')->execute(array($id,$id)); plm_pdo()->prepare('DELETE FROM plm_models WHERE id=?')->execute(array($id)); plm_audit('删除样品','model',$id,$pid,'删除样品/型号及该样品独立开发流程'); } plm_json(array('ok'=>true));
    }
    if ($action === 'upload_model_image') {
        $mid=(int)($_POST['model_id']??0); $m=plm_row('SELECT project_id FROM plm_models WHERE id=?',array($mid)); if(!$m) plm_json(array('ok'=>false,'error'=>'找不到样品'));
        list($file,$err)=plm_upload_one('file'); if($err) plm_json(array('ok'=>false,'error'=>$err));
        plm_dyn_update('plm_models',array('image_path'=>$file['file_path'],'updated_at'=>plm_now()),'id=?',array($mid));
        plm_dyn_insert('plm_files', array_merge($file,array('project_id'=>$m['project_id'],'model_id'=>$mid,'category'=>'样品图片','title'=>'样品图片','customer_visible'=>1,'created_at'=>plm_now())));
        plm_audit('上传样品图片','file',0,(int)$m['project_id'],$file['original_name'] ?? '');
        plm_json(array('ok'=>true,'path'=>$file['file_path']));
    }
    if ($action === 'prepare_sample_flow') {
        $d=plm_input();
        try { $res = plm_prepare_sample_flow((int)($d['project_id']??0),(int)($d['model_id']??0)); plm_json(array('ok'=>true)+$res); }
        catch(Exception $e){ plm_json(array('ok'=>false,'error'=>$e->getMessage())); }
    }
    if ($action === 'ensure_default_steps') {
        $d=plm_input(); $pid=(int)($d['project_id']??0); $mid=(int)($d['model_id']??0);
        if($mid) { $count = plm_ensure_model_steps_complete($pid,$mid); plm_json(array('ok'=>true,'step_count'=>$count)); }
        else { try { $res = plm_prepare_sample_flow($pid,0); plm_json(array('ok'=>true)+$res); } catch(Exception $e){ plm_json(array('ok'=>false,'error'=>$e->getMessage())); } }
    }
    if ($action === 'delete_all_steps') {
        $d=plm_input();
        try{
            $pid=(int)($d['project_id']??0); $mid=(int)($d['model_id']??0);
            $count=plm_delete_model_steps_all($pid,$mid);
            plm_json(array('ok'=>true,'deleted'=>$count,'message'=>'已清空当前样品流程：'.$count.' 个步骤'));
        }catch(Exception $e){ plm_json(array('ok'=>false,'error'=>$e->getMessage())); }
    }
    if ($action === 'apply_route_template') {
        $d=plm_input();
        try{
            $pid=(int)($d['project_id']??0); $mid=(int)($d['model_id']??0);
            $replace = !empty($d['replace']);
            $names = array();
            if(isset($d['steps']) && is_array($d['steps'])) $names=$d['steps'];
            elseif(isset($d['steps_text'])) $names=preg_split('/[\r\n,，;；]+/', (string)$d['steps_text']);
            else $names=plm_route_template_steps($d['template']??'standard');
            $res=plm_apply_route_steps($pid,$mid,$names,$replace);
            plm_json(array('ok'=>true)+$res+array('message'=>($replace?'已替换当前样品工艺路线':'已追加当前样品工艺路线')));
        }catch(Exception $e){ plm_json(array('ok'=>false,'error'=>$e->getMessage())); }
    }
    if ($action === 'save_step') {
        $d=plm_input(); $id=(int)($d['id']??0); $pid=(int)($d['project_id']??0); if(!$pid) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        $mid=(int)($d['model_id']??0);
        if($id && !$mid){ $oldMid=plm_row('SELECT model_id FROM plm_flow_steps WHERE id=?',array($id)); $mid=(int)($oldMid['model_id']??0); }
        $status=$d['status']??''; $reason=trim($d['reason']??''); $now=plm_now();
        $data=array('project_id'=>$pid,'model_id'=>$mid,'updated_at'=>$now); foreach(array('step_name','sort_order','status','operator','planned_start','planned_end','note') as $k){ if(array_key_exists($k,$d)) $data[$k]=($d[$k]==='' && in_array($k,array('planned_start','planned_end')))?null:$d[$k]; }
        if($status){
            if($status==='开始' || $status==='进行中') { $data['started_at']=$now; $data['status']='进行中'; }
            if($status==='已完成' || $status==='通过') { $data['finished_at']=$now; $data['status']='已完成'; }
            if($status==='暂停') { $data['status']='暂停'; }
            if($status==='重来') { $data['status']='重来'; $data['started_at']=null; $data['finished_at']=null; $data['last_reason']=$reason; }
        }
        if($id) {
            $old=plm_row('SELECT * FROM plm_flow_steps WHERE id=?',array($id));
            if($status==='重来') $data['rework_count']=(int)($old['rework_count']??0)+1;
            if(($status==='已完成' || $status==='通过') && !empty($old['started_at'])) $data['actual_hours']=round((strtotime($now)-strtotime($old['started_at']))/3600,2);
            plm_dyn_update('plm_flow_steps',$data,'id=?',array($id));
        } else {
            if(empty($data['sort_order'])) { $r=plm_row('SELECT COALESCE(MAX(sort_order),0)+10 v FROM plm_flow_steps WHERE project_id=? AND model_id=?',array($pid,$mid)); $data['sort_order']=$r?$r['v']:10; }
            $data['created_at']=$now;
            try { $id=plm_dyn_insert('plm_flow_steps',$data); }
            catch(Exception $e) { plm_fix_flow_unique_indexes(); try { $id=plm_dyn_insert('plm_flow_steps',$data); } catch(Exception $e2) { plm_json(array('ok'=>false,'error'=>'新增流程失败：'.$e2->getMessage())); } }
        }
        if($status) plm_dyn_insert('plm_flow_logs',array('step_id'=>$id,'project_id'=>$pid,'model_id'=>$mid,'action'=>$status,'reason'=>$reason,'operator'=>$d['operator']??'','created_at'=>$now));
        plm_audit($status ? '流程操作：'.$status : '保存流程','step',$id,$pid,$reason,$d['operator']??'');
        plm_json(array('ok'=>true,'id'=>$id));
    }
    if ($action === 'delete_step') { $d=plm_input(); $id=(int)($d['id']??0); if($id){ $st=plm_row('SELECT project_id,model_id,step_name FROM plm_flow_steps WHERE id=?',array($id)); plm_pdo()->prepare('DELETE FROM plm_flow_logs WHERE step_id=?')->execute(array($id)); plm_pdo()->prepare('DELETE FROM plm_flow_steps WHERE id=?')->execute(array($id)); plm_audit('删除流程','step',$id,(int)($st['project_id']??0),'样品ID '.(int)($st['model_id']??0).' ｜ '.($st['step_name']??'')); } plm_json(array('ok'=>true)); }
    if ($action === 'reorder_steps') { $d=plm_input(); $ids=$d['ids']??array(); $i=1; foreach($ids as $id){ plm_dyn_update('plm_flow_steps',array('sort_order'=>$i*10,'updated_at'=>plm_now()),'id=?',array((int)$id)); $i++; } plm_json(array('ok'=>true)); }
    if ($action === 'save_test') {
        $d=plm_input(); $id=(int)($d['id']??0); $pid=(int)($d['project_id']??0); if(!$pid) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        $allow=array('project_id','model_id','test_type','title','status','result','tester','test_date','ambient_temp','led_temp','driver_temp','shell_temp','max_temp','ies_angle','ies_lumen','ies_power','ies_efficacy','ip_level','aging_hours','note');
        $data=array('updated_at'=>plm_now());
        foreach($allow as $k) if(array_key_exists($k,$d)) $data[$k]=($d[$k]==='' && in_array($k,array('test_date','ambient_temp','led_temp','driver_temp','shell_temp','max_temp')))?null:$d[$k];
        $mid=(int)($data['model_id'] ?? 0);
        if($mid) plm_ensure_model_steps_complete($pid,$mid);
        if($id) {
            if(!$mid){ $old=plm_row('SELECT model_id FROM plm_tests WHERE id=?',array($id)); $mid=(int)($old['model_id']??0); }
            plm_dyn_update('plm_tests',$data,'id=?',array($id));
            plm_audit('保存样品测试','test',$id,$pid,'样品ID '.$mid.' ｜ '.($data['test_type']??'').' '.($data['status']??''));
        }
        else {
            $data['created_at']=plm_now(); $id=plm_dyn_insert('plm_tests',$data);
            plm_audit('新增样品测试','test',$id,$pid,'样品ID '.$mid.' ｜ '.($data['test_type']??'').' '.($data['status']??''));
        }
        plm_sync_test_to_step($id);
        plm_json(array('ok'=>true,'id'=>$id));
    }
    if ($action === 'delete_test') { $d=plm_input(); $id=(int)($d['id']??0); if($id){ $t=plm_row('SELECT project_id,test_type,title FROM plm_tests WHERE id=?',array($id)); plm_pdo()->prepare('DELETE FROM plm_tests WHERE id=?')->execute(array($id)); plm_audit('删除测试','test',$id,(int)($t['project_id']??0),trim(($t['test_type']??'').' '.($t['title']??''))); } plm_json(array('ok'=>true)); }
    if ($action === 'upload_file') {
        $pid=(int)($_POST['project_id']??0); if(!$pid) plm_json(array('ok'=>false,'error'=>'缺少项目ID'));
        list($file,$err)=plm_upload_one('file'); if($err) plm_json(array('ok'=>false,'error'=>$err));
        $category = trim((string)($_POST['category']??'其它'));
        $testId = (int)($_POST['test_id']??0);
        $testCats = array('温升报告','IES文件','IES报告','积分球报告','积分球原始数据','EMC报告','IP测试','老化测试','测试图片','测试视频','其它测试资料');
        $fileCenterCats = array('产品图片','样品图片','加工图','BOM','报价单','PDF规格书','包装图','客户资料','安装说明','其它');
        if($testId<=0 && in_array($category,$testCats,true)) plm_json(array('ok'=>false,'error'=>'测试资料请到测试中心对应测试项上传'));
        if($testId<=0 && !in_array($category,$fileCenterCats,true)) $category='其它';
        $data=array_merge($file,array('project_id'=>$pid,'model_id'=>(int)($_POST['model_id']??0),'test_id'=>$testId,'category'=>$category,'title'=>$_POST['title']??$file['original_name'],'note'=>$_POST['note']??'','customer_visible'=>(int)($_POST['customer_visible']??1),'created_at'=>plm_now()));
        $id=plm_dyn_insert('plm_files',$data);
        plm_audit('上传附件','file',$id,$pid,($data['category']??'').' ｜ '.($data['title']??''));
        plm_json(array('ok'=>true,'id'=>$id,'file'=>plm_norm_file(array_merge($data,array('id'=>$id)))));
    }
    if ($action === 'delete_file') {
        $d=plm_input(); $id=(int)($d['id']??0);
        if(!$id) plm_json(array('ok'=>false,'error'=>'缺少附件ID'));
        $f=plm_row('SELECT project_id,file_path,title,original_name FROM plm_files WHERE id=?',array($id));
        if(!$f) plm_json(array('ok'=>false,'error'=>'附件不存在或已删除'));
        if(!empty($f['file_path'])) plm_delete_real_file($f['file_path']);
        $st=plm_pdo()->prepare('DELETE FROM plm_files WHERE id=?');
        $st->execute(array($id));
        plm_audit('删除附件','file',$id,(int)($f['project_id']??0),($f['title']?:($f['original_name']??'')));
        plm_json(array('ok'=>true,'deleted'=>$st->rowCount()));
    }
    if ($action === 'update_file_meta') {
        $d=plm_input(); $id=(int)($d['id']??0); if(!$id) plm_json(array('ok'=>false,'error'=>'缺少附件ID'));
        $data=array(); foreach(array('model_id','category','title','note','customer_visible') as $k){ if(array_key_exists($k,$d)) $data[$k]=$d[$k]; }
        if(array_key_exists('customer_visible',$data)) $data['customer_visible']=(int)$data['customer_visible'];
        if(array_key_exists('model_id',$data)) $data['model_id']=(int)$data['model_id'];
        plm_dyn_update('plm_files',$data,'id=?',array($id));
        $f=plm_row('SELECT project_id,title,original_name FROM plm_files WHERE id=?',array($id));
        plm_audit('保存附件资料','file',$id,(int)($f['project_id']??0),($f['title']?:($f['original_name']??'')));
        plm_json(array('ok'=>true));
    }
    if ($action === 'create_doc_package') {
        $d=plm_input();
        $res = plm_create_doc_package($d['docs'] ?? array(), (int)($d['project_id'] ?? 0), (string)($d['title'] ?? ''), (string)($d['note'] ?? ''));
        if(!empty($res['ok'])) plm_audit('生成资料包','doc_package',0,(int)($d['project_id'] ?? 0),($res['package_code'] ?? '').' 共 '.($res['count'] ?? 0).' 项');
        plm_json($res);
    }
    if ($action === 'get_doc_package') {
        $d=plm_input(); $pkg = plm_get_doc_package($d['package_code'] ?? '');
        if(!$pkg) plm_json(array('ok'=>false,'error'=>'未找到资料包'));
        plm_json(array('ok'=>true,'package'=>$pkg));
    }

    if ($action === 'search_docs') {
        $d=plm_input(); $kw=trim($d['kw']??''); $ids=array(); $like=plm_like_param($kw);
        if($kw==='') {
            $ps=plm_rows('SELECT id FROM plm_projects ORDER BY id DESC LIMIT 20'); foreach($ps as $p) $ids[(int)$p['id']]=true;
        } else {
            $ps=plm_rows('SELECT id FROM plm_projects WHERE name LIKE ? OR customer LIKE ? OR project_no LIKE ? OR series LIKE ? OR model LIKE ? OR product_type LIKE ? ORDER BY id DESC LIMIT 80',array($like,$like,$like,$like,$like,$like)); foreach($ps as $p) $ids[(int)$p['id']]=true;
            $ms=plm_rows('SELECT project_id FROM plm_models WHERE name LIKE ? OR model LIKE ? OR sample_no LIKE ? OR led LIKE ? OR driver LIKE ? OR optic LIKE ? OR shell LIKE ? OR finish LIKE ? ORDER BY id DESC LIMIT 80',array($like,$like,$like,$like,$like,$like,$like,$like)); foreach($ms as $m) $ids[(int)$m['project_id']]=true;
            $fs=plm_rows('SELECT project_id FROM plm_files WHERE title LIKE ? OR original_name LIKE ? OR category LIKE ? OR note LIKE ? ORDER BY id DESC LIMIT 80',array($like,$like,$like,$like)); foreach($fs as $f) $ids[(int)$f['project_id']]=true;
            $ts=plm_rows('SELECT project_id FROM plm_tests WHERE title LIKE ? OR test_type LIKE ? OR status LIKE ? OR note LIKE ? ORDER BY id DESC LIMIT 80',array($like,$like,$like,$like)); foreach($ts as $t) $ids[(int)$t['project_id']]=true;
        }
        $out=array();
        foreach(array_keys($ids) as $pid){
            $p=plm_row('SELECT * FROM plm_projects WHERE id=?',array($pid)); if(!$p) continue;
            if(!empty($p['image_path'])) $out[]=array('source'=>'项目主图','project_id'=>$pid,'project_name'=>$p['name'],'customer'=>$p['customer'],'model_id'=>0,'model_name'=>'项目公共资料','category'=>'项目图片','title'=>$p['name'].' 主图','path'=>$p['image_path'],'file_type'=>'image','customer_visible'=>1);
            $mimgs=plm_rows('SELECT * FROM plm_models WHERE project_id=? AND image_path<>""',array($pid));
            foreach($mimgs as $m) $out[]=array('source'=>'样品图片','project_id'=>$pid,'project_name'=>$p['name'],'customer'=>$p['customer'],'model_id'=>(int)$m['id'],'model_name'=>plm_model_title((int)$m['id']),'category'=>'样品图片','title'=>trim(($m['name']??'').' '.($m['model']??'')).' 图片','path'=>$m['image_path'],'file_type'=>'image','customer_visible'=>1);
            $docs=plm_rows('SELECT f.*,m.name m_name,m.model m_model FROM plm_files f LEFT JOIN plm_models m ON m.id=f.model_id WHERE f.project_id=? ORDER BY f.model_id ASC,f.id DESC',array($pid));
            foreach($docs as $f){ $mid=(int)($f['model_id']??0); $out[]=array('source'=>'PLM文件','project_id'=>$pid,'project_name'=>$p['name'],'customer'=>$p['customer'],'model_id'=>$mid,'model_name'=>plm_model_title($mid),'category'=>$f['category'],'title'=>$f['title'] ?: $f['original_name'],'path'=>$f['file_path'],'file_type'=>$f['mime_type'],'customer_visible'=>$f['customer_visible'],'file_id'=>$f['id']); }
            $tests=plm_rows('SELECT t.*,m.name m_name,m.model m_model FROM plm_tests t LEFT JOIN plm_models m ON m.id=t.model_id WHERE t.project_id=? ORDER BY t.model_id ASC,t.id DESC',array($pid));
            foreach($tests as $t){ $mid=(int)($t['model_id']??0); $out[]=array('source'=>'测试记录','project_id'=>$pid,'project_name'=>$p['name'],'customer'=>$p['customer'],'model_id'=>$mid,'model_name'=>plm_model_title($mid),'category'=>$t['test_type'],'title'=>($t['title'] ?: $t['test_type']).' / '.$t['status'],'path'=>'','file_type'=>'test','customer_visible'=>1,'test_id'=>$t['id']); }
            if(plm_table_exists('plm_external_links')){
                $links=plm_rows('SELECT * FROM plm_external_links WHERE project_id=? ORDER BY model_id ASC,id DESC',array($pid));
                foreach($links as $ln){ $mid=(int)($ln['model_id']??0); $cat = ($ln['link_type']==='quote')?'报价':'BOM'; $out[]=array('source'=>'系统联动','project_id'=>$pid,'project_name'=>$p['name'],'customer'=>$p['customer'],'model_id'=>$mid,'model_name'=>plm_model_title($mid),'category'=>$cat,'title'=>$cat.'记录 #'.($ln['external_id']??''),'path'=>$ln['open_url']??'','file_type'=>'link','customer_visible'=>1,'link_id'=>$ln['id']); }
            }
        }
        plm_json(array('ok'=>true,'docs'=>$out));
    }

    if ($action === 'save_sample_review') {
        $d=plm_input(); plm_json(plm_save_sample_review($d));
    }
    if ($action === 'freeze_sample') {
        $d=plm_input(); plm_json(plm_freeze_sample($d));
    }
    if ($action === 'create_sample_version') {
        $d=plm_input(); plm_json(plm_create_sample_version($d));
    }
    if ($action === 'review_history') {
        $d=plm_input(); $mid=(int)($d['model_id']??0); plm_json(array('ok'=>true,'reviews'=>plm_review_history($mid),'versions'=>plm_version_history($mid)));
    }
    if ($action === 'bridge_detect' || $action === 'db_overview') {
        plm_json(array('ok'=>true,'bridge'=>plm_bridge_detect()));
    }
    if ($action === 'bridge_summary' || $action === 'refresh_bridge') {
        $d=plm_input();
        plm_json(array('ok'=>true,'link_summary'=>plm_bridge_summary((int)($d['project_id']??0))));
    }
    if ($action === 'save_bridge_config') {
        $d = plm_input();
        $cfg = $d['config'] ?? $d;
        if(isset($cfg['bom_table']) && $cfg['bom_table']!=='' && !plm_safe_ident($cfg['bom_table'])) plm_json(array('ok'=>false,'error'=>'BOM表名不安全'));
        if(isset($cfg['quote_table']) && $cfg['quote_table']!=='' && !plm_safe_ident($cfg['quote_table'])) plm_json(array('ok'=>false,'error'=>'报价表名不安全'));
        $cfg = plm_config_save_bridge($cfg);
        plm_json(array('ok'=>true,'config'=>$cfg));
    }
    if ($action === 'create_bom' || $action === 'one_click_bom') {
        $d=plm_input(); $mid=(int)($d['model_id']??0); $res=plm_create_bom_from_model($mid, true); $m=$mid?plm_row('SELECT project_id,name,model FROM plm_models WHERE id=?',array($mid)):null; plm_audit('一键生成BOM','model',$mid,(int)($m['project_id']??0),($res['ok']?'成功':'失败').' ｜ '.($res['message']??$res['error']??'')); plm_json($res);
    }
    if ($action === 'create_quote' || $action === 'one_click_quote') {
        $d=plm_input(); $mid=(int)($d['model_id']??0); $res=plm_create_quote_from_model($mid, true); $m=$mid?plm_row('SELECT project_id,name,model FROM plm_models WHERE id=?',array($mid)):null; plm_audit('生成报价','model',$mid,(int)($m['project_id']??0),($res['ok']?'成功':'失败').' ｜ '.($res['message']??$res['error']??'')); plm_json($res);
    }
    if ($action === 'handoffs') {
        $rows = plm_rows('SELECT * FROM plm_bom_handoffs ORDER BY id DESC LIMIT 100');
        plm_json(array('ok'=>true,'handoffs'=>$rows));
    }
    if ($action === 'search_materials') {
        $d = plm_input();
        $kw = trim($d['kw'] ?? '');
        $cat = trim($d['category'] ?? '');
        $limit = (int)($d['limit'] ?? 50);
        $mats = plm_search_materials($kw, $cat, $limit);
        plm_json(array('ok'=>true,'materials'=>$mats,'tables'=>plm_material_tables()));
    }
    if ($action === 'sync_bom') { $d=plm_input(); $mid=(int)($d['model_id']??0); $res=plm_sync_bom($mid); $m=$mid?plm_row('SELECT project_id FROM plm_models WHERE id=?',array($mid)):null; plm_audit('同步BOM回流','model',$mid,(int)($m['project_id']??0),($res['ok']?'成功':'失败').' ｜ '.($res['message']??$res['error']??'')); plm_json($res); }
    if ($action === 'sync_quote') { $d=plm_input(); $mid=(int)($d['model_id']??0); $res=plm_sync_quote($mid); $m=$mid?plm_row('SELECT project_id FROM plm_models WHERE id=?',array($mid)):null; plm_audit('同步报价','model',$mid,(int)($m['project_id']??0),($res['ok']?'成功':'失败').' ｜ '.($res['message']??$res['error']??'')); plm_json($res); }
    plm_json(array('ok'=>false,'error'=>'未知操作：'.$action));
} catch (Exception $e) {
    plm_json(array('ok'=>false,'error'=>$e->getMessage()));
}
