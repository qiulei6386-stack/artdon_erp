<?php
/**
 * Artdon Office - 命名中心 V3.0.8.32
 * 文件：naming.php
 *
 * 本版目标：接入官网实时同步、统一权限中心，同时保留布局、搜索、弹窗、日志与 PLM/BOM 兼容。
 * - 页面自动同步官网新增/修改/删除，支持手动强制同步与计划任务入口
 * - 恢复 naming_inbox / PLM 样品命名回写 / BOM 兼容字段
 * - 不使用实时轮询切换视图，避免大图/中图/小表来回跳
 * - 读取现有 naming_models / naming_rules / naming_inbox 数据
 * - 官网同步型号允许权限内编辑，官网新增/修改/删除仍会回写命名中心
 */
declare(strict_types=1);

@ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/includes/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_name('ARTDON_SYS');
    @session_set_cookie_params(array('lifetime'=>86400*30,'path'=>'/','httponly'=>true,'samesite'=>'Lax'));
    @session_start();
}
// 命名中心脚本和列表更新频繁，禁止浏览器/代理复用旧 HTML 与旧内联脚本。
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 统一登录/权限中心软加载：真正的拦截放到数据库表检查之后执行，避免缺列时白屏。
$GLOBALS['NM_SSO_CORE_AVAILABLE'] = false;
$GLOBALS['NM_SSO_CORE_ERROR'] = '';
$__artdon_sso_core = __DIR__ . '/includes/artdon_sso_core.php';
if (is_file($__artdon_sso_core)) {
    try {
        include_once $__artdon_sso_core;
        $GLOBALS['NM_SSO_CORE_AVAILABLE'] = function_exists('artdon_sso_current_user');
    } catch (Throwable $e) {
        $GLOBALS['NM_SSO_CORE_ERROR'] = $e->getMessage();
        @error_log('[naming v3.0.8.6] SSO soft load failed: '.$e->getMessage());
    }
}

const NAMING_VERSION = '3.0.8.32';
const NM_UPLOAD_LIMIT = 512000; // 500KB
const NM_UPLOAD_DIR = __DIR__ . '/uploads/naming';
const NM_BACKUP_DIR = __DIR__ . '/uploads/naming_backups';
const NM_WEBSITE_BASE = 'https://artdonlighting.com';
if (!defined('NM_WEBSITE_SYNC_API')) {
    define('NM_WEBSITE_SYNC_API', 'http://43.132.210.162/api/naming_product_feed.php');
}
const NM_WEBSITE_SYNC_INTERVAL = 60; // 页面实时同步最短间隔，避免官网/API被频繁打爆。
const NM_WEBSITE_SYNC_HTTP_TIMEOUT = 6;

function nm_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nm_js($v): string { return htmlspecialchars(json_encode((string)$v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); }
function nm_s($v, int $max = 0): string {
    $v = trim((string)($v ?? ''));
    if ($max > 0) {
        if (function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
        return substr($v, 0, $max);
    }
    return $v;
}
function nm_json($data = null, bool $ok = true, string $msg = ''): void {
    @ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>$ok,'msg'=>$msg,'data'=>$data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function nm_fail(string $msg): void { nm_json(null, false, $msg); }

function nm_require_config(): void {
    $cfg = __DIR__ . '/config.php';
    if (is_file($cfg)) require_once $cfg;
    $auth = __DIR__ . '/crm_plm_auth_lib.php';
    if (is_file($auth)) require_once $auth;
}
function nm_pdo(): PDO {
    nm_require_config();
    foreach (array('crm_plm_pdo','db','get_pdo') as $fn) {
        if (function_exists($fn)) {
            try {
                $pdo = $fn();
                if ($pdo instanceof PDO) {
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    return $pdo;
                }
            } catch (Throwable $e) {}
        }
    }
    $host = defined('DB_HOST') ? DB_HOST : (defined('MYSQL_HOST') ? MYSQL_HOST : '127.0.0.1');
    $name = defined('DB_NAME') ? DB_NAME : (defined('MYSQL_DB') ? MYSQL_DB : '');
    $user = defined('DB_USER') ? DB_USER : (defined('MYSQL_USER') ? MYSQL_USER : '');
    $pass = defined('DB_PASS') ? DB_PASS : (defined('MYSQL_PASS') ? MYSQL_PASS : '');
    $port = defined('DB_PORT') ? DB_PORT : 3306;
    if ($name === '' || $user === '') throw new RuntimeException('数据库配置不完整：请确认 config.php 的 DB_HOST / DB_NAME / DB_USER / DB_PASS。');
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4')
    );
}
function nm_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute(array($table));
    return (int)$st->fetchColumn() > 0;
}
function nm_col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute(array($table,$col));
    return (int)$st->fetchColumn() > 0;
}
function nm_cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute(array($table));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: array() as $c) $out[] = (string)$c;
    return $out;
}
function nm_add_col(PDO $pdo, string $table, string $col, string $ddl): void {
    if (!nm_col_exists($pdo, $table, $col)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
}
function nm_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_rules(
      id INT AUTO_INCREMENT PRIMARY KEY,
      category VARCHAR(80) NOT NULL,
      item_name VARCHAR(120) NOT NULL,
      prefix VARCHAR(10) NOT NULL,
      size_label VARCHAR(80) NOT NULL DEFAULT '开孔/孔宽',
      default_size VARCHAR(10) NOT NULL DEFAULT '075',
      seq_digits TINYINT NOT NULL DEFAULT 2,
      no_four TINYINT NOT NULL DEFAULT 1,
      enabled TINYINT NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_prefix(prefix), KEY idx_category(category), KEY idx_enabled(enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_models(
      id INT AUTO_INCREMENT PRIMARY KEY,
      model_no VARCHAR(60) NOT NULL,
      rule_id INT NULL,
      category VARCHAR(80) NOT NULL DEFAULT '',
      item_name VARCHAR(120) NOT NULL DEFAULT '',
      prefix VARCHAR(10) NOT NULL DEFAULT '',
      size_code VARCHAR(10) NOT NULL DEFAULT '',
      serial_no VARCHAR(10) NOT NULL DEFAULT '',
      product_name VARCHAR(180) NOT NULL DEFAULT '',
      customer VARCHAR(180) NOT NULL DEFAULT '',
      status VARCHAR(30) NOT NULL DEFAULT '草稿',
      remark TEXT NULL,
      image_path VARCHAR(500) NOT NULL DEFAULT '',
      drawing_path VARCHAR(500) NOT NULL DEFAULT '',
      created_by VARCHAR(80) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_model_no(model_no), KEY idx_rule_id(rule_id), KEY idx_prefix_size(prefix,size_code), KEY idx_category(category), KEY idx_created_at(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (array(
        'dimension_type'=>"`dimension_type` VARCHAR(30) NOT NULL DEFAULT ''",
        'dim_opening'=>"`dim_opening` VARCHAR(60) NOT NULL DEFAULT ''",
        'dim_outer_d'=>"`dim_outer_d` VARCHAR(60) NOT NULL DEFAULT ''",
        'dim_length'=>"`dim_length` VARCHAR(60) NOT NULL DEFAULT ''",
        'dim_width'=>"`dim_width` VARCHAR(60) NOT NULL DEFAULT ''",
        'dim_height'=>"`dim_height` VARCHAR(60) NOT NULL DEFAULT ''",
        'updated_by'=>"`updated_by` VARCHAR(80) NOT NULL DEFAULT ''",
        // 官网同步/来源字段：V3.0.8.7 起用于官网实时增删改同步。
        'source_system'=>"`source_system` VARCHAR(60) NOT NULL DEFAULT ''",
        'source_id'=>"`source_id` VARCHAR(120) NOT NULL DEFAULT ''",
        'source_url'=>"`source_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'source_synced_at'=>"`source_synced_at` DATETIME NULL",
        'source_image_url'=>"`source_image_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'source_drawing_url'=>"`source_drawing_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'web_series'=>"`web_series` VARCHAR(180) NOT NULL DEFAULT ''",
        'web_size_name'=>"`web_size_name` VARCHAR(180) NOT NULL DEFAULT ''",
        'web_dimensions'=>"`web_dimensions` VARCHAR(300) NOT NULL DEFAULT ''",
        'web_image_url'=>"`web_image_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'web_dimension_url'=>"`web_dimension_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'web_drawing_url'=>"`web_drawing_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'web_size_image_url'=>"`web_size_image_url` VARCHAR(500) NOT NULL DEFAULT ''",
        'series_name'=>"`series_name` VARCHAR(180) NOT NULL DEFAULT ''",
        'lamp_type'=>"`lamp_type` VARCHAR(120) NOT NULL DEFAULT ''",
        'source_hash'=>"`source_hash` VARCHAR(64) NOT NULL DEFAULT ''",
        'source_updated_at'=>"`source_updated_at` DATETIME NULL",
        'website_sync_managed'=>"`website_sync_managed` TINYINT(1) NOT NULL DEFAULT 0",
        'website_last_seen_at'=>"`website_last_seen_at` DATETIME NULL",
        'website_sync_run_id'=>"`website_sync_run_id` VARCHAR(40) NOT NULL DEFAULT ''",
        'website_deleted'=>"`website_deleted` TINYINT(1) NOT NULL DEFAULT 0",
        'website_deleted_at'=>"`website_deleted_at` DATETIME NULL",
        'web_slug'=>"`web_slug` VARCHAR(180) NOT NULL DEFAULT ''",
        'source_payload_json'=>"`source_payload_json` MEDIUMTEXT NULL",
        // BOM 兼容字段：BOM/报价侧如读取这些字段，不会因本次单文件重排丢失。
        'bom_template_type'=>"`bom_template_type` VARCHAR(80) NOT NULL DEFAULT ''",
        'bom_modules_json'=>"`bom_modules_json` MEDIUMTEXT DEFAULT NULL",
        'bom_allowed'=>"`bom_allowed` TINYINT(1) NOT NULL DEFAULT 1",
        'bom_unit'=>"`bom_unit` VARCHAR(30) NOT NULL DEFAULT 'PCS'",
        'bom_head_count'=>"`bom_head_count` INT NOT NULL DEFAULT 1",
        'bom_ready_note'=>"`bom_ready_note` VARCHAR(255) NOT NULL DEFAULT ''"
    ) as $c=>$ddl) {
        try { nm_add_col($pdo, 'naming_models', $c, $ddl); } catch (Throwable $e) {}
    }

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM naming_rules")->fetchColumn();
    if ($cnt === 0) {
        $rules = array(
            array('嵌入式灯具型号命名','有边固定','51','开孔/孔宽','075',1),
            array('嵌入式灯具型号命名','有边可调','52','开孔/孔宽','075',2),
            array('嵌入式灯具型号命名','有边凸出','53','开孔/孔宽','075',3),
            array('嵌入式灯具型号命名','有边线性','55','开孔/孔宽','075',4),
            array('嵌入式灯具型号命名','嵌入式圆筒','56','开孔/孔宽','075',5),
            array('嵌入式无边灯具型号命名','无边固定','60','开孔/孔宽','075',10),
            array('嵌入式无边灯具型号命名','无边可调','57','开孔/孔宽','075',11),
            array('嵌入式无边灯具型号命名','无边凸出','58','开孔/孔宽','075',12),
            array('嵌入式无边灯具型号命名','无边线性','59','开孔/孔宽','075',13),
            array('K条线性灯具型号命名','嵌入式','21','灯体宽度','075',20),
            array('K条线性灯具型号命名','明装式','22','灯体宽度','075',21),
            array('导轨式灯具型号命名','带电器盒','31','灯体直径或宽','075',30),
            array('导轨式灯具型号命名','不带电器盒','32','灯体直径或宽','075',31),
            array('导轨式灯具型号命名','方形灯具','33','灯体直径或宽','075',32),
            array('导轨式灯具型号命名','带电器盒多颗','35','灯体直径或宽','075',33),
            array('导轨式灯具型号命名','不带电器盒多颗','36','灯体直径或宽','075',34),
            array('磁吸灯具型号命名','磁吸SMD','61','灯体直径或宽','075',40),
            array('磁吸灯具型号命名','磁吸COB','62','灯体直径或宽','075',41),
            array('磁吸灯具型号命名','磁吸COB吊装','63','灯体直径或宽','075',42),
            array('磁吸灯具型号命名','磁吸SMD吊装','65','灯体直径或宽','075',43)
        );
        $st = $pdo->prepare("INSERT INTO naming_rules(category,item_name,prefix,size_label,default_size,seq_digits,no_four,enabled,sort_order) VALUES(?,?,?,?,?,2,1,1,?)");
        foreach ($rules as $r) $st->execute($r);
    }
    // 官网同步型号如果分类/灯具类型被官网系列名覆盖，按型号前缀自动归回命名规则分类。
    nm_repair_website_category_by_model($pdo);
    nm_repair_website_dimensions_by_model($pdo);
    if (!is_dir(NM_UPLOAD_DIR)) @mkdir(NM_UPLOAD_DIR, 0755, true);
    nm_ensure_inbox($pdo);
    nm_ensure_logs($pdo);
    nm_ensure_settings($pdo);
}


function nm_model_prefix(string $modelNo): string {
    $modelNo = strtoupper(trim($modelNo));
    if ($modelNo === '') return '';
    if (preg_match('/^([A-Z0-9]{1,10})[\.\-]/', $modelNo, $m)) return (string)$m[1];
    if (preg_match('/^([0-9]{2})/', $modelNo, $m)) return (string)$m[1];
    return '';
}
function nm_rule_is_embedded(array $rule): bool {
    $txt = nm_s($rule['category'] ?? '').' '.nm_s($rule['item_name'] ?? '').' '.nm_s($rule['prefix'] ?? '');
    $prefix = nm_s($rule['prefix'] ?? '');
    if (preg_match('/嵌入|无边|有边/u', $txt)) return true;
    return in_array($prefix, array('51','52','53','55','56','57','58','59','60'), true);
}
function nm_rule_map_from_globals(): array {
    $map = array();
    $rules = $GLOBALS['rules'] ?? array();
    if (is_array($rules)) {
        foreach ($rules as $r) {
            $p = strtoupper(nm_s($r['prefix'] ?? ''));
            if ($p !== '') $map[$p] = $r;
        }
    }
    return $map;
}
function nm_infer_rule_from_row(array $row): array {
    $prefix = strtoupper(nm_s($row['prefix'] ?? ''));
    if ($prefix === '') $prefix = nm_model_prefix(nm_s($row['model_no'] ?? ''));
    if ($prefix === '') return array();
    $map = nm_rule_map_from_globals();
    return isset($map[$prefix]) && is_array($map[$prefix]) ? $map[$prefix] : array();
}
function nm_display_category(array $row): string {
    $rule = nm_infer_rule_from_row($row);
    if ($rule && nm_s($rule['category'] ?? '') !== '') return nm_s($rule['category']);
    return nm_folder_name($row);
}
function nm_display_lamp_type(array $row): string {
    $rule = nm_infer_rule_from_row($row);
    if ($rule && nm_s($rule['item_name'] ?? '') !== '') return nm_s($rule['item_name']);
    return nm_lamp_type($row);
}
function nm_repair_website_category_by_model(PDO $pdo): void {
    try {
        if (!nm_table_exists($pdo, 'naming_models') || !nm_table_exists($pdo, 'naming_rules')) return;
        $cols = nm_cols($pdo, 'naming_models');
        if (!in_array('model_no', $cols, true) || !in_array('category', $cols, true) || !in_array('item_name', $cols, true)) return;
        $rules = $pdo->query("SELECT prefix,category,item_name FROM naming_rules WHERE prefix<>'' ORDER BY sort_order ASC,id ASC")->fetchAll() ?: array();
        if (!$rules) return;
        $webCond = nm_website_condition_sql($cols);
        foreach ($rules as $r) {
            $prefix = nm_s($r['prefix'] ?? '');
            if ($prefix === '') continue;
            $sets = array('category=?','item_name=?');
            $args = array(nm_s($r['category'] ?? ''), nm_s($r['item_name'] ?? ''));
            if (in_array('prefix', $cols, true)) { $sets[] = "prefix=?"; $args[] = $prefix; }
            if (in_array('lamp_type', $cols, true)) { $sets[] = "lamp_type=?"; $args[] = nm_s($r['item_name'] ?? ''); }
            // 只修官网同步行，或分类/类型为空的残缺行；不覆盖正常本地手工型号。
            $sql = 'UPDATE naming_models SET '.implode(',', $sets).' WHERE model_no LIKE ? AND ('.$webCond." OR category='' OR item_name='')";
            $args[] = $prefix.'.%';
            $st = $pdo->prepare($sql);
            $st->execute($args);
        }
    } catch (Throwable $e) {
        @error_log('[naming v'.NAMING_VERSION.'] repair website category failed: '.$e->getMessage());
    }
}


function nm_repair_website_dimensions_by_model(PDO $pdo): void {
    try {
        if (!nm_table_exists($pdo, 'naming_models') || !nm_table_exists($pdo, 'naming_rules')) return;
        $cols = nm_cols($pdo, 'naming_models');
        if (!in_array('model_no', $cols, true)) return;
        $needCols = array('dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','size_code','prefix');
        foreach (array('dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height') as $c) if (!in_array($c, $cols, true)) return;
        $webCond = nm_website_condition_sql($cols);
        if ($webCond === '0=1') return;
        $rows = $pdo->query('SELECT * FROM naming_models WHERE '.$webCond.' ORDER BY id DESC LIMIT 5000')->fetchAll() ?: array();
        foreach ($rows as $row) {
            $model = nm_s($row['model_no'] ?? '');
            if ($model === '') continue;
            $prefix = nm_s($row['prefix'] ?? '');
            if ($prefix === '') $prefix = nm_model_prefix($model);
            $sizeCode = nm_s($row['size_code'] ?? '');
            if ($sizeCode === '' && strpos($model, '.') !== false) {
                $after = explode('.', $model, 2)[1] ?? '';
                $digits = preg_replace('/\D+/', '', $after);
                if (strlen($digits) >= 3) $sizeCode = substr($digits, 0, 3);
            }
            $rule = nm_website_rule_by_prefix($pdo, $prefix);
            $raw = $row;
            if (!empty($row['source_payload_json'])) {
                $payload = json_decode((string)$row['source_payload_json'], true);
                if (is_array($payload)) $raw = array_merge($payload, $raw);
            }
            $dims = nm_website_parse_dimensions($raw, $rule, $sizeCode);
            $sets = array(); $args = array();
            foreach ($dims as $c=>$v) {
                if (!in_array($c, $cols, true)) continue;
                // 只补空值，避免覆盖手工已经修好的本地编辑。
                if (nm_s($row[$c] ?? '') === '' && nm_s($v) !== '') { $sets[] = "`{$c}`=?"; $args[] = $v; }
            }
            if (in_array('size_code', $cols, true) && nm_s($row['size_code'] ?? '') === '' && $sizeCode !== '') { $sets[]='`size_code`=?'; $args[]=$sizeCode; }
            if (in_array('prefix', $cols, true) && nm_s($row['prefix'] ?? '') === '' && $prefix !== '') { $sets[]='`prefix`=?'; $args[]=$prefix; }
            if ($sets) {
                $args[] = (int)$row['id'];
                $pdo->prepare('UPDATE naming_models SET '.implode(',', $sets).' WHERE id=?')->execute($args);
            }
        }
    } catch (Throwable $e) {
        @error_log('[naming v'.NAMING_VERSION.'] repair website dimensions failed: '.$e->getMessage());
    }
}

function nm_current_user(): string {
    foreach ($_SESSION as $sk=>$sv) {
        if (is_scalar($sv) && preg_match('/(username|user_name|account|login_name|display_name|real_name|name|email)$/i', (string)$sk)) {
            $v = trim((string)$sv); if ($v !== '') return $v;
        }
        if (is_array($sv)) {
            foreach (array('display_name','real_name','name','nickname','username','user_name','account','email','login_name') as $k) {
                if (!empty($sv[$k]) && is_scalar($sv[$k])) return trim((string)$sv[$k]);
            }
        }
    }
    foreach (array('artdon_username','plm_username','crm_username','username','user_name','account','login_name') as $ck) {
        if (!empty($_COOKIE[$ck])) return trim((string)$_COOKIE[$ck]);
    }
    return '未识别账号';
}


function nm_perm_features(): array {
    return array(
        'view_models'=>array('label'=>'查看型号库','cap'=>'view'),
        'create_model'=>array('label'=>'新增/生成型号','cap'=>'create'),
        'edit_model'=>array('label'=>'修改型号档案','cap'=>'edit'),
        'delete_model'=>array('label'=>'删除型号档案','cap'=>'delete'),
        'disable_model'=>array('label'=>'停用/恢复型号','cap'=>'edit'),
        'confirm_model'=>array('label'=>'确认正式型号','cap'=>'edit'),
        'manage_rules'=>array('label'=>'管理命名规则','cap'=>'admin'),
        'manage_settings'=>array('label'=>'命名中心显示设置','cap'=>'admin'),
        'sync_website'=>array('label'=>'官网实时同步','cap'=>'admin'),
        'process_inbox'=>array('label'=>'领取和处理收件箱','cap'=>'edit'),
        'return_inbox'=>array('label'=>'退回/要求补资料','cap'=>'edit'),
        'dispatch_create'=>array('label'=>'从命名中心创建派工待办','cap'=>'edit'),
        'export_csv'=>array('label'=>'导出型号 CSV','cap'=>'export'),
        'backup_json'=>array('label'=>'备份导出/列表','cap'=>'export'),
        'backup_restore'=>array('label'=>'备份导入/恢复/删除','cap'=>'admin')
    );
}
function nm_perm_safe_add_col(PDO $pdo, string $table, string $col, string $ddl): void { try { if (!nm_col_exists($pdo,$table,$col)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}"); } catch (Throwable $e) {} }
function nm_perm_safe_add_index(PDO $pdo, string $table, string $indexName, string $ddl): void {
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
        $st->execute(array($table,$indexName));
        if ((int)$st->fetchColumn()===0) $pdo->exec("ALTER TABLE `{$table}` ADD {$ddl}");
    } catch (Throwable $e) {}
}
function nm_perm_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS artdon_user_permissions(
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      module_key VARCHAR(60) NOT NULL,
      can_view TINYINT(1) NOT NULL DEFAULT 0,
      can_create TINYINT(1) NOT NULL DEFAULT 0,
      can_edit TINYINT(1) NOT NULL DEFAULT 0,
      can_delete TINYINT(1) NOT NULL DEFAULT 0,
      can_export TINYINT(1) NOT NULL DEFAULT 0,
      can_admin TINYINT(1) NOT NULL DEFAULT 0,
      data_scope VARCHAR(30) NOT NULL DEFAULT 'inherit',
      scope_json MEDIUMTEXT NULL,
      source_mode VARCHAR(30) NOT NULL DEFAULT 'central',
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_artdon_perm_user_module(user_id,module_key),
      KEY idx_module(module_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array(
        'can_view'=>"`can_view` TINYINT(1) NOT NULL DEFAULT 0", 'can_create'=>"`can_create` TINYINT(1) NOT NULL DEFAULT 0", 'can_edit'=>"`can_edit` TINYINT(1) NOT NULL DEFAULT 0", 'can_delete'=>"`can_delete` TINYINT(1) NOT NULL DEFAULT 0", 'can_export'=>"`can_export` TINYINT(1) NOT NULL DEFAULT 0", 'can_admin'=>"`can_admin` TINYINT(1) NOT NULL DEFAULT 0", 'data_scope'=>"`data_scope` VARCHAR(30) NOT NULL DEFAULT 'inherit'", 'scope_json'=>"`scope_json` MEDIUMTEXT NULL", 'source_mode'=>"`source_mode` VARCHAR(30) NOT NULL DEFAULT 'central'", 'updated_by'=>"`updated_by` VARCHAR(120) NOT NULL DEFAULT ''", 'updated_at'=>"`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ) as $c=>$ddl) nm_perm_safe_add_col($pdo,'artdon_user_permissions',$c,$ddl);
    nm_perm_safe_add_index($pdo,'artdon_user_permissions','uq_artdon_perm_user_module','UNIQUE KEY uq_artdon_perm_user_module(user_id,module_key)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS artdon_feature_permissions(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      module_key VARCHAR(60) NOT NULL,
      feature_key VARCHAR(120) NOT NULL,
      allowed TINYINT(1) NOT NULL DEFAULT 0,
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_artdon_feature_user_module_key(user_id,module_key,feature_key),
      KEY idx_module_feature(module_key,feature_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach(array('feature_key'=>"`feature_key` VARCHAR(120) NOT NULL DEFAULT ''", 'allowed'=>"`allowed` TINYINT(1) NOT NULL DEFAULT 0", 'updated_by'=>"`updated_by` VARCHAR(120) NOT NULL DEFAULT ''", 'updated_at'=>"`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") as $c=>$ddl) nm_perm_safe_add_col($pdo,'artdon_feature_permissions',$c,$ddl);
    nm_perm_safe_add_index($pdo,'artdon_feature_permissions','uq_artdon_feature_user_module_key','UNIQUE KEY uq_artdon_feature_user_module_key(user_id,module_key,feature_key)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS artdon_permission_audit_logs(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      actor_user_id INT NOT NULL DEFAULT 0,
      actor_username VARCHAR(120) NOT NULL DEFAULT '',
      target_user_id INT NOT NULL DEFAULT 0,
      module_key VARCHAR(60) NOT NULL DEFAULT '',
      action VARCHAR(80) NOT NULL DEFAULT '',
      before_json MEDIUMTEXT NULL,
      after_json MEDIUMTEXT NULL,
      note VARCHAR(1000) NOT NULL DEFAULT '',
      ip VARCHAR(80) NOT NULL DEFAULT '',
      user_agent VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_module_created(module_key,created_at), KEY idx_target(target_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function nm_perm_current_user(bool $sync=true): ?array {
    if (function_exists('artdon_sso_current_user')) {
        try { $u = artdon_sso_current_user($sync); return is_array($u) ? $u : null; } catch (Throwable $e) { return null; }
    }
    return null;
}
function nm_perm_is_admin($u): bool {
    $u = is_array($u) ? $u : array(); if (!$u) return false;
    if (function_exists('artdon_sso_role_is_admin')) { try { if (artdon_sso_role_is_admin($u)) return true; } catch (Throwable $e) {} }
    $raw = strtolower(trim((string)($u['username']??'').' '.(string)($u['role_name']??'').' '.(string)($u['role']??'').' '.(string)($u['department']??'')));
    return in_array(strtolower((string)($u['username']??'')), array('qiulei','boss','admin'), true) || strpos($raw,'boss')!==false || strpos($raw,'admin')!==false || strpos($raw,'owner')!==false || strpos($raw,'老板')!==false || strpos($raw,'管理员')!==false || strpos($raw,'超级')!==false;
}
function nm_perm_template_for_user(array $u): array {
    $roleRaw = strtolower((string)($u['username']??'').' '.(string)($u['role_name']??'').' '.(string)($u['role']??'').' '.(string)($u['department']??''));
    $admin = nm_perm_is_admin($u);
    $engineer = preg_match('/工程|研发|engineer|技术|结构/u', $roleRaw) === 1;
    $sales = preg_match('/业务|销售|sales/u', $roleRaw) === 1;
    $caps = array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0); // V3.0.8.7：默认可新建/编辑/导出，避免命名中心建好后员工不能维护自己型号。
    if ($admin) $caps = array('view'=>1,'create'=>1,'edit'=>1,'delete'=>1,'export'=>1,'admin'=>1);
    elseif ($engineer) $caps = array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0);
    elseif ($sales) $caps = array('view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'admin'=>0); // V3.0.8.8：命名中心先保证能维护型号，删除/规则/同步仍按管理员控制。
    $features = array();
    foreach (nm_perm_features() as $k=>$d) { $cap=(string)($d['cap']??'view'); $features[$k]=!empty($caps[$cap])?1:0; }
    if (!$admin) { $features['delete_model']=0; $features['manage_rules']=0; }
    return array('caps'=>$caps,'features'=>$features);
}
function nm_perm_read_module_row(PDO $pdo, int $userId): ?array {
    try { $st=$pdo->prepare("SELECT * FROM artdon_user_permissions WHERE user_id=? AND module_key='naming' LIMIT 1"); $st->execute(array($userId)); $r=$st->fetch(); return $r?:null; } catch (Throwable $e) { return null; }
}
function nm_perm_upsert_user(PDO $pdo, array $u, bool $force=false): void {
    $uid=(int)($u['id']??0); if($uid<=0) return;
    $tpl=nm_perm_template_for_user($u); $caps=$tpl['caps']; $features=$tpl['features']; $admin=nm_perm_is_admin($u);
    $roleRawForPerm = strtolower((string)($u['username']??'').' '.(string)($u['role_name']??'').' '.(string)($u['role']??'').' '.(string)($u['department']??''));
    $sales = preg_match('/业务|销售|sales/u', $roleRawForPerm) === 1;
    $row=nm_perm_read_module_row($pdo,$uid);
    $sum=$row?((int)($row['can_view']??0)+(int)($row['can_create']??0)+(int)($row['can_edit']??0)+(int)($row['can_delete']??0)+(int)($row['can_export']??0)+(int)($row['can_admin']??0)):0;
    $autoManagedRow = $row && strpos((string)($row['updated_by'] ?? ''), 'naming_v') === 0;
    $tooRestrictedAutoRow = $autoManagedRow && (empty($row['can_create']) || empty($row['can_edit'])) && empty($row['can_admin']);
    $needRow=$force || !$row || $sum===0 || $admin || $tooRestrictedAutoRow;
    if($needRow){
        $pdo->prepare("INSERT INTO artdon_user_permissions(user_id,module_key,can_view,can_create,can_edit,can_delete,can_export,can_admin,data_scope,scope_json,source_mode,updated_by,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_create=VALUES(can_create),can_edit=VALUES(can_edit),can_delete=VALUES(can_delete),can_export=VALUES(can_export),can_admin=VALUES(can_admin),data_scope=VALUES(data_scope),scope_json=VALUES(scope_json),source_mode=VALUES(source_mode),updated_by=VALUES(updated_by),updated_at=NOW()")
            ->execute(array($uid,'naming',$caps['view'],$caps['create'],$caps['edit'],$caps['delete'],$caps['export'],$caps['admin'],'inherit',json_encode(array('selected_user_ids'=>array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'central','naming_v'.NAMING_VERSION));
    }
    $hasFeature=false; $autoManagedFeature=false;
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM artdon_feature_permissions WHERE user_id=? AND module_key='naming'"); $st->execute(array($uid)); $hasFeature=(int)$st->fetchColumn()>0;
        $st=$pdo->prepare("SELECT COUNT(*) FROM artdon_feature_permissions WHERE user_id=? AND module_key='naming' AND updated_by LIKE 'naming_v%' AND feature_key IN ('create_model','edit_model') AND allowed=0"); $st->execute(array($uid)); $autoManagedFeature=(int)$st->fetchColumn()>0 && !$sales;
    } catch(Throwable $e) {}
    if($force || $admin || !$hasFeature || $autoManagedFeature){
        $pdo->prepare("DELETE FROM artdon_feature_permissions WHERE user_id=? AND module_key='naming'")->execute(array($uid));
        $st=$pdo->prepare("INSERT INTO artdon_feature_permissions(user_id,module_key,feature_key,allowed,updated_by,updated_at) VALUES(?,?,?,?,?,NOW())");
        foreach($features as $k=>$v) $st->execute(array($uid,'naming',$k,$v,'naming_v'.NAMING_VERSION));
    }
}
function nm_perm_bootstrap(PDO $pdo): void {
    static $done=false; if($done) return; $done=true;
    try {
        if (function_exists('artdon_naming_ensure_permissions')) artdon_naming_ensure_permissions();
    } catch(Throwable $e) {
        @error_log('[naming permission bootstrap] '.$e->getMessage());
    }
}
function nm_perm_json_forbid(string $msg, int $code=403): void {
    if(function_exists('artdon_sso_json_error')) artdon_sso_json_error($msg,$code,array('forbidden'=>true,'module'=>'naming'));
    nm_fail($msg);
}
function nm_perm_page_forbid(string $msg): void {
    http_response_code(403);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>无权限</title><body style="margin:0;background:#f4f7fb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,Arial;color:#0f172a;padding:32px"><div style="max-width:720px;margin:8vh auto;background:#fff;border:1px solid #dbe4ef;border-radius:22px;padding:26px;box-shadow:0 18px 50px rgba(15,23,42,.10)"><h1 style="margin-top:0">没有命名中心权限</h1><p>'.nm_h($msg).'</p><p><a href="index.php">返回首页</a>　<a href="logout.php">退出登录</a></p></div></body></html>';
    exit;
}
function nm_perm_login_user(bool $api=false): ?array {
    if(!function_exists('artdon_sso_current_user')) return null;
    $u=nm_perm_current_user(true);
    if(!$u){
        if($api) nm_perm_json_forbid('登录已失效，请重新登录',401);
        if(function_exists('artdon_sso_login_url')){ header('Location: '.artdon_sso_login_url()); exit; }
        nm_perm_page_forbid('请先登录。');
    }
    return $u;
}
function nm_perm_can(string $feature, ?array $u=null): bool {
    if(!function_exists('artdon_sso_current_user')) return false;
    $u=$u?:nm_perm_current_user(false); if(!$u) return false;
    if(function_exists('artdon_naming_ensure_permissions')) {
        try { artdon_naming_ensure_permissions(); } catch(Throwable $e) {}
    }
    if(function_exists('artdon_sso_can_feature')) {
        try { return (bool)artdon_sso_can_feature('naming',$feature,$u); } catch(Throwable $e) {}
    }
    $defs=nm_perm_features();
    $cap=(string)($defs[$feature]['cap']??'view');
    return function_exists('artdon_sso_can') ? (bool)artdon_sso_can('naming',$cap,$u) : false;
}
function nm_perm_runtime(): array {
    $u=nm_perm_current_user(false);
    $out=array('sso'=>function_exists('artdon_sso_current_user')?1:0,'user'=>nm_current_user(),'is_admin'=>$u&&nm_perm_is_admin($u)?1:0);
    foreach(array_keys(nm_perm_features()) as $f) $out[$f]=nm_perm_can($f,$u)?1:0;
    $out['view_logs']=nm_perm_can('view_logs',$u)?1:0;
    return $out;
}
function nm_perm_require(string $feature, string $label='', bool $api=true): void {
    $u=nm_perm_login_user($api);
    if(!function_exists('artdon_sso_current_user')) return;
    if(!nm_perm_can($feature,$u)){
        $defs=nm_perm_features(); $name=$label!==''?$label:(($defs[$feature]['label']??$feature));
        $msg='当前账号没有权限：'.$name;
        if($api) nm_perm_json_forbid($msg,403); else nm_perm_page_forbid($msg);
    }
}
function nm_perm_require_page(PDO $pdo): void {
    nm_perm_bootstrap($pdo);
    $u=nm_perm_login_user(false);
    if(function_exists('artdon_sso_current_user') && !nm_perm_can('view_models',$u)) nm_perm_page_forbid('当前账号没有进入命名中心/查看型号库的权限。');
}
function nm_perm_require_api(PDO $pdo, string $action): void {
    // 计划任务入口只允许跑官网同步，不打开其它 API。
    if (defined('NM_WEBSITE_SYNC_CRON') && NM_WEBSITE_SYNC_CRON && $action === 'realtime_sync_v19') return;
    nm_perm_bootstrap($pdo);
    $u=nm_perm_login_user(true);
    if(function_exists('artdon_sso_current_user') && !nm_perm_can('view_models',$u)) nm_perm_json_forbid('当前账号没有进入命名中心的权限',403);
    $map=array(
        'inbox_list'=>'process_inbox','inbox_submit'=>'process_inbox','submit_inbox'=>'process_inbox','create_inbox'=>'process_inbox','save_inbox'=>'process_inbox','inbox_claim'=>'process_inbox','inbox_link_model'=>'process_inbox','inbox_return'=>'return_inbox',
        'logs'=>'view_logs','save_drawing_settings'=>'manage_settings','backup_settings'=>'backup_json','save_backup_settings'=>'manage_settings','backup_list'=>'backup_json','backup_create'=>'backup_json','backup_download'=>'backup_json','backup_json'=>'backup_json','export_json'=>'backup_json','backup_import_json'=>'backup_restore','backup_restore_file'=>'backup_restore','backup_delete_file'=>'backup_restore','save_rule'=>'manage_rules','delete_rule'=>'manage_rules','next_model'=>'create_model','delete_model'=>'delete_model','disable_model'=>'disable_model','enable_model'=>'disable_model','dispatch_users'=>'dispatch_create','dispatch_create'=>'dispatch_create','export_csv'=>'export_csv'
    );
    if($action==='save_model'){
        $id=(int)($_POST['id']??0); nm_perm_require($id>0?'edit_model':'create_model',$id>0?'修改型号档案':'新增/生成型号',true); return;
    }
    if(isset($map[$action])) nm_perm_require($map[$action],'',true);
}

function nm_code_clean(string $s, int $max = 10): string {
    $s = strtoupper(trim($s));
    $s = preg_replace('/[^A-Z0-9]+/', '', $s);
    return $max > 0 ? substr($s, 0, $max) : $s;
}
function nm_digits(string $s, int $len = 3): string {
    $s = preg_replace('/\D+/', '', $s);
    if ($s === '') return '';
    return str_pad(substr($s, 0, $len), $len, '0', STR_PAD_LEFT);
}
function nm_model_clean(string $s): string {
    $s = strtoupper(trim($s));
    $s = str_replace(array('．','。','-',' '), array('.','.','',''), $s);
    return preg_replace('/[^A-Z0-9.]+/', '', $s);
}
function nm_has_four(string $s): bool { return strpos($s, '4') !== false; }
function nm_get_rule(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM naming_rules WHERE id=? LIMIT 1');
    $st->execute(array($id));
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('规则不存在。');
    return $r;
}
function nm_next_serial(PDO $pdo, string $prefix, string $size, int $digits = 2, bool $noFour = true): array {
    $base = strtoupper(trim($prefix)) . '.' . $size;
    $maxAllowed = (int)pow(10, max(1,$digits)) - 1;
    $st = $pdo->prepare('SELECT model_no FROM naming_models WHERE UPPER(model_no) LIKE ?');
    $st->execute(array($base.'%'));
    $maxUsed = -1;
    $used = array();
    $rx = '/^'.preg_quote($base,'/').'([0-9]{'.(int)$digits.'})$/i';
    foreach ($st->fetchAll() ?: array() as $row) {
        $mno = strtoupper(trim((string)($row['model_no'] ?? '')));
        if (preg_match($rx, $mno, $m)) { $n=(int)$m[1]; $used[$n]=1; if ($n>$maxUsed) $maxUsed=$n; }
    }
    for ($i=$maxUsed+1; $i<=$maxAllowed; $i++) {
        $serial = str_pad((string)$i, $digits, '0', STR_PAD_LEFT);
        if ($noFour && nm_has_four($serial)) continue;
        if (isset($used[$i])) continue;
        $model = $base.$serial;
        $ck = $pdo->prepare('SELECT id FROM naming_models WHERE model_no=? LIMIT 1');
        $ck->execute(array($model));
        if ($ck->fetch()) continue;
        return array($serial, $model);
    }
    throw new RuntimeException('这个规则和尺寸的后续流水号已用完，请增加流水号位数或调整规则。');
}
function nm_validate_unique_model(PDO $pdo, string $modelNo, int $ignoreId = 0): void {
    if (!preg_match('/^[A-Z0-9]+\.[A-Z0-9]+$/', $modelNo)) throw new RuntimeException('型号格式不正确，必须类似 51.07500。');
    if ($ignoreId > 0) {
        $st = $pdo->prepare('SELECT id FROM naming_models WHERE model_no=? AND id<>? LIMIT 1');
        $st->execute(array($modelNo,$ignoreId));
    } else {
        $st = $pdo->prepare('SELECT id FROM naming_models WHERE model_no=? LIMIT 1');
        $st->execute(array($modelNo));
    }
    if ($st->fetch()) throw new RuntimeException('型号已存在，不允许重复：'.$modelNo);
}

function nm_upload(string $field, string $oldPath = ''): string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $oldPath;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败：'.$field);
    if (($f['size'] ?? 0) > NM_UPLOAD_LIMIT) throw new RuntimeException('图片不能超过 500KB，请压缩后再上传。');
    $name = (string)($f['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','webp','gif','pdf'), true)) throw new RuntimeException('只允许上传 jpg / png / webp / gif / pdf。');
    $tmp = (string)($f['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('上传临时文件无效。');
    if (!is_dir(NM_UPLOAD_DIR) && !@mkdir(NM_UPLOAD_DIR,0755,true) && !is_dir(NM_UPLOAD_DIR)) throw new RuntimeException('上传目录创建失败：uploads/naming');
    if (!is_writable(NM_UPLOAD_DIR)) throw new RuntimeException('上传目录不可写：uploads/naming');
    $ym = date('Ym');
    $dir = NM_UPLOAD_DIR.'/'.$ym;
    if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('月份上传目录创建失败。');
    $safe = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!move_uploaded_file($tmp, $dir.'/'.$safe)) throw new RuntimeException('保存上传文件失败。');
    return 'uploads/naming/'.$ym.'/'.$safe;
}

function nm_delete_local_upload(string $path): void {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || preg_match('/^https?:\/\//i', $path)) return;
    if (strpos($path, 'uploads/naming/') !== 0) return;
    $full = realpath(__DIR__ . '/' . $path);
    $base = realpath(NM_UPLOAD_DIR);
    if (!$full || !$base) return;
    if (strpos($full, $base) !== 0) return;
    if (is_file($full)) @unlink($full);
}

function nm_clone_local_upload(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || preg_match('/^https?:\/\//i', $path)) return $path;
    if (strpos($path, 'uploads/naming/') !== 0) return $path;
    $full = realpath(__DIR__ . '/' . $path);
    $base = realpath(NM_UPLOAD_DIR);
    if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) return $path;
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','webp','gif','pdf'), true)) $ext = 'bin';
    $ym = date('Ym');
    $dir = NM_UPLOAD_DIR.'/'.$ym;
    if (!is_dir($dir) && !@mkdir($dir,0755,true) && !is_dir($dir)) return $path;
    $safe = date('Ymd_His').'_copy_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (@copy($full, $dir.'/'.$safe)) return 'uploads/naming/'.$ym.'/'.$safe;
    return $path;
}
function nm_file_is_image(string $url): bool { return (bool)preg_match('/\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i', $url); }

function nm_is_website_row(array $row): bool {
    $source = strtolower(nm_s($row['source_system'] ?? ''));
    if (in_array($source, array('website','web','hongkong_web','hk_web','official_website','artdon_website'), true)) return true;
    foreach (array('web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','source_url','source_synced_at') as $k) {
        if (isset($row[$k]) && nm_s($row[$k]) !== '') return true;
    }
    $txt = nm_s($row['remark'] ?? '').' '.nm_s($row['created_by'] ?? '').' '.nm_s($row['updated_by'] ?? '');
    return (strpos($txt, '官网') !== false && (strpos($txt, '同步') !== false || strpos($txt, '自动读取') !== false || strpos($txt, '实时') !== false));
}
function nm_asset_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^https?://#i', $path)) {
        $u = @parse_url($path);
        $host = strtolower((string)($u['host'] ?? ''));
        $p = (string)($u['path'] ?? '');
        if ($p !== '' && (strpos($p, '/uploads/website/') === 0 || strpos($p, '/uploads/products/') === 0)) {
            return rtrim(NM_WEBSITE_BASE, '/') . $p . (!empty($u['query']) ? '?'.$u['query'] : '');
        }
        if (strpos($host, 'gallin.cn') !== false || $host === '43.132.210.162') {
            if ($p !== '') return rtrim(NM_WEBSITE_BASE, '/') . $p;
        }
        return $path;
    }
    if (strpos($path, '//') === 0) return 'https:'.$path;
    $clean = preg_replace('/[?#].*$/', '', $path);
    if (strpos($clean, '/uploads/website/') === 0) return rtrim(NM_WEBSITE_BASE, '/') . $clean;
    if (strpos($clean, 'uploads/website/') === 0) return rtrim(NM_WEBSITE_BASE, '/') . '/' . $clean;
    if (strpos($clean, '/uploads/products/') === 0) return rtrim(NM_WEBSITE_BASE, '/') . $clean;
    if (strpos($clean, 'uploads/products/') === 0) return rtrim(NM_WEBSITE_BASE, '/') . '/' . $clean;
    return $path;
}
function nm_pick_media(array $row, string $type): string {
    $keys = $type === 'drawing'
        ? array('drawing_path','web_dimension_url','web_drawing_url','source_drawing_url','dimension_url','drawing_url','drawing_image','dimension_image','dimension_image_url','size_drawing_url','web_size_drawing_url','size_image','size_image_url','web_size_image_url')
        : array('image_path','web_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','cover_image_url','web_cover_url');
    foreach ($keys as $k) {
        if (isset($row[$k]) && nm_s($row[$k]) !== '') return nm_asset_url(nm_s($row[$k]));
    }
    return '';
}
function nm_series(array $row): string {
    foreach (array('web_series','series_name','product_name','website_display_name','category') as $k) {
        if (isset($row[$k]) && nm_s($row[$k]) !== '') return nm_s($row[$k]);
    }
    $remark = nm_s($row['remark'] ?? '');
    if (preg_match('/系列[:：]\s*([^;；\n\r]+)/u', $remark, $m)) return trim((string)$m[1]);
    return '未分系列';
}
function nm_lamp_type(array $row): string {
    foreach (array('lamp_type','item_name','web_size_name','category') as $k) {
        if (isset($row[$k]) && nm_s($row[$k]) !== '') return nm_s($row[$k]);
    }
    return '未分类';
}
function nm_mm($v): string {
    $v = nm_s($v);
    if ($v === '') return '';
    // V3.0.8.28：尺寸显示去掉前导 0。
    // 型号流水仍保留 035 / 045 这种三位尺寸代码；这里只影响页面显示。
    $v = trim((string)$v);
    if (preg_match('/mm$/i', $v)) {
        $v = preg_replace('/\s*mm$/i', '', $v);
    }
    $v = trim((string)$v);
    if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
        $v = preg_replace('/^0+(?=\d)/', '', $v);
        if ($v === '') $v = '0';
        if (strpos($v, '.') !== false) {
            $v = rtrim(rtrim($v, '0'), '.');
            if ($v === '') $v = '0';
        }
        if (strpos($v, '.') === 0) $v = '0'.$v;
    }
    return $v.'mm';
}
function nm_dim_text(array $r): string {
    // V3.0.8.28：尺寸显示去掉前导 0，开孔 035mm 统一显示为开孔 35mm。
    // 这样官网同步来的嵌入式型号也能显示“开孔 xxmm / 直径 xxmm / 高 xxmm”。
    $model = nm_s($r['model_no'] ?? '');
    $prefix = nm_s($r['prefix'] ?? '');
    if ($prefix === '') $prefix = nm_model_prefix($model);
    $sizeCode = nm_s($r['size_code'] ?? '');
    if ($sizeCode === '' && strpos($model, '.') !== false) {
        $after = explode('.', $model, 2)[1] ?? '';
        $digits = preg_replace('/\D+/', '', $after);
        if (strlen($digits) >= 3) $sizeCode = substr($digits, 0, 3);
    }
    $rule = nm_infer_rule_from_row($r);
    $isEmbedded = $rule ? nm_rule_is_embedded($rule) : (bool)preg_match('/嵌入|有边|无边|recess/i', (nm_s($r['category'] ?? '').' '.nm_s($r['item_name'] ?? '').' '.nm_s($r['lamp_type'] ?? '').' '.nm_s($r['product_name'] ?? '').' '.nm_s($r['web_series'] ?? '')));
    $opening = nm_s($r['dim_opening'] ?? '');
    $outer = nm_s($r['dim_outer_d'] ?? '');
    $length = nm_s($r['dim_length'] ?? '');
    $width = nm_s($r['dim_width'] ?? '');
    $height = nm_s($r['dim_height'] ?? '');
    // 老官网记录如果只存了 web_dimensions，则临时解析一次；数据库也会由修复函数后台补齐。
    if ($opening==='' && $outer==='' && $length==='' && $width==='' && $height==='') {
        $parsed = nm_website_parse_dimensions($r, $rule ?: array(), $sizeCode);
        $opening = nm_s($parsed['dim_opening'] ?? '');
        $outer = nm_s($parsed['dim_outer_d'] ?? '');
        $length = nm_s($parsed['dim_length'] ?? '');
        $width = nm_s($parsed['dim_width'] ?? '');
        $height = nm_s($parsed['dim_height'] ?? '');
    }
    if ($isEmbedded && $opening === '' && $sizeCode !== '') $opening = $sizeCode;
    $parts = array();
    if ($isEmbedded && $opening !== '') $parts[] = '开孔 '.nm_mm($opening);
    if ($outer !== '') $parts[] = '直径 '.nm_mm($outer);
    if ($length !== '') $parts[] = '长 '.nm_mm($length);
    if ($width !== '') $parts[] = '宽 '.nm_mm($width);
    if ($height !== '') $parts[] = '高 '.nm_mm($height);
    if ($parts) return implode(' / ', $parts);
    foreach (array('web_dimensions','dimensions','size_text') as $k) {
        if (isset($r[$k]) && nm_s($r[$k]) !== '') return nm_s($r[$k]);
    }
    if ($sizeCode !== '') return '尺寸 '.nm_mm($sizeCode);
    return '尺寸未填';
}
function nm_folder_name(array $r): string {
    $c = nm_s($r['category'] ?? '');
    return $c !== '' ? $c : '未分类';
}
function nm_badge_class(string $status): string {
    if (in_array($status, array('已确认','已量产','正常'), true)) return 'ok';
    if (in_array($status, array('停用','删除','禁用'), true)) return 'bad';
    return 'warn';
}
function nm_website_condition_sql(array $cols): string {
    $or = array();
    if (in_array('source_system', $cols, true)) $or[] = "LOWER(source_system) IN ('website','web','hongkong_web','hk_web','official_website','artdon_website')";
    foreach (array('web_series','web_size_name','web_dimensions','web_image_url','web_dimension_url','source_url') as $c) {
        if (in_array($c, $cols, true)) $or[] = "(`{$c}` IS NOT NULL AND `{$c}`<>'')";
    }
    if (in_array('remark', $cols, true)) $or[] = "(`remark` LIKE '%官网%' AND (`remark` LIKE '%同步%' OR `remark` LIKE '%自动读取%' OR `remark` LIKE '%实时%'))";
    if (in_array('created_by', $cols, true)) $or[] = "(`created_by` LIKE '%官网%' AND `created_by` LIKE '%同步%')";
    if (in_array('updated_by', $cols, true)) $or[] = "(`updated_by` LIKE '%官网%' AND `updated_by` LIKE '%同步%')";
    return $or ? '(' . implode(' OR ', $or) . ')' : '0=1';
}
function nm_build_where(array $cols, array $f, array &$args, bool $forFolder = false): string {
    $where = array();
    // V3.0.8.4 强模糊搜索：型号/无点型号/系列/官网系列/灯具类型/尺寸/备注都参与。
    // 同时兼容 kw / q 两种参数，避免旧页面或其它模块传 q 时搜索不到。
    $kw = nm_s($f['kw'] ?? ($f['q'] ?? ''));
    if ($kw !== '') {
        $kwLike = '%'.$kw.'%';
        $kwAlt = str_replace(array('．','。','-','_','/','\\','　'), array('.','.',' ',' ',' ',' ',' '), $kw);
        $kwCompact = strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $kw));
        $kwDigits = preg_replace('/\D+/', '', $kw);
        $searchCols = array_values(array_intersect(array(
            'model_no','product_name','series_name','web_series','website_series','website_display_name','web_size_name','web_dimensions',
            'lamp_type','item_name','category','prefix','size_code','serial_no','customer','remark','source_url',
            'name','title','series','type_name','product_type','product_category','size_name','dimension_text','dimensions','size_text'
        ), $cols));
        $or = array();
        foreach ($searchCols as $c) {
            $or[] = "COALESCE(`{$c}`,'') LIKE ?"; $args[] = $kwLike;
            if ($kwAlt !== $kw) { $or[] = "COALESCE(`{$c}`,'') LIKE ?"; $args[] = '%'.$kwAlt.'%'; }
        }
        if (in_array('model_no', $cols, true)) {
            // 型号专用模糊：56.、56、5605518、56 05518 都可命中 56.05518。
            $modelNeedles = array_unique(array_filter(array(
                $kw, $kwAlt, str_replace(array(' ', '　'), '', $kwAlt),
                str_replace(array('.', '．', '。', '-', '_', '/', '\\', ' ', '　'), '', $kw),
                $kwDigits
            ), static function($x){ return trim((string)$x) !== ''; }));
            foreach ($modelNeedles as $mn) {
                $mn = trim((string)$mn);
                $or[] = "COALESCE(`model_no`,'') LIKE ?"; $args[] = '%'.$mn.'%';
                $or[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`model_no`,'')),'.',''),'．',''),'。',''),'-',''),' ','') LIKE ?"; $args[] = '%'.strtoupper(str_replace(array('.', '．', '。', '-', '_', '/', '\\', ' ', '　'), '', $mn)).'%';
            }
        }
        if ($kwCompact !== '') {
            $needle = '%'.$kwCompact.'%';
            foreach ($searchCols as $c) {
                $expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`{$c}`,'')),' ',''),'　',''),'.',''),'-',''),'_',''),'/',''),'·',''),'×',''),'X','')";
                $or[] = $expr." LIKE ?"; $args[] = $needle;
            }
            if (in_array('model_no', $cols, true)) {
                $or[] = "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(`model_no`,'')),'.',''),'-',''),' ',''),'_','') LIKE ?";
                $args[] = $needle;
            }
        }
        if ($kwDigits !== '') {
            foreach (array('model_no','size_code','serial_no','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','web_dimensions','dimensions','size_text') as $c) {
                if (in_array($c, $cols, true)) { $or[] = "COALESCE(`{$c}`,'') LIKE ?"; $args[] = '%'.$kwDigits.'%'; }
            }
            if (in_array('prefix', $cols, true) && in_array('size_code', $cols, true) && in_array('serial_no', $cols, true)) {
                $or[] = "CONCAT(COALESCE(`prefix`,''),COALESCE(`size_code`,''),COALESCE(`serial_no`,'')) LIKE ?";
                $args[] = '%'.$kwDigits.'%';
            }
        }
        // 拆词也做模糊，但多个词必须分别命中。旧逻辑把所有词直接并入
        // 大 OR，导致 LITE PRO 中的 PRO 命中 source_url 的 product.php，
        // 从而把大量官网产品误判成搜索结果。
        $tokenGroups = array();
        $tokens = preg_split('/[\s,，;；]+/u', trim($kwAlt), -1, PREG_SPLIT_NO_EMPTY) ?: array();
        foreach ($tokens as $tk) {
            $tk = trim((string)$tk);
            if ($tk === '' || (function_exists('mb_strlen') ? mb_strlen($tk,'UTF-8') : strlen($tk)) < 2) continue;
            $tokenOr = array();
            foreach ($searchCols as $c) {
                $tokenOr[] = "COALESCE(`{$c}`,'') LIKE ?";
                $args[] = '%'.$tk.'%';
            }
            if ($tokenOr) $tokenGroups[] = '(' . implode(' OR ', $tokenOr) . ')';
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
        foreach ($tokenGroups as $tokenGroup) $where[] = $tokenGroup;
    }
    $map = array('category'=>'category','item_name'=>'item_name','prefix'=>'prefix','status'=>'status','customer'=>'customer');
    foreach ($map as $fk=>$col) {
        $v = nm_s($f[$fk] ?? '');
        if ($v !== '' && in_array($col, $cols, true)) {
            // 综合关键词用于跨文件夹找完整系列。旧 URL/浏览器状态里残留的
            // category 不应把同系列的有边、无边型号再次截断。
            if ($fk === 'category' && $kw !== '') continue;
            if ($fk === 'customer') { $where[] = "`{$col}` LIKE ?"; $args[] = '%'.$v.'%'; }
            else { $where[] = "`{$col}`=?"; $args[] = $v; }
        }
    }
    $series = nm_s($f['series'] ?? '');
    if ($series !== '') {
        $or = array();
        $seriesCompact = strtoupper(preg_replace('/[^A-Z0-9\x{4e00}-\x{9fa5}]+/iu', '', $series));
        foreach (array('web_series','series_name','website_series','website_display_name','product_name','category','lamp_type','item_name','series','title','name') as $c) {
            if (in_array($c, $cols, true)) {
                $or[] = "`{$c}` LIKE ?"; $args[] = '%'.$series.'%';
                if ($seriesCompact !== '') {
                    $or[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(`{$c}`),' ',''),'-',''),'_',''),'/',''),'·','') LIKE ?";
                    $args[] = '%'.$seriesCompact.'%';
                }
            }
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
    }
    $size = nm_s($f['size_code'] ?? '');
    if ($size !== '') {
        $or = array();
        $digits = preg_replace('/\D+/', '', $size);
        if ($digits !== '') {
            $size3 = str_pad(substr($digits,0,3),3,'0',STR_PAD_LEFT);
            foreach (array('size_code','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','web_dimensions') as $c) {
                if (in_array($c, $cols, true)) { $or[] = "`{$c}` LIKE ?"; $args[] = '%'.$size3.'%'; }
            }
        } else {
            foreach (array('web_dimensions','size_code') as $c) if (in_array($c,$cols,true)) { $or[]="`{$c}` LIKE ?"; $args[]='%'.$size.'%'; }
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if (nm_s($f['date_start'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', nm_s($f['date_start'])) && in_array('created_at',$cols,true)) { $where[]='DATE(created_at)>=?'; $args[]=nm_s($f['date_start']); }
    if (nm_s($f['date_end'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', nm_s($f['date_end'])) && in_array('created_at',$cols,true)) { $where[]='DATE(created_at)<=?'; $args[]=nm_s($f['date_end']); }
    $hasImage = nm_s($f['has_image'] ?? '');
    if ($hasImage !== '') {
        $imageCols = array_values(array_intersect(array('image_path','web_image_url','source_image_url','cover_image_url'), $cols));
        if ($imageCols) {
            $parts = array(); foreach ($imageCols as $c) $parts[] = "(`{$c}` IS NOT NULL AND `{$c}`<>'')";
            $cond = '(' . implode(' OR ', $parts) . ')';
            $where[] = $hasImage === 'yes' ? $cond : 'NOT '.$cond;
        }
    }
    $hasDrawing = nm_s($f['has_drawing'] ?? '');
    if ($hasDrawing !== '') {
        $drawingCols = array_values(array_intersect(array('drawing_path','web_dimension_url','web_drawing_url','web_size_image_url','source_drawing_url','dimension_url','drawing_url','size_image_url'), $cols));
        if ($drawingCols) {
            $parts = array(); foreach ($drawingCols as $c) $parts[] = "(`{$c}` IS NOT NULL AND `{$c}`<>'')";
            $cond = '(' . implode(' OR ', $parts) . ')';
            $where[] = $hasDrawing === 'yes' ? $cond : 'NOT '.$cond;
        }
    }
    $source = nm_s($f['source'] ?? '');
    if ($source === 'website') $where[] = nm_website_condition_sql($cols);
    if ($source === 'local') $where[] = 'NOT '.nm_website_condition_sql($cols);
    return $where ? ' WHERE '.implode(' AND ', $where) : '';
}
function nm_fetch_page(PDO $pdo, array $f, int $page, int $per): array {
    $cols = nm_cols($pdo, 'naming_models');
    $args = array();
    $where = nm_build_where($cols, $f, $args);
    $stc = $pdo->prepare('SELECT COUNT(*) FROM naming_models'.$where);
    $stc->execute($args);
    $total = (int)$stc->fetchColumn();
    $sort = nm_s($f['sort'] ?? 'created_desc');
    $orders = array(
        'created_desc' => in_array('created_at',$cols,true) ? 'created_at DESC,id DESC' : 'id DESC',
        'created_asc' => in_array('created_at',$cols,true) ? 'created_at ASC,id ASC' : 'id ASC',
        'updated_desc' => in_array('updated_at',$cols,true) ? 'updated_at DESC,id DESC' : 'id DESC',
        'model_asc' => 'model_no ASC,id DESC',
        'model_desc' => 'model_no DESC,id DESC',
        'category' => 'category ASC,item_name ASC,model_no ASC'
    );
    $order = $orders[$sort] ?? $orders['created_desc'];
    $offset = max(0, ($page-1)*$per);
    $sql = 'SELECT * FROM naming_models'.$where.' ORDER BY '.$order.' LIMIT '.(int)$offset.','.(int)$per;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return array('rows'=>$st->fetchAll() ?: array(), 'total'=>$total, 'cols'=>$cols);
}
function nm_distinct(PDO $pdo, string $col, int $limit = 500): array {
    if (!nm_col_exists($pdo, 'naming_models', $col)) return array();
    $rows = $pdo->query("SELECT DISTINCT `{$col}` FROM naming_models WHERE `{$col}`<>'' ORDER BY `{$col}` ASC LIMIT ".(int)$limit)->fetchAll(PDO::FETCH_COLUMN) ?: array();
    $out = array();
    foreach ($rows as $r) { $r=nm_s($r); if ($r!=='' && !in_array($r,$out,true)) $out[]=$r; }
    return $out;
}
function nm_folder_counts(PDO $pdo): array {
    if (!nm_col_exists($pdo, 'naming_models', 'category')) return array();
    $rows = $pdo->query("SELECT IF(category='', '未分类', category) AS folder_name, COUNT(*) AS c FROM naming_models GROUP BY IF(category='', '未分类', category) ORDER BY c DESC, folder_name ASC LIMIT 80")->fetchAll() ?: array();
    return $rows;
}

function nm_board_stats(PDO $pdo): array {
    $out = array(
        'total'=>0,'draft'=>0,'confirmed'=>0,'website'=>0,'today'=>0,'no_image'=>0,'no_drawing'=>0,
        'inbox_pending'=>0,'inbox_processing'=>0,'inbox_total'=>0,'updated_today'=>0
    );
    try {
        if (!nm_table_exists($pdo, 'naming_models')) return $out;
        $cols = nm_cols($pdo, 'naming_models');
        $out['total'] = (int)$pdo->query('SELECT COUNT(*) FROM naming_models')->fetchColumn();
        if (in_array('status', $cols, true)) {
            $st=$pdo->prepare("SELECT COUNT(*) FROM naming_models WHERE status=?");
            $st->execute(array('草稿')); $out['draft']=(int)$st->fetchColumn();
            $st->execute(array('已确认')); $out['confirmed']=(int)$st->fetchColumn();
        }
        if (in_array('created_at', $cols, true)) $out['today']=(int)$pdo->query("SELECT COUNT(*) FROM naming_models WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        if (in_array('updated_at', $cols, true)) $out['updated_today']=(int)$pdo->query("SELECT COUNT(*) FROM naming_models WHERE DATE(updated_at)=CURDATE()")->fetchColumn();
        $wc = nm_website_condition_sql($cols);
        if ($wc !== '0=1') $out['website']=(int)$pdo->query('SELECT COUNT(*) FROM naming_models WHERE '.$wc)->fetchColumn();
        $imageCols = array_values(array_intersect(array('image_path','web_image_url','source_image_url','cover_image_url'), $cols));
        if ($imageCols) {
            $parts=array(); foreach($imageCols as $c) $parts[]="(`{$c}` IS NOT NULL AND `{$c}`<>'')";
            $out['no_image']=(int)$pdo->query('SELECT COUNT(*) FROM naming_models WHERE NOT ('.implode(' OR ',$parts).')')->fetchColumn();
        }
        $drawingCols = array_values(array_intersect(array('drawing_path','web_dimension_url','web_drawing_url','web_size_image_url','source_drawing_url','dimension_url','drawing_url','size_image_url'), $cols));
        if ($drawingCols) {
            $parts=array(); foreach($drawingCols as $c) $parts[]="(`{$c}` IS NOT NULL AND `{$c}`<>'')";
            $out['no_drawing']=(int)$pdo->query('SELECT COUNT(*) FROM naming_models WHERE NOT ('.implode(' OR ',$parts).')')->fetchColumn();
        }
        if (nm_table_exists($pdo, 'naming_inbox')) {
            $out['inbox_total']=(int)$pdo->query('SELECT COUNT(*) FROM naming_inbox')->fetchColumn();
            foreach ($pdo->query("SELECT status,COUNT(*) c FROM naming_inbox GROUP BY status")->fetchAll() ?: array() as $r) {
                $stt = (string)($r['status'] ?? ''); $c=(int)($r['c'] ?? 0);
                if (in_array($stt, array('待处理','已退回'), true)) $out['inbox_pending'] += $c;
                if ($stt === '处理中') $out['inbox_processing'] += $c;
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function nm_sync_time(array $row): string {
    foreach (array('source_synced_at','web_synced_at','synced_at','source_updated_at','updated_at') as $k) {
        if (isset($row[$k]) && nm_s($row[$k]) !== '') return nm_s($row[$k]);
    }
    $remark = nm_s($row['remark'] ?? '');
    if (preg_match('/(20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?)/u', $remark, $m)) return str_replace('/','-',(string)$m[1]);
    return '';
}
function nm_request_ip(): string {
    foreach (array('HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k])) return substr(trim(explode(',', (string)$_SERVER[$k])[0]),0,80);
    }
    return '';
}
function nm_ensure_logs(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_logs(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      action VARCHAR(80) NOT NULL DEFAULT '',
      target_type VARCHAR(60) NOT NULL DEFAULT '',
      target_id BIGINT NOT NULL DEFAULT 0,
      model_no VARCHAR(80) NOT NULL DEFAULT '',
      username VARCHAR(120) NOT NULL DEFAULT '',
      detail_json MEDIUMTEXT NULL,
      ip VARCHAR(80) NOT NULL DEFAULT '',
      user_agent VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_naming_logs_action(action),
      KEY idx_naming_logs_target(target_type,target_id),
      KEY idx_naming_logs_model(model_no),
      KEY idx_naming_logs_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function nm_log(PDO $pdo, string $action, string $targetType = '', int $targetId = 0, string $modelNo = '', $detail = null): void {
    try {
        nm_ensure_logs($pdo);
        $json = $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== null && strlen($json) > 60000) $json = substr($json, 0, 60000);
        $st = $pdo->prepare('INSERT INTO naming_logs(action,target_type,target_id,model_no,username,detail_json,ip,user_agent) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute(array(nm_s($action,80), nm_s($targetType,60), $targetId, nm_s($modelNo,80), nm_current_user(), $json, nm_request_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255)));
    } catch (Throwable $e) {}
}

function nm_ensure_settings(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_settings(
      k VARCHAR(120) NOT NULL PRIMARY KEY,
      v VARCHAR(1000) NOT NULL DEFAULT '',
      updated_by VARCHAR(120) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function nm_setting_defaults(): array {
    return array(
        'drawing_scale_mode' => 'separate',
        'drawing_scale_all' => '120',
        'drawing_scale_local' => '120',
        'drawing_scale_website' => '100',
        'drawing_scale_other' => '100',
        'company_cn' => '中山雅大光电有限公司',
        'company_en' => 'Artdon Lighting Limited',
        'show_company_header' => '1',
        'watermark_enabled' => '1',
        'watermark_text_cn' => '中山雅大光电有限公司',
        'watermark_text_en' => 'Artdon Lighting Limited',
        'watermark_opacity' => '12',
        'watermark_scope_preview' => '1',
        'watermark_scope_edit' => '1',
        'watermark_scope_card' => '0',
        // V3.0.8.23：所有弹窗尺寸按区域可调。百分比按浏览器宽高计算。
        'modal_media_width' => '70',      // 产品图/尺寸图查看弹窗宽度（vw），约等于上一版放大 50%。
        'modal_media_height' => '66',     // 产品图/尺寸图查看框高度（vh）。
        'modal_model_width' => '74',      // 新建/编辑型号弹窗宽度（vw），V3.0.8.26 日志放到图片右侧。
        'modal_model_height' => '86',     // 新建/编辑型号弹窗最大高度（vh）。
        'model_upload_size' => '280',     // 新建/编辑里的产品图/尺寸图方形预览画布大小（px），外层文字/按钮不再挤占图片。
        'modal_config_width' => '76',     // 规则/筛选/显示设置弹窗宽度（vw）。
        'modal_data_width' => '76',       // 日志/备份/草稿/收件箱弹窗宽度（vw）。
        'auto_backup_enabled' => '1',
        'auto_backup_interval_hours' => '24',
        'auto_backup_keep' => '7',
        'auto_backup_replace' => '1',
        'auto_backup_last_at' => '',
        'auto_backup_last_file' => ''
    );
}
function nm_clamp_percent($v, int $min = 50, int $max = 300): int {
    $n = (int)preg_replace('/[^0-9]+/', '', (string)$v);
    if ($n <= 0) $n = ($min <= 0 ? 0 : 100);
    return max($min, min($max, $n));
}
function nm_setting_load(PDO $pdo): array {
    nm_ensure_settings($pdo);
    $out = nm_setting_defaults();
    try {
        $st = $pdo->query('SELECT k,v FROM naming_settings');
        foreach ($st->fetchAll() ?: array() as $r) {
            $k = (string)($r['k'] ?? '');
            if (array_key_exists($k, $out)) $out[$k] = (string)($r['v'] ?? '');
        }
    } catch (Throwable $e) {}
    $out['drawing_scale_mode'] = in_array($out['drawing_scale_mode'], array('same','separate'), true) ? $out['drawing_scale_mode'] : 'separate';
    foreach (array('drawing_scale_all','drawing_scale_local','drawing_scale_website','drawing_scale_other') as $k) $out[$k] = (string)nm_clamp_percent($out[$k]);
    $modalBounds = array(
        'modal_media_width'=>array(45,95),'modal_media_height'=>array(35,90),
        'modal_model_width'=>array(50,95),'modal_model_height'=>array(60,94),'model_upload_size'=>array(240,380),'modal_config_width'=>array(50,95),'modal_data_width'=>array(50,95)
    );
    foreach ($modalBounds as $k=>$b) $out[$k] = (string)nm_clamp_percent($out[$k], $b[0], $b[1]);
    foreach (array('company_cn','company_en','watermark_text_cn','watermark_text_en') as $k) $out[$k] = nm_s($out[$k], 120);
    foreach (array('show_company_header','watermark_enabled','watermark_scope_preview','watermark_scope_edit','watermark_scope_card','auto_backup_enabled','auto_backup_replace') as $k) $out[$k] = in_array((string)$out[$k], array('1','true','yes','on'), true) ? '1' : '0';
    $out['watermark_opacity'] = (string)nm_clamp_percent($out['watermark_opacity'], 0, 35);
    $out['auto_backup_interval_hours'] = (string)nm_clamp_percent($out['auto_backup_interval_hours'], 1, 720);
    $out['auto_backup_keep'] = (string)nm_clamp_percent($out['auto_backup_keep'], 1, 30);
    if ($out['company_cn'] === '') $out['company_cn'] = '中山雅大光电有限公司';
    if ($out['company_en'] === '') $out['company_en'] = 'Artdon Lighting Limited';
    if ($out['watermark_text_cn'] === '') $out['watermark_text_cn'] = $out['company_cn'];
    if ($out['watermark_text_en'] === '') $out['watermark_text_en'] = $out['company_en'];
    return $out;
}
function nm_setting_save(PDO $pdo, array $raw): array {
    nm_ensure_settings($pdo);
    $cur = nm_setting_load($pdo);
    $mode = nm_s($raw['drawing_scale_mode'] ?? $cur['drawing_scale_mode'], 20);
    $cur['drawing_scale_mode'] = in_array($mode, array('same','separate'), true) ? $mode : 'separate';
    foreach (array('drawing_scale_all','drawing_scale_local','drawing_scale_website','drawing_scale_other') as $k) {
        if (array_key_exists($k, $raw)) $cur[$k] = (string)nm_clamp_percent($raw[$k]);
    }
    $modalBounds = array(
        'modal_media_width'=>array(45,95),'modal_media_height'=>array(35,90),
        'modal_model_width'=>array(50,95),'modal_model_height'=>array(60,94),'model_upload_size'=>array(240,380),'modal_config_width'=>array(50,95),'modal_data_width'=>array(50,95)
    );
    foreach ($modalBounds as $k=>$b) {
        if (array_key_exists($k, $raw)) $cur[$k] = (string)nm_clamp_percent($raw[$k], $b[0], $b[1]);
    }
    foreach (array('company_cn','company_en','watermark_text_cn','watermark_text_en') as $k) {
        if (array_key_exists($k, $raw)) $cur[$k] = nm_s($raw[$k], 120);
    }
    foreach (array('show_company_header','watermark_enabled','watermark_scope_preview','watermark_scope_edit','watermark_scope_card') as $k) {
        if (array_key_exists($k, $raw)) $cur[$k] = !empty($raw[$k]) && (string)$raw[$k] !== '0' ? '1' : '0';
    }
    if (array_key_exists('watermark_opacity', $raw)) $cur['watermark_opacity'] = (string)nm_clamp_percent($raw['watermark_opacity'], 0, 35);
    if ($cur['company_cn'] === '') $cur['company_cn'] = '中山雅大光电有限公司';
    if ($cur['company_en'] === '') $cur['company_en'] = 'Artdon Lighting Limited';
    if ($cur['watermark_text_cn'] === '') $cur['watermark_text_cn'] = $cur['company_cn'];
    if ($cur['watermark_text_en'] === '') $cur['watermark_text_en'] = $cur['company_en'];
    $st = $pdo->prepare('INSERT INTO naming_settings(k,v,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_by=VALUES(updated_by),updated_at=NOW()');
    foreach ($cur as $k=>$v) $st->execute(array($k,(string)$v,nm_current_user()));
    nm_log($pdo, 'settings.save', 'settings', 0, '', $cur);
    return $cur;
}

function nm_setting_put_many(PDO $pdo, array $pairs): void {
    nm_ensure_settings($pdo);
    $st = $pdo->prepare('INSERT INTO naming_settings(k,v,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_by=VALUES(updated_by),updated_at=NOW()');
    foreach ($pairs as $k=>$v) $st->execute(array(nm_s((string)$k,120), nm_s((string)$v,1000), nm_current_user()));
}
function nm_backup_dir(): string {
    if (!is_dir(NM_BACKUP_DIR)) @mkdir(NM_BACKUP_DIR, 0775, true);
    if (!is_dir(NM_BACKUP_DIR) || !is_writable(NM_BACKUP_DIR)) throw new RuntimeException('备份目录不可写：uploads/naming_backups，请检查目录权限。');
    $ht = NM_BACKUP_DIR . '/.htaccess';
    if (!is_file($ht)) @file_put_contents($ht, "Order deny,allow\nDeny from all\n");
    return NM_BACKUP_DIR;
}
function nm_backup_tables(): array {
    return array('naming_rules','naming_models','naming_settings','naming_inbox','naming_logs','naming_website_sync_runs','naming_website_deleted_archive');
}
function nm_backup_payload(PDO $pdo): array {
    nm_ensure_tables($pdo);
    nm_ensure_settings($pdo);
    $payload = array('version'=>NAMING_VERSION,'exported_at'=>date('Y-m-d H:i:s'),'exported_by'=>nm_current_user(),'type'=>'full','tables'=>array());
    foreach (nm_backup_tables() as $t) {
        if (!nm_table_exists($pdo, $t)) continue;
        $order = nm_col_exists($pdo,$t,'id') ? 'id ASC' : (nm_col_exists($pdo,$t,'k') ? 'k ASC' : '1');
        $payload['tables'][$t] = $pdo->query('SELECT * FROM `'.$t.'` ORDER BY '.$order)->fetchAll() ?: array();
    }
    $payload['models'] = $payload['tables']['naming_models'] ?? array();
    $payload['rules'] = $payload['tables']['naming_rules'] ?? array();
    return $payload;
}
function nm_backup_filename(string $type='manual'): string {
    $type = preg_replace('/[^a-z0-9_\-]+/i','_', $type) ?: 'manual';
    return 'artdon_naming_'.$type.'_'.date('Ymd_His').'.json';
}
function nm_backup_list_files(): array {
    $dir = nm_backup_dir();
    $files = glob($dir.'/artdon_naming_*.json') ?: array();
    usort($files, function($a,$b){ return filemtime($b) <=> filemtime($a); });
    $out = array();
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $bn = basename($f);
        $type = (strpos($bn,'artdon_naming_auto_')===0) ? 'auto' : ((strpos($bn,'artdon_naming_safety_')===0) ? 'safety' : 'manual');
        $out[] = array('file'=>$bn,'type'=>$type,'size'=>filesize($f),'mtime'=>date('Y-m-d H:i:s', filemtime($f)));
    }
    return $out;
}
function nm_backup_safe_path(string $file): string {
    $file = basename($file);
    if (!preg_match('/^artdon_naming_[A-Za-z0-9_\-]+\.json$/', $file)) throw new RuntimeException('备份文件名不合法。');
    $path = nm_backup_dir().'/'.$file;
    if (!is_file($path)) throw new RuntimeException('备份文件不存在：'.$file);
    return $path;
}
function nm_backup_prune(PDO $pdo, ?array $settings = null): void {
    $settings = $settings ?: nm_setting_load($pdo);
    if (($settings['auto_backup_replace'] ?? '1') !== '1') return;
    $keep = max(1, min(30, (int)($settings['auto_backup_keep'] ?? 7)));
    $auto = array_values(array_filter(nm_backup_list_files(), function($r){ return ($r['type'] ?? '') === 'auto'; }));
    for ($i=$keep; $i<count($auto); $i++) { try { @unlink(nm_backup_safe_path((string)$auto[$i]['file'])); } catch (Throwable $e) {} }
}
function nm_backup_write(PDO $pdo, string $type='manual'): array {
    $dir = nm_backup_dir();
    $payload = nm_backup_payload($pdo);
    $payload['backup_type'] = $type;
    $file = nm_backup_filename($type);
    $path = $dir.'/'.$file;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents($path.'.tmp', $json, LOCK_EX) === false) throw new RuntimeException('写入备份失败，请检查 uploads/naming_backups 权限。');
    @rename($path.'.tmp', $path);
    nm_backup_prune($pdo);
    nm_log($pdo, 'backup.create', 'backup', 0, '', array('file'=>$file,'type'=>$type));
    return array('file'=>$file,'size'=>filesize($path),'mtime'=>date('Y-m-d H:i:s', filemtime($path)));
}
function nm_backup_auto_maybe(PDO $pdo, array $settings): void {
    try {
        if (($settings['auto_backup_enabled'] ?? '1') !== '1') return;
        $interval = max(1, min(720, (int)($settings['auto_backup_interval_hours'] ?? 24))) * 3600;
        $last = strtotime((string)($settings['auto_backup_last_at'] ?? '')) ?: 0;
        if ($last > 0 && time() - $last < $interval) return;
        $res = nm_backup_write($pdo, 'auto');
        nm_setting_put_many($pdo, array('auto_backup_last_at'=>date('Y-m-d H:i:s'), 'auto_backup_last_file'=>$res['file']));
    } catch (Throwable $e) { try { nm_log($pdo, 'backup.auto_error', 'backup', 0, '', $e->getMessage()); } catch (Throwable $ignore) {} }
}
function nm_backup_settings_save(PDO $pdo, array $raw): array {
    $cur = nm_setting_load($pdo);
    $cur['auto_backup_enabled'] = !empty($raw['auto_backup_enabled']) && (string)$raw['auto_backup_enabled'] !== '0' ? '1' : '0';
    $cur['auto_backup_replace'] = !empty($raw['auto_backup_replace']) && (string)$raw['auto_backup_replace'] !== '0' ? '1' : '0';
    $cur['auto_backup_interval_hours'] = (string)nm_clamp_percent($raw['auto_backup_interval_hours'] ?? $cur['auto_backup_interval_hours'], 1, 720);
    $cur['auto_backup_keep'] = (string)nm_clamp_percent($raw['auto_backup_keep'] ?? $cur['auto_backup_keep'], 1, 30);
    nm_setting_put_many($pdo, array(
        'auto_backup_enabled'=>$cur['auto_backup_enabled'], 'auto_backup_replace'=>$cur['auto_backup_replace'],
        'auto_backup_interval_hours'=>$cur['auto_backup_interval_hours'], 'auto_backup_keep'=>$cur['auto_backup_keep']
    ));
    nm_backup_prune($pdo, $cur);
    nm_log($pdo, 'backup.settings.save', 'backup', 0, '', $cur);
    return nm_setting_load($pdo);
}
function nm_backup_col_meta(PDO $pdo, string $table): array {
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $out = array();
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`')->fetchAll() ?: array();
        foreach ($rows as $r) {
            $out[(string)$r['Field']] = array(
                'type' => strtolower((string)($r['Type'] ?? '')),
                'null' => strtoupper((string)($r['Null'] ?? '')) === 'YES',
                'default' => $r['Default'] ?? null,
                'extra' => strtolower((string)($r['Extra'] ?? '')),
            );
        }
    } catch (Throwable $e) {}
    return $cache[$table] = $out;
}
function nm_backup_normalize_row(PDO $pdo, string $table, array $data): array {
    $meta = nm_backup_col_meta($pdo, $table);
    foreach ($data as $col => $value) {
        $m = $meta[$col] ?? null;
        if (!$m) continue;
        $type = (string)($m['type'] ?? '');
        $isDateTime = (bool)preg_match('/\b(date|datetime|timestamp|time|year)\b/i', $type);
        if (!$isDateTime) continue;
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            if (!empty($m['null'])) {
                $data[$col] = null;
            } else {
                unset($data[$col]);
            }
            continue;
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime($value);
            if ($ts === false) {
                if (!empty($m['null'])) $data[$col] = null;
                else unset($data[$col]);
            }
        }
    }
    return $data;
}
function nm_backup_insert_row(PDO $pdo, string $table, array $row, string $mode): bool {
    $cols = nm_cols($pdo, $table);
    if (!$cols) return false;
    $data = array();
    foreach ($row as $k=>$v) if (in_array((string)$k,$cols,true)) $data[(string)$k] = $v;
    $data = nm_backup_normalize_row($pdo, $table, $data);
    if (!$data) return false;
    if ($mode === 'merge' && $table === 'naming_models' && !empty($data['model_no']) && in_array('model_no',$cols,true)) {
        $st=$pdo->prepare('SELECT id FROM naming_models WHERE model_no=? LIMIT 1'); $st->execute(array((string)$data['model_no'])); $id=(int)$st->fetchColumn();
        if ($id>0) { unset($data['id']); $sets=array(); $args=array(); foreach($data as $c=>$v){$sets[]='`'.$c.'`=?'; $args[]=$v;} $args[]=$id; $pdo->prepare('UPDATE naming_models SET '.implode(',',$sets).' WHERE id=?')->execute($args); return true; }
    }
    if ($table === 'naming_settings' && isset($data['k'])) {
        $pdo->prepare('INSERT INTO naming_settings(k,v,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_by=VALUES(updated_by),updated_at=NOW()')->execute(array((string)$data['k'], (string)($data['v'] ?? ''), nm_current_user()));
        return true;
    }
    $names = array_keys($data);
    $marks = array_fill(0, count($names), '?');
    $updates = array();
    foreach ($names as $c) if ($c !== 'id') $updates[] = '`'.$c.'`=VALUES(`'.$c.'`)';
    $sql = 'INSERT INTO `'.$table.'`(`'.implode('`,`',$names).'`) VALUES('.implode(',',$marks).')'.($updates ? ' ON DUPLICATE KEY UPDATE '.implode(',',$updates) : '');
    $pdo->prepare($sql)->execute(array_values($data));
    return true;
}
function nm_backup_restore_payload(PDO $pdo, array $payload, string $mode='merge'): array {
    $mode = $mode === 'replace' ? 'replace' : 'merge';
    nm_ensure_tables($pdo);
    $tables = $payload['tables'] ?? array();
    if (!$tables) {
        if (isset($payload['rules'])) $tables['naming_rules'] = $payload['rules'];
        if (isset($payload['models'])) $tables['naming_models'] = $payload['models'];
    }
    $allowed = nm_backup_tables();
    $stats = array();
    if ($mode === 'replace') nm_backup_write($pdo, 'safety');
    $pdo->beginTransaction();
    try {
        if ($mode === 'replace') {
            foreach ($allowed as $t) if (nm_table_exists($pdo,$t)) $pdo->exec('DELETE FROM `'.$t.'`');
        }
        foreach ($allowed as $t) {
            $rows = $tables[$t] ?? array();
            if (!is_array($rows) || !nm_table_exists($pdo,$t)) continue;
            $n = 0;
            foreach ($rows as $row) if (is_array($row) && nm_backup_insert_row($pdo,$t,$row,$mode)) $n++;
            $stats[$t] = $n;
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    nm_log($pdo, 'backup.restore', 'backup', 0, '', array('mode'=>$mode,'stats'=>$stats,'version'=>$payload['version'] ?? ''));
    return array('mode'=>$mode,'stats'=>$stats);
}
function nm_backup_payload_from_upload(): array {
    if (!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) $txt = file_get_contents($_FILES['backup_file']['tmp_name']);
    else $txt = file_get_contents('php://input');
    $data = json_decode((string)$txt, true);
    if (!is_array($data)) throw new RuntimeException('备份文件不是有效 JSON。');
    return $data;
}


/* ===== V3.0.8.7 官网实时同步 START ===== */
function nm_cfg_first(array $names, string $default = ''): string {
    foreach ($names as $n) {
        if (defined($n)) {
            $v = constant($n);
            if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
        }
        $env = getenv($n);
        if ($env !== false && trim((string)$env) !== '') return trim((string)$env);
    }
    return $default;
}
function nm_website_sync_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_website_sync_state(
      k VARCHAR(80) NOT NULL PRIMARY KEY,
      v MEDIUMTEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_website_sync_runs(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      run_id VARCHAR(40) NOT NULL DEFAULT '',
      source VARCHAR(120) NOT NULL DEFAULT '',
      status VARCHAR(30) NOT NULL DEFAULT '',
      fetched_count INT NOT NULL DEFAULT 0,
      created_count INT NOT NULL DEFAULT 0,
      updated_count INT NOT NULL DEFAULT 0,
      deleted_count INT NOT NULL DEFAULT 0,
      skipped_count INT NOT NULL DEFAULT 0,
      error_text MEDIUMTEXT NULL,
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      PRIMARY KEY(id),
      KEY idx_run_id(run_id), KEY idx_status(status), KEY idx_finished(finished_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_website_deleted_archive(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      model_id BIGINT NOT NULL DEFAULT 0,
      model_no VARCHAR(80) NOT NULL DEFAULT '',
      source_id VARCHAR(120) NOT NULL DEFAULT '',
      payload_json MEDIUMTEXT NULL,
      deleted_by_sync_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_model_no(model_no), KEY idx_source_id(source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function nm_website_state_get(PDO $pdo, string $k, string $default = ''): string {
    nm_website_sync_ensure($pdo);
    $st = $pdo->prepare('SELECT v FROM naming_website_sync_state WHERE k=? LIMIT 1');
    $st->execute(array($k));
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function nm_website_state_set(PDO $pdo, string $k, string $v): void {
    nm_website_sync_ensure($pdo);
    $pdo->prepare('INSERT INTO naming_website_sync_state(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_at=NOW()')->execute(array($k,$v));
}
function nm_website_sync_status(PDO $pdo): array {
    nm_website_sync_ensure($pdo);
    $state = array();
    foreach ($pdo->query('SELECT k,v,updated_at FROM naming_website_sync_state')->fetchAll() ?: array() as $r) {
        $state[(string)$r['k']] = array('value'=>(string)($r['v'] ?? ''),'updated_at'=>(string)($r['updated_at'] ?? ''));
    }
    $run = null;
    try { $run = $pdo->query('SELECT * FROM naming_website_sync_runs ORDER BY id DESC LIMIT 1')->fetch(); } catch (Throwable $e) {}
    return array('state'=>$state,'last_run'=>$run?:array(),'version'=>NAMING_VERSION,'interval'=>NM_WEBSITE_SYNC_INTERVAL);
}
function nm_http_json(string $url, string &$err): array {
    $err = '';
    $ctx = stream_context_create(array('http'=>array('method'=>'GET','timeout'=>NM_WEBSITE_SYNC_HTTP_TIMEOUT,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nUser-Agent: Artdon-Naming-Sync/".NAMING_VERSION."\r\n"), 'ssl'=>array('verify_peer'=>false,'verify_peer_name'=>false)));
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim((string)$raw) === '') { $err = '官网接口无返回：'.$url; return array(); }
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) { $err = '官网接口不是 JSON：'.$url; return array(); }
    return $j;
}
function nm_arr_is_list_compat(array $a): bool {
    if (function_exists('array_is_list')) return array_is_list($a);
    $i=0; foreach(array_keys($a) as $k){ if($k !== $i++) return false; } return true;
}
function nm_any(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && is_scalar($row[$k]) && nm_s($row[$k]) !== '') return nm_s($row[$k]);
    }
    foreach ($keys as $k) {
        foreach ($row as $rk=>$rv) {
            if (is_scalar($rv) && strtolower((string)$rk) === strtolower((string)$k) && nm_s($rv) !== '') return nm_s($rv);
        }
    }
    return $default;
}
function nm_website_abs_url(string $path): string {
    $path = nm_s($path, 500);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return nm_asset_url($path);
    if (strpos($path, '//') === 0) return 'https:'.$path;
    return rtrim(NM_WEBSITE_BASE, '/') . '/' . ltrim($path, '/');
}
function nm_website_sync_flatten($data, array &$out, array $base = array()): void {
    if (!is_array($data)) return;
    if (isset($data['ok']) && array_key_exists('data', $data)) { nm_website_sync_flatten($data['data'], $out, $base); return; }
    foreach (array('rows','products','list','items','data','models','result','records') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { nm_website_sync_flatten($data[$k], $out, $base); return; }
    }
    if (nm_arr_is_list_compat($data)) {
        foreach ($data as $r) nm_website_sync_flatten($r, $out, $base);
        return;
    }
    $childKeys = array('sizes','variants','children','items','models','skus');
    foreach ($childKeys as $ck) {
        if (isset($data[$ck]) && is_array($data[$ck])) {
            $base2 = $data; unset($base2[$ck]);
            foreach ($data[$ck] as $child) if (is_array($child)) nm_website_sync_flatten(array_merge($base2, $child), $out, $base);
            return;
        }
    }
    $out[] = array_merge($base, $data);
}
function nm_website_num(string $v): string {
    $v = str_replace(array('mm','MM','毫米','Φ','Ø'), '', trim($v));
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $v, $m)) return (string)$m[1];
    return '';
}
function nm_website_datetime(string $v): ?string {
    $v = nm_s($v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
function nm_website_rule_by_prefix(PDO $pdo, string $prefix): array {
    if ($prefix === '' || !nm_table_exists($pdo, 'naming_rules')) return array();
    $st = $pdo->prepare('SELECT * FROM naming_rules WHERE prefix=? LIMIT 1');
    $st->execute(array($prefix));
    $r = $st->fetch();
    return is_array($r) ? $r : array();
}
function nm_website_parse_dimensions(array $row, array $rule, string $sizeCode): array {
    $txt = nm_any($row, array('web_dimensions','dimensions','dimension','dimension_text','size_text','size','spec','规格','尺寸'));
    $out = array('dimension_type'=>'','dim_opening'=>'','dim_outer_d'=>'','dim_length'=>'','dim_width'=>'','dim_height'=>'');
    $out['dim_opening'] = nm_website_num(nm_any($row, array('dim_opening','opening','opening_size','opening_mm','cutout','cutout_size','cutout_mm','cut_out','cut_out_size','hole','hole_size','hole_diameter','aperture','aperture_size','aperture_mm','cut_size','开孔','孔径')));
    $out['dim_outer_d'] = nm_website_num(nm_any($row, array('dim_outer_d','diameter','diameter_mm','outer_d','outer_diameter','outer_dia','outer_d_mm','dia','d','直径','外径')));
    $out['dim_length'] = nm_website_num(nm_any($row, array('dim_length','length','length_mm','len','l','长','长度')));
    $out['dim_width'] = nm_website_num(nm_any($row, array('dim_width','width','width_mm','w','宽','宽度')));
    $out['dim_height'] = nm_website_num(nm_any($row, array('dim_height','height','height_mm','h','高','高度')));
    if ($txt !== '') {
        if ($out['dim_opening'] === '' && preg_match('/(?:开孔|孔径|cut\s*out|cutout|hole|aperture|opening)[^0-9]{0,12}([0-9]+(?:\.[0-9]+)?)/iu', $txt, $m)) $out['dim_opening']=(string)$m[1];
        if ($out['dim_outer_d'] === '' && preg_match('/(?:直径|外径|diameter|outer|dia|[ΦØ])[^0-9]{0,12}([0-9]+(?:\.[0-9]+)?)/iu', $txt, $m)) $out['dim_outer_d']=(string)$m[1];
        if ($out['dim_height'] === '' && preg_match('/(?:高|高度|height|\bH)[^0-9]{0,12}([0-9]+(?:\.[0-9]+)?)/iu', $txt, $m)) $out['dim_height']=(string)$m[1];
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*[x×X\*]\s*([0-9]+(?:\.[0-9]+)?)(?:\s*[x×X\*]\s*([0-9]+(?:\.[0-9]+)?))?/u', $txt, $m)) {
            if ($out['dim_length'] === '') $out['dim_length'] = (string)$m[1];
            if ($out['dim_width'] === '') $out['dim_width'] = (string)$m[2];
            if ($out['dim_height'] === '' && !empty($m[3])) $out['dim_height'] = (string)$m[3];
        }
    }
    $isEmbedded = $rule ? nm_rule_is_embedded($rule) : false;
    if ($isEmbedded) {
        if ($out['dim_opening'] === '' && $sizeCode !== '') $out['dim_opening'] = $sizeCode;
        if ($out['dim_length'] !== '' && $out['dim_width'] !== '') { $out['dimension_type'] = 'embedded_square'; $out['dim_outer_d']=''; }
        else { $out['dimension_type'] = 'embedded_round'; $out['dim_length']=''; $out['dim_width']=''; }
    } else {
        $out['dim_opening']='';
        if ($out['dim_length'] !== '' || $out['dim_width'] !== '') { $out['dimension_type']='box'; $out['dim_outer_d']=''; }
        else { $out['dimension_type']='diameter'; if($out['dim_outer_d']==='' && $sizeCode!=='') $out['dim_outer_d']=$sizeCode; }
    }
    return $out;
}
function nm_website_sync_normalize(PDO $pdo, array $raw, int $idx = 0): array {
    $model = nm_model_clean(nm_any($raw, array('model_no','model','sku','code','product_model','product_code','item_model','型号','modelName')));
    if ($model === '') return array();
    $prefix = nm_model_prefix($model);
    $sizeCode = '';
    if (strpos($model, '.') !== false) { $after = explode('.', $model, 2)[1] ?? ''; $digits = preg_replace('/\D+/', '', $after); if (strlen($digits) >= 3) $sizeCode = substr($digits,0,3); }
    $rule = nm_website_rule_by_prefix($pdo, $prefix);
    $series = nm_any($raw, array('web_series','series_name','series','product_name','product_title','name','title','category_name','family_name','系列','产品名称'));
    $lamp = $rule ? nm_s($rule['item_name'] ?? '') : nm_any($raw, array('lamp_type','item_name','type_name','product_type','category','category_name','灯具类型'));
    $category = $rule ? nm_s($rule['category'] ?? '') : nm_any($raw, array('category','category_name','product_category','type_name'));
    $image = nm_website_abs_url(nm_any($raw, array('web_image_url','source_image_url','image_path','image_url','main_image','cover_image','cover_url','cover_image_url','product_image','图片')));
    $drawing = nm_website_abs_url(nm_any($raw, array('web_dimension_url','web_drawing_url','web_size_image_url','source_drawing_url','drawing_path','drawing_url','dimension_url','size_image_url','size_image','dimension_image','尺寸图')));
    $slug = nm_any($raw, array('slug','product_slug','url_slug'));
    $sourceUrl = nm_website_abs_url(nm_any($raw, array('source_url','url','link','product_url')));
    if ($sourceUrl === '' && $slug !== '') $sourceUrl = rtrim(NM_WEBSITE_BASE,'/').'/product.php?slug='.rawurlencode($slug);
    $sourceId = nm_any($raw, array('source_id','size_id','variant_id','sku_id','id','product_id'));
    if ($sourceId === '') $sourceId = $slug !== '' ? $slug.'#'.$model : $model;
    $dims = nm_website_parse_dimensions($raw, $rule, $sizeCode);
    $dimText = nm_any($raw, array('web_dimensions','dimensions','dimension','dimension_text','size_text','size','spec','规格','尺寸'));
    $updated = nm_website_datetime(nm_any($raw, array('updated_at','modified_at','source_updated_at','publish_updated_at','last_modified')));
    $payload = $raw;
    return array_merge(array(
        'model_no'=>$model,'rule_id'=>$rule?(int)($rule['id']??0):0,'category'=>$category,'item_name'=>$lamp,'prefix'=>$prefix,'size_code'=>$sizeCode,
        'serial_no'=>strpos($model,'.')!==false ? substr(explode('.', $model, 2)[1], strlen($sizeCode)) : '',
        'product_name'=>$series,'series_name'=>$series,'lamp_type'=>$lamp,'status'=>'已确认','remark'=>'来源：香港官网实时同步；系列：'.$series,
        'image_path'=>$image,'drawing_path'=>$drawing,'source_system'=>'artdon_website','source_id'=>nm_s($sourceId,120),'source_url'=>$sourceUrl,
        'source_image_url'=>$image,'source_drawing_url'=>$drawing,'web_series'=>$series,'web_size_name'=>$lamp,'web_dimensions'=>$dimText,
        'web_image_url'=>$image,'web_dimension_url'=>$drawing,'web_drawing_url'=>$drawing,'web_size_image_url'=>$drawing,'web_slug'=>$slug,
        'source_updated_at'=>$updated,'source_payload_json'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ), $dims);
}
function nm_website_sync_rows_from_api(string &$sourceName, string &$err): array {
    $err='';
    $api = nm_cfg_first(array('NM_WEBSITE_SYNC_API','WEBSITE_SYNC_API','ARTDON_WEBSITE_SYNC_API'), rtrim(NM_WEBSITE_BASE,'/').'/website_naming_sync_api.php');
    $token = nm_cfg_first(array('NM_WEBSITE_SYNC_TOKEN','WEBSITE_SYNC_TOKEN','ARTDON_WEBSITE_SYNC_TOKEN'), '');
    if ($token !== '') $api .= (strpos($api,'?')===false?'?':'&').'token='.rawurlencode($token);
    $sourceName = $api;
    $json = nm_http_json($api, $err);
    if (!$json) return array();
    if (isset($json['ok']) && !$json['ok']) { $err = nm_s($json['msg'] ?? '官网接口返回失败'); return array(); }
    $flat = array(); nm_website_sync_flatten($json, $flat);
    return $flat;
}
function nm_website_sync_remote_pdo(string &$err): ?PDO {
    $err='';
    $host = nm_cfg_first(array('NM_WEBSITE_DB_HOST','WEBSITE_DB_HOST','WEB_DB_HOST','ARTDON_WEB_DB_HOST'), '');
    $db = nm_cfg_first(array('NM_WEBSITE_DB_NAME','WEBSITE_DB_NAME','WEB_DB_NAME','ARTDON_WEB_DB_NAME'), '');
    $user = nm_cfg_first(array('NM_WEBSITE_DB_USER','WEBSITE_DB_USER','WEB_DB_USER','ARTDON_WEB_DB_USER'), '');
    $pass = nm_cfg_first(array('NM_WEBSITE_DB_PASS','WEBSITE_DB_PASS','WEB_DB_PASS','ARTDON_WEB_DB_PASS'), '');
    $port = (int)nm_cfg_first(array('NM_WEBSITE_DB_PORT','WEBSITE_DB_PORT','WEB_DB_PORT','ARTDON_WEB_DB_PORT'), '3306');
    if ($host === '' || $db === '' || $user === '') return null;
    try { return new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4')); }
    catch(Throwable $e){ $err='官网数据库连接失败：'.$e->getMessage(); return null; }
}
function nm_website_sync_rows_from_db(PDO $db, string &$sourceName): array {
    $candidates = array('website_naming_products','website_products','web_products','artdon_products','products','product_models','web_product_models','website_product_models','product_sizes','website_product_sizes');
    $rows = array();
    foreach ($candidates as $t) {
        if (!nm_table_exists($db,$t)) continue;
        $cols = nm_cols($db,$t);
        $modelCols = array_values(array_intersect(array('model_no','model','sku','code','product_model','product_code'), $cols));
        if (!$modelCols) continue;
        $sourceName = 'db:'.$t;
        $select = array();
        foreach ($cols as $c) if (preg_match('/^(id|product_id|size_id|variant_id|model_no|model|sku|code|product_model|product_code|name|title|product_name|series|series_name|category|category_name|type_name|product_type|lamp_type|slug|url|link|image|image_path|image_url|main_image|cover_image|cover_url|drawing|drawing_path|drawing_url|dimension|dimension_url|size_image|size_image_url|dimensions|size_text|spec|opening|opening_size|opening_mm|cutout|cutout_size|cutout_mm|cut_out|cut_out_size|hole|hole_size|hole_diameter|aperture|aperture_size|aperture_mm|cut_size|diameter|diameter_mm|outer_d|outer_diameter|outer_dia|outer_d_mm|length|length_mm|width|width_mm|height|height_mm|updated_at|modified_at|created_at)$/i', $c)) $select[] = '`'.$c.'`';
        if (!$select) $select = array_map(function($c){return '`'.$c.'`';}, array_slice($cols,0,30));
        $sql = 'SELECT '.implode(',', $select).' FROM `'.$t.'` WHERE `'.$modelCols[0]."`<>'' ORDER BY ".(in_array('updated_at',$cols,true)?'updated_at DESC,':'').'`'.$modelCols[0].'` ASC LIMIT 10000';
        try { $rows = array_merge($rows, $db->query($sql)->fetchAll() ?: array()); } catch(Throwable $e) {}
        if ($rows) break;
    }
    return $rows;
}
function nm_website_sync_collect(PDO $pdo, string &$source, string &$err): array {
    $source=''; $err='';
    $raw = nm_website_sync_rows_from_api($source, $err);
    if (!$raw) {
        $remoteErr=''; $remote = nm_website_sync_remote_pdo($remoteErr);
        if ($remote instanceof PDO) { $raw = nm_website_sync_rows_from_db($remote, $source); if (!$raw && $remoteErr!=='') $err=$remoteErr; }
    }
    if (!$raw) { $localSource=''; $local = nm_website_sync_rows_from_db($pdo, $localSource); if ($local) { $raw=$local; $source='local '.$localSource; } }
    $items = array();
    foreach ($raw as $i=>$r) { if (is_array($r)) { $n = nm_website_sync_normalize($pdo, $r, (int)$i); if ($n && !empty($n['model_no'])) $items[]=$n; } }
    $dedup = array();
    foreach ($items as $it) $dedup[(string)$it['source_id'].'|'.(string)$it['model_no']] = $it;
    if (!$dedup && $err==='') $err = '没有从官网接口/官网数据库读取到可同步型号。请确认官网端 website_naming_sync_api.php 已放到官网根目录，或在 config.php 配置 NM_WEBSITE_SYNC_API / WEBSITE_DB_*。';
    return array_values($dedup);
}
function nm_website_sync_upsert(PDO $pdo, array $item, string $runId): string {
    $cols = nm_cols($pdo, 'naming_models');
    $model = nm_s($item['model_no'] ?? '', 80); if ($model==='') return 'skip';
    $sourceId = nm_s($item['source_id'] ?? $model, 120);
    $hash = sha1(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $old = null;
    $st = $pdo->prepare("SELECT * FROM naming_models WHERE (source_system='artdon_website' AND source_id=?) OR model_no=? ORDER BY source_system='artdon_website' DESC LIMIT 1");
    $st->execute(array($sourceId,$model));
    $old = $st->fetch();
    $common = $item;
    $common['source_hash']=$hash; $common['source_synced_at']=date('Y-m-d H:i:s'); $common['website_last_seen_at']=date('Y-m-d H:i:s'); $common['website_sync_run_id']=$runId; $common['website_sync_managed']=1; $common['website_deleted']=0; $common['website_deleted_at']=null; $common['updated_by']='官网实时同步';
    if ($old) {
        if (nm_s($old['source_hash'] ?? '') === $hash && (int)($old['website_deleted'] ?? 0) === 0) {
            $sets=array(); $args=array(); foreach(array('source_synced_at','website_last_seen_at','website_sync_run_id') as $c){ if(in_array($c,$cols,true)){ $sets[]="`{$c}`=?"; $args[]=$common[$c]; } }
            if($sets){ $args[]=(int)$old['id']; $pdo->prepare('UPDATE naming_models SET '.implode(',',$sets).' WHERE id=?')->execute($args); }
            return 'skip';
        }
        $sets=array(); $args=array();
        foreach($common as $c=>$v){ if(in_array($c,$cols,true) && $c!=='id' && $c!=='created_at' && $c!=='created_by'){ $sets[]="`{$c}`=?"; $args[]=$v; } }
        if(in_array('updated_at',$cols,true)) $sets[]='updated_at=NOW()';
        $args[]=(int)$old['id'];
        $pdo->prepare('UPDATE naming_models SET '.implode(',',$sets).' WHERE id=?')->execute($args);
        nm_log($pdo,'website.update','model',(int)$old['id'],$model,array('source_id'=>$sourceId));
        return 'update';
    }
    $common['created_by']='官网实时同步';
    $names=array(); $marks=array(); $args=array();
    foreach($common as $c=>$v){ if(in_array($c,$cols,true)){ $names[]='`'.$c.'`'; $marks[]='?'; $args[]=$v; } }
    $pdo->prepare('INSERT INTO naming_models('.implode(',',$names).') VALUES('.implode(',',$marks).')')->execute($args);
    $id=(int)$pdo->lastInsertId();
    nm_log($pdo,'website.create','model',$id,$model,array('source_id'=>$sourceId));
    return 'create';
}
function nm_website_sync_delete_missing(PDO $pdo, array $seen, string $runId): int {
    $cols = nm_cols($pdo, 'naming_models');
    if (!in_array('source_system',$cols,true)) return 0;
    $cond = "source_system='artdon_website'";
    if (in_array('website_sync_managed',$cols,true)) $cond = "(source_system='artdon_website' OR website_sync_managed=1)";
    $rows = $pdo->query('SELECT * FROM naming_models WHERE '.$cond)->fetchAll() ?: array();
    $n=0;
    foreach($rows as $r){
        $key = nm_s($r['source_id'] ?? ''); if($key==='') $key = nm_s($r['model_no'] ?? '');
        if($key==='' || isset($seen[$key])) continue;
        $payload = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try { $pdo->prepare('INSERT INTO naming_website_deleted_archive(model_id,model_no,source_id,payload_json) VALUES(?,?,?,?)')->execute(array((int)$r['id'],nm_s($r['model_no']??''),nm_s($r['source_id']??''),$payload)); } catch(Throwable $e) {}
        $pdo->prepare('DELETE FROM naming_models WHERE id=?')->execute(array((int)$r['id']));
        nm_log($pdo,'website.delete','model',(int)$r['id'],nm_s($r['model_no']??''),array('source_id'=>$key,'run_id'=>$runId,'reason'=>'官网已删除'));
        $n++;
    }
    return $n;
}
function nm_website_sync_run(PDO $pdo, bool $force = false, string $mode = 'auto'): array {
    nm_website_sync_ensure($pdo);
    $now = time();
    $last = nm_website_state_get($pdo,'last_attempt_at','');
    if (!$force && $last !== '' && $now - strtotime($last) < NM_WEBSITE_SYNC_INTERVAL) {
        return array('ok'=>true,'skipped'=>1,'msg'=>'同步间隔内，已跳过','last_attempt_at'=>$last,'status'=>nm_website_sync_status($pdo));
    }
    $lastSuccessBefore = nm_website_state_get($pdo,'last_success_at','');
    nm_website_state_set($pdo,'last_attempt_at',date('Y-m-d H:i:s'));
    $runId = date('YmdHis').'_'.substr(md5(uniqid('',true)),0,8);
    $source=''; $err=''; $created=0; $updated=0; $skipped=0; $deleted=0; $fetched=0; $status='ok'; $degraded=0;
    $started = date('Y-m-d H:i:s');
    try { $pdo->query("SELECT GET_LOCK('artdon_naming_website_sync', 1)"); } catch(Throwable $e) {}
    try {
        $items = nm_website_sync_collect($pdo, $source, $err); $fetched=count($items);
        if (!$items) throw new RuntimeException($err ?: '官网没有返回型号。');
        $seen=array();
        foreach($items as $it){ $key=nm_s($it['source_id']??''); if($key==='') $key=nm_s($it['model_no']??''); $seen[$key]=1; $res=nm_website_sync_upsert($pdo,$it,$runId); if($res==='create')$created++; elseif($res==='update')$updated++; else $skipped++; }
        $deleted = nm_website_sync_delete_missing($pdo,$seen,$runId);
        nm_repair_website_category_by_model($pdo);
        nm_repair_website_dimensions_by_model($pdo);
        nm_website_state_set($pdo,'last_success_at',date('Y-m-d H:i:s'));
        nm_website_state_set($pdo,'last_error','');
        nm_website_state_set($pdo,'last_auto_error','');
        nm_website_state_set($pdo,'last_source',$source);
        nm_website_state_set($pdo,'last_count',(string)$fetched);
    } catch(Throwable $e) {
        $err=$e->getMessage();
        if ($mode === 'auto' && $lastSuccessBefore !== '') {
            // V3.0.8.11：自动后台同步偶发失败时，不再把页面刷成红色错误；保留上次成功状态并静默重试。
            $status='warn'; $degraded=1;
            nm_website_state_set($pdo,'last_auto_error',$err);
        } else {
            $status='error';
            nm_website_state_set($pdo,'last_error',$err);
        }
    }
    try { $pdo->query("SELECT RELEASE_LOCK('artdon_naming_website_sync')"); } catch(Throwable $e) {}
    $finished = date('Y-m-d H:i:s');
    try { $pdo->prepare('INSERT INTO naming_website_sync_runs(run_id,source,status,fetched_count,created_count,updated_count,deleted_count,skipped_count,error_text,started_at,finished_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(array($runId,$source,$status,$fetched,$created,$updated,$deleted,$skipped,$err,$started,$finished)); } catch(Throwable $e) {}
    nm_log($pdo, $status==='ok'?'website.sync':'website.sync_error', 'website', 0, '', array('run_id'=>$runId,'mode'=>$mode,'source'=>$source,'fetched'=>$fetched,'created'=>$created,'updated'=>$updated,'deleted'=>$deleted,'skipped'=>$skipped,'error'=>$err,'degraded'=>$degraded));
    return array('ok'=>$status!=='error','degraded'=>$degraded,'run_id'=>$runId,'source'=>$source,'fetched'=>$fetched,'created'=>$created,'updated'=>$updated,'deleted'=>$deleted,'skipped'=>$skipped,'error'=>$err,'finished_at'=>$finished,'msg'=>$status==='ok'?'官网实时同步完成':($degraded?'官网后台同步暂时失败，已保留上次成功数据，稍后自动重试':'官网实时同步失败：'.$err));
}
/* ===== V3.0.8.7 官网实时同步 END ===== */

function nm_ensure_inbox(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naming_inbox(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      source_type VARCHAR(60) NOT NULL DEFAULT 'plm_sample',
      source_table VARCHAR(120) NOT NULL DEFAULT '',
      source_id VARCHAR(120) NOT NULL DEFAULT '',
      customer_id INT NOT NULL DEFAULT 0,
      customer_name VARCHAR(255) NOT NULL DEFAULT '',
      sample_no VARCHAR(120) NOT NULL DEFAULT '',
      sample_model VARCHAR(255) NOT NULL DEFAULT '',
      product_name VARCHAR(255) NOT NULL DEFAULT '',
      category_hint VARCHAR(160) NOT NULL DEFAULT '',
      item_hint VARCHAR(160) NOT NULL DEFAULT '',
      requirements MEDIUMTEXT NULL,
      note MEDIUMTEXT NULL,
      image_path VARCHAR(500) NOT NULL DEFAULT '',
      drawing_path VARCHAR(500) NOT NULL DEFAULT '',
      payload_json MEDIUMTEXT NULL,
      status VARCHAR(40) NOT NULL DEFAULT '待处理',
      assigned_to VARCHAR(120) NOT NULL DEFAULT '',
      submitted_by VARCHAR(120) NOT NULL DEFAULT '',
      submitted_at DATETIME NULL,
      processed_by VARCHAR(120) NOT NULL DEFAULT '',
      processed_at DATETIME NULL,
      naming_model_id INT NOT NULL DEFAULT 0,
      model_no VARCHAR(80) NOT NULL DEFAULT '',
      return_reason MEDIUMTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_naming_inbox_source(source_type,source_id),
      KEY idx_naming_inbox_status(status),
      KEY idx_naming_inbox_customer(customer_id),
      KEY idx_naming_inbox_model(naming_model_id),
      KEY idx_naming_inbox_updated(updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (nm_table_exists($pdo, 'crm_sample_shipments')) {
        foreach (array(
          'naming_inbox_id'=>"`naming_inbox_id` BIGINT UNSIGNED NOT NULL DEFAULT 0",
          'naming_status'=>"`naming_status` VARCHAR(40) NOT NULL DEFAULT ''",
          'naming_model_id'=>"`naming_model_id` INT NOT NULL DEFAULT 0",
          'naming_model_no'=>"`naming_model_no` VARCHAR(80) NOT NULL DEFAULT ''",
          'naming_submitted_at'=>"`naming_submitted_at` DATETIME NULL",
          'naming_completed_at'=>"`naming_completed_at` DATETIME NULL",
          'naming_last_note'=>"`naming_last_note` TEXT NULL"
        ) as $c=>$ddl) { try { nm_add_col($pdo,'crm_sample_shipments',$c,$ddl); } catch (Throwable $e) {} }
    }
    if (nm_table_exists($pdo, 'plm_models')) {
        foreach (array(
          'naming_inbox_id'=>"`naming_inbox_id` BIGINT UNSIGNED NOT NULL DEFAULT 0",
          'naming_status'=>"`naming_status` VARCHAR(40) NOT NULL DEFAULT ''",
          'naming_submitted_at'=>"`naming_submitted_at` DATETIME NULL",
          'naming_completed_at'=>"`naming_completed_at` DATETIME NULL",
          'naming_last_note'=>"`naming_last_note` TEXT NULL",
          'naming_assigned_to'=>"`naming_assigned_to` VARCHAR(120) NOT NULL DEFAULT ''",
          'naming_id'=>"`naming_id` INT NOT NULL DEFAULT 0",
          'naming_model_no'=>"`naming_model_no` VARCHAR(80) NOT NULL DEFAULT ''",
          'naming_category'=>"`naming_category` VARCHAR(120) NOT NULL DEFAULT ''",
          'naming_item_name'=>"`naming_item_name` VARCHAR(120) NOT NULL DEFAULT ''",
          'naming_product_name'=>"`naming_product_name` VARCHAR(180) NOT NULL DEFAULT ''",
          'naming_image_path'=>"`naming_image_path` VARCHAR(500) NOT NULL DEFAULT ''",
          'naming_drawing_path'=>"`naming_drawing_path` VARCHAR(500) NOT NULL DEFAULT ''",
          'naming_remark'=>"`naming_remark` TEXT NULL",
          'naming_linked_at'=>"`naming_linked_at` DATETIME NULL"
        ) as $c=>$ddl) { try { nm_add_col($pdo,'plm_models',$c,$ddl); } catch (Throwable $e) {} }
    }
}
function nm_inbox_update_source(PDO $pdo, array $box): void {
    $sourceTable = (string)($box['source_table'] ?? '');
    $sourceType = (string)($box['source_type'] ?? '');
    $sid = (int)($box['source_id'] ?? 0);
    if ($sid <= 0) return;
    $status = (string)($box['status'] ?? '');
    $inboxId = (int)($box['id'] ?? 0);
    $namingModelId = (int)($box['naming_model_id'] ?? 0);
    $modelNo = (string)($box['model_no'] ?? '');
    $lastNote = (string)($box['return_reason'] ?? '');
    $done = $status === '已确认';
    if (($sourceTable === 'crm_sample_shipments' || $sourceType === 'crm_sample') && nm_table_exists($pdo,'crm_sample_shipments')) {
        try {
            $pdo->prepare("UPDATE crm_sample_shipments SET naming_inbox_id=?,naming_status=?,naming_model_id=?,naming_model_no=?,naming_last_note=?,naming_completed_at=?,updated_at=NOW() WHERE id=?")
                ->execute(array($inboxId,$status,$namingModelId,$modelNo,$lastNote,$done?date('Y-m-d H:i:s'):null,$sid));
        } catch (Throwable $e) {}
        return;
    }
    if (($sourceTable === 'plm_models' || $sourceType === 'plm_sample') && nm_table_exists($pdo,'plm_models')) {
        try {
            $assigned = (string)($box['assigned_to'] ?? '');
            $pdo->prepare("UPDATE plm_models SET naming_inbox_id=?,naming_status=?,naming_last_note=?,naming_assigned_to=?,naming_completed_at=?,updated_at=NOW() WHERE id=?")
                ->execute(array($inboxId,$status,$lastNote,$assigned,$done?date('Y-m-d H:i:s'):null,$sid));
            if ($done && $namingModelId > 0) {
                $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
                $st->execute(array($namingModelId));
                $nm = $st->fetch();
                if ($nm) {
                    foreach (array(
                        'product_category'=>"`product_category` VARCHAR(120) NOT NULL DEFAULT ''",
                        'opening_size'=>"`opening_size` VARCHAR(60) NOT NULL DEFAULT ''",
                        'diameter'=>"`diameter` VARCHAR(60) NOT NULL DEFAULT ''",
                        'length_mm'=>"`length_mm` VARCHAR(60) NOT NULL DEFAULT ''",
                        'width_mm'=>"`width_mm` VARCHAR(60) NOT NULL DEFAULT ''",
                        'height_mm'=>"`height_mm` VARCHAR(60) NOT NULL DEFAULT ''"
                    ) as $c=>$ddl) { try { nm_add_col($pdo,'plm_models',$c,$ddl); } catch (Throwable $e) {} }
                    $pdo->prepare("UPDATE plm_models SET model=?,naming_id=?,naming_model_no=?,naming_category=?,naming_item_name=?,naming_product_name=?,naming_image_path=?,naming_drawing_path=?,naming_remark=?,naming_linked_at=NOW(),product_category=IF(product_category='',?,product_category),opening_size=IF(opening_size='',?,opening_size),diameter=IF(diameter='',?,diameter),length_mm=IF(length_mm='',?,length_mm),width_mm=IF(width_mm='',?,width_mm),height_mm=IF(height_mm='',?,height_mm),updated_at=NOW() WHERE id=?")
                        ->execute(array((string)($nm['model_no']??$modelNo),$namingModelId,(string)($nm['model_no']??$modelNo),(string)($nm['category']??''),(string)($nm['item_name']??''),(string)($nm['product_name']??''),(string)($nm['image_path']??''),(string)($nm['drawing_path']??''),(string)($nm['remark']??''),(string)($nm['category']??''),(string)($nm['dim_opening']??''),(string)($nm['dim_outer_d']??''),(string)($nm['dim_length']??''),(string)($nm['dim_width']??''),(string)($nm['dim_height']??''),$sid));
                }
            }
        } catch (Throwable $e) {}
    }
}
function nm_inbox_link_model(PDO $pdo, int $inboxId, int $modelId, string $modelNo, string $modelStatus): void {
    if ($inboxId <= 0 || $modelId <= 0) return;
    nm_ensure_inbox($pdo);
    $st = $pdo->prepare('SELECT * FROM naming_inbox WHERE id=? LIMIT 1');
    $st->execute(array($inboxId));
    $box = $st->fetch();
    if (!$box) return;
    $done = in_array($modelStatus, array('已确认','已量产'), true);
    $status = $done ? '已确认' : '处理中';
    $user = nm_current_user();
    $pdo->prepare("UPDATE naming_inbox SET naming_model_id=?,model_no=?,status=?,assigned_to=IF(assigned_to='',?,assigned_to),processed_by=?,processed_at=?,updated_at=NOW() WHERE id=?")
        ->execute(array($modelId,$modelNo,$status,$user,$user,$done?date('Y-m-d H:i:s'):null,$inboxId));
    $st = $pdo->prepare('SELECT * FROM naming_inbox WHERE id=?');
    $st->execute(array($inboxId));
    $fresh = $st->fetch();
    if ($fresh) nm_inbox_update_source($pdo, $fresh);
    nm_log($pdo, 'inbox.link_model', 'inbox', $inboxId, $modelNo, array('model_id'=>$modelId,'status'=>$status));
}

/* ===== V3.0.8.23 命名中心内嵌派工待办 START ===== */
function nm_dispatch_cols(PDO $pdo, string $table): array { return nm_table_exists($pdo,$table) ? nm_cols($pdo,$table) : array(); }
function nm_dispatch_choose_col(array $cols, array $candidates): string {
    $lower=array(); foreach($cols as $c) $lower[strtolower((string)$c)] = (string)$c;
    foreach($candidates as $cand){ $k=strtolower((string)$cand); if(isset($lower[$k])) return $lower[$k]; }
    return '';
}
function nm_dispatch_user_display(array $u): string {
    $name = trim((string)($u['name'] ?? $u['display_name'] ?? $u['realname'] ?? $u['username'] ?? ''));
    $username = trim((string)($u['username'] ?? $u['account'] ?? ''));
    if ($name !== '' && $username !== '' && $name !== $username) return $name.'（'.$username.'）';
    return $name !== '' ? $name : ($username !== '' ? $username : ('用户#'.(int)($u['id'] ?? 0)));
}
function nm_dispatch_sync_users(PDO $pdo): void {
    if (!nm_table_exists($pdo,'dispatch_users') || !nm_table_exists($pdo,'artdon_users')) return;
    $dcols = nm_cols($pdo,'dispatch_users');
    $ucols = nm_cols($pdo,'artdon_users');
    $uUser = nm_dispatch_choose_col($ucols,array('username','account','user_name','login','email','name'));
    if ($uUser === '' || !in_array('username',$dcols,true)) return;
    $uName = nm_dispatch_choose_col($ucols,array('display_name','name','realname','nickname','username','account'));
    $uRole = nm_dispatch_choose_col($ucols,array('role_name','role','user_role','type','is_admin','admin'));
    $uDept = nm_dispatch_choose_col($ucols,array('department','dept','group_name','team'));
    $uHash = nm_dispatch_choose_col($ucols,array('password_hash','password','pass','pwd'));
    $uStatus = nm_dispatch_choose_col($ucols,array('status','is_active','active','enabled'));
    $uId = nm_dispatch_choose_col($ucols,array('id','user_id','uid'));
    $where='';
    if($uStatus!=='') $where=" WHERE (`{$uStatus}` IS NULL OR `{$uStatus}`='' OR `{$uStatus}` IN ('1','active','启用','正常','true','yes'))";
    $sql="SELECT * FROM artdon_users{$where} ORDER BY ".($uId!==''?"`{$uId}`":"`{$uUser}`")." ASC LIMIT 500";
    try { $rows=$pdo->query($sql)->fetchAll() ?: array(); } catch(Throwable $e) { return; }
    $check=$pdo->prepare("SELECT id FROM dispatch_users WHERE username=? LIMIT 1");
    foreach($rows as $r){
        $username = nm_s($r[$uUser] ?? '',80); if($username==='') continue;
        try { $check->execute(array($username)); if($check->fetchColumn()) continue; } catch(Throwable $e) { continue; }
        $data=array('username'=>$username);
        if(in_array('password_hash',$dcols,true) && $uHash!=='' && !empty($r[$uHash])) $data['password_hash']=(string)$r[$uHash];
        if(in_array('name',$dcols,true)) $data['name']=nm_s(($uName!==''?($r[$uName]??''):'') ?: $username,120);
        if(in_array('role',$dcols,true)) $data['role']=(preg_match('/boss|admin|owner|老板|管理|超级/u', (string)($uRole!==''?($r[$uRole]??''):'')) ? 'boss' : 'staff');
        if(in_array('department',$dcols,true) && $uDept!=='') $data['department']=nm_s($r[$uDept] ?? '',120);
        if(in_array('external_source',$dcols,true)) $data['external_source']='SSO';
        if(in_array('external_table',$dcols,true)) $data['external_table']='artdon_users';
        if(in_array('external_id',$dcols,true) && $uId!=='') $data['external_id']=(string)($r[$uId] ?? '');
        if(in_array('is_active',$dcols,true)) $data['is_active']=1;
        if(in_array('created_at',$dcols,true)) $data['created_at']=date('Y-m-d H:i:s');
        if(in_array('updated_at',$dcols,true)) $data['updated_at']=date('Y-m-d H:i:s');
        $cols=array(); $marks=array(); $args=array();
        foreach($data as $c=>$v){ if(in_array($c,$dcols,true)){ $cols[]='`'.$c.'`'; $marks[]='?'; $args[]=$v; } }
        if($cols){ try { $pdo->prepare('INSERT INTO dispatch_users('.implode(',',$cols).') VALUES('.implode(',',$marks).')')->execute($args); } catch(Throwable $e) {} }
    }
}
function nm_dispatch_users(PDO $pdo): array {
    if (!nm_table_exists($pdo,'dispatch_users')) return array();
    nm_dispatch_sync_users($pdo);
    $cols = nm_cols($pdo,'dispatch_users');
    $where = array();
    if(in_array('is_active',$cols,true)) $where[]='is_active=1';
    if(in_array('hidden_at',$cols,true)) $where[]="(hidden_at IS NULL OR hidden_at='' OR hidden_at='0000-00-00 00:00:00')";
    $sql='SELECT * FROM dispatch_users'.($where ? ' WHERE '.implode(' AND ',$where) : '').' ORDER BY '.(in_array('role',$cols,true)?"role='boss' DESC,":'').(in_array('department',$cols,true)?'department ASC,':'').' id ASC LIMIT 500';
    try { $rows=$pdo->query($sql)->fetchAll() ?: array(); } catch(Throwable $e) { return array(); }
    $out=array();
    foreach($rows as $u){
        $out[]=array('id'=>(int)($u['id']??0),'name'=>nm_s($u['name']??'',120),'username'=>nm_s($u['username']??'',80),'department'=>nm_s($u['department']??'',120),'role'=>nm_s($u['role']??'',30),'label'=>nm_dispatch_user_display($u));
    }
    return $out;
}
function nm_dispatch_current_user_id(PDO $pdo): int {
    if (!nm_table_exists($pdo,'dispatch_users')) return 0;
    $sid=(int)($_SESSION['dispatch_user_id'] ?? 0);
    if($sid>0){ try{ $st=$pdo->prepare('SELECT id FROM dispatch_users WHERE id=? LIMIT 1'); $st->execute(array($sid)); if($st->fetchColumn()) return $sid; }catch(Throwable $e){} }
    $artId=(int)($_SESSION['artdon_user_id'] ?? 0);
    if($artId>0 && nm_col_exists($pdo,'dispatch_users','external_table') && nm_col_exists($pdo,'dispatch_users','external_id')){
        try{ $st=$pdo->prepare("SELECT id FROM dispatch_users WHERE external_table='artdon_users' AND external_id=? LIMIT 1"); $st->execute(array((string)$artId)); $id=(int)$st->fetchColumn(); if($id>0) return $id; }catch(Throwable $e){}
    }
    $username=nm_current_user();
    if($username!=='' && $username!=='未识别账号'){
        try{ $st=$pdo->prepare('SELECT id FROM dispatch_users WHERE username=? OR name=? ORDER BY id ASC LIMIT 1'); $st->execute(array($username,$username)); $id=(int)$st->fetchColumn(); if($id>0) return $id; }catch(Throwable $e){}
    }
    return 0;
}
function nm_dispatch_task_no(PDO $pdo): string {
    for($i=0;$i<20;$i++){
        $no='D'.date('ymdHis').mt_rand(1000,9999);
        try{ $st=$pdo->prepare('SELECT id FROM dispatch_tasks WHERE task_no=? LIMIT 1'); $st->execute(array($no)); if(!$st->fetchColumn()) return $no; }catch(Throwable $e){ return $no; }
    }
    return 'D'.date('ymdHis').mt_rand(10000,99999);
}
function nm_dispatch_due($v): ?string {
    $v=nm_s($v,80); if($v==='') return null; $v=str_replace('T',' ',$v);
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) $v.=' 23:59:00';
    if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$v)) $v.=':00';
    return $v;
}
function nm_dispatch_insert_log(PDO $pdo, int $taskId, int $userId, int $assignedTo, string $title, string $note): void {
    $now=date('Y-m-d H:i:s');
    try { if(nm_table_exists($pdo,'dispatch_task_logs')) $pdo->prepare("INSERT INTO dispatch_task_logs(task_id,user_id,action,old_status,new_status,note,created_at) VALUES(?,?,?,?,?,?,?)")->execute(array($taskId,$userId,'assign','','pending_accept',$note,$now)); } catch(Throwable $e) {}
    try { if(nm_table_exists($pdo,'dispatch_activity_logs')) $pdo->prepare("INSERT INTO dispatch_activity_logs(user_id,user_name,action_type,action_label,task_id,task_title,project,note,page,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute(array($userId,nm_current_user(),'assign','创建派工',$taskId,$title,'命名中心',$note,'naming',$now)); } catch(Throwable $e) {}
    try { if(nm_table_exists($pdo,'dispatch_notifications') && $assignedTo>0) $pdo->prepare("INSERT INTO dispatch_notifications(recipient_id,sender_id,task_id,type,title,message,is_read,created_at) VALUES(?,?,?,?,?,?,0,?)")->execute(array($assignedTo,$userId,$taskId,'dispatch_received','收到派工：'.$title,$note,$now)); } catch(Throwable $e) {}
}
function nm_dispatch_create(PDO $pdo, array $raw): array {
    if (!nm_table_exists($pdo,'dispatch_tasks')) throw new RuntimeException('派工系统未安装或缺少 dispatch_tasks 表。');
    if (!nm_table_exists($pdo,'dispatch_users')) throw new RuntimeException('派工系统未安装或缺少 dispatch_users 表，不能选择负责人。');
    nm_dispatch_sync_users($pdo);
    $modelId=(int)($raw['model_id'] ?? 0);
    $model=null;
    if($modelId>0){ $st=$pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1'); $st->execute(array($modelId)); $model=$st->fetch() ?: null; }
    $modelNo=nm_s($raw['model_no'] ?? ($model['model_no'] ?? ''),80);
    $series=nm_s($raw['series'] ?? ($model['product_name'] ?? $model['series_name'] ?? $model['web_series'] ?? ''),180);
    $dim=$model ? nm_dim_text($model) : nm_s($raw['dimension'] ?? '',200);
    $title=nm_s($raw['title'] ?? '',255);
    if($title==='') $title='命名型号派工：'.$modelNo;
    if($title==='命名型号派工：') throw new RuntimeException('请先保存或填写型号，再创建派工待办。');
    $assigned=(int)($raw['assigned_to'] ?? 0);
    if($assigned<=0) throw new RuntimeException('请选择负责人。');
    $desc=nm_s($raw['description'] ?? '',20000);
    if($desc===''){
        $desc=trim("请处理命名型号相关事项。\n型号：{$modelNo}\n系列：{$series}\n尺寸：{$dim}");
    }
    $priority=nm_s($raw['priority'] ?? 'normal',30); if($priority==='') $priority='normal';
    $dueAt=nm_dispatch_due($raw['due_at'] ?? '');
    $taskDate=$dueAt ? substr($dueAt,0,10) : date('Y-m-d');
    $createdBy=nm_dispatch_current_user_id($pdo);
    $cols=nm_cols($pdo,'dispatch_tasks');
    $data=array(
        'task_no'=>nm_dispatch_task_no($pdo),'title'=>$title,'description'=>$desc,'task_type'=>'命名','priority'=>$priority,'status'=>'pending_accept','source_type'=>'dispatch','source'=>'dispatch','boss_private'=>0,
        'created_by'=>$createdBy,'assigned_to'=>$assigned,'assignee_id'=>$assigned,'helpers_json'=>json_encode(array(),JSON_UNESCAPED_UNICODE),'customer'=>nm_s($raw['customer'] ?? ($model['customer'] ?? ''),160),'project'=>'命名中心','product_model'=>$modelNo,
        'linked_system'=>'NAMING','linked_table'=>'naming_models','linked_id'=>(string)$modelId,'linked_title'=>$modelNo,'linked_url'=>'naming.php?q='.rawurlencode($modelNo),
        'linked_json'=>json_encode(array('system'=>'NAMING','model_id'=>$modelId,'model_no'=>$modelNo,'series'=>$series,'dimension'=>$dim),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'task_date'=>$taskDate,'extra_json'=>json_encode(array('source'=>'naming','model_id'=>$modelId,'model_no'=>$modelNo,'series'=>$series,'dimension'=>$dim),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'due_at'=>$dueAt,'progress'=>0,'sort_order'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
    );
    if(in_array('sort_order',$cols,true)){ try{ $data['sort_order']=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+100 FROM dispatch_tasks')->fetchColumn(); }catch(Throwable $e){ $data['sort_order']=0; } }
    $insertCols=array(); $marks=array(); $args=array();
    foreach($data as $c=>$v){ if(in_array($c,$cols,true)){ $insertCols[]='`'.$c.'`'; $marks[]='?'; $args[]=$v; } }
    if(!in_array('title',$cols,true)) throw new RuntimeException('dispatch_tasks 表缺少 title 字段，不能创建派工。');
    $pdo->prepare('INSERT INTO dispatch_tasks('.implode(',',$insertCols).') VALUES('.implode(',',$marks).')')->execute($args);
    $taskId=(int)$pdo->lastInsertId();
    nm_dispatch_insert_log($pdo,$taskId,$createdBy,$assigned,$title,$desc);
    nm_log($pdo,'dispatch.create','model',$modelId,$modelNo,array('task_id'=>$taskId,'title'=>$title,'assigned_to'=>$assigned,'due_at'=>$dueAt,'priority'=>$priority));
    return array('task_id'=>$taskId,'title'=>$title,'assigned_to'=>$assigned,'url'=>'dispatch_next.php?task_id='.$taskId);
}
/* ===== V3.0.8.23 命名中心内嵌派工待办 END ===== */

function nm_api(): void {
    $action = nm_s($_GET['action'] ?? $_POST['action'] ?? '');
    if ($action === '') return;
    @ini_set('display_errors','0');
    try {
        $pdo = nm_pdo();
        nm_ensure_tables($pdo);
        nm_perm_require_api($pdo, $action);
        if ($action === 'install') nm_json(array('version'=>NAMING_VERSION), true, '命名表检查完成');
        if ($action === 'auth_status_v3064') nm_json(array('user'=>nm_current_user(),'version'=>NAMING_VERSION,'permissions'=>nm_perm_runtime()), true, '账号检测');
        if ($action === 'drawing_settings') nm_json(nm_setting_load($pdo), true, '显示设置');
        if ($action === 'save_drawing_settings') {
            $raw = json_decode(file_get_contents('php://input'), true); if (!is_array($raw)) $raw = $_POST;
            $saved = nm_setting_save($pdo, $raw);
            nm_json($saved, true, '显示设置已保存');
        }
        if ($action === 'backup_settings' || $action === 'backup_list') nm_json(array('settings'=>nm_setting_load($pdo),'files'=>nm_backup_list_files()), true, '备份管理');
        if ($action === 'save_backup_settings') {
            $raw = json_decode(file_get_contents('php://input'), true); if (!is_array($raw)) $raw = $_POST;
            $saved = nm_backup_settings_save($pdo, $raw);
            nm_json(array('settings'=>$saved,'files'=>nm_backup_list_files()), true, '备份设置已保存');
        }
        if ($action === 'backup_create') nm_json(array('created'=>nm_backup_write($pdo, 'manual'),'files'=>nm_backup_list_files()), true, '已生成备份');
        if ($action === 'backup_download') {
            $file = nm_s($_GET['file'] ?? $_POST['file'] ?? '', 180);
            $path = nm_backup_safe_path($file);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.basename($path).'"');
            readfile($path); exit;
        }
        if ($action === 'backup_delete_file') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $file = nm_s($raw['file'] ?? '', 180); $path = nm_backup_safe_path($file); @unlink($path);
            nm_log($pdo, 'backup.delete', 'backup', 0, '', array('file'=>$file));
            nm_json(array('files'=>nm_backup_list_files()), true, '备份已删除');
        }
        if ($action === 'backup_restore_file') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $file = nm_s($raw['file'] ?? '', 180); $mode = nm_s($raw['mode'] ?? 'merge', 20);
            $payload = json_decode((string)file_get_contents(nm_backup_safe_path($file)), true);
            if (!is_array($payload)) throw new RuntimeException('备份文件损坏，无法恢复。');
            $res = nm_backup_restore_payload($pdo, $payload, $mode);
            nm_json($res, true, '备份已恢复');
        }
        if ($action === 'backup_import_json') {
            $mode = nm_s($_GET['mode'] ?? $_POST['mode'] ?? 'merge', 20);
            $payload = nm_backup_payload_from_upload();
            $res = nm_backup_restore_payload($pdo, $payload, $mode);
            nm_json($res, true, '备份已导入');
        }
        if ($action === 'website_sync_status') nm_json(nm_website_sync_status($pdo), true, '官网同步状态');
        if ($action === 'realtime_sync_v19') {
            $force = !empty($_GET['force']) || !empty($_POST['force']);
            if ($force && !(defined('NM_WEBSITE_SYNC_CRON') && NM_WEBSITE_SYNC_CRON)) nm_perm_require('sync_website','官网实时同步',true);
            $res = nm_website_sync_run($pdo, $force, $force ? 'manual' : 'auto');
            nm_json($res, !empty($res['ok']), $res['msg'] ?? '官网实时同步完成');
        }
        if ($action === 'inbox_list') {
            nm_ensure_inbox($pdo);
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = (int)($_GET['per_page'] ?? 20); if (!in_array($per,array(10,20,50),true)) $per = 20;
            $where = array(); $args = array();
            $kw = nm_s($_GET['kw'] ?? '');
            if ($kw !== '') { $where[] = '(customer_name LIKE ? OR sample_no LIKE ? OR product_name LIKE ? OR sample_model LIKE ? OR model_no LIKE ?)'; for($i=0;$i<5;$i++) $args[]='%'.$kw.'%'; }
            $status = nm_s($_GET['status'] ?? '');
            if ($status !== '') { $where[] = 'status=?'; $args[] = $status; }
            $sqlWhere = $where ? (' WHERE '.implode(' AND ', $where)) : '';
            $stc = $pdo->prepare('SELECT COUNT(*) FROM naming_inbox'.$sqlWhere); $stc->execute($args); $total=(int)$stc->fetchColumn();
            $offset = ($page-1)*$per;
            $st = $pdo->prepare('SELECT * FROM naming_inbox'.$sqlWhere.' ORDER BY updated_at DESC,id DESC LIMIT '.(int)$offset.','.(int)$per); $st->execute($args);
            $stats = array('total'=>$total);
            foreach ($pdo->query("SELECT status,COUNT(*) c FROM naming_inbox GROUP BY status")->fetchAll() ?: array() as $rr) $stats[(string)$rr['status']] = (int)$rr['c'];
            nm_json(array('rows'=>$st->fetchAll() ?: array(), 'stats'=>$stats, 'page'=>$page, 'per_page'=>$per, 'total'=>$total), true, '命名收件箱');
        }
        if (in_array($action, array('inbox_submit','submit_inbox','create_inbox','save_inbox'), true)) {
            nm_ensure_inbox($pdo);
            $raw = json_decode(file_get_contents('php://input'), true); if (!is_array($raw)) $raw = $_POST;
            $sourceType = nm_s($raw['source_type'] ?? 'plm_sample', 60);
            $sourceTable = nm_s($raw['source_table'] ?? ($sourceType==='crm_sample'?'crm_sample_shipments':'plm_models'), 120);
            $sourceId = nm_s($raw['source_id'] ?? ($raw['id'] ?? ''), 120);
            if ($sourceId === '') $sourceId = 'manual_'.date('YmdHis').'_'.substr(md5(json_encode($raw).microtime(true)),0,8);
            $payload = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $st = $pdo->prepare("INSERT INTO naming_inbox(source_type,source_table,source_id,customer_id,customer_name,sample_no,sample_model,product_name,category_hint,item_hint,requirements,note,image_path,drawing_path,payload_json,status,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),customer_name=VALUES(customer_name),sample_no=VALUES(sample_no),sample_model=VALUES(sample_model),product_name=VALUES(product_name),category_hint=VALUES(category_hint),item_hint=VALUES(item_hint),requirements=VALUES(requirements),note=VALUES(note),image_path=VALUES(image_path),drawing_path=VALUES(drawing_path),payload_json=VALUES(payload_json),status=IF(status='已确认',status,'待处理'),updated_at=NOW()");
            $st->execute(array($sourceType,$sourceTable,$sourceId,(int)($raw['customer_id'] ?? 0),nm_s($raw['customer_name'] ?? '',255),nm_s($raw['sample_no'] ?? '',120),nm_s($raw['sample_model'] ?? '',255),nm_s($raw['product_name'] ?? '',255),nm_s($raw['category_hint'] ?? ($raw['category'] ?? ''),160),nm_s($raw['item_hint'] ?? ($raw['item_name'] ?? ''),160),nm_s($raw['requirements'] ?? '',5000),nm_s($raw['note'] ?? '',5000),nm_s($raw['image_path'] ?? '',500),nm_s($raw['drawing_path'] ?? '',500),$payload,'待处理',nm_current_user()));
            $id=(int)$pdo->lastInsertId();
            if ($id===0) { $st=$pdo->prepare('SELECT id FROM naming_inbox WHERE source_type=? AND source_id=? LIMIT 1'); $st->execute(array($sourceType,$sourceId)); $id=(int)$st->fetchColumn(); }
            nm_log($pdo, 'inbox.submit', 'inbox', $id, '', array('source_type'=>$sourceType,'source_id'=>$sourceId));
            nm_json(array('id'=>$id), true, '已提交命名收件箱');
        }
        if ($action === 'inbox_claim') {
            $raw=json_decode(file_get_contents('php://input'),true) ?: array(); $id=(int)($raw['id']??0); if($id<=0) throw new RuntimeException('缺少收件箱 ID。');
            $pdo->prepare("UPDATE naming_inbox SET assigned_to=?,status=IF(status='待处理','处理中',status),updated_at=NOW() WHERE id=?")->execute(array(nm_current_user(),$id));
            nm_log($pdo, 'inbox.claim', 'inbox', $id, '', null); nm_json(null,true,'已领取');
        }
        if ($action === 'inbox_return') {
            $raw=json_decode(file_get_contents('php://input'),true) ?: array(); $id=(int)($raw['id']??0); if($id<=0) throw new RuntimeException('缺少收件箱 ID。');
            $reason=nm_s($raw['reason']??'',5000);
            $pdo->prepare("UPDATE naming_inbox SET status='已退回',return_reason=?,processed_by=?,processed_at=NOW(),updated_at=NOW() WHERE id=?")->execute(array($reason,nm_current_user(),$id));
            $st=$pdo->prepare('SELECT * FROM naming_inbox WHERE id=?'); $st->execute(array($id)); $box=$st->fetch(); if($box) nm_inbox_update_source($pdo,$box);
            nm_log($pdo, 'inbox.return', 'inbox', $id, '', array('reason'=>$reason)); nm_json(null,true,'已退回');
        }
        if ($action === 'inbox_link_model') {
            $raw=json_decode(file_get_contents('php://input'),true) ?: array(); $id=(int)($raw['id']??0); $mid=(int)($raw['model_id']??0);
            if($id<=0 || $mid<=0) throw new RuntimeException('缺少收件箱或型号 ID。');
            $st=$pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1'); $st->execute(array($mid)); $m=$st->fetch(); if(!$m) throw new RuntimeException('型号不存在。');
            nm_inbox_link_model($pdo,$id,$mid,(string)($m['model_no']??''),(string)($m['status']??'')); nm_json(null,true,'已关联型号');
        }
        if ($action === 'logs') {
            nm_ensure_logs($pdo);
            $page=max(1,(int)($_GET['page']??1)); $per=(int)($_GET['per_page']??20); if(!in_array($per,array(10,20,50),true))$per=20;
            $where=array(); $args=array(); $kw=nm_s($_GET['kw']??'');
            if($kw!==''){ $where[]='(action LIKE ? OR model_no LIKE ? OR username LIKE ? OR detail_json LIKE ?)'; for($i=0;$i<4;$i++)$args[]='%'.$kw.'%'; }
            $logAction = nm_s($_GET['log_action'] ?? ($_GET['action_filter'] ?? ''));
            if($logAction!==''){ $where[]='`action`=?'; $args[]=$logAction; }
            $logTarget = nm_s($_GET['target_type'] ?? '');
            if($logTarget!==''){ $where[]='`target_type`=?'; $args[]=$logTarget; }
            if(nm_s($_GET['date_start']??'')!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',nm_s($_GET['date_start']))){$where[]='DATE(created_at)>=?';$args[]=nm_s($_GET['date_start']);}
            if(nm_s($_GET['date_end']??'')!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',nm_s($_GET['date_end']))){$where[]='DATE(created_at)<=?';$args[]=nm_s($_GET['date_end']);}
            $sqlWhere=$where?(' WHERE '.implode(' AND ',$where)):''; $stc=$pdo->prepare('SELECT COUNT(*) FROM naming_logs'.$sqlWhere); $stc->execute($args); $total=(int)$stc->fetchColumn();
            $offset=($page-1)*$per; $st=$pdo->prepare('SELECT * FROM naming_logs'.$sqlWhere.' ORDER BY id DESC LIMIT '.(int)$offset.','.(int)$per); $st->execute($args);
            nm_json(array('rows'=>$st->fetchAll()?:array(),'total'=>$total,'page'=>$page,'per_page'=>$per),true,'操作日志');
        }

        if ($action === 'rules') {
            $rows = $pdo->query('SELECT * FROM naming_rules ORDER BY sort_order ASC,id ASC')->fetchAll() ?: array();
            nm_json(array('rules'=>$rows), true, '规则列表');
        }
        if ($action === 'save_rule') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: array();
            $id = (int)($raw['id'] ?? 0);
            $category = nm_s($raw['category'] ?? '', 80);
            $item = nm_s($raw['item_name'] ?? '', 120);
            $prefix = nm_code_clean((string)($raw['prefix'] ?? ''), 10);
            $sizeLabel = nm_s($raw['size_label'] ?? '开孔/孔宽', 80);
            $defaultSize = nm_digits((string)($raw['default_size'] ?? '075'), 3);
            $seqDigits = max(1, min(5, (int)($raw['seq_digits'] ?? 2)));
            $noFour = !empty($raw['no_four']) ? 1 : 0;
            $enabled = !empty($raw['enabled']) ? 1 : 0;
            $sort = (int)($raw['sort_order'] ?? 0);
            if ($category==='' || $item==='' || $prefix==='' || $defaultSize==='') throw new RuntimeException('规则资料不完整。');
            if ($noFour && nm_has_four($prefix)) throw new RuntimeException('规则前缀包含数字 4。');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE naming_rules SET category=?,item_name=?,prefix=?,size_label=?,default_size=?,seq_digits=?,no_four=?,enabled=?,sort_order=? WHERE id=?');
                $st->execute(array($category,$item,$prefix,$sizeLabel,$defaultSize,$seqDigits,$noFour,$enabled,$sort,$id));
            } else {
                $st = $pdo->prepare('INSERT INTO naming_rules(category,item_name,prefix,size_label,default_size,seq_digits,no_four,enabled,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
                $st->execute(array($category,$item,$prefix,$sizeLabel,$defaultSize,$seqDigits,$noFour,$enabled,$sort));
                $id = (int)$pdo->lastInsertId();
            }
            nm_log($pdo, $id > 0 ? 'rule.save' : 'rule.save', 'rule', $id, '', array('category'=>$category,'item_name'=>$item,'prefix'=>$prefix));
            nm_json(array('id'=>$id), true, '规则已保存');
        }
        if ($action === 'delete_rule') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: array();
            $id = (int)($raw['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('缺少规则 ID。');
            $st = $pdo->prepare('SELECT COUNT(*) FROM naming_models WHERE rule_id=?');
            $st->execute(array($id));
            if ((int)$st->fetchColumn() > 0) throw new RuntimeException('这个规则已经生成过型号，不能删除；可以停用。');
            $pdo->prepare('DELETE FROM naming_rules WHERE id=?')->execute(array($id));
            nm_log($pdo, 'rule.delete', 'rule', $id, '', null);
            nm_json(null, true, '规则已删除');
        }
        if ($action === 'next_model') {
            $ruleId = (int)($_GET['rule_id'] ?? 0);
            $size = nm_digits((string)($_GET['size'] ?? ''), 3);
            $r = nm_get_rule($pdo, $ruleId);
            if ($size === '') $size = (string)$r['default_size'];
            list($serial,$model) = nm_next_serial($pdo, (string)$r['prefix'], $size, (int)$r['seq_digits'], !empty($r['no_four']));
            nm_json(array('serial'=>$serial,'model_no'=>$model,'size_code'=>$size,'rule'=>$r), true, '已生成');
        }
        if ($action === 'get_model') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('缺少型号 ID。');
            $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
            $st->execute(array($id));
            $row = $st->fetch();
            if (!$row) throw new RuntimeException('型号不存在。');
            $row['is_website_sync'] = nm_is_website_row($row) ? 1 : 0;
            $row['image_url'] = nm_pick_media($row,'image');
            $row['drawing_url'] = nm_pick_media($row,'drawing');
            $row['sync_time'] = nm_sync_time($row);
            $row['audit_text'] = array(
                'created_by'=>nm_s($row['created_by'] ?? ''),
                'created_at'=>nm_s($row['created_at'] ?? ''),
                'updated_by'=>nm_s($row['updated_by'] ?? ''),
                'updated_at'=>nm_s($row['updated_at'] ?? ''),
                'source_system'=>nm_s($row['source_system'] ?? ''),
                'source_synced_at'=>nm_s($row['source_synced_at'] ?? '')
            );
            try {
                nm_ensure_logs($pdo);
                $ls = $pdo->prepare('SELECT action,username,created_at,ip,detail_json FROM naming_logs WHERE target_type=? AND target_id=? ORDER BY id DESC LIMIT 20');
                $ls->execute(array('model',$id));
                $row['recent_logs'] = $ls->fetchAll() ?: array();
            } catch (Throwable $e) { $row['recent_logs'] = array(); }
            nm_json($row, true, '型号详情');
        }
        if ($action === 'models') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = (int)($_GET['per_page'] ?? 20); if (!in_array($per,array(10,20,50),true)) $per = 20;
            $res = nm_fetch_page($pdo, $_GET, $page, $per);
            $rows = array();
            foreach ($res['rows'] as $r) { $r['is_website_sync']=nm_is_website_row($r)?1:0; $r['image_url']=nm_pick_media($r,'image'); $r['drawing_url']=nm_pick_media($r,'drawing'); $rows[]=$r; }
            nm_json(array('models'=>$rows,'total'=>$res['total'],'page'=>$page,'per_page'=>$per), true, '型号列表');
        }
        if ($action === 'draft_list') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per = (int)($_GET['per_page'] ?? 20); if (!in_array($per,array(10,20,50),true)) $per = 20;
            $f = $_GET; $f['status'] = '草稿';
            $res = nm_fetch_page($pdo, $f, $page, $per);
            $rows = array();
            foreach ($res['rows'] as $r) { $r['is_website_sync']=nm_is_website_row($r)?1:0; $r['image_url']=nm_pick_media($r,'image'); $r['drawing_url']=nm_pick_media($r,'drawing'); $r['dim_text']=nm_dim_text($r); $rows[]=$r; }
            nm_json(array('rows'=>$rows,'total'=>$res['total'],'page'=>$page,'per_page'=>$per), true, '草稿箱');
        }
        if ($action === 'dispatch_users') {
            nm_json(array('rows'=>nm_dispatch_users($pdo),'current_dispatch_user_id'=>nm_dispatch_current_user_id($pdo)), true, '派工负责人');
        }
        if ($action === 'dispatch_create') {
            $raw = json_decode(file_get_contents('php://input'), true); if (!is_array($raw)) $raw = $_POST;
            $created = nm_dispatch_create($pdo, $raw);
            nm_json($created, true, '已加入派工待办');
        }
        if ($action === 'dispatch_prefill') {
            $id = (int)($_GET['id'] ?? 0);
            $row = null;
            if ($id > 0) { $st=$pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1'); $st->execute(array($id)); $row=$st->fetch(); }
            if (!$row) throw new RuntimeException('未找到型号，无法生成派工待办。');
            $title = '命名型号派工：'.nm_s($row['model_no'] ?? '');
            $note = trim('型号：'.nm_s($row['model_no'] ?? '')."\n系列：".nm_s($row['product_name'] ?? '')."\n灯具类型：".nm_s($row['item_name'] ?? '')."\n尺寸：".nm_dim_text($row));
            nm_log($pdo, 'dispatch.prefill', 'model', $id, nm_s($row['model_no'] ?? ''), array('title'=>$title));
            nm_json(array('url'=>'dispatch_next.php?'.http_build_query(array('source'=>'naming','naming_model_id'=>$id,'model_no'=>nm_s($row['model_no'] ?? ''),'title'=>$title,'note'=>$note))), true, '已准备派工待办');
        }
        if ($action === 'save_model') {
            $id = (int)($_POST['id'] ?? 0);
            $cloneSourceId = (int)($_POST['clone_source_id'] ?? 0);
            $inboxId = (int)($_POST['inbox_id'] ?? 0);
            $isUpdateModel = $id > 0;
            $cloneSource = null;
            if (!$isUpdateModel && $cloneSourceId > 0) {
                $stClone = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
                $stClone->execute(array($cloneSourceId));
                $cloneSource = $stClone->fetch();
                if (!$cloneSource) throw new RuntimeException('复制来源型号不存在。');
            }
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            if ($ruleId <= 0) throw new RuntimeException('请选择命名规则。');
            $r = nm_get_rule($pdo, $ruleId);
            $size = nm_digits((string)($_POST['size_code'] ?? ''), 3);
            if ($size === '') $size = (string)$r['default_size'];
            $manual = nm_model_clean((string)($_POST['model_no'] ?? ''));
            $old = null;
            if ($id > 0) {
                $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
                $st->execute(array($id));
                $old = $st->fetch();
                if (!$old) throw new RuntimeException('要编辑的型号不存在。');
                // V3.0.8.7：官网同步型号允许有 edit_model 权限的账号打开编辑；后续官网同步仍会更新官网字段。
            }
            if ($manual === '') {
                list($serial,$modelNo) = nm_next_serial($pdo, (string)$r['prefix'], $size, (int)$r['seq_digits'], !empty($r['no_four']));
            } else {
                $modelNo = $manual;
                if (strpos($modelNo,'.') === false) throw new RuntimeException('手动型号必须包含点，例如 51.07500。');
                $after = explode('.', $modelNo, 2)[1] ?? '';
                $digits = preg_replace('/\D+/', '', $after);
                if (strlen($digits) >= 3) $size = substr($digits,0,3);
                $serial = substr($after, strlen($size));
                nm_validate_unique_model($pdo, $modelNo, $id);
            }
            $productName = nm_s($_POST['product_name'] ?? '', 180);
            $customer = nm_s($_POST['customer'] ?? '', 180);
            $status = nm_s($_POST['status'] ?? '草稿', 30);
            if ($status === '') $status = '草稿';
            if (in_array($status, array('已确认','已量产'), true)) {
                $oldStatus = $old ? nm_s($old['status'] ?? '') : '';
                if (!$old || !in_array($oldStatus, array('已确认','已量产'), true) || $oldStatus !== $status) nm_perm_require('confirm_model','确认正式型号',true);
            }
            $remark = nm_s($_POST['remark'] ?? '', 2000);
            $bomTemplateType = nm_s($_POST['bom_template_type'] ?? '', 80);
            $bomModulesJson = nm_s($_POST['bom_modules_json'] ?? '', 20000);
            $bomAllowed = isset($_POST['bom_allowed']) ? (int)$_POST['bom_allowed'] : 1;
            $bomUnit = nm_s($_POST['bom_unit'] ?? 'PCS', 30);
            $bomHeadCount = max(1, (int)($_POST['bom_head_count'] ?? 1));
            $bomReadyNote = nm_s($_POST['bom_ready_note'] ?? '', 255);
            $dimType = nm_s($_POST['dimension_type'] ?? '', 30);
            $dimOpening = nm_s($_POST['dim_opening'] ?? '', 60);
            $dimOuter = nm_s($_POST['dim_outer_d'] ?? '', 60);
            $dimLength = nm_s($_POST['dim_length'] ?? '', 60);
            $dimWidth = nm_s($_POST['dim_width'] ?? '', 60);
            $dimHeight = nm_s($_POST['dim_height'] ?? '', 60);
            if (nm_rule_is_embedded($r)) {
                if (!in_array($dimType, array('embedded_round','embedded_square'), true)) $dimType = 'embedded_round';
                // 嵌入圆形：开孔 + 直径 + 高；嵌入方形：开孔 + 长 + 宽 + 高。
                if ($dimType === 'embedded_round') { $dimLength = ''; $dimWidth = ''; }
                if ($dimType === 'embedded_square') { $dimOuter = ''; }
            } else {
                $dimOpening = '';
                if (!in_array($dimType, array('diameter','box'), true)) $dimType = 'diameter';
                // 普通圆形：直径 + 高；普通方形/线性：长 + 宽 + 高。
                if ($dimType === 'diameter') { $dimLength = ''; $dimWidth = ''; }
                if ($dimType === 'box') { $dimOuter = ''; }
            }
            $oldImage = $old ? (string)($old['image_path'] ?? '') : ($cloneSource ? nm_pick_media($cloneSource, 'image') : '');
            $oldDrawing = $old ? (string)($old['drawing_path'] ?? '') : ($cloneSource ? nm_pick_media($cloneSource, 'drawing') : '');
            $clearImage = !empty($_POST['clear_image']);
            $clearDrawing = !empty($_POST['clear_drawing']);
            $hasNewImage = !empty($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $hasNewDrawing = !empty($_FILES['drawing_file']) && (int)($_FILES['drawing_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $img = ($cloneSource && !$isUpdateModel && !$clearImage && !$hasNewImage) ? nm_clone_local_upload($oldImage) : nm_upload('image_file', $clearImage ? '' : $oldImage);
            $drawing = ($cloneSource && !$isUpdateModel && !$clearDrawing && !$hasNewDrawing) ? nm_clone_local_upload($oldDrawing) : nm_upload('drawing_file', $clearDrawing ? '' : $oldDrawing);
            if ($isUpdateModel && $clearImage && $oldImage !== '' && $oldImage !== $img) nm_delete_local_upload($oldImage);
            if ($isUpdateModel && $clearDrawing && $oldDrawing !== '' && $oldDrawing !== $drawing) nm_delete_local_upload($oldDrawing);
            if (!$isUpdateModel && $img === '') throw new RuntimeException('请上传产品图。');
            if (!$isUpdateModel && $drawing === '') throw new RuntimeException('请上传尺寸图。');
            $cols = nm_cols($pdo, 'naming_models');
            $user = nm_current_user();
            if ($id > 0) {
                $sets = array(); $args = array();
                $data = array(
                    'model_no'=>$modelNo,'rule_id'=>$ruleId,'category'=>$r['category'],'item_name'=>$r['item_name'],'prefix'=>$r['prefix'],'size_code'=>$size,'serial_no'=>$serial,
                    'product_name'=>$productName,'customer'=>$customer,'status'=>$status,'remark'=>$remark,'image_path'=>$img,'drawing_path'=>$drawing,
                    'dimension_type'=>$dimType,'dim_opening'=>$dimOpening,'dim_outer_d'=>$dimOuter,'dim_length'=>$dimLength,'dim_width'=>$dimWidth,'dim_height'=>$dimHeight,'bom_template_type'=>$bomTemplateType,'bom_modules_json'=>$bomModulesJson,'bom_allowed'=>$bomAllowed,'bom_unit'=>$bomUnit,'bom_head_count'=>$bomHeadCount,'bom_ready_note'=>$bomReadyNote,'updated_by'=>$user
                );
                foreach ($data as $c=>$v) { if (in_array($c,$cols,true)) { $sets[]="`{$c}`=?"; $args[]=$v; } }
                $args[] = $id;
                $pdo->prepare('UPDATE naming_models SET '.implode(',', $sets).' WHERE id=?')->execute($args);
            } else {
                $data = array(
                    'model_no'=>$modelNo,'rule_id'=>$ruleId,'category'=>$r['category'],'item_name'=>$r['item_name'],'prefix'=>$r['prefix'],'size_code'=>$size,'serial_no'=>$serial,
                    'product_name'=>$productName,'customer'=>$customer,'status'=>$status,'remark'=>$remark,'image_path'=>$img,'drawing_path'=>$drawing,'created_by'=>$user,
                    'dimension_type'=>$dimType,'dim_opening'=>$dimOpening,'dim_outer_d'=>$dimOuter,'dim_length'=>$dimLength,'dim_width'=>$dimWidth,'dim_height'=>$dimHeight,'bom_template_type'=>$bomTemplateType,'bom_modules_json'=>$bomModulesJson,'bom_allowed'=>$bomAllowed,'bom_unit'=>$bomUnit,'bom_head_count'=>$bomHeadCount,'bom_ready_note'=>$bomReadyNote,'updated_by'=>$user
                );
                $insertCols = array(); $marks = array(); $args = array();
                foreach ($data as $c=>$v) { if (in_array($c,$cols,true)) { $insertCols[]="`{$c}`"; $marks[]='?'; $args[]=$v; } }
                $pdo->prepare('INSERT INTO naming_models('.implode(',', $insertCols).') VALUES('.implode(',', $marks).')')->execute($args);
                $id = (int)$pdo->lastInsertId();
            }
            $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
            $st->execute(array($id));
            $fresh = $st->fetch();
            nm_log($pdo, $isUpdateModel ? 'model.update' : ($cloneSource ? 'model.copy_create' : 'model.create'), 'model', $id, $modelNo, array('before'=>$old,'copy_from'=>$cloneSourceId,'after'=>$fresh));
            if ($inboxId > 0) nm_inbox_link_model($pdo, $inboxId, $id, $modelNo, $status);
            nm_json(array('id'=>$id,'model_no'=>$modelNo), true, '型号已保存：'.$modelNo);
        }
        if ($action === 'disable_model' || $action === 'enable_model') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: array();
            $id = (int)($raw['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('缺少型号 ID。');
            $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
            $st->execute(array($id));
            $row = $st->fetch();
            if (!$row) throw new RuntimeException('型号不存在。');
            $newStatus = $action === 'disable_model' ? '停用' : '已确认';
            $pdo->prepare('UPDATE naming_models SET status=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute(array($newStatus, nm_current_user(), $id));
            $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
            $st->execute(array($id));
            $fresh = $st->fetch();
            nm_log($pdo, $action === 'disable_model' ? 'model.disable' : 'model.enable', 'model', $id, (string)($row['model_no'] ?? ''), array('before'=>$row,'after'=>$fresh));
            nm_json(array('id'=>$id,'status'=>$newStatus), true, $action === 'disable_model' ? '型号已停用' : '型号已恢复启用');
        }
        if ($action === 'delete_model') {
            $raw = json_decode(file_get_contents('php://input'), true) ?: array();
            $id = (int)($raw['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('缺少型号 ID。');
            $st = $pdo->prepare('SELECT * FROM naming_models WHERE id=? LIMIT 1');
            $st->execute(array($id));
            $row = $st->fetch();
            if ($row && nm_is_website_row($row)) throw new RuntimeException('官网同步型号不要本地删除，否则下次官网同步会重新回来。请用“停用”功能。');
            if ($row) {
                if (!empty($row['image_path'])) nm_delete_local_upload((string)$row['image_path']);
                if (!empty($row['drawing_path'])) nm_delete_local_upload((string)$row['drawing_path']);
            }
            $pdo->prepare('DELETE FROM naming_models WHERE id=?')->execute(array($id));
            nm_log($pdo, 'model.delete', 'model', $id, (string)($row['model_no'] ?? ''), array('row'=>$row));
            nm_json(null, true, '型号已删除');
        }
        if (in_array($action, array('backup_json','export_json'), true)) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="artdon_naming_full_backup_'.date('Ymd_His').'.json"');
            echo json_encode(nm_backup_payload($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }
        if ($action === 'export_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="artdon_naming_models_'.date('Ymd_His').'.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output','w');
            fputcsv($out, array('ID','系列','灯具类型','型号','分类','尺寸','状态','产品图','尺寸图','创建时间'));
            $res = nm_fetch_page($pdo, $_GET, 1, 50000);
            foreach ($res['rows'] as $r) fputcsv($out, array($r['id'] ?? '', nm_series($r), nm_display_lamp_type($r), $r['model_no'] ?? '', nm_display_category($r), nm_dim_text($r), $r['status'] ?? '', nm_pick_media($r,'image'), nm_pick_media($r,'drawing'), $r['created_at'] ?? ''));
            fclose($out); exit;
        }
        nm_fail('未知 action：'.$action);
    } catch (Throwable $e) {
        nm_fail($e->getMessage());
    }
}
nm_api();

$error = '';
$rows = array(); $total = 0; $folders = array(); $rules = array(); $cols = array();
$categories = array(); $items = array(); $statuses = array(); $seriesOptions = array();
$boardStats = array('total'=>0,'draft'=>0,'confirmed'=>0,'website'=>0,'today'=>0,'no_image'=>0,'no_drawing'=>0,'inbox_pending'=>0,'inbox_processing'=>0,'inbox_total'=>0,'updated_today'=>0);
$nmPerm = array();
$drawingSettings = nm_setting_defaults();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, array(10,20,50), true)) $perPage = 20;
$isProductsPage = isset($_GET['products']) || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'naming_products.php';
$view = nm_s($_GET['view'] ?? 'large');
if (!in_array($view, array('large','medium','small','table'), true)) $view = 'large';
try {
    $pdo = nm_pdo();
    nm_ensure_tables($pdo);
    nm_perm_require_page($pdo);
    $res = nm_fetch_page($pdo, $_GET, $page, $perPage);
    $rows = $res['rows']; $total = $res['total']; $cols = $res['cols'];
    $folders = nm_folder_counts($pdo);
    $rules = $pdo->query('SELECT * FROM naming_rules ORDER BY sort_order ASC,id ASC')->fetchAll() ?: array();
    $categories = nm_distinct($pdo, 'category');
    $items = nm_distinct($pdo, 'item_name');
    $statuses = nm_distinct($pdo, 'status');
    $seriesOptions = array_values(array_unique(array_merge(nm_distinct($pdo,'web_series'), nm_distinct($pdo,'series_name'), nm_distinct($pdo,'product_name'))));
    $drawingSettings = nm_setting_load($pdo);
    $boardStats = nm_board_stats($pdo);
    nm_backup_auto_maybe($pdo, $drawingSettings);
} catch (Throwable $e) { $error = $e->getMessage(); }
try { $nmPerm = nm_perm_runtime(); } catch (Throwable $e) { $nmPerm = array('sso'=>0,'user'=>nm_current_user(),'is_admin'=>0,'view_models'=>1,'create_model'=>1,'edit_model'=>1,'delete_model'=>1,'confirm_model'=>1,'manage_rules'=>1,'manage_settings'=>1,'process_inbox'=>1,'return_inbox'=>1,'export_csv'=>1,'backup_json'=>1,'backup_restore'=>1,'view_logs'=>1); }
$totalPages = max(1, (int)ceil($total / max(1,$perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
    try {
        if (!$error) {
            $res = nm_fetch_page($pdo, $_GET, $page, $perPage);
            $rows = $res['rows'];
            $total = $res['total'];
        }
    } catch (Throwable $e) {}
}
function nm_url(array $overrides = array()): string {
    $q = $_GET;
    foreach ($overrides as $k=>$v) { if ($v === null) unset($q[$k]); else $q[$k]=$v; }
    $base = (isset($GLOBALS['isProductsPage']) && $GLOBALS['isProductsPage']) ? 'naming_products.php' : 'naming.php';
    return $base.($q ? '?'.http_build_query($q) : '');
}
function nm_pager_html(int $page, int $totalPages, string $extraClass = ''): string {
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);
    $cls = trim('pager ' . $extraClass);
    return '<div class="'.nm_h($cls).'"><a class="btn '.($page<=1?'disabled':'').'" href="'.nm_h(nm_url(array('page'=>$prev))).'">上一页</a><span class="chip">第 '.intval($page).' / '.intval($totalPages).' 页</span><a class="btn '.($page>=$totalPages?'disabled':'').'" href="'.nm_h(nm_url(array('page'=>$next))).'">下一页</a></div>';
}
$activeCategory = nm_s($_GET['category'] ?? '');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Artdon 命名中心 V<?=nm_h(NAMING_VERSION)?></title>
<style>
:root{--bg:#f4f6fa;--card:#fff;--line:#dbe3ef;--text:#0f172a;--muted:#64748b;--blue:#2563eb;--red:#dc2626;--green:#16a34a;--orange:#f97316;--shadow:0 16px 50px rgba(15,23,42,.08)}
*{box-sizing:border-box}html{scroll-behavior:auto}body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;font-size:14px}.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.92);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}.top-inner{max-width:1680px;margin:0 auto;padding:14px 18px;display:flex;align-items:center;gap:14px}.logo{width:42px;height:42px;border-radius:14px;background:#111827;color:#fff;display:grid;place-items:center;font-weight:900}.title h1{margin:0;font-size:20px;line-height:1.2}.title p{margin:3px 0 0;color:var(--muted);font-size:12px}.top .title{padding-top:0}.perm-line{color:#334155!important;font-weight:800}.no-perm{opacity:.45;cursor:not-allowed}.top-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}.wrap{max-width:1680px;margin:0 auto;padding:18px}.btn,button{border:1px solid var(--line);background:#fff;color:#111827;border-radius:12px;padding:9px 13px;font-weight:800;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.1}.btn:hover,button:hover{border-color:#b9c7d8}.primary{background:#111827;color:#fff;border-color:#111827}.danger{background:#fff;color:var(--red);border-color:#fecaca}.soft{background:#f8fafc}.panel{background:var(--card);border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow);padding:14px;margin-bottom:14px}.searchbar{display:grid;grid-template-columns:1.5fr auto auto auto auto;gap:10px;align-items:end}.field label{display:block;font-size:12px;color:var(--muted);font-weight:800;margin-bottom:6px}input,select,textarea{width:100%;border:1px solid var(--line);background:#fff;border-radius:12px;padding:10px 11px;font-size:14px;outline:none}textarea{min-height:84px;resize:vertical}input:focus,select:focus,textarea:focus{border-color:#94a3b8;box-shadow:0 0 0 3px rgba(37,99,235,.08)}.stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.chip{display:inline-flex;align-items:center;gap:4px;border:1px solid #e2e8f0;background:#f8fafc;color:#334155;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;white-space:nowrap}.chip.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.chip.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.chip.bad{background:#fef2f2;color:#991b1b;border-color:#fecaca}.folders{display:grid;grid-template-columns:repeat(8,1fr);gap:10px}.folder{border:1px solid var(--line);background:#fff;border-radius:16px;padding:12px;text-decoration:none;color:inherit;min-height:76px}.folder.active{border-color:#111827;box-shadow:inset 0 0 0 1px #111827}.folder .ico{font-size:22px}.folder .name{font-weight:900;margin-top:6px;line-height:1.25;min-height:34px}.folder .count{color:var(--muted);font-size:12px;font-weight:800}.viewbar{display:flex;justify-content:space-between;gap:10px;align-items:center}.viewlinks{display:flex;gap:8px;flex-wrap:wrap}.viewlinks .active{background:#111827;color:#fff;border-color:#111827}.grid{display:grid;gap:14px}.grid.large{grid-template-columns:repeat(5,1fr)}.grid.medium{grid-template-columns:repeat(6,1fr)}.grid.small{grid-template-columns:repeat(8,1fr);gap:10px}.card{background:#fff;border:1px solid var(--line);border-radius:20px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.06);display:flex;flex-direction:column;min-width:0}.pic{aspect-ratio:1/1;background:#fff;border-bottom:1px solid #edf2f7;position:relative;display:grid;place-items:center;overflow:hidden}.pic img{width:100%;height:100%;object-fit:contain;background:#fff;display:block}.empty{color:#94a3b8;font-weight:900;font-size:13px}.webtag{position:absolute;left:10px;top:10px;background:#111827;color:#fff;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;box-shadow:0 8px 24px rgba(0,0,0,.16)}.card-body{padding:12px;display:flex;flex-direction:column;gap:8px;min-height:180px}.model{font-size:21px;font-weight:1000;letter-spacing:.02em;line-height:1.12;word-break:break-all}.grid.medium .model{font-size:18px}.grid.small .model{font-size:15px}.series{font-weight:900;color:#0f172a;line-height:1.3;min-height:36px}.type{color:#475569;font-weight:800;font-size:13px;line-height:1.35}.dim{color:#334155;font-size:13px;line-height:1.35;background:#f8fafc;border:1px solid #edf2f7;border-radius:12px;padding:7px}.minirow{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.drawing{display:flex;gap:8px;align-items:center;border:1px solid #edf2f7;border-radius:12px;padding:7px;background:#fff}.drawing .thumb{width:42px;height:42px;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain;background:#fff}.card-actions{margin-top:auto;display:flex;gap:8px;flex-wrap:wrap}.grid.small .series,.grid.small .type,.grid.small .dim,.grid.small .drawing{font-size:12px}.grid.small .card-body{padding:9px;gap:6px;min-height:160px}.table-wrap{overflow:auto;background:#fff;border:1px solid var(--line);border-radius:18px}.table{width:100%;border-collapse:separate;border-spacing:0}.table th,.table td{padding:10px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:middle;font-size:13px;white-space:nowrap}.table th{background:#f8fafc;color:#475569;font-weight:900;position:sticky;top:0}.sqthumb{width:64px;height:64px;border:1px solid #e2e8f0;border-radius:12px;object-fit:contain;background:#fff}.pager{display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap;margin:18px 0}.pager .disabled{opacity:.45;pointer-events:none}.modal-mask{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:100;display:none;align-items:flex-start;justify-content:center;padding:30px 16px;overflow:auto}.modal-mask.show{display:flex}.modal{background:#fff;border-radius:22px;box-shadow:0 28px 90px rgba(0,0,0,.25);width:min(1100px,100%);overflow:hidden}.modal-head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid var(--line)}.modal-head h2{font-size:18px;margin:0}.modal-body{padding:18px}.form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.form-grid .span2{grid-column:span 2}.form-grid .span4{grid-column:1/-1}.rule-table{width:100%;border-collapse:collapse}.rule-table th,.rule-table td{border-bottom:1px solid #edf2f7;padding:8px;text-align:left;font-size:13px}.err{padding:13px 14px;border-radius:16px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;font-weight:900;margin-bottom:14px}.okmsg{padding:11px 13px;border-radius:14px;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;font-weight:900;margin-bottom:12px;display:none}.muted{color:var(--muted)}.hide{display:none!important}@media(max-width:1400px){.grid.large{grid-template-columns:repeat(4,1fr)}.grid.medium{grid-template-columns:repeat(5,1fr)}.grid.small{grid-template-columns:repeat(6,1fr)}.folders{grid-template-columns:repeat(6,1fr)}}@media(max-width:1100px){.searchbar{grid-template-columns:1fr 1fr}.grid.large,.grid.medium{grid-template-columns:repeat(3,1fr)}.grid.small{grid-template-columns:repeat(4,1fr)}.folders{grid-template-columns:repeat(4,1fr)}.form-grid{grid-template-columns:repeat(2,1fr)}.form-grid .span4{grid-column:1/-1}}@media(max-width:680px){.top-inner{align-items:flex-start}.top-actions{margin-left:0}.searchbar{grid-template-columns:1fr}.grid.large,.grid.medium,.grid.small{grid-template-columns:repeat(2,1fr)}.folders{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.form-grid .span2,.form-grid .span4{grid-column:1/-1}.wrap{padding:12px}.viewbar{align-items:flex-start;flex-direction:column}}

/* V3.0.8.5：接入统一权限中心；保留正常卡片布局；弹窗上传框、尺寸逻辑、日志与搜索加强 */
.pic,.pic img,.thumb{border-radius:0!important}.card{border-radius:14px}.audit{font-size:11px;line-height:1.55;color:#64748b;background:#fbfdff;border:1px solid #edf2f7;border-radius:10px;padding:7px}.audit b{color:#334155}.draw-btn{justify-content:center}.upload-paste-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.upload-paste{border:1px dashed #b8c4d6;background:#f8fafc;border-radius:0;min-height:0;aspect-ratio:1/1;padding:10px;display:flex;flex-direction:column;gap:8px;cursor:pointer}.upload-paste:focus,.upload-paste.active{outline:3px solid rgba(37,99,235,.14);border-color:#2563eb}.upload-paste h4{margin:0;font-size:14px}.upload-paste p{margin:0;color:#64748b;font-size:12px;line-height:1.45}.upload-preview{flex:1;min-height:0;aspect-ratio:1/1;background:#fff;border:1px solid #edf2f7;display:grid;place-items:center;overflow:hidden;color:#94a3b8;font-size:12px}.upload-preview img{width:100%;height:100%;object-fit:contain}.upload-name{font-size:12px;color:#334155;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.modal.media-modal .modal{max-width:980px}.media-frame{height:72vh;background:#fff;border:1px solid #edf2f7;display:grid;place-items:center;overflow:auto}.media-frame img{max-width:100%;max-height:100%;object-fit:contain}.media-frame iframe{width:100%;height:100%;border:0}.log-toolbar{display:grid;grid-template-columns:1fr 120px 120px 100px;gap:8px;margin-bottom:10px}.log-list{display:flex;flex-direction:column;gap:8px;max-height:62vh;overflow:auto}.log-item{border:1px solid #edf2f7;background:#fff;border-radius:12px;padding:10px}.log-item b{font-size:13px}.log-item small{display:block;color:#64748b;margin-top:4px}.log-detail{margin-top:7px;color:#475569;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:7px;white-space:pre-wrap;max-height:120px;overflow:auto;font-size:12px}
@media(max-width:760px){.upload-paste-grid,.log-toolbar{grid-template-columns:1fr}.card{border-radius:10px}}


/* V3.0.8.5：只把“新建型号弹窗”的产品图/尺寸图上传框做成方形，不强制型号卡片变方形 */
.field-hidden{display:none!important}.audit-panel{background:#f8fafc;border:1px solid #dbe3ef;border-radius:14px;padding:12px;margin-bottom:12px;color:#334155;line-height:1.55}.audit-panel b{color:#0f172a}.audit-list{margin-top:8px;display:grid;gap:6px}.audit-line{border-top:1px dashed #dbe3ef;padding-top:6px;font-size:12px}.log-toolbar{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr auto;gap:8px;margin-bottom:12px}.log-list{display:grid;gap:10px}.log-item{border:1px solid #e2e8f0;background:#fbfdff;border-radius:14px;padding:10px}.log-item small{display:block;color:#64748b;margin-top:4px}.log-detail{margin-top:8px;padding:8px;background:#fff;border:1px dashed #dbe3ef;border-radius:10px;max-height:180px;overflow:auto;white-space:pre-wrap;font-size:12px}.log-summary{margin-top:6px;color:#334155;font-size:12px}.field-hidden{display:none!important}
.module-nav{max-width:1680px;margin:0 auto;padding:0 18px 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.module-nav .btn{padding:7px 10px;border-radius:10px;font-size:12px;background:#f8fafc}
.module-nav .btn.current{background:#111827;color:#fff;border-color:#111827}
.upload-paste-grid{grid-template-columns:repeat(2,minmax(260px,340px));justify-content:start;align-items:start}
.upload-paste{aspect-ratio:1/1;min-height:0!important;border-radius:0!important;padding:12px;display:flex;flex-direction:column}
.upload-preview{aspect-ratio:1/1!important;width:100%!important;height:auto!important;min-height:0!important;border-radius:0!important;flex:1;background:#fff;border:1px solid #edf2f7}
.upload-preview img{width:100%!important;height:100%!important;object-fit:contain!important;border-radius:0!important}
.upload-clear{margin-top:4px;background:#fff;color:#dc2626;border-color:#fecaca;padding:7px 10px;font-size:12px;justify-content:center}
.upload-paste.cleared{border-color:#fecaca;background:#fff7f7}
.media-frame{position:relative}.media-frame img.drawing-zoomed{width:var(--nm-drawing-scale,100%);height:auto;max-width:none;max-height:none;object-fit:contain}
.setting-note{font-size:12px;color:#64748b;line-height:1.7;background:#f8fafc;border:1px solid #edf2f7;border-radius:12px;padding:10px;margin-bottom:12px}.setting-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.range-row{display:grid;grid-template-columns:120px 1fr 58px;gap:10px;align-items:center}.range-row input[type=range]{padding:0}.range-row output{font-weight:900;color:#111827;text-align:right}.setting-block{border:1px solid #edf2f7;background:#fbfdff;border-radius:14px;padding:12px}.setting-block h3{margin:0 0 10px;font-size:14px}.check-grid{display:flex;gap:10px;flex-wrap:wrap}.check-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid #e2e8f0;border-radius:999px;padding:7px 10px;font-weight:900;color:#334155;background:#fff}.check-pill input{width:auto}.company-title{margin:0;line-height:1.18}.company-title .company-cn{font-size:24px;font-weight:1000;color:#0f172a}.company-title .company-en{font-size:17px;font-weight:1000;color:#334155;letter-spacing:.02em}.nm-watermark-wrap{position:relative!important}.nm-watermark-wrap::after{content:attr(data-watermark);position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) rotate(-24deg);white-space:pre-line;text-align:center;line-height:1.45;font-weight:1000;letter-spacing:.04em;color:#0f172a;opacity:var(--nm-watermark-opacity,.12);font-size:clamp(18px,4.5vw,58px);pointer-events:none;z-index:12;mix-blend-mode:multiply;text-shadow:0 1px 0 rgba(255,255,255,.45)}.pic.nm-watermark-wrap::after{font-size:clamp(12px,1.7vw,26px)}.upload-preview.nm-watermark-wrap::after{font-size:18px}.media-frame.nm-watermark-wrap::after{position:fixed;font-size:clamp(26px,5vw,72px)}.pic .webtag{z-index:20}
.backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.backup-file-list{display:grid;gap:8px;max-height:300px;overflow:auto}.backup-file{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#fff}.backup-file b{display:block}.backup-file small{display:block;color:#64748b;margin-top:3px}.backup-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.backup-actions .btn,.backup-actions button{padding:7px 9px;font-size:12px}


/* V3.0.8.16：顶部看板、草稿箱、收件箱、保存并派工 */
.top-kanban{margin-left:auto;min-width:560px;max-width:920px;display:grid;grid-template-columns:repeat(5,minmax(96px,1fr));gap:8px;align-self:stretch;align-items:stretch}.kb-card{border:1px solid #e2e8f0;background:#fff;border-radius:14px;padding:10px 12px;text-decoration:none;color:#0f172a;display:flex;flex-direction:column;justify-content:center;min-height:64px;box-shadow:0 8px 24px rgba(15,23,42,.05);cursor:pointer}.kb-card:hover{border-color:#94a3b8}.kb-card span{font-size:12px;color:#64748b;font-weight:900}.kb-card b{font-size:22px;line-height:1.1;margin-top:3px}.kb-card.warn{background:#fff7ed;border-color:#fed7aa}.kb-card.bad{background:#fef2f2;border-color:#fecaca}.kb-card.ok{background:#ecfdf5;border-color:#bbf7d0}.box-list{display:grid;gap:10px;max-height:62vh;overflow:auto}.box-item{border:1px solid #e2e8f0;background:#fff;border-radius:14px;padding:12px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.box-item b{font-size:15px}.box-item small{display:block;color:#64748b;margin-top:4px;line-height:1.45}.box-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.box-actions button,.box-actions .btn{padding:7px 10px;font-size:12px}.dispatch-save{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.dispatch-save:hover{border-color:#86efac}@media(max-width:1280px){.top-inner{align-items:flex-start}.top-kanban{width:100%;min-width:0;max-width:none;grid-template-columns:repeat(5,1fr);margin-left:0}.top-inner{flex-wrap:wrap}}@media(max-width:760px){.top-kanban{grid-template-columns:repeat(2,1fr)}.box-item{grid-template-columns:1fr}.box-actions{justify-content:flex-start}}

/* V3.0.8.15：新建型号先选规则；图片/尺寸图上传区改成所见即所得方形画布 */
.rule-first-note{margin-top:7px;color:#b45309;background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:7px 10px;font-size:12px;font-weight:900;display:none}.rule-first-note.show{display:block}.form-locked .model-after-rule{opacity:.45}.form-locked .model-after-rule input,.form-locked .model-after-rule select,.form-locked .model-after-rule textarea,.form-locked .model-after-rule button{pointer-events:none}.model-step-tip{font-size:12px;color:#64748b;font-weight:800;margin-top:4px}.upload-paste{background:#fff!important}.upload-preview{position:relative;width:100%;aspect-ratio:1/1;min-height:0!important;background:#fff!important;border:1px solid #e2e8f0!important;display:grid!important;place-items:center!important;overflow:hidden!important}.upload-preview img{width:100%!important;height:100%!important;max-width:100%!important;max-height:100%!important;object-fit:contain!important;object-position:center center!important;display:block!important;margin:auto!important}.upload-preview.file-box{background:#f8fafc!important}.upload-paste .upload-wysiwyg-tip{display:block;font-size:11px;color:#64748b;margin:0 0 2px}.upload-paste.cleared .upload-preview{border-color:#fecaca!important;background:#fff7f7!important}.auto-model-chip{display:inline-flex;align-items:center;margin-top:6px;color:#166534;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900}
@media(max-width:760px){.upload-paste-grid{grid-template-columns:1fr}.upload-paste{max-width:360px;width:100%}.module-nav{padding:0 12px 10px}.setting-grid{grid-template-columns:1fr}}

/* V3.0.8.18：列表顶部/底部同样翻页；顶部栏改为不透明，避免卡片“官网同步”角标滚动穿到顶部 */
.top{background:#fff!important;z-index:1000!important;box-shadow:0 8px 28px rgba(15,23,42,.08)}
.top-inner,.module-nav{background:#fff;position:relative;z-index:2}
.module-nav{border-top:0;box-shadow:0 1px 0 rgba(219,227,239,.8)}
.card{isolation:isolate}.pic{z-index:0}.webtag{z-index:1;pointer-events:none}
.viewbar{display:grid!important;grid-template-columns:1fr auto auto;align-items:center}

/* V3.0.8.19：尺寸图查看弹窗缩小约 50%，关闭按钮固定显示，避免被顶部栏或大图挤掉 */
.modal-mask.media-modal{align-items:center!important;justify-content:center!important;padding:86px 16px 26px!important;z-index:1300!important;}
.media-modal .modal{width:min(560px,92vw)!important;max-width:560px!important;max-height:78vh!important;display:flex!important;flex-direction:column!important;overflow:hidden!important;border-radius:18px!important;}
.media-modal .modal-head{flex:0 0 auto!important;position:relative!important;z-index:4!important;background:#fff!important;padding:10px 12px!important;border-bottom:1px solid var(--line)!important;}
.media-modal .modal-head h2{font-size:15px!important;line-height:1.2!important;max-width:calc(100% - 88px);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.media-modal .modal-head button{position:relative!important;z-index:5!important;padding:7px 12px!important;border-radius:10px!important;background:#fff!important;}
.media-modal .modal-body{flex:1 1 auto!important;min-height:0!important;padding:10px!important;overflow:hidden!important;background:#fff!important;}
.media-modal .media-frame{height:min(44vh,430px)!important;min-height:260px!important;border-radius:12px!important;overflow:auto!important;}
.media-modal .media-frame img.drawing-zoomed{display:block;margin:auto;}
.media-modal .media-frame .chip{right:10px!important;bottom:10px!important;}
@media(max-width:680px){.media-modal .modal{width:92vw!important;max-width:92vw!important}.media-modal .media-frame{height:46vh!important;min-height:220px!important}.modal-mask.media-modal{padding-top:70px!important}}
.viewbar .pager{margin:0;justify-content:center}.pager-top{margin:0}.pager-bottom{margin:18px 0}.pager .btn.disabled{opacity:.45;pointer-events:none}
@media(max-width:980px){.viewbar{grid-template-columns:1fr!important}.viewbar .pager{justify-content:flex-start}.viewlinks{justify-content:flex-start}}


/* V3.0.8.21：弹窗层级硬修。所有弹窗/遮罩必须盖住顶部菜单、看板、按钮和卡片角标。 */
.modal-mask{
  position:fixed!important;
  inset:0!important;
  top:0!important;left:0!important;right:0!important;bottom:0!important;
  width:100vw!important;
  height:100vh!important;
  height:100dvh!important;
  z-index:2147483000!important;
  background:rgba(15,23,42,.58)!important;
  display:none!important;
  align-items:flex-start!important;
  justify-content:center!important;
  padding:28px 16px!important;
  overflow:auto!important;
}
.modal-mask.show{display:flex!important;}
.modal-mask .modal{position:relative!important;z-index:2147483100!important;}
.modal-mask.media-modal{z-index:2147483200!important;align-items:center!important;justify-content:center!important;padding:28px 16px!important;}
.modal-mask.media-modal .modal{z-index:2147483300!important;}
body.nm-modal-open{overflow:hidden!important;}
body.nm-modal-open .top,
body.nm-modal-open .top-kanban,
body.nm-modal-open .module-nav,
body.nm-modal-open .webtag,
body.nm-modal-open .kb-card{z-index:1!important;}


/* V3.0.8.23：弹窗尺寸分区可调。由“显示设置”保存到 naming_settings。 */
:root{
  --nm-media-modal-width:70vw;
  --nm-media-frame-height:66vh;
  --nm-model-modal-width:68vw;
  --nm-model-modal-height:86vh;
  --nm-model-upload-size:190px;
  --nm-config-modal-width:76vw;
  --nm-data-modal-width:76vw;
}
#mediaModal .modal{width:min(var(--nm-media-modal-width),calc(100vw - 32px))!important;max-width:calc(100vw - 32px)!important;}
#mediaModal .modal-body{padding:12px!important;}
#mediaModal .media-frame{height:min(var(--nm-media-frame-height),calc(100vh - 150px))!important;min-height:320px!important;width:100%!important;}
#modelModal .modal{width:min(var(--nm-model-modal-width),calc(100vw - 32px))!important;max-width:calc(100vw - 32px)!important;}
#ruleModal .modal,#filterModal .modal,#settingModal .modal{width:min(var(--nm-config-modal-width),calc(100vw - 32px))!important;max-width:calc(100vw - 32px)!important;}
#logModal .modal,#backupModal .modal,#draftModal .modal,#inboxModal .modal{width:min(var(--nm-data-modal-width),calc(100vw - 32px))!important;max-width:calc(100vw - 32px)!important;}
#mediaModal .media-frame img.drawing-zoomed{display:block;margin:auto;}
@media(max-width:680px){
  #mediaModal .modal,#modelModal .modal,#ruleModal .modal,#filterModal .modal,#settingModal .modal,#logModal .modal,#backupModal .modal,#draftModal .modal,#inboxModal .modal{width:94vw!important;max-width:94vw!important;}
  #mediaModal .media-frame{height:56vh!important;min-height:260px!important;}
}



/* V3.0.8.24：新建/编辑型号弹窗紧凑化。减少大空白，保存/派工按钮更容易看到。 */
#modelModal.modal-mask{align-items:flex-start!important;padding:14px 16px!important;}
#modelModal .modal{width:min(var(--nm-model-modal-width),1080px,calc(100vw - 32px))!important;max-width:1080px!important;max-height:min(var(--nm-model-modal-height),calc(100vh - 28px))!important;display:flex!important;flex-direction:column!important;border-radius:18px!important;}
#modelModal .modal-head{padding:10px 14px!important;flex:0 0 auto!important;}
#modelModal .modal-head h2{font-size:17px!important;}
#modelModal .modal-head p{font-size:11px!important;margin-top:2px!important;}
#modelModal .modal-body{padding:12px 14px!important;overflow:auto!important;min-height:0!important;}
#modelModal #modelMsg{margin-bottom:8px!important;padding:8px 10px!important;}
#modelModal #modelAudit{padding:8px 10px!important;margin-bottom:8px!important;font-size:12px!important;line-height:1.45!important;}
#modelModal #modelAudit .audit-list{margin-top:5px!important;gap:4px!important;}
#modelModal #modelAudit .audit-line{padding-top:4px!important;font-size:11px!important;}
#modelModal #modelForm .form-grid{grid-template-columns:1.2fr 1.2fr .85fr .85fr!important;gap:8px 10px!important;align-items:end!important;}
#modelModal .field label{font-size:11px!important;margin-bottom:3px!important;}
#modelModal input,#modelModal select,#modelModal textarea{padding:7px 9px!important;border-radius:9px!important;font-size:13px!important;}
#modelModal textarea#m_remark{min-height:52px!important;}
#modelModal .model-step-tip,#modelModal .rule-first-note,#modelModal .upload-wysiwyg-tip,#modelModal .upload-paste p{font-size:11px!important;line-height:1.35!important;}
#modelModal .upload-paste-grid{grid-template-columns:repeat(2,var(--nm-model-upload-size))!important;gap:10px!important;justify-content:start!important;}
#modelModal .upload-paste{width:var(--nm-model-upload-size)!important;max-width:var(--nm-model-upload-size)!important;min-height:0!important;padding:8px!important;gap:5px!important;}
#modelModal .upload-paste h4{font-size:13px!important;margin:0!important;}
#modelModal .upload-preview{height:auto!important;min-height:0!important;}
#modelModal .upload-name{font-size:11px!important;}
#modelModal .upload-clear{padding:5px 8px!important;font-size:11px!important;border-radius:9px!important;}
#modelModal .model-actionbar{display:flex!important;gap:8px!important;align-items:center!important;padding:8px 0 0!important;margin:0!important;position:sticky!important;bottom:0!important;background:linear-gradient(to top,#fff 75%,rgba(255,255,255,.92))!important;z-index:8!important;}
#modelModal .model-actionbar button{padding:8px 12px!important;}
#modelModal .dispatch-panel{margin-top:8px!important;padding:10px!important;border-radius:12px!important;}
#modelModal .dispatch-panel h3{font-size:14px!important;margin-bottom:4px!important;}
#modelModal .dispatch-panel .dispatch-tip{font-size:11px!important;margin-bottom:6px!important;line-height:1.45!important;}
#modelModal .dispatch-panel .dispatch-grid{gap:8px!important;}
#modelModal .dispatch-panel textarea{min-height:56px!important;}
#modelModal .dispatch-panel .dispatch-actions{margin-top:8px!important;}
@media(max-width:1180px){#modelModal .modal{width:calc(100vw - 24px)!important;max-width:calc(100vw - 24px)!important}#modelModal #modelForm .form-grid{grid-template-columns:repeat(2,1fr)!important}#modelModal #modelForm .form-grid .span2,#modelModal #modelForm .form-grid .span4{grid-column:1/-1!important}}
@media(max-width:720px){#modelModal .upload-paste-grid{grid-template-columns:1fr!important}#modelModal .upload-paste{width:100%!important;max-width:260px!important}}

/* V3.0.8.23：新建/编辑型号弹窗内嵌派工待办，不再跳转派工页面 */
.dispatch-panel{display:none;margin-top:14px;border:1px solid #dbeafe;background:#f8fbff;border-radius:16px;padding:14px;box-shadow:0 10px 30px rgba(37,99,235,.08)}
.dispatch-panel.show{display:block}.dispatch-panel h3{margin:0 0 8px;font-size:16px}.dispatch-panel .dispatch-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:10px}.dispatch-panel .dispatch-grid .span4{grid-column:1/-1}.dispatch-panel textarea{min-height:96px}.dispatch-panel .dispatch-actions{display:flex;gap:8px;align-items:center;justify-content:flex-start;flex-wrap:wrap;margin-top:10px}.dispatch-panel .dispatch-tip{font-size:12px;color:#64748b;line-height:1.6;margin-bottom:10px}.dispatch-panel .dispatch-msg{display:none;margin-top:8px;padding:9px 10px;border-radius:12px;border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;font-weight:900}.dispatch-panel .dispatch-msg.err{border-color:#fecaca;background:#fef2f2;color:#991b1b}
@media(max-width:1000px){.dispatch-panel .dispatch-grid{grid-template-columns:1fr 1fr}.dispatch-panel .dispatch-grid .span4{grid-column:1/-1}}@media(max-width:680px){.dispatch-panel .dispatch-grid{grid-template-columns:1fr}}



/* V3.0.8.25：新建/编辑弹窗重新整理：不再把整个上传卡片缩小；只让预览画布保持大方形，文字/删除按钮放在下方。 */
#modelModal .modal{
  width:min(var(--nm-model-modal-width),1180px,calc(100vw - 32px))!important;
  max-width:1180px!important;
}
#modelModal #modelAudit{
  max-height:118px!important;
  overflow:auto!important;
  padding:8px 10px!important;
  margin-bottom:8px!important;
}
#modelModal #modelAudit .audit-list{
  max-height:68px!important;
  overflow:auto!important;
}
#modelModal #modelForm .form-grid{
  grid-template-columns:1.15fr 1.15fr .9fr .9fr!important;
  gap:8px 10px!important;
}
#modelModal .upload-paste-grid{
  grid-template-columns:repeat(2,minmax(280px,360px))!important;
  gap:12px!important;
  align-items:start!important;
  justify-content:start!important;
}
#modelModal .upload-paste{
  width:100%!important;
  max-width:360px!important;
  min-height:auto!important;
  aspect-ratio:auto!important;
  padding:10px!important;
  display:flex!important;
  flex-direction:column!important;
  gap:6px!important;
  border-radius:0!important;
}
#modelModal .upload-preview{
  width:var(--nm-model-upload-size)!important;
  height:var(--nm-model-upload-size)!important;
  min-width:260px!important;
  min-height:260px!important;
  max-width:340px!important;
  max-height:340px!important;
  aspect-ratio:1/1!important;
  flex:0 0 auto!important;
  margin:0 auto!important;
  background:#fff!important;
  border:1px solid #e2e8f0!important;
  display:grid!important;
  place-items:center!important;
  overflow:hidden!important;
}
#modelModal .upload-preview img{
  width:100%!important;
  height:100%!important;
  max-width:100%!important;
  max-height:100%!important;
  object-fit:contain!important;
  object-position:center center!important;
  display:block!important;
}
#modelModal .upload-paste p{
  min-height:0!important;
  margin:0!important;
  font-size:11px!important;
  line-height:1.35!important;
}
#modelModal .upload-name{
  min-height:18px!important;
}
#modelModal .upload-clear{
  width:100%!important;
  margin-top:2px!important;
}
#modelModal textarea#m_remark{
  min-height:48px!important;
}
#modelModal .model-actionbar{
  min-height:46px!important;
  border-top:1px solid #edf2f7!important;
}
#modelModal .dispatch-panel.show{
  max-height:260px!important;
  overflow:auto!important;
}
@media(max-width:980px){
  #modelModal .upload-paste-grid{grid-template-columns:1fr 1fr!important;}
  #modelModal .upload-preview{min-width:220px!important;min-height:220px!important;}
}
@media(max-width:720px){
  #modelModal .upload-paste-grid{grid-template-columns:1fr!important;}
  #modelModal .upload-paste{max-width:100%!important;}
  #modelModal .upload-preview{width:min(280px,100%)!important;height:min(280px,80vw)!important;min-width:220px!important;min-height:220px!important;}
}


/* V3.0.8.26：把编辑弹窗日志移到产品图/尺寸图右侧，利用右侧空白；图片预览保持大方形。 */
#modelModal .model-media-block{align-self:stretch!important;}
#modelModal .model-media-log-row{display:grid!important;grid-template-columns:minmax(620px,730px) minmax(360px,1fr)!important;gap:16px!important;align-items:stretch!important;width:100%!important;}
#modelModal .model-media-left{min-width:0!important;}
#modelModal .model-media-left .upload-paste-grid{grid-template-columns:repeat(2,minmax(280px,340px))!important;gap:12px!important;justify-content:start!important;}
#modelModal #modelAudit.model-media-log{background:#f8fafc!important;border:1px solid #dbe3ef!important;border-radius:14px!important;padding:12px!important;margin:0!important;min-height:360px!important;max-height:520px!important;overflow:auto!important;font-size:13px!important;line-height:1.65!important;color:#334155!important;}
#modelModal #modelAudit.model-media-log .audit-title{font-size:15px!important;font-weight:1000!important;color:#0f172a!important;margin-bottom:10px!important;padding-bottom:8px!important;border-bottom:1px solid #e2e8f0!important;}
#modelModal #modelAudit.model-media-log .audit-list{max-height:none!important;overflow:visible!important;margin-top:10px!important;display:grid!important;gap:6px!important;}
#modelModal #modelAudit.model-media-log .audit-line{font-size:12px!important;padding:6px 0!important;border-top:1px dashed #dbe3ef!important;}
#modelModal #modelAudit.model-media-log b{color:#0f172a!important;}
@media(max-width:1180px){#modelModal .model-media-log-row{grid-template-columns:1fr!important;}#modelModal #modelAudit.model-media-log{min-height:160px!important;max-height:260px!important;}#modelModal .model-media-left .upload-paste-grid{grid-template-columns:1fr 1fr!important;}}
@media(max-width:720px){#modelModal .model-media-left .upload-paste-grid{grid-template-columns:1fr!important;}}



/* V3.0.8.29：手机端顶部折叠。手机只保留公司名与一个“展开功能”按钮，防止功能按钮/看板挡掉半屏。 */
.mobile-fold-toggle{display:none;border:1px solid #dbe3ef;background:#fff;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900;color:#0f172a;margin-top:6px;line-height:1;box-shadow:0 4px 12px rgba(15,23,42,.05)}
@media(max-width:760px){
  .top{position:sticky!important;top:0!important;background:#fff!important;z-index:1000!important;}
  .top-inner{padding:10px 14px!important;gap:10px!important;align-items:center!important;flex-wrap:nowrap!important;}
  .logo{width:42px!important;height:42px!important;min-width:42px!important;border-radius:13px!important;}
  .title{min-width:0!important;flex:1!important;}
  .company-title .company-cn{font-size:22px!important;line-height:1.12!important;white-space:normal!important;}
  .company-title .company-en{font-size:16px!important;line-height:1.12!important;white-space:normal!important;}
  .mobile-fold-toggle{display:inline-flex!important;align-items:center;justify-content:center;}
  body:not(.nm-mobile-menu-open) .title > p,
  body:not(.nm-mobile-menu-open) .top-kanban,
  body:not(.nm-mobile-menu-open) .module-nav{display:none!important;}
  body.nm-mobile-menu-open .top-inner{flex-wrap:wrap!important;align-items:flex-start!important;}
  body.nm-mobile-menu-open .top-kanban{display:grid!important;width:100%!important;min-width:0!important;max-width:none!important;margin-left:0!important;grid-template-columns:repeat(2, minmax(0,1fr))!important;gap:8px!important;}
  body.nm-mobile-menu-open .kb-card{min-height:54px!important;padding:8px 10px!important;border-radius:13px!important;}
  body.nm-mobile-menu-open .kb-card b{font-size:19px!important;}
  body.nm-mobile-menu-open .module-nav{display:flex!important;padding:8px 14px 10px!important;gap:7px!important;max-height:42vh!important;overflow:auto!important;background:#fff!important;box-shadow:0 1px 0 rgba(219,227,239,.8),0 10px 24px rgba(15,23,42,.08)!important;}
  body.nm-mobile-menu-open .module-nav .btn,
  body.nm-mobile-menu-open .module-nav button{padding:7px 9px!important;font-size:12px!important;border-radius:10px!important;}
  .wrap{padding:10px!important;}
  .panel{border-radius:16px!important;padding:10px!important;margin-bottom:10px!important;}
  .searchbar{gap:8px!important;}
  .folders{gap:8px!important;}
  .folder{min-height:64px!important;padding:10px!important;border-radius:14px!important;}
  .grid.large,.grid.medium,.grid.small{gap:10px!important;}
  .card{border-radius:16px!important;}
  .card-body{padding:10px!important;gap:7px!important;}
  .model{font-size:18px!important;}
}

</style>
</head>
<body>
<header class="top">
  <div class="top-inner">
    <div class="logo">N</div>
    <div class="title">
      <?php if(($drawingSettings['show_company_header'] ?? '1') === '1'): ?>
      <div class="company-title"><div class="company-cn"><?=nm_h($drawingSettings['company_cn'] ?? '中山雅大光电有限公司')?></div><div class="company-en"><?=nm_h($drawingSettings['company_en'] ?? 'Artdon Lighting Limited')?></div></div>
      <?php endif; ?>
      <button type="button" class="mobile-fold-toggle" id="mobileTopToggle" onclick="toggleMobileTop()">展开功能</button>
      <p>V<?=nm_h(NAMING_VERSION)?>：首页链接已改为 index.php；手机端默认折叠功能按钮。</p>
      <p class="perm-line">当前账号：<?=nm_h($nmPerm['user'] ?? nm_current_user())?>　权限：<?=!empty($nmPerm['is_admin'])?'管理员':'命名中心'?></p>
    </div>
    <div class="top-kanban" id="topKanban">
      <a class="kb-card" href="<?=nm_h(nm_url(array('status'=>null,'page'=>1)))?>"><span>全部型号</span><b><?=number_format((int)($boardStats['total'] ?? 0))?></b></a>
      <button type="button" class="kb-card warn" onclick="openDraftModal()"><span>草稿箱</span><b><?=number_format((int)($boardStats['draft'] ?? 0))?></b></button>
      <button type="button" class="kb-card <?=((int)($boardStats['inbox_pending'] ?? 0)>0?'bad':'ok')?>" onclick="openInboxModal()"><span>收件箱待处理</span><b><?=number_format((int)($boardStats['inbox_pending'] ?? 0))?></b></button>
      <a class="kb-card ok" href="<?=nm_h(nm_url(array('date_start'=>date('Y-m-d'),'date_end'=>date('Y-m-d'),'page'=>1)))?>"><span>今日新增</span><b><?=number_format((int)($boardStats['today'] ?? 0))?></b></a>
      <a class="kb-card" href="<?=nm_h(nm_url(array('source'=>'website','page'=>1)))?>"><span>官网同步</span><b><?=number_format((int)($boardStats['website'] ?? 0))?></b></a>
    </div>
  </div>
  <nav class="module-nav">
    <a class="btn" href="index.php">首页</a><a class="btn" href="login.php">登陆</a><a class="btn" href="logout.php">退出</a>
    <a class="btn" href="crm.php">CRM</a><a class="btn" href="mail.php">邮箱</a><a class="btn" href="promotion.php">推广</a><a class="btn" href="quotation.php">报价</a><a class="btn" href="datasheet.php">资料</a><a class="btn" href="plm.php">PLM</a><a class="btn" href="bom.php">BOM</a><a class="btn" href="dispatch_next.php">派工</a><a class="btn current" href="naming.php">命名中心</a><a class="btn" href="permissions.php">权限中心</a><button type="button" onclick="document.getElementById('topKanban')&&document.getElementById('topKanban').scrollIntoView({behavior:'smooth',block:'center'})">看板</button><button type="button" onclick="openDraftModal()">草稿箱</button><button type="button" onclick="openInboxModal()">收件箱</button>
    <?php if(!empty($nmPerm['create_model'])): ?><button class="primary" type="button" onclick="openModelModal()">＋ 新建型号</button><?php endif; ?>
    <?php if(!empty($nmPerm['manage_rules'])): ?><button type="button" onclick="openRuleModal()">规则管理</button><?php endif; ?>
    <?php if(!empty($nmPerm['manage_settings'])): ?><button type="button" onclick="openSettingModal()">显示设置</button><?php endif; ?>
    <button type="button" onclick="openFilterModal()">多条件筛选</button>
    <?php if(!empty($nmPerm['view_logs'])): ?><button type="button" onclick="openLogModal()">操作日志</button><?php endif; ?>
    <?php if(!empty($nmPerm['export_csv'])): ?><a class="btn" href="<?=nm_h(nm_url(array('action'=>'export_csv')))?>">导出CSV</a><?php endif; ?>
    <?php if(!empty($nmPerm['backup_json'])): ?><button type="button" onclick="openBackupModal()">备份管理</button><?php endif; ?>
    <a class="btn soft" href="<?=nm_h(nm_url(array()))?>">刷新</a>
  </nav>
</header>
<main class="wrap">
<?php if($error): ?><div class="err">错误：<?=nm_h($error)?></div><?php endif; ?>
<section class="panel">
  <form class="searchbar" method="get" action="<?= $isProductsPage ? 'naming_products.php' : 'naming.php' ?>">
    <input type="hidden" name="view" value="<?=nm_h($view)?>">
    <input type="hidden" name="page" value="1">
    <div class="field"><label>搜索型号 / 系列 / 灯具类型 / 尺寸</label><input id="quickKw" name="kw" value="<?=nm_h($_GET['kw'] ?? '')?>" placeholder="例：56. / 5605518 / FLEXI / RECESSED / 075" autocomplete="off"></div>
    <div class="field"><label>来源</label><select name="source"><option value="" <?=nm_s($_GET['source']??'')===''?'selected':''?>>全部</option><option value="local" <?=nm_s($_GET['source']??'')==='local'?'selected':''?>>本地新建</option><option value="website" <?=nm_s($_GET['source']??'')==='website'?'selected':''?>>官网同步</option></select></div>
    <div class="field"><label>分类文件夹</label><select name="category"><option value="">全部分类</option><?php foreach($categories as $c): ?><option value="<?=nm_h($c)?>" <?=$activeCategory===$c?'selected':''?>><?=nm_h($c)?></option><?php endforeach; ?></select></div>
    <div class="field"><label>每页</label><select name="per_page" onchange="this.form.submit()"><option value="10" <?=$perPage===10?'selected':''?>>10</option><option value="20" <?=$perPage===20?'selected':''?>>20</option><option value="50" <?=$perPage===50?'selected':''?>>50</option></select></div>
    <div class="field model-after-rule"><label>&nbsp;</label><span class="chip ok" id="quickSearchState">自动搜索</span></div>
  </form>
  <div class="stats"><span class="chip">共 <?=number_format($total)?> 个型号</span><span class="chip">第 <?=$page?> / <?=$totalPages?> 页</span><span class="chip ok">默认按建立日期排列</span><span class="chip warn">视图不会自动切换</span><span class="chip ok">自动模糊搜索不丢光标</span><span class="chip warn" id="websiteSyncChip">官网同步：检测中</span></div>
</section>
<section class="panel">
  <div class="folders">
    <a class="folder <?=$activeCategory===''?'active':''?>" href="<?=nm_h(nm_url(array('category'=>null,'page'=>1)))?>"><div class="ico">📁</div><div class="name">全部型号</div><div class="count"><?=number_format($total)?> 个</div></a>
    <?php foreach($folders as $fo): $fn=(string)$fo['folder_name']; ?>
    <a class="folder <?=$activeCategory===$fn?'active':''?>" href="<?=nm_h(nm_url(array('category'=>$fn==='未分类'?'':$fn,'page'=>1)))?>"><div class="ico">📂</div><div class="name"><?=nm_h($fn)?></div><div class="count"><?=number_format((int)$fo['c'])?> 个</div></a>
    <?php endforeach; ?>
  </div>
</section>
<section class="panel viewbar"><div><b>型号列表</b><span class="muted">　产品图/尺寸图统一方形框显示</span></div><?=nm_pager_html($page,$totalPages,'pager-top')?><div class="viewlinks"><a class="btn <?=$view==='large'?'active':''?>" href="<?=nm_h(nm_url(array('view'=>'large','page'=>1)))?>">大图</a><a class="btn <?=$view==='medium'?'active':''?>" href="<?=nm_h(nm_url(array('view'=>'medium','page'=>1)))?>">中图</a><a class="btn <?=$view==='small'?'active':''?>" href="<?=nm_h(nm_url(array('view'=>'small','page'=>1)))?>">小图</a><a class="btn <?=$view==='table'?'active':''?>" href="<?=nm_h(nm_url(array('view'=>'table','page'=>1)))?>">小表</a></div></section>
<?php if($view === 'table'): ?>
<section class="table-wrap"><table class="table"><thead><tr><th>产品图</th><th>型号</th><th>系列名字</th><th>灯具类型</th><th>分类</th><th>尺寸</th><th>尺寸图</th><th>来源</th><th>状态</th><th>创建/修改</th><th>操作</th></tr></thead><tbody>
<?php foreach($rows as $r): $img=nm_pick_media($r,'image'); $dr=nm_pick_media($r,'drawing'); $web=nm_is_website_row($r); $synced=nm_sync_time($r); ?>
<tr><td><?= $img!=='' ? '<img class="sqthumb" src="'.nm_h($img).'" loading="lazy">' : '<span class="chip bad">无图</span>' ?></td><td><b><?=nm_h($r['model_no']??'')?></b></td><td><?=nm_h(nm_series($r))?></td><td><?=nm_h(nm_display_lamp_type($r))?></td><td><?=nm_h(nm_display_category($r))?></td><td><?=nm_h(nm_dim_text($r))?></td><td><?= $dr!=='' ? '<button type="button" onclick="openMediaModal('.nm_js(($r['model_no']??'').' 尺寸图').','.nm_js($dr).','.nm_js($web?'website':'local').')">尺寸图</button>' : '<span class="chip">无</span>' ?></td><td><span class="chip <?=$web?'ok':''?>"><?=$web?'官网同步':'本地'?></span></td><td><span class="chip <?=nm_badge_class(nm_s($r['status']??''))?>"><?=nm_h($r['status']??'')?></span></td><td><small>创建：<?=nm_h($r['created_at']??'')?><?=nm_s($r['created_by']??'')!==''?' · '.nm_h($r['created_by']):''?><br>修改：<?=nm_h($r['updated_at']??'')?><?=nm_s($r['updated_by']??'')!==''?' · '.nm_h($r['updated_by']):''?></small></td><td><?php if(!empty($nmPerm['edit_model'])): ?><button type="button" onclick="editModel(<?=intval($r['id']??0)?>)">编辑</button><?php if(!empty($nmPerm['create_model'])): ?> <button type="button" onclick="copyModel(<?=intval($r['id']??0)?>)">复制新增</button><?php endif; ?><?php else: ?><span class="chip">无编辑权限</span><?php endif; ?> <?php if(!empty($nmPerm['disable_model']) || !empty($nmPerm['edit_model'])): ?><?php if(nm_s($r['status']??'')==='停用'): ?><button type="button" onclick="setModelDisabled(<?=intval($r['id']??0)?>,false)">恢复</button><?php else: ?><button type="button" onclick="setModelDisabled(<?=intval($r['id']??0)?>,true)">停用</button><?php endif; ?><?php endif; ?> <?php if(!empty($nmPerm['delete_model'])): ?><button class="danger" type="button" onclick="deleteModel(<?=intval($r['id']??0)?>,<?=nm_js($r['model_no']??'')?>,<?= $web ? 'true' : 'false' ?>)">删除</button><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></section>
<?php else: ?>
<section class="grid <?=nm_h($view)?>">
<?php foreach($rows as $r): $img=nm_pick_media($r,'image'); $dr=nm_pick_media($r,'drawing'); $web=nm_is_website_row($r); $synced=nm_sync_time($r); ?>
<article class="card">
  <div class="pic"><?php if($img): ?><img src="<?=nm_h($img)?>" loading="lazy" alt="<?=nm_h($r['model_no']??'')?>"><?php else: ?><div class="empty">无产品图</div><?php endif; ?><?php if($web): ?><div class="webtag">官网同步</div><?php endif; ?></div>
  <div class="card-body">
    <div class="model"><?=nm_h($r['model_no']??'')?></div>
    <div class="series">系列：<?=nm_h(nm_series($r))?></div>
    <div class="type">灯具类型：<?=nm_h(nm_display_lamp_type($r))?></div>
    <div class="dim">尺寸：<?=nm_h(nm_dim_text($r))?></div>
    <div class="minirow"><span class="chip <?=nm_badge_class(nm_s($r['status']??''))?>"><?=nm_h($r['status']??'')?></span><?php if($web): ?><span class="chip ok">官网同步<?= $synced ? '：'.nm_h($synced) : '' ?></span><?php endif; ?></div>
    <div class="audit"><b>创建：</b><?=nm_h($r['created_at']??'')?><?=nm_s($r['created_by']??'')!==''?' · '.nm_h($r['created_by']):''?><br><b>最后修改：</b><?=nm_h($r['updated_at']??'')?><?=nm_s($r['updated_by']??'')!==''?' · '.nm_h($r['updated_by']):''?></div>
    <div class="card-actions"><?php if($dr): ?><button class="draw-btn" type="button" onclick="openMediaModal(<?=nm_js(($r['model_no']??'').' 尺寸图')?>,<?=nm_js($dr)?>,<?=nm_js($web?'website':'local')?>)">尺寸图</button><?php else: ?><span class="chip">无尺寸图</span><?php endif; ?><?php if(!empty($nmPerm['edit_model'])): ?><button type="button" onclick="editModel(<?=intval($r['id']??0)?>)">编辑</button><?php if(!empty($nmPerm['create_model'])): ?> <button type="button" onclick="copyModel(<?=intval($r['id']??0)?>)">复制新增</button><?php endif; ?><?php else: ?><span class="chip">无编辑权限</span><?php endif; ?><?php if(!empty($nmPerm['disable_model']) || !empty($nmPerm['edit_model'])): ?><?php if(nm_s($r['status']??'')==='停用'): ?><button type="button" onclick="setModelDisabled(<?=intval($r['id']??0)?>,false)">恢复</button><?php else: ?><button type="button" onclick="setModelDisabled(<?=intval($r['id']??0)?>,true)">停用</button><?php endif; ?><?php endif; ?><?php if(!empty($nmPerm['delete_model'])): ?><button class="danger" type="button" onclick="deleteModel(<?=intval($r['id']??0)?>,<?=nm_js($r['model_no']??'')?>,<?= $web ? 'true' : 'false' ?>)">删除</button><?php endif; ?></div>
  </div>
</article>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?=nm_pager_html($page,$totalPages,'pager-bottom')?>
</main>

<div class="modal-mask" id="modelModal"><div class="modal"><div class="modal-head"><div><h2 id="modelTitle">新建型号</h2><p style="margin:4px 0 0;color:#64748b;font-size:12px">图片/尺寸图可点击选择、拖入，也可以复制图片后在框内粘贴。</p></div><button type="button" onclick="closeModal('modelModal')">关闭</button></div><div class="modal-body"><div id="modelMsg" class="okmsg"></div><form id="modelForm" enctype="multipart/form-data" class="form-locked"><input type="hidden" name="id" id="m_id"><input type="hidden" name="clone_source_id" id="m_clone_source_id"><input type="hidden" name="inbox_id" id="m_inbox_id"><div class="form-grid"><div class="field span2"><label>命名规则</label><select name="rule_id" id="m_rule" required><option value="">选择规则</option><?php foreach($rules as $r): ?><option value="<?=intval($r['id'])?>" data-size="<?=nm_h($r['default_size'])?>" data-category="<?=nm_h($r['category'])?>" data-item="<?=nm_h($r['item_name'])?>" data-prefix="<?=nm_h($r['prefix'])?>"><?=nm_h($r['category'].' / '.$r['item_name'].' / '.$r['prefix'])?></option><?php endforeach; ?></select><div id="modelRuleHint" class="rule-first-note show">先选择命名规则，再填写尺寸和图片；选择后系统会按已有型号自动流水。</div></div><div class="field model-after-rule"><label>尺寸代码</label><input name="size_code" id="m_size" placeholder="075"></div><div class="field model-after-rule"><label>&nbsp;</label><button type="button" onclick="generateModel()">生成型号</button></div><div class="field span2 model-after-rule"><label>型号</label><input name="model_no" id="m_model" placeholder="选择规则后，填开孔/直径自动生成"><div id="autoModelTip" class="model-step-tip">本地型号按整个型号库已有流水自动生成，例如 D6.04502 后生成 D6.04503。</div></div><div class="field span2 model-after-rule"><label>系列名字 / 产品名称</label><input name="product_name" id="m_product" placeholder="例如 FLEXI RECESSED DOWNLIGHT"></div><div class="field model-after-rule"><label>状态</label><select name="status" id="m_status"><option value="草稿">草稿</option><option value="已确认">已确认</option><option value="已量产">已量产</option><option value="停用">停用</option></select></div><div class="field model-after-rule"><label>客户</label><input name="customer" id="m_customer"></div><div class="field model-after-rule"><label>尺寸类型</label><select name="dimension_type" id="m_dim_type"><option value="embedded_round">嵌入圆形</option><option value="embedded_square">嵌入方形</option><option value="diameter">圆形</option><option value="box">方形/线性</option></select></div><div class="field" id="openingField"><label>开孔</label><input name="dim_opening" id="m_opening"></div><div class="field" id="outerField"><label>直径</label><input name="dim_outer_d" id="m_outer"></div><div class="field" id="lengthField"><label>长</label><input name="dim_length" id="m_length"></div><div class="field" id="widthField"><label>宽</label><input name="dim_width" id="m_width"></div><div class="field" id="heightField"><label>高</label><input name="dim_height" id="m_height"></div><div class="field span4 model-after-rule model-media-block"><label>产品图 / 尺寸图 / 操作日志</label><div class="model-media-log-row"><div class="model-media-left"><div class="upload-paste-grid"><div class="upload-paste" id="imageDrop" tabindex="0" data-input="m_image"><h4>产品图</h4><div class="upload-preview" id="imagePreview">点击 / 拖入 / 粘贴图片</div><p><span class="upload-wysiwyg-tip">所见即所得，保存后按这个方形框居中显示。</span>建议方形图，≤500KB。</p><div class="upload-name" id="imageName">未选择</div><button type="button" class="upload-clear" onclick="clearUpload('m_image',event)">删除产品图</button><input type="hidden" name="clear_image" id="m_clear_image" value="0"><input type="file" name="image_file" id="m_image" accept="image/*" hidden></div><div class="upload-paste" id="drawingDrop" tabindex="0" data-input="m_drawing"><h4>尺寸图</h4><div class="upload-preview" id="drawingPreview">点击 / 拖入 / 粘贴图片</div><p><span class="upload-wysiwyg-tip">所见即所得，上传后会按框内居中显示。</span>支持图片或 PDF，≤500KB。查看倍率可在“显示设置”里统一或分来源调整。</p><div class="upload-name" id="drawingName">未选择</div><button type="button" class="upload-clear" onclick="clearUpload('m_drawing',event)">删除尺寸图</button><input type="hidden" name="clear_drawing" id="m_clear_drawing" value="0"><input type="file" name="drawing_file" id="m_drawing" accept="image/*,.pdf" hidden></div></div></div><div id="modelAudit" class="audit-panel model-media-log" style="display:none"></div></div></div><div class="field span4 model-after-rule"><label>备注</label><textarea name="remark" id="m_remark"></textarea></div><input type="hidden" name="bom_allowed" value="1"><input type="hidden" name="bom_unit" value="PCS"><input type="hidden" name="bom_head_count" value="1"><div class="field span4 model-actionbar"><?php if(!empty($nmPerm['create_model']) || !empty($nmPerm['edit_model'])): ?><button class="primary" type="submit" id="saveModelBtn">保存型号</button><button class="dispatch-save" type="button" id="saveDispatchBtn" onclick="openDispatchPanelFromForm()">派工待办</button><?php else: ?><span class="chip bad">当前账号无新增/编辑型号权限</span><?php endif; ?></div></div><div class="dispatch-panel model-after-rule" id="modelDispatchPanel"><h3>派工待办</h3><div class="dispatch-tip">在这里编辑派工信息并选择负责人。点击“创建派工待办”后，会先保存当前型号，再直接写入派工系统，不跳转页面。</div><div class="dispatch-grid"><div class="field"><label>任务标题</label><input id="d_title" placeholder="命名型号派工：型号"></div><div class="field"><label>负责人</label><select id="d_assignee"><option value="">正在读取负责人……</option></select></div><div class="field"><label>截止时间</label><input id="d_due" type="datetime-local"></div><div class="field"><label>优先级</label><select id="d_priority"><option value="normal">普通</option><option value="important">重要</option><option value="urgent">紧急</option><option value="today">今天必须</option></select></div><div class="field span4"><label>任务说明</label><textarea id="d_description"></textarea></div></div><div class="dispatch-actions"><button class="primary" type="button" onclick="saveModelAndCreateDispatch()">创建派工待办</button><button type="button" onclick="hideDispatchPanel()">取消</button><span class="muted">会写入派工系统待接收列表，并提醒负责人。</span></div><div id="dispatchMsg" class="dispatch-msg"></div></div></form></div></div></div>

<div class="modal-mask" id="filterModal"><div class="modal"><div class="modal-head"><h2>全功能筛选</h2><button type="button" onclick="closeModal('filterModal')">关闭</button></div><div class="modal-body"><form method="get" action="<?= $isProductsPage ? 'naming_products.php' : 'naming.php' ?>" class="form-grid"><input type="hidden" name="view" value="<?=nm_h($view)?>"><div class="field span2"><label>关键词</label><input name="kw" value="<?=nm_h($_GET['kw']??'')?>"></div><div class="field"><label>来源</label><select name="source"><option value="">全部</option><option value="local" <?=nm_s($_GET['source']??'')==='local'?'selected':''?>>本地</option><option value="website" <?=nm_s($_GET['source']??'')==='website'?'selected':''?>>官网同步</option></select></div><div class="field"><label>每页</label><select name="per_page"><option value="10" <?=$perPage===10?'selected':''?>>10</option><option value="20" <?=$perPage===20?'selected':''?>>20</option><option value="50" <?=$perPage===50?'selected':''?>>50</option></select></div><div class="field"><label>系列名字</label><input name="series" list="seriesList" value="<?=nm_h($_GET['series']??'')?>"><datalist id="seriesList"><?php foreach($seriesOptions as $x): ?><option value="<?=nm_h($x)?>"><?php endforeach; ?></datalist></div><div class="field"><label>分类</label><select name="category"><option value="">全部</option><?php foreach($categories as $c): ?><option value="<?=nm_h($c)?>" <?=$activeCategory===$c?'selected':''?>><?=nm_h($c)?></option><?php endforeach; ?></select></div><div class="field"><label>灯具类型</label><select name="item_name"><option value="">全部</option><?php foreach($items as $it): ?><option value="<?=nm_h($it)?>" <?=nm_s($_GET['item_name']??'')===$it?'selected':''?>><?=nm_h($it)?></option><?php endforeach; ?></select></div><div class="field model-after-rule"><label>状态</label><select name="status"><option value="">全部</option><?php foreach($statuses as $st): ?><option value="<?=nm_h($st)?>" <?=nm_s($_GET['status']??'')===$st?'selected':''?>><?=nm_h($st)?></option><?php endforeach; ?></select></div><div class="field"><label>前缀</label><input name="prefix" value="<?=nm_h($_GET['prefix']??'')?>" placeholder="51"></div><div class="field"><label>尺寸/开孔</label><input name="size_code" value="<?=nm_h($_GET['size_code']??'')?>" placeholder="075"></div><div class="field"><label>产品图</label><select name="has_image"><option value="">全部</option><option value="yes" <?=nm_s($_GET['has_image']??'')==='yes'?'selected':''?>>有图</option><option value="no" <?=nm_s($_GET['has_image']??'')==='no'?'selected':''?>>无图</option></select></div><div class="field"><label>尺寸图</label><select name="has_drawing"><option value="">全部</option><option value="yes" <?=nm_s($_GET['has_drawing']??'')==='yes'?'selected':''?>>有尺寸图</option><option value="no" <?=nm_s($_GET['has_drawing']??'')==='no'?'selected':''?>>无尺寸图</option></select></div><div class="field"><label>开始日期</label><input type="date" name="date_start" value="<?=nm_h($_GET['date_start']??'')?>"></div><div class="field"><label>结束日期</label><input type="date" name="date_end" value="<?=nm_h($_GET['date_end']??'')?>"></div><div class="field"><label>排序</label><select name="sort"><option value="created_desc" <?=nm_s($_GET['sort']??'created_desc')==='created_desc'?'selected':''?>>建立日期新到旧</option><option value="created_asc" <?=nm_s($_GET['sort']??'')==='created_asc'?'selected':''?>>建立日期旧到新</option><option value="updated_desc" <?=nm_s($_GET['sort']??'')==='updated_desc'?'selected':''?>>更新日期</option><option value="model_asc" <?=nm_s($_GET['sort']??'')==='model_asc'?'selected':''?>>型号 A-Z</option><option value="model_desc" <?=nm_s($_GET['sort']??'')==='model_desc'?'selected':''?>>型号 Z-A</option><option value="category" <?=nm_s($_GET['sort']??'')==='category'?'selected':''?>>分类</option></select></div><div class="field span4"><button class="primary" type="submit">应用筛选</button><a class="btn" href="<?= $isProductsPage ? 'naming_products.php' : 'naming.php' ?>">清空筛选</a></div></form></div></div></div>

<div class="modal-mask" id="ruleModal"><div class="modal"><div class="modal-head"><h2>规则管理</h2><button type="button" onclick="closeModal('ruleModal')">关闭</button></div><div class="modal-body"><?php if(empty($nmPerm['manage_rules'])): ?><div class="err">当前账号没有管理命名规则权限。</div><?php endif; ?><div id="ruleMsg" class="okmsg"></div><form id="ruleForm" class="form-grid"><input type="hidden" id="r_id"><div class="field"><label>分类</label><input id="r_category" required></div><div class="field"><label>灯具类型</label><input id="r_item" required></div><div class="field"><label>前缀</label><input id="r_prefix" required></div><div class="field"><label>默认尺寸</label><input id="r_size" value="075"></div><div class="field"><label>尺寸名称</label><input id="r_label" value="开孔/孔宽"></div><div class="field"><label>流水位数</label><input id="r_digits" value="2"></div><div class="field"><label>排序</label><input id="r_sort" value="0"></div><div class="field"><label>选项</label><label class="chip"><input type="checkbox" id="r_no4" checked style="width:auto"> 禁 4</label> <label class="chip"><input type="checkbox" id="r_enabled" checked style="width:auto"> 启用</label></div><div class="field span4"><button class="primary" type="submit">保存规则</button><button type="button" onclick="resetRuleForm()">清空</button></div></form><div style="overflow:auto;margin-top:14px"><table class="rule-table" id="ruleTable"><thead><tr><th>分类</th><th>类型</th><th>前缀</th><th>默认尺寸</th><th>流水</th><th>状态</th><th>操作</th></tr></thead><tbody></tbody></table></div></div></div></div>

<div class="modal-mask media-modal" id="mediaModal"><div class="modal"><div class="modal-head"><h2 id="mediaTitle">尺寸图</h2><button type="button" onclick="closeModal('mediaModal')">关闭</button></div><div class="modal-body"><div class="media-frame" id="mediaFrame"></div></div></div></div>

<div class="modal-mask" id="dispatchQuickModal"><div class="modal"><div class="modal-head"><h2>派工待办</h2><button type="button" onclick="closeModal('dispatchQuickModal')">关闭</button></div><div class="modal-body"><input type="hidden" id="qd_model_id"><input type="hidden" id="qd_model_no"><div class="dispatch-panel show"><div class="dispatch-tip">编辑派工信息，选择负责人后直接写入派工系统，不跳转页面。</div><div class="dispatch-grid"><div class="field"><label>任务标题</label><input id="qd_title"></div><div class="field"><label>负责人</label><select id="qd_assignee"><option value="">正在读取负责人……</option></select></div><div class="field"><label>截止时间</label><input id="qd_due" type="datetime-local"></div><div class="field"><label>优先级</label><select id="qd_priority"><option value="normal">普通</option><option value="important">重要</option><option value="urgent">紧急</option><option value="today">今天必须</option></select></div><div class="field span4"><label>任务说明</label><textarea id="qd_description"></textarea></div></div><div class="dispatch-actions"><button class="primary" type="button" onclick="createDispatchFromQuickModal()">创建派工待办</button><button type="button" onclick="closeModal('dispatchQuickModal')">取消</button></div><div id="quickDispatchMsg" class="dispatch-msg"></div></div></div></div></div>
<div class="modal-mask" id="logModal"><div class="modal"><div class="modal-head"><h2>操作日志</h2><button type="button" onclick="closeModal('logModal')">关闭</button></div><div class="modal-body"><div class="log-toolbar"><input id="logKw" placeholder="搜索型号/人员/动作/内容"><select id="logTarget"><option value="">全部对象</option><option value="model">型号</option><option value="rule">规则</option><option value="settings">设置</option><option value="inbox">收件箱</option><option value="website">官网同步</option></select><select id="logAction"><option value="">全部动作</option><option value="model.create">新建型号</option><option value="model.copy_create">复制新增</option><option value="model.update">修改型号</option><option value="model.delete">删除型号</option><option value="model.disable">停用型号</option><option value="model.enable">恢复启用</option><option value="settings.save">保存设置</option><option value="rule.save">规则保存</option><option value="inbox.submit">PLM/BOM收件</option><option value="website.sync">官网同步</option><option value="website.create">官网新增</option><option value="website.update">官网修改</option><option value="website.delete">官网删除</option><option value="website.sync_error">官网同步错误</option></select><select id="logPer"><option value="20">20条</option><option value="50">50条</option><option value="10">10条</option></select><button type="button" onclick="loadLogs()">查询</button></div><div id="logList" class="log-list"></div></div></div></div>


<div class="modal-mask" id="draftModal"><div class="modal"><div class="modal-head"><h2>草稿箱</h2><button type="button" onclick="closeModal('draftModal')">关闭</button></div><div class="modal-body"><div class="setting-note">这里显示状态为“草稿”的本地/官网型号。可直接点编辑继续完善，确认后会从草稿箱减少。</div><div id="draftList" class="box-list"><div class="empty">正在读取草稿……</div></div></div></div></div>

<div class="modal-mask" id="inboxModal"><div class="modal"><div class="modal-head"><h2>命名收件箱</h2><button type="button" onclick="closeModal('inboxModal')">关闭</button></div><div class="modal-body"><div class="setting-note">收件箱用于承接 PLM / CRM / BOM 传来的命名需求。领取后可新建型号，保存时会自动回写收件箱关联。</div><div class="log-toolbar"><input id="inboxKw" placeholder="搜索客户 / 样品 / 产品 / 要求"><select id="inboxStatus"><option value="">全部状态</option><option value="待处理">待处理</option><option value="处理中">处理中</option><option value="已确认">已确认</option><option value="已退回">已退回</option></select><select id="inboxPer"><option value="20">20条</option><option value="50">50条</option><option value="10">10条</option></select><button type="button" onclick="loadInbox()">查询</button></div><div id="inboxList" class="box-list"><div class="empty">正在读取收件箱……</div></div></div></div></div>

<div class="modal-mask" id="backupModal"><div class="modal"><div class="modal-head"><h2>备份管理</h2><button type="button" onclick="closeModal('backupModal')">关闭</button></div><div class="modal-body"><div id="backupMsg" class="okmsg"></div><div class="setting-note">备份包含型号、规则、显示设置、收件箱、日志、官网同步记录。自动备份保存到 uploads/naming_backups，可设置保留数量，超过后自动替换最旧自动备份。</div><div class="backup-grid"><div class="setting-block"><h3>导出 / 自动备份</h3><div class="check-grid" style="margin-bottom:10px"><label class="check-pill"><input type="checkbox" id="b_auto_enabled"> 开启自动备份</label><label class="check-pill"><input type="checkbox" id="b_auto_replace"> 超出数量自动替换旧备份</label></div><div class="setting-grid"><div class="field"><label>自动备份间隔（小时）</label><input id="b_interval" type="number" min="1" max="720" value="24"></div><div class="field"><label>自动备份保留数量</label><input id="b_keep" type="number" min="1" max="30" value="7"></div></div><div class="backup-actions" style="justify-content:flex-start;margin-top:10px"><button class="primary" type="button" onclick="createBackupNow()">立即生成备份</button><a class="btn" href="naming.php?action=backup_json">导出完整 JSON</a><button type="button" onclick="saveBackupSettings()">保存自动备份设置</button></div><p class="muted" id="backupLast" style="margin:10px 0 0"></p></div><div class="setting-block"><h3>导入备份</h3><form id="backupImportForm"><div class="field"><label>选择 JSON 备份文件</label><input type="file" id="backupFile" name="backup_file" accept="application/json,.json"></div><div class="field"><label>导入方式</label><select id="backupImportMode"><option value="merge">合并导入：同型号更新，不清空现有数据</option><option value="replace">替换恢复：先自动做安全备份，再清空后恢复</option></select></div><button class="primary" type="submit">导入备份</button></form></div></div><div class="setting-block" style="margin-top:12px"><h3>服务器备份列表</h3><div id="backupFiles" class="backup-file-list"><div class="empty">正在读取备份列表……</div></div></div></div></div></div>
<div class="modal-mask" id="settingModal"><div class="modal"><div class="modal-head"><h2>显示设置</h2><button type="button" onclick="closeModal('settingModal')">关闭</button></div><div class="modal-body"><div id="settingMsg" class="okmsg"></div><div class="setting-note">这里统一管理公司名、水印和“卡片上点尺寸图按钮后”的查看倍率。设置保存到 naming_settings，不改原图文件。</div><form id="settingForm" class="form-grid"><div class="field span4 setting-block"><h3>公司名</h3><div class="setting-grid"><div class="field"><label>中文公司名</label><input id="s_company_cn" name="company_cn"></div><div class="field"><label>英文公司名</label><input id="s_company_en" name="company_en"></div></div><div class="check-grid"><label class="check-pill"><input type="checkbox" id="s_show_company_header" name="show_company_header"> 页眉显示中英文公司名</label></div></div><div class="field span4 setting-block"><h3>水印</h3><div class="setting-grid"><div class="field"><label>水印中文</label><input id="s_watermark_text_cn" name="watermark_text_cn"></div><div class="field"><label>水印英文</label><input id="s_watermark_text_en" name="watermark_text_en"></div></div><div class="range-row" style="margin:8px 0 10px"><span>透明度</span><input type="range" min="0" max="35" step="1" id="s_watermark_opacity" name="watermark_opacity"><output id="o_watermark_opacity"></output></div><div class="check-grid"><label class="check-pill"><input type="checkbox" id="s_watermark_enabled" name="watermark_enabled"> 开启水印</label><label class="check-pill"><input type="checkbox" id="s_watermark_scope_preview" name="watermark_scope_preview"> 产品图/尺寸图查看弹窗</label><label class="check-pill"><input type="checkbox" id="s_watermark_scope_edit" name="watermark_scope_edit"> 新建/编辑图片预览</label><label class="check-pill"><input type="checkbox" id="s_watermark_scope_card" name="watermark_scope_card"> 型号卡片缩略图</label></div></div><div class="field span4 setting-block"><h3>尺寸图查看倍率</h3><label>尺寸图倍率模式</label><select id="s_scale_mode" name="drawing_scale_mode" onchange="toggleScaleMode()"><option value="separate">分开调整：本地 / 官网 / 其它来源</option><option value="same">统一调整：所有尺寸图同一个倍率</option></select><div id="scaleAllBox" style="margin-top:10px"><label>统一倍率</label><div class="range-row"><span>全部来源</span><input type="range" min="50" max="300" step="5" id="s_scale_all" name="drawing_scale_all"><output id="o_scale_all"></output></div></div><div id="scaleSeparateBox" style="margin-top:10px"><label>分来源倍率</label><div class="setting-grid"><div class="range-row"><span>本地新建</span><input type="range" min="50" max="300" step="5" id="s_scale_local" name="drawing_scale_local"><output id="o_scale_local"></output></div><div class="range-row"><span>官网同步</span><input type="range" min="50" max="300" step="5" id="s_scale_website" name="drawing_scale_website"><output id="o_scale_website"></output></div><div class="range-row"><span>其它来源</span><input type="range" min="50" max="300" step="5" id="s_scale_other" name="drawing_scale_other"><output id="o_scale_other"></output></div></div></div></div><div class="field span4 setting-block"><h3>弹窗尺寸 / 分区调整</h3><div class="setting-note">这些设置只改变弹窗和查看框大小，不改原图文件。调整后所有弹窗按区域统一生效，后面不用再反复改代码。</div><div class="setting-grid"><div class="range-row"><span>尺寸图弹窗宽度</span><input type="range" min="45" max="95" step="1" id="s_modal_media_width" name="modal_media_width"><output id="o_modal_media_width"></output></div><div class="range-row"><span>尺寸图窗口高度</span><input type="range" min="35" max="90" step="1" id="s_modal_media_height" name="modal_media_height"><output id="o_modal_media_height"></output></div><div class="range-row"><span>新建/编辑宽度</span><input type="range" min="50" max="95" step="1" id="s_modal_model_width" name="modal_model_width"><output id="o_modal_model_width"></output></div><div class="range-row"><span>新建/编辑高度</span><input type="range" min="60" max="94" step="1" id="s_modal_model_height" name="modal_model_height"><output id="o_modal_model_height"></output></div><div class="range-row"><span>上传方框大小</span><input type="range" min="240" max="380" step="10" id="s_model_upload_size" name="model_upload_size"><output id="o_model_upload_size"></output></div><div class="range-row"><span>规则/筛选/设置</span><input type="range" min="50" max="95" step="1" id="s_modal_config_width" name="modal_config_width"><output id="o_modal_config_width"></output></div><div class="range-row"><span>日志/备份/收件箱</span><input type="range" min="50" max="95" step="1" id="s_modal_data_width" name="modal_data_width"><output id="o_modal_data_width"></output></div></div></div><div class="field span4"><button class="primary" type="submit">保存显示设置</button></div></form></div></div></div>

<script>
const NM_PERMS = <?= json_encode($nmPerm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
window.NM_PERMS = NM_PERMS;
let NM_DRAWING_SETTINGS = <?= json_encode($drawingSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
window.NM_DRAWING_SETTINGS = NM_DRAWING_SETTINGS;
const $ = (id)=>document.getElementById(id);
function settingVal(k, def=''){ const st=NM_DRAWING_SETTINGS||{}; return (st[k]===undefined || st[k]===null || st[k]==='') ? def : String(st[k]); }
function settingFlag(k, def='1'){ const v=settingVal(k, def); return v==='1' || v==='true' || v==='yes' || v==='on'; }
function watermarkText(){ const cn=settingVal('watermark_text_cn', settingVal('company_cn','中山雅大光电有限公司')); const en=settingVal('watermark_text_en', settingVal('company_en','Artdon Lighting Limited')); return (cn+'\n'+en).trim(); }
function watermarkOpacity(){ let v=parseInt(settingVal('watermark_opacity','12').replace(/[^0-9]/g,''),10); if(isNaN(v)) v=12; if(v<0)v=0; if(v>35)v=35; return v/100; }
function applyWatermark(el, scope){ if(!el) return; const scopeKey=scope==='card'?'watermark_scope_card':(scope==='edit'?'watermark_scope_edit':'watermark_scope_preview'); const on=settingFlag('watermark_enabled','1') && settingFlag(scopeKey, scope==='card'?'0':'1'); if(on){ el.classList.add('nm-watermark-wrap'); el.dataset.watermark=watermarkText(); el.style.setProperty('--nm-watermark-opacity', String(watermarkOpacity())); } else { el.classList.remove('nm-watermark-wrap'); delete el.dataset.watermark; el.style.removeProperty('--nm-watermark-opacity'); } }
function applyStaticWatermarks(){ document.querySelectorAll('.pic').forEach(el=>applyWatermark(el,'card')); document.querySelectorAll('.upload-preview').forEach(el=>applyWatermark(el,'edit')); }
function clampRange(v,min,max,defv){ v=parseInt(String(v??'').replace(/[^0-9]/g,''),10); if(isNaN(v)||v<=0)v=defv; if(v<min)v=min; if(v>max)v=max; return v; }
function modalSetting(k,defv,min,max){ return clampRange(settingVal(k,String(defv)),min,max,defv); }
function applyModalSettingsFromGetter(getter){
  const root=document.documentElement;
  const mediaW=getter('modal_media_width',70,45,95);
  const mediaH=getter('modal_media_height',66,35,90);
  const modelW=getter('modal_model_width',68,50,95);
  const modelH=getter('modal_model_height',86,60,94);
  const uploadSize=getter('model_upload_size',280,240,380);
  const configW=getter('modal_config_width',76,50,95);
  const dataW=getter('modal_data_width',76,50,95);
  root.style.setProperty('--nm-media-modal-width', mediaW+'vw');
  root.style.setProperty('--nm-media-frame-height', mediaH+'vh');
  root.style.setProperty('--nm-model-modal-width', modelW+'vw');
  root.style.setProperty('--nm-model-modal-height', modelH+'vh');
  root.style.setProperty('--nm-model-upload-size', uploadSize+'px');
  root.style.setProperty('--nm-config-modal-width', configW+'vw');
  root.style.setProperty('--nm-data-modal-width', dataW+'vw');
}
function applyModalSettings(){ applyModalSettingsFromGetter((k,d,min,max)=>modalSetting(k,d,min,max)); }
function applyModalSettingsFromForm(){ applyModalSettingsFromGetter((k,d,min,max)=>{ const el=$('s_'+k); return el?clampRange(el.value,min,max,d):modalSetting(k,d,min,max); }); }

function anyModalShown(){ return Array.from(document.querySelectorAll('.modal-mask')).some(m=>m.classList.contains('show')); }
function syncModalBodyState(){ document.body.classList.toggle('nm-modal-open', anyModalShown()); }
function openModal(id){ applyModalSettings(); const el=$(id); if(el){ el.classList.add('show'); el.style.zIndex = el.classList.contains('media-modal') ? '2147483200' : '2147483000'; } syncModalBodyState(); }
function closeModal(id){ const el=$(id); if(el) el.classList.remove('show'); syncModalBodyState(); }

function isImgUrl(u){ return /\.(jpg|jpeg|png|webp|gif)(\?.*)?$/i.test(String(u||'')); }
function isLocalUploadUrl(u){ u=String(u||''); return /^uploads\/naming\//.test(u) || /\/uploads\/naming\//.test(u); }
function clampPct(v){ v=parseInt(String(v||'').replace(/[^0-9]/g,''),10); if(!v||v<50)v=50; if(v>300)v=300; return v; }
function drawingScaleForSource(source){ const st=NM_DRAWING_SETTINGS||{}; if((st.drawing_scale_mode||'separate')==='same') return clampPct(st.drawing_scale_all||100); source=String(source||''); if(source==='website') return clampPct(st.drawing_scale_website||100); if(source==='local') return clampPct(st.drawing_scale_local||100); return clampPct(st.drawing_scale_other||100); }
function inferDrawingSource(url, source){ if(source) return source; const u=String(url||''); if(isLocalUploadUrl(u)) return 'local'; if(u.indexOf('artdonlighting.com')>=0 || u.indexOf('/uploads/website/')>=0) return 'website'; return 'other'; }
function openMediaModal(title,url,source){ $('mediaTitle').textContent=title||'尺寸图'; const f=$('mediaFrame'); const u=String(url||''); const src=inferDrawingSource(u,source); const scale=drawingScaleForSource(src); if(!u){f.innerHTML='<div class="empty">没有尺寸图</div>';} else if(isImgUrl(u)){ f.innerHTML='<img class="drawing-zoomed" style="--nm-drawing-scale:'+scale+'%" src="'+esc(u)+'" alt="尺寸图"><div class="chip" style="position:absolute;right:10px;bottom:10px;z-index:30">'+esc(src==='website'?'官网同步':'本地/其它')+' · '+scale+'%</div>'; } else {f.innerHTML='<iframe src="'+esc(u)+'"></iframe>';} applyWatermark(f,'preview'); openModal('mediaModal'); setTimeout(()=>{ const mm=$('mediaModal'); if(mm) mm.scrollTop=0; if(f) f.scrollTop=0; },20); }
let activeUploadInput=null;
function setClearFlag(inputId,val){ const h=inputId==='m_image' ? $('m_clear_image') : $('m_clear_drawing'); if(h) h.value=val?'1':'0'; }
function setUploadFile(inputId,file){ const input=$(inputId); if(!input||!file)return; const dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; setClearFlag(inputId,0); updateUploadPreview(inputId,file); }
function updateUploadPreview(inputId,file,url=''){
  const isImage = file ? /^image\//.test(file.type) : isImgUrl(url);
  const preview = inputId==='m_image' ? $('imagePreview') : $('drawingPreview');
  const name = inputId==='m_image' ? $('imageName') : $('drawingName');
  const box = inputId==='m_image' ? $('imageDrop') : $('drawingDrop');
  if(box) box.classList.remove('cleared');
  if(file){ setClearFlag(inputId,0); preview.classList.toggle('file-box', !isImage); name.textContent=file.name+' · '+Math.round(file.size/1024)+'KB'; if(isImage){ const u=URL.createObjectURL(file); preview.innerHTML='<img src="'+u+'" alt="preview">'; } else { preview.textContent='已选择：'+file.name; } applyWatermark(preview,'edit'); return; }
  if(url){ setClearFlag(inputId,0); preview.classList.toggle('file-box', !isImage); name.textContent='当前已有文件'; if(isImage){ preview.innerHTML='<img src="'+esc(url)+'" alt="preview">'; } else { preview.textContent='当前 PDF / 文件'; } applyWatermark(preview,'edit'); return; }
  name.textContent='未选择'; preview.classList.remove('file-box'); preview.textContent=inputId==='m_image'?'点击 / 拖入 / 粘贴图片':'点击 / 拖入 / 粘贴图片'; applyWatermark(preview,'edit');
}
function resetUploadPreview(){ ['m_image','m_drawing'].forEach(id=>{ const input=$(id); if(input) input.value=''; setClearFlag(id,0); }); updateUploadPreview('m_image',null,''); updateUploadPreview('m_drawing',null,''); }
function clearUpload(inputId, ev){ if(ev){ev.preventDefault();ev.stopPropagation();} const input=$(inputId); if(input) input.value=''; setClearFlag(inputId,1); const preview=inputId==='m_image'?$('imagePreview'):$('drawingPreview'); const name=inputId==='m_image'?$('imageName'):$('drawingName'); const box=inputId==='m_image'?$('imageDrop'):$('drawingDrop'); if(preview){preview.classList.remove('drawing-zoom'); preview.textContent='已标记删除，保存后生效；也可以重新上传';} if(name) name.textContent=inputId==='m_image'?'已标记删除产品图':'已标记删除尺寸图'; if(box) box.classList.add('cleared'); }
function selectedRuleMeta(){ const s=$('m_rule'); const o=s&&s.selectedOptions&&s.selectedOptions[0]; return {category:o?(o.dataset.category||''):'', item:o?(o.dataset.item||''):'', prefix:o?(o.dataset.prefix||''):'', size:o?(o.dataset.size||''):''}; }
function isEmbeddedMeta(meta){ const txt=(meta.category+' '+meta.item+' '+meta.prefix); return /嵌入|无边|有边/.test(txt) || ['51','52','53','55','56','57','58','59','60'].includes(String(meta.prefix||'')); }
function setDimOptions(opts){ const sel=$('m_dim_type'); const old=sel.value; sel.innerHTML=''; opts.forEach(o=>{ const op=document.createElement('option'); op.value=o[0]; op.textContent=o[1]; sel.appendChild(op); }); if(opts.some(o=>o[0]===old)) sel.value=old; else sel.value=opts[0][0]; }
function toggleField(id, show, inputId){ const el=$(id); if(!el)return; el.classList.toggle('field-hidden', !show); if(!show && inputId && $(inputId)) $(inputId).value=''; }
function updateDimensionFields(){
  const meta=selectedRuleMeta(); const embedded=isEmbeddedMeta(meta);
  setDimOptions(embedded ? [['embedded_round','嵌入圆形'],['embedded_square','嵌入方形']] : [['diameter','圆形'],['box','方形 / 线性']]);
  const t=$('m_dim_type').value;
  const isRound = (t==='embedded_round' || t==='diameter');
  const isSquare = (t==='embedded_square' || t==='box');
  toggleField('openingField', embedded, 'm_opening');
  toggleField('outerField', isRound, 'm_outer');
  toggleField('lengthField', isSquare, 'm_length');
  toggleField('widthField', isSquare, 'm_width');
  toggleField('heightField', true, 'm_height');
}
function lockModelFormUntilRule(){ const form=$('modelForm'); if(!form)return; const hasRule=!!($('m_rule')&&$('m_rule').value); form.classList.toggle('form-locked', !hasRule); const hint=$('modelRuleHint'); if(hint) hint.classList.toggle('show', !hasRule); const save=$('saveModelBtn'); if(save) save.disabled=!hasRule; const saveDispatch=$('saveDispatchBtn'); if(saveDispatch) saveDispatch.disabled=!hasRule; ['m_size','m_model','m_product','m_status','m_customer','m_dim_type','m_opening','m_outer','m_length','m_width','m_height','m_image','m_drawing','m_remark'].forEach(id=>{ const el=$(id); if(el) el.disabled=!hasRule; });}
function onRuleChanged(){ const meta=selectedRuleMeta(); if(meta.size) $('m_size').value=meta.size; updateDimensionFields(); lockModelFormUntilRule(); autoGenerateModelFromDimensions(true); }

function initPasteUpload(){ ['imageDrop','drawingDrop'].forEach(id=>{ const z=$(id); if(!z||z.dataset.ready)return; z.dataset.ready='1'; const inputId=z.dataset.input; z.addEventListener('click',()=>{ if($(inputId)&&$(inputId).disabled){alert('先选择命名规则');return;} activeUploadInput=inputId; z.focus(); $(inputId).click();}); z.addEventListener('focus',()=>{activeUploadInput=inputId; z.classList.add('active');}); z.addEventListener('blur',()=>{z.classList.remove('active');}); z.addEventListener('dragover',e=>{e.preventDefault(); z.classList.add('active'); activeUploadInput=inputId;}); z.addEventListener('dragleave',()=>z.classList.remove('active')); z.addEventListener('drop',e=>{e.preventDefault(); z.classList.remove('active'); const f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; if(f)setUploadFile(inputId,f);}); $(inputId).addEventListener('change',e=>{const f=e.target.files&&e.target.files[0]; if(f) updateUploadPreview(inputId,f);}); }); }
document.addEventListener('paste',e=>{ if(!activeUploadInput) return; const items=Array.from((e.clipboardData&&e.clipboardData.items)||[]); const it=items.find(x=>x.kind==='file'); if(!it)return; const f=it.getAsFile(); if(f){ e.preventDefault(); setUploadFile(activeUploadInput,f); } });
function bindScaleOutputs(){ ['all','local','website','other'].forEach(k=>{ const inp=$('s_scale_'+k), out=$('o_scale_'+k); if(!inp||!out)return; const sync=()=>{out.textContent=clampPct(inp.value)+'%'}; inp.addEventListener('input',sync); sync(); }); }
function bindWatermarkOutputs(){ const inp=$('s_watermark_opacity'), out=$('o_watermark_opacity'); if(inp&&out){ const sync=()=>{let v=parseInt(inp.value||'12',10); if(isNaN(v))v=12; if(v<0)v=0; if(v>35)v=35; out.textContent=v+'%';}; inp.addEventListener('input',sync); sync(); } }
function bindModalOutputs(){
  const defs={modal_media_width:[70,45,95],modal_media_height:[66,35,90],modal_model_width:[68,50,95],modal_model_height:[86,60,94],model_upload_size:[280,240,380],modal_config_width:[76,50,95],modal_data_width:[76,50,95]};
  Object.keys(defs).forEach(k=>{
    const inp=$('s_'+k), out=$('o_'+k); if(!inp||!out)return;
    const [defv,min,max]=defs[k];
    const sync=()=>{ const v=clampRange(inp.value,min,max,defv); out.textContent=v+'%'; applyModalSettingsFromForm(); };
    inp.addEventListener('input',sync); sync();
  });
}
function toggleScaleMode(){ const mode=($('s_scale_mode')&&$('s_scale_mode').value)||'separate'; if($('scaleAllBox')) $('scaleAllBox').classList.toggle('hide', mode!=='same'); if($('scaleSeparateBox')) $('scaleSeparateBox').classList.toggle('hide', mode==='same'); }
async function openSettingModal(){ if(!NM_PERMS.manage_settings){alert('当前账号没有显示设置权限');return;} try{ const d=await api('drawing_settings'); NM_DRAWING_SETTINGS=d||NM_DRAWING_SETTINGS||{}; }catch(e){} const st=NM_DRAWING_SETTINGS||{}; const setVal=(id,val)=>{ if($(id)) $(id).value=val; }; const setChk=(id,val)=>{ if($(id)) $(id).checked=String(val)==='1' || val===true; }; setVal('s_company_cn', st.company_cn||'中山雅大光电有限公司'); setVal('s_company_en', st.company_en||'Artdon Lighting Limited'); setChk('s_show_company_header', st.show_company_header??'1'); setVal('s_watermark_text_cn', st.watermark_text_cn||st.company_cn||'中山雅大光电有限公司'); setVal('s_watermark_text_en', st.watermark_text_en||st.company_en||'Artdon Lighting Limited'); setVal('s_watermark_opacity', st.watermark_opacity||'12'); setChk('s_watermark_enabled', st.watermark_enabled??'1'); setChk('s_watermark_scope_preview', st.watermark_scope_preview??'1'); setChk('s_watermark_scope_edit', st.watermark_scope_edit??'1'); setChk('s_watermark_scope_card', st.watermark_scope_card??'0'); if($('s_scale_mode')) $('s_scale_mode').value=st.drawing_scale_mode||'separate'; ['all','local','website','other'].forEach(k=>{ const inp=$('s_scale_'+k); if(inp) inp.value=clampPct(st['drawing_scale_'+k]|| (k==='website'||k==='other'?100:120)); }); const modalDefs={modal_media_width:70,modal_media_height:66,modal_model_width:68,modal_model_height:86,model_upload_size:280,modal_config_width:76,modal_data_width:76}; Object.keys(modalDefs).forEach(k=>{ const inp=$('s_'+k); if(inp) inp.value=st[k]||modalDefs[k]; }); bindScaleOutputs(); bindWatermarkOutputs(); bindModalOutputs(); toggleScaleMode(); if($('settingMsg')){$('settingMsg').style.display='none';$('settingMsg').textContent='';} openModal('settingModal'); }
if($('settingForm')) $('settingForm').addEventListener('submit', async (ev)=>{ ev.preventDefault(); try{ const val=id=>$(id)?$(id).value:''; const chk=id=>$(id)&&$(id).checked?'1':'0'; const body={drawing_scale_mode:val('s_scale_mode'),drawing_scale_all:val('s_scale_all'),drawing_scale_local:val('s_scale_local'),drawing_scale_website:val('s_scale_website'),drawing_scale_other:val('s_scale_other'),company_cn:val('s_company_cn'),company_en:val('s_company_en'),show_company_header:chk('s_show_company_header'),watermark_enabled:chk('s_watermark_enabled'),watermark_text_cn:val('s_watermark_text_cn'),watermark_text_en:val('s_watermark_text_en'),watermark_opacity:val('s_watermark_opacity'),watermark_scope_preview:chk('s_watermark_scope_preview'),watermark_scope_edit:chk('s_watermark_scope_edit'),watermark_scope_card:chk('s_watermark_scope_card'),modal_media_width:val('s_modal_media_width'),modal_media_height:val('s_modal_media_height'),modal_model_width:val('s_modal_model_width'),modal_model_height:val('s_modal_model_height'),model_upload_size:val('s_model_upload_size'),modal_config_width:val('s_modal_config_width'),modal_data_width:val('s_modal_data_width')}; const res=await fetch('naming.php?action=save_drawing_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'保存失败'); NM_DRAWING_SETTINGS=j.data||body; applyStaticWatermarks(); applyModalSettings(); const msg=$('settingMsg'); if(msg){msg.textContent='显示设置已保存；水印立即生效，页眉公司名刷新后完全同步。';msg.style.display='block';} }catch(e){alert(e.message||e);} });
async function openBackupModal(){ if(!NM_PERMS.backup_json){alert('当前账号没有备份权限');return;} openModal('backupModal'); await loadBackupPanel(); }
function backupMsg(txt, bad=false){ const m=$('backupMsg'); if(m){m.textContent=txt;m.className=bad?'err':'okmsg';m.style.display='block';} }
async function loadBackupPanel(){ try{ const d=await api('backup_settings'); const st=d.settings||{}; if($('b_auto_enabled')) $('b_auto_enabled').checked=String(st.auto_backup_enabled??'1')==='1'; if($('b_auto_replace')) $('b_auto_replace').checked=String(st.auto_backup_replace??'1')==='1'; if($('b_interval')) $('b_interval').value=st.auto_backup_interval_hours||24; if($('b_keep')) $('b_keep').value=st.auto_backup_keep||7; if($('backupLast')) $('backupLast').textContent=(st.auto_backup_last_at?'最近自动备份：'+st.auto_backup_last_at+' · '+(st.auto_backup_last_file||''):'还没有自动备份记录'); renderBackupFiles(d.files||[]); }catch(e){backupMsg('读取备份失败：'+(e.message||e),true);} }
function renderBackupFiles(files){ const box=$('backupFiles'); if(!box)return; box.innerHTML=''; if(!files.length){box.innerHTML='<div class="empty">暂无服务器备份</div>';return;} files.forEach(f=>{ const div=document.createElement('div'); div.className='backup-file'; const size=(Number(f.size||0)/1024).toFixed(1)+' KB'; div.innerHTML='<div><b>'+esc(f.file||'')+'</b><small>'+esc(f.type||'')+' · '+esc(f.mtime||'')+' · '+size+'</small></div><div class="backup-actions"><a class="btn" href="naming.php?action=backup_download&file='+encodeURIComponent(f.file||'')+'">下载</a>'+((NM_PERMS.backup_restore||NM_PERMS.is_admin)?'<button type="button" data-act="restore">恢复</button><button type="button" class="danger" data-act="delete">删除</button>':'')+'</div>'; const r=div.querySelector('[data-act="restore"]'); if(r) r.onclick=()=>restoreBackupFile(f.file); const del=div.querySelector('[data-act="delete"]'); if(del) del.onclick=()=>deleteBackupFile(f.file); box.appendChild(div); }); }
async function saveBackupSettings(){ try{ const body={auto_backup_enabled:$('b_auto_enabled')&&$('b_auto_enabled').checked?1:0,auto_backup_replace:$('b_auto_replace')&&$('b_auto_replace').checked?1:0,auto_backup_interval_hours:$('b_interval')?$('b_interval').value:24,auto_backup_keep:$('b_keep')?$('b_keep').value:7}; const res=await fetch('naming.php?action=save_backup_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'保存失败'); backupMsg('自动备份设置已保存'); await loadBackupPanel(); }catch(e){backupMsg('保存失败：'+(e.message||e),true);} }
async function createBackupNow(){ try{ backupMsg('正在生成备份……'); const d=await api('backup_create'); backupMsg('已生成备份：'+((d.created&&d.created.file)||'')); await loadBackupPanel(); }catch(e){backupMsg('生成失败：'+(e.message||e),true);} }
async function restoreBackupFile(file){ if(!NM_PERMS.backup_restore){alert('当前账号没有恢复备份权限');return;} const mode=confirm('点“确定”：替换恢复（会先做安全备份，再清空恢复）\n点“取消”：合并导入（同型号更新，不清空）')?'replace':'merge'; if(!confirm('确认恢复备份：'+file+'？'))return; try{ backupMsg('正在恢复备份……'); const res=await fetch('naming.php?action=backup_restore_file',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({file,mode})}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'恢复失败'); backupMsg('备份已恢复，页面即将刷新'); setTimeout(()=>location.reload(),800); }catch(e){backupMsg('恢复失败：'+(e.message||e),true);} }
async function deleteBackupFile(file){ if(!NM_PERMS.backup_restore){alert('当前账号没有删除备份权限');return;} if(!confirm('确认删除备份文件：'+file+'？'))return; try{ const res=await fetch('naming.php?action=backup_delete_file',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({file})}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'删除失败'); backupMsg('备份已删除'); await loadBackupPanel(); }catch(e){backupMsg('删除失败：'+(e.message||e),true);} }
if($('backupImportForm')) $('backupImportForm').addEventListener('submit', async (ev)=>{ ev.preventDefault(); if(!NM_PERMS.backup_restore){alert('当前账号没有导入备份权限');return;} const f=$('backupFile')&&$('backupFile').files&&$('backupFile').files[0]; if(!f){alert('先选择 JSON 备份文件');return;} const mode=($('backupImportMode')&&$('backupImportMode').value)||'merge'; if(mode==='replace' && !confirm('替换恢复会先做安全备份，再清空现有命名数据后恢复，确认继续？'))return; try{ backupMsg('正在导入备份……'); const fd=new FormData(); fd.append('backup_file',f); const res=await fetch('naming.php?action=backup_import_json&mode='+encodeURIComponent(mode),{method:'POST',body:fd}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'导入失败'); backupMsg('备份已导入，页面即将刷新'); setTimeout(()=>location.reload(),800); }catch(e){backupMsg('导入失败：'+(e.message||e),true);} });
async function openLogModal(){ if(!NM_PERMS.view_logs){alert('当前账号没有查看操作日志权限');return;} openModal('logModal'); await loadLogs(); }
function logSummary(json){ try{ const o=JSON.parse(json||'{}'); if(o.before&&o.after){ const b=o.before||{}, a=o.after||{}; const keys=['model_no','product_name','category','item_name','status','dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','image_path','drawing_path']; const changed=[]; keys.forEach(k=>{ if(String(b[k]??'')!==String(a[k]??'')) changed.push(k+': '+String(b[k]??'')+' → '+String(a[k]??'')); }); return changed.length?('修改字段：'+changed.slice(0,8).join('；')+(changed.length>8?'……':'')):''; } return ''; }catch(e){ return ''; } }
async function loadLogs(){
  const box=$('logList');
  try{
    if(box) box.innerHTML='<div class="empty">正在读取日志……</div>';
    const params=new URLSearchParams();
    params.set('kw', $('logKw') ? ($('logKw').value||'') : '');
    params.set('target_type', $('logTarget') ? ($('logTarget').value||'') : '');
    params.set('log_action', $('logAction') ? ($('logAction').value||'') : '');
    params.set('per_page', $('logPer') ? ($('logPer').value||20) : 20);
    const d=await api('logs',{qs:params.toString()});
    if(!box) return;
    box.innerHTML='';
    (d.rows||[]).forEach(r=>{ const div=document.createElement('div'); div.className='log-item'; const sum=logSummary(r.detail_json||''); div.innerHTML='<b>'+esc(r.action||'')+'</b> <span class="chip">'+esc(r.target_type||'')+'</span> <span class="chip">'+esc(r.model_no||'')+'</span><small>'+esc(r.created_at||'')+' · 操作人：'+esc(r.username||'')+' · IP：'+esc(r.ip||'')+'</small>'+(sum?'<div class="log-summary">'+esc(sum)+'</div>':'')+(r.detail_json?'<details class="log-detail"><summary>查看原始记录</summary>'+esc(r.detail_json)+'</details>':''); box.appendChild(div); });
    if(!(d.rows||[]).length) box.innerHTML='<div class="empty">暂无日志</div>';
  }catch(e){ if(box) box.innerHTML='<div class="err">日志读取失败：'+esc(e.message||e)+'</div>'; else alert(e.message||e); }
}

function renderModelAudit(r){ const a=$('modelAudit'); if(!a)return; const warn=r.is_website_sync?'<div class="audit-line"><b>提示：</b>这是官网同步型号，允许编辑；官网后续修改仍会实时同步回来。</div>':''; const logs=(r.recent_logs||[]).map(x=>'<div class="audit-line">'+esc(x.created_at||'')+' · '+esc(x.username||'')+' · '+esc(x.action||'')+'</div>').join(''); a.innerHTML='<div class="audit-title">型号日志 / 变更记录</div><b>创建：</b>'+esc(r.created_at||'')+(r.created_by?' · '+esc(r.created_by):'')+'<br><b>最后修改：</b>'+esc(r.updated_at||'')+(r.updated_by?' · '+esc(r.updated_by):'')+'<br><b>来源：</b>'+esc(r.is_website_sync?'官网同步':'本地新建')+(r.sync_time?' · 同步时间：'+esc(r.sync_time):'')+warn+(logs?'<div class="audit-list"><b>最近操作：</b>'+logs+'</div>':''); a.style.display='block'; }
let NM_SAVE_TO_DISPATCH=false; let NM_DISPATCH_PENDING_DATA=null;
function saveModelAndDispatch(){ openDispatchPanelFromForm(); }
function hideDispatchPanel(){ const p=$('modelDispatchPanel'); if(p)p.classList.remove('show'); }
async function loadDispatchUsersInto(selectId){ const sel=$(selectId); if(!sel)return; sel.innerHTML='<option value="">正在读取负责人……</option>'; try{ const j=await api('dispatch_users'); const rows=(j&&j.rows)||[]; sel.innerHTML='<option value="">选择负责人</option>'; rows.forEach(u=>{ const opt=document.createElement('option'); opt.value=u.id; opt.textContent=(u.label||u.name||u.username||('用户#'+u.id))+(u.department?' · '+u.department:''); sel.appendChild(opt); }); if(!rows.length) sel.innerHTML='<option value="">没有可选负责人</option>'; }catch(e){ sel.innerHTML='<option value="">负责人读取失败</option>'; } }
function dispatchTextFromCurrentForm(){ const no=($('m_model')&&$('m_model').value)||''; const series=($('m_product')&&$('m_product').value)||''; const dimType=($('m_dim_type')&&$('m_dim_type').value)||''; const parts=[]; if($('m_opening')&&$('m_opening').value)parts.push('开孔 '+$('m_opening').value); if($('m_outer')&&$('m_outer').value)parts.push('直径 '+$('m_outer').value); if($('m_length')&&$('m_length').value)parts.push('长 '+$('m_length').value); if($('m_width')&&$('m_width').value)parts.push('宽 '+$('m_width').value); if($('m_height')&&$('m_height').value)parts.push('高 '+$('m_height').value); return ['请处理命名型号相关事项。','型号：'+no,'系列：'+series,'尺寸类型：'+dimType,'尺寸：'+parts.join(' / '),'备注：'+(( $('m_remark')&&$('m_remark').value)||'')].join('\n'); }
async function openDispatchPanelFromForm(){ const p=$('modelDispatchPanel'); if(!p)return; if(!$('m_rule')||!$('m_rule').value){ alert('请先选择命名规则。'); return; } p.classList.add('show'); await loadDispatchUsersInto('d_assignee'); const no=($('m_model')&&$('m_model').value)||''; if($('d_title')) $('d_title').value='命名型号派工：'+(no||'待生成型号'); if($('d_description')) $('d_description').value=dispatchTextFromCurrentForm(); p.scrollIntoView({behavior:'smooth',block:'center'}); }
function saveModelAndCreateDispatch(){ const assignee=$('d_assignee')&&$('d_assignee').value; if(!assignee){ alert('请选择负责人。'); return; } NM_SAVE_TO_DISPATCH=true; NM_DISPATCH_PENDING_DATA={title:($('d_title')&&$('d_title').value)||'',assigned_to:assignee,due_at:($('d_due')&&$('d_due').value)||'',priority:($('d_priority')&&$('d_priority').value)||'normal',description:($('d_description')&&$('d_description').value)||''}; const f=$('modelForm'); if(!f)return; if(f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event('submit',{cancelable:true})); }
async function createDispatchTaskForModel(modelId, modelNo, overrides, msgEl){
    const payload=Object.assign({}, overrides||{}, {model_id:modelId||0, model_no:modelNo||''});
    if(msgEl){ msgEl.textContent='正在写入派工系统……'; msgEl.classList.remove('err'); msgEl.style.display='block'; }
    const res=await fetch('naming.php?action=dispatch_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const j=await parseJsonResponse(res);
    if(!j.ok) throw new Error(j.msg||'创建派工失败');
    if(msgEl){ msgEl.textContent='已加入派工待办：#'+((j.data&&j.data.task_id)||''); msgEl.classList.remove('err'); msgEl.style.display='block'; }
    return j.data||{};
}
async function openDispatchFromModel(id,fallbackModelNo){
    try{
        $('qd_model_id').value=id||0; $('qd_model_no').value=fallbackModelNo||'';
        openModal('dispatchQuickModal');
        await loadDispatchUsersInto('qd_assignee');
        let title='命名型号派工：'+(fallbackModelNo||''); let note='型号：'+(fallbackModelNo||'');
        if(id){ try{ const d=await api('dispatch_prefill',{qs:'id='+encodeURIComponent(id)}); if(d&&d.url){ const u=new URL(d.url, location.href); title=u.searchParams.get('title')||title; note=u.searchParams.get('note')||note; } }catch(e){} }
        if($('qd_title')) $('qd_title').value=title;
        if($('qd_description')) $('qd_description').value=note;
        const msg=$('quickDispatchMsg'); if(msg){msg.style.display='none';msg.textContent='';msg.classList.remove('err');}
    }catch(e){ alert(e.message||e); }
}
async function createDispatchFromQuickModal(){
    const msg=$('quickDispatchMsg');
    try{
        const assignee=$('qd_assignee')&&$('qd_assignee').value; if(!assignee) throw new Error('请选择负责人。');
        await createDispatchTaskForModel(($('qd_model_id')&&$('qd_model_id').value)||0, ($('qd_model_no')&&$('qd_model_no').value)||'', {title:($('qd_title')&&$('qd_title').value)||'',assigned_to:assignee,due_at:($('qd_due')&&$('qd_due').value)||'',priority:($('qd_priority')&&$('qd_priority').value)||'normal',description:($('qd_description')&&$('qd_description').value)||''}, msg);
    }catch(e){ if(msg){msg.textContent='创建失败：'+(e.message||e);msg.classList.add('err');msg.style.display='block';} else alert(e.message||e); }
}

function renderDrafts(rows){ const box=$('draftList'); if(!box)return; box.innerHTML=''; if(!rows.length){box.innerHTML='<div class="empty">暂无草稿型号</div>';return;} rows.forEach(r=>{ const div=document.createElement('div'); div.className='box-item'; div.innerHTML='<div><b>'+esc(r.model_no||'(未命名)')+'</b><small>系列：'+esc(r.product_name||r.series_name||r.web_series||'')+'<br>类型：'+esc(r.item_name||r.lamp_type||'')+'　尺寸：'+esc(r.dim_text||r.web_dimensions||r.size_code||'')+'<br>创建：'+esc(r.created_at||'')+' · '+esc(r.created_by||'')+'</small></div><div class="box-actions"><button type="button" data-act="edit">编辑</button><button type="button" data-act="dispatch">派工待办</button><button type="button" data-act="disable">停用</button></div>'; const e=div.querySelector('[data-act="edit"]'); if(e)e.onclick=()=>{closeModal('draftModal'); editModel(r.id);}; const dp=div.querySelector('[data-act="dispatch"]'); if(dp)dp.onclick=()=>openDispatchFromModel(r.id,r.model_no||''); const dis=div.querySelector('[data-act="disable"]'); if(dis)dis.onclick=()=>setModelDisabled(r.id,true); box.appendChild(div); }); }
async function openInboxModal(){ if(!NM_PERMS.process_inbox && !NM_PERMS.is_admin){alert('当前账号没有收件箱权限');return;} openModal('inboxModal'); await loadInbox(); }
async function loadInbox(){ const box=$('inboxList'); if(box) box.innerHTML='<div class="empty">正在读取收件箱……</div>'; try{ const params=new URLSearchParams(); const kw=$('inboxKw')&&$('inboxKw').value; const st=$('inboxStatus')&&$('inboxStatus').value; const per=$('inboxPer')&&$('inboxPer').value; if(kw)params.set('kw',kw); if(st)params.set('status',st); if(per)params.set('per_page',per); const d=await api('inbox_list',{qs:params.toString()}); renderInbox(d.rows||[]); }catch(e){ if(box) box.innerHTML='<div class="err">读取收件箱失败：'+esc(e.message||e)+'</div>'; } }
function renderInbox(rows){ const box=$('inboxList'); if(!box)return; box.innerHTML=''; if(!rows.length){box.innerHTML='<div class="empty">暂无收件箱任务</div>';return;} rows.forEach(r=>{ const status=r.status||''; const div=document.createElement('div'); div.className='box-item'; div.innerHTML='<div><b>'+esc(r.product_name||r.sample_model||r.sample_no||'命名需求')+'</b><small>状态：'+esc(status)+'　来源：'+esc(r.source_type||'')+' #'+esc(r.source_id||'')+'<br>客户：'+esc(r.customer_name||'')+'　样品：'+esc(r.sample_no||'')+'<br>要求：'+esc(r.requirements||r.note||'')+'<br>提交：'+esc(r.submitted_at||'')+' · '+esc(r.submitted_by||'')+'</small></div><div class="box-actions"><button type="button" data-act="claim">领取</button><button type="button" data-act="new">新建型号</button><button type="button" data-act="return">退回</button></div>'; const claim=div.querySelector('[data-act="claim"]'); if(claim)claim.onclick=async()=>{ try{ await fetch('naming.php?action=inbox_claim',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:r.id})}).then(parseJsonResponse).then(j=>{if(!j.ok)throw new Error(j.msg||'领取失败')}); await loadInbox(); }catch(e){alert(e.message||e);} }; const nw=div.querySelector('[data-act="new"]'); if(nw)nw.onclick=()=>openModelFromInbox(r); const ret=div.querySelector('[data-act="return"]'); if(ret)ret.onclick=async()=>{ const reason=prompt('退回原因：','资料不完整，请补充产品图/尺寸图/尺寸参数'); if(reason===null)return; try{ await fetch('naming.php?action=inbox_return',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:r.id,reason})}).then(parseJsonResponse).then(j=>{if(!j.ok)throw new Error(j.msg||'退回失败')}); await loadInbox(); }catch(e){alert(e.message||e);} }; box.appendChild(div); }); }
function openModelFromInbox(r){ closeModal('inboxModal'); openModelModal(); setTimeout(()=>{ if($('m_inbox_id')) $('m_inbox_id').value=r.id||''; if($('m_product')) $('m_product').value=r.product_name||r.sample_model||''; if($('m_customer')) $('m_customer').value=r.customer_name||''; if($('m_remark')) $('m_remark').value=[r.requirements||'',r.note||''].filter(Boolean).join('\n'); const msg=$('modelMsg'); if(msg){msg.textContent='已从收件箱带入需求，请先选择命名规则，再保存型号。';msg.style.display='block';} },80); }
function openModelModal(){ if(!NM_PERMS.create_model){alert('当前账号没有新增型号权限');return;} $('modelTitle').textContent='新建型号'; $('modelForm').reset(); $('m_id').value=''; if($('m_clone_source_id')) $('m_clone_source_id').value=''; $('m_inbox_id').value=''; $('modelMsg').style.display='none'; if($('modelAudit')){$('modelAudit').style.display='none';$('modelAudit').innerHTML='';} resetUploadPreview(); if($('autoModelTip')) $('autoModelTip').textContent='本地型号按整个型号库已有流水自动生成，例如 D6.04502 后生成 D6.04503。'; updateDimensionFields(); lockModelFormUntilRule(); openModal('modelModal'); setTimeout(initPasteUpload,30); }
function openFilterModal(){ openModal('filterModal'); }
async function parseJsonResponse(res){
  const text=await res.text();
  try{ return JSON.parse(text); }catch(e){
    const clean=String(text||'').replace(/<script[\s\S]*?<\/script>/gi,'').replace(/<style[\s\S]*?<\/style>/gi,'').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,220);
    throw new Error('接口没有返回 JSON。可能是登录失效、权限页跳转或 PHP 报错。返回内容：'+(clean||text.slice(0,120)));
  }
}

// V3.0.8.32：删除/停用按钮兜底修复。之前前端缺少 deleteModel / setModelDisabled 全局函数，点击后无反应。
async function deleteModel(id, modelNo, isWebsite){
  id = parseInt(id || 0, 10);
  modelNo = String(modelNo || '');
  if(!id){ alert('删除失败：型号 ID 无效'); return; }
  if(!NM_PERMS.delete_model && !NM_PERMS.is_admin){ alert('当前账号没有删除型号权限，请到权限中心授权 delete_model。'); return; }
  if(isWebsite){
    alert('官网同步型号不建议本地硬删除；如果官网还存在，下次同步会重新回来。请使用“停用”。');
    return;
  }
  if(!confirm('确认删除型号：'+(modelNo||('#'+id))+'？\n删除后会清理本地产品图/尺寸图，操作会写入日志。')) return;
  if(!confirm('再次确认：真的删除这个型号？')) return;
  try{
    const res = await fetch('naming.php?action=delete_model', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({id:id})
    });
    const j = await parseJsonResponse(res);
    if(!j.ok) throw new Error(j.msg || '删除失败');
    alert(j.msg || '型号已删除');
    refreshModelListAfterAction();
  }catch(e){
    alert('删除失败：'+(e.message || e));
  }
}

async function setModelDisabled(id, disabled){
  id = parseInt(id || 0, 10);
  if(!id){ alert('操作失败：型号 ID 无效'); return; }
  if(!NM_PERMS.disable_model && !NM_PERMS.edit_model && !NM_PERMS.is_admin){ alert('当前账号没有停用/恢复权限。'); return; }
  const act = disabled ? 'disable_model' : 'enable_model';
  const label = disabled ? '停用' : '恢复';
  if(!confirm('确认'+label+'这个型号？')) return;
  try{
    const res = await fetch('naming.php?action='+act, {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({id:id})
    });
    const j = await parseJsonResponse(res);
    if(!j.ok) throw new Error(j.msg || (label+'失败'));
    refreshModelListAfterAction();
  }catch(e){
    alert(label+'失败：'+(e.message || e));
  }
}

function refreshModelListAfterAction(){
  const form = document.querySelector('form.searchbar');
  if(form){
    const ev = new Event('submit', {bubbles:true, cancelable:true});
    form.dispatchEvent(ev);
    setTimeout(function(){ if(document.querySelector('.grid,.table-wrap')) return; location.reload(); }, 800);
  }else{
    location.reload();
  }
}

async function api(action, opts={}){
  const url='naming.php?action='+encodeURIComponent(action)+(opts.qs?('&'+opts.qs):'');
  const res=await fetch(url, opts);
  const text=await res.text();
  let j=null;
  try{ j=JSON.parse(text); }catch(e){
    const clean=String(text||'').replace(/<script[\s\S]*?<\/script>/gi,'').replace(/<style[\s\S]*?<\/style>/gi,'').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,220);
    throw new Error('接口没有返回 JSON。可能是登录失效、权限页跳转或 PHP 报错。返回内容：'+(clean||text.slice(0,120)));
  }
  if(!j.ok) throw new Error(j.msg||'操作失败');
  return j.data;
}

function initQuickFuzzySearch(){
  const inp=$('quickKw'); if(!inp || !inp.form) return;
  const form=inp.form;
  let composing=false;
  let timer=null;
  let aborter=null;
  let lastUrl='';
  function setSearchState(text, cls){
    const st=$('quickSearchState');
    if(st){ st.textContent=text; st.className='chip '+(cls||'ok'); }
  }
  function buildSearchUrl(){
    const p=form.querySelector('input[name=page]'); if(p) p.value='1';
    const fd=new FormData(form);
    const params=new URLSearchParams();
    for(const [k,v] of fd.entries()){
      const val=String(v||'').trim();
      if(val!=='') params.set(k,val);
    }
    const base=form.getAttribute('action') || location.pathname;
    const qs=params.toString();
    return base + (qs ? ('?'+qs) : '');
  }
  function replaceIfFound(doc, selector, mode){
    const oldEl=document.querySelector(selector);
    const newEl=doc.querySelector(selector);
    if(oldEl && newEl){
      if(mode==='inner') oldEl.innerHTML=newEl.innerHTML;
      else oldEl.replaceWith(newEl);
      return true;
    }
    return false;
  }
  function replaceAllMatching(doc, selector, mode){
    const olds=Array.from(document.querySelectorAll(selector));
    const news=Array.from(doc.querySelectorAll(selector));
    if(!olds.length || !news.length) return false;
    olds.forEach((oldEl, i)=>{
      const src = news[i] || news[news.length-1];
      if(!src) return;
      const newEl = src.cloneNode(true);
      if(mode==='inner') oldEl.innerHTML = newEl.innerHTML;
      else oldEl.replaceWith(newEl);
    });
    return true;
  }
  async function runQuickSearch(){
    if(composing) return;
    const url=buildSearchUrl();
    if(url===lastUrl) return;
    lastUrl=url;
    if(aborter) aborter.abort();
    setSearchState('正在搜索…','warn');
    // HTML 局部替换在登录重定向、权限页或代理缓存出现时会静默失败，
    // 输入框却仍显示“已自动搜索”。直接导航到完整查询地址，确保统计、
    // 文件夹和型号列表使用同一次服务器查询，不再出现画面仍是全部 161 条。
    window.location.assign(url);
  }
  function scheduleQuickSearch(delay){
    if(composing) return;
    clearTimeout(timer);
    timer=setTimeout(runQuickSearch, delay || 420);
  }
  function clearFolderConstraintForKeyword(){
    const category=form.querySelector('select[name="category"]');
    if(category && category.value!=='') category.value='';
  }
  inp.addEventListener('compositionstart',()=>{composing=true; clearTimeout(timer);});
  inp.addEventListener('compositionend',()=>{composing=false; clearFolderConstraintForKeyword(); scheduleQuickSearch(260);});
  inp.addEventListener('input',()=>{clearFolderConstraintForKeyword(); scheduleQuickSearch(420);});
  inp.addEventListener('keydown',function(e){
    if(e.key==='Enter'){
      e.preventDefault();
      scheduleQuickSearch(0);
    }
  });
  form.addEventListener('submit',function(e){
    e.preventDefault();
    scheduleQuickSearch(0);
  });
  form.addEventListener('change',function(e){
    if(e.target && e.target.id!=='quickKw') scheduleQuickSearch(120);
  });
}
function nmModalOpen(){ return Array.from(document.querySelectorAll('.modal-mask')).some(m=>m.classList.contains('show')); }
function isUserEditingNow(){
  const a=document.activeElement;
  if(nmModalOpen()) return true;
  if(!a) return false;
  const tag=(a.tagName||'').toLowerCase();
  return tag==='input' || tag==='textarea' || tag==='select' || a.isContentEditable;
}
async function updateWebsiteSyncChip(){
  const chip=$('websiteSyncChip'); if(!chip) return;
  try{
    const d=await api('website_sync_status');
    const st=d.state||{};
    const last=(st.last_success_at&&st.last_success_at.value)||'';
    const err=(st.last_error&&st.last_error.value)||'';
    const autoErr=(st.last_auto_error&&st.last_auto_error.value)||'';
    if(last){
      chip.className='chip ok';
      chip.title=(autoErr||err)||'';
      chip.textContent='官网同步：'+last;
    }else if(err){
      chip.className='chip warn';
      chip.title=err;
      chip.textContent='官网同步待配置：点官网同步查看原因';
    }else{
      chip.className='chip warn';
      chip.textContent='官网同步：待同步';
    }
  }catch(e){ chip.className='chip warn'; chip.title=e.message||''; chip.textContent='官网同步状态待检测'; }
}
async function runWebsiteSync(force){
  const chip=$('websiteSyncChip');
  if(!force && isUserEditingNow()){
    if(chip){chip.className='chip warn';chip.textContent='官网同步：编辑/搜索中暂停';}
    return;
  }
  if(chip){chip.className='chip warn';chip.textContent=force?'官网同步：强制同步中':'官网同步：后台检查中';}
  try{
    const d=await api('realtime_sync_v19',{qs:force?'force=1':''});
    if(chip){
      if(d.degraded){ chip.className='chip warn'; chip.title=d.error||''; chip.textContent='官网同步：上次成功，后台重试中'; }
      else { chip.className=d.error?'chip warn':'chip ok'; chip.textContent=d.skipped?'官网同步：已是最新':('官网同步：新增'+(d.created||0)+' / 改'+(d.updated||0)+' / 删'+(d.deleted||0)); }
    }
    if(force) alert((d.msg||'官网同步完成')+'\n新增：'+(d.created||0)+'，修改：'+(d.updated||0)+'，删除：'+(d.deleted||0)+'，跳过：'+(d.skipped||0)+(d.error?'\n原因：'+d.error:''));
    if(!d.skipped && !d.degraded && ((d.created||0)+(d.updated||0)+(d.deleted||0)>0)){
      if(!isUserEditingNow()) setTimeout(()=>location.reload(),600);
      else if(chip){ chip.className='chip warn'; chip.textContent='官网同步：有更新，保存后刷新'; }
    }
  }catch(e){
    if(force){ if(chip){chip.className='chip bad';chip.textContent='官网同步失败：'+(e.message||'').slice(0,30);} alert(e.message); }
    else { if(chip){chip.className='chip warn';chip.title=e.message||'';chip.textContent='官网同步：后台稍后重试';} }
  }
}
window.addEventListener('load', function(){ initQuickFuzzySearch(); updateWebsiteSyncChip(); setTimeout(function(){runWebsiteSync(false);}, 2500); setInterval(function(){runWebsiteSync(false);}, 60000); });
function digits3FromText(v){ v=String(v||'').replace(/[^0-9]/g,''); if(!v)return ''; if(v.length>3)v=v.slice(0,3); while(v.length<3)v='0'+v; return v; }
function sizeFromDimensionFields(){ const meta=selectedRuleMeta(); const embedded=isEmbeddedMeta(meta); const t=$('m_dim_type')?$('m_dim_type').value:''; let v=''; if(embedded){ v=$('m_opening')&&$('m_opening').value ? $('m_opening').value : (($('m_outer')&&$('m_outer').value)||($('m_length')&&$('m_length').value)||''); } else if(t==='diameter'){ v=($('m_outer')&&$('m_outer').value)||''; } else { v=($('m_length')&&$('m_length').value)||($('m_width')&&$('m_width').value)||''; } return digits3FromText(v) || digits3FromText($('m_size')&&$('m_size').value) || digits3FromText(meta.size); }
let autoModelTimer=null, autoModelBusy=false;
async function generateModel(silent=false){ if(!NM_PERMS.create_model){if(!silent)alert('当前账号没有生成型号权限');return;} try{ const rid=$('m_rule').value; const size=sizeFromDimensionFields(); if(!rid){if(!silent)alert('先选择命名规则');return;} if(!size){if(!silent)alert('请先填写开孔、直径或尺寸代码');return;} const data=await api('next_model',{qs:'rule_id='+encodeURIComponent(rid)+'&size='+encodeURIComponent(size)}); $('m_model').value=data.model_no; $('m_size').value=data.size_code; const tip=$('autoModelTip'); if(tip) tip.innerHTML='<span class="auto-model-chip">已按现有型号库自动流水：'+esc(data.model_no)+'</span>'; }catch(e){ if(!silent) alert(e.message); }}
function autoGenerateModelFromDimensions(force=false){ if($('m_id') && $('m_id').value) return; if(!$('m_rule') || !$('m_rule').value) return; const size=sizeFromDimensionFields(); if(!size)return; clearTimeout(autoModelTimer); autoModelTimer=setTimeout(()=>generateModel(true), force?60:360); }
async function editModel(id){
  // V3.0.8.8：先弹窗，再加载资料。旧版缺少 NM_PERMS 常量会导致按钮点了没反应；这一版彻底修掉。
  const msg=$('modelMsg');
  try{
    $('modelTitle').textContent='编辑型号';
    $('modelForm').reset();
    if(msg){ msg.textContent='正在读取型号资料……'; msg.style.display='block'; }
    if($('modelAudit')){ $('modelAudit').style.display='none'; $('modelAudit').innerHTML=''; }
    resetUploadPreview();
    openModal('modelModal');
    setTimeout(initPasteUpload,30);
    const r=await api('get_model',{qs:'id='+encodeURIComponent(id)});
    $('modelTitle').textContent=r.is_website_sync?'编辑型号（官网同步）':'编辑型号';
    $('m_id').value=r.id||''; if($('m_clone_source_id')) $('m_clone_source_id').value='';
    if($('m_rule')){
      $('m_rule').value=r.rule_id||'';
      if(!$('m_rule').value && r.prefix){
        const opt=Array.from($('m_rule').options).find(o=>String(o.dataset.prefix||'')===String(r.prefix||''));
        if(opt) $('m_rule').value=opt.value;
      }
    }
    updateDimensionFields();
    $('m_size').value=r.size_code||'';
    $('m_model').value=r.model_no||'';
    $('m_product').value=r.product_name||r.series_name||r.web_series||'';
    $('m_customer').value=r.customer||'';
    $('m_status').value=r.status||'草稿';
    const dt=r.dimension_type||($('openingField')&&!$('openingField').classList.contains('field-hidden')?'embedded_round':'diameter');
    if($('m_dim_type') && Array.from($('m_dim_type').options).some(o=>o.value===dt)) $('m_dim_type').value=dt;
    updateDimensionFields();
    if($('m_opening')) $('m_opening').value=r.dim_opening||'';
    if($('m_outer')) $('m_outer').value=r.dim_outer_d||'';
    if($('m_length')) $('m_length').value=r.dim_length||'';
    if($('m_width')) $('m_width').value=r.dim_width||'';
    if($('m_height')) $('m_height').value=r.dim_height||'';
    if($('m_remark')) $('m_remark').value=r.remark||'';
    if(msg){ msg.style.display='none'; msg.textContent=''; }
    renderModelAudit(r);
    resetUploadPreview();
    if(r.image_url) updateUploadPreview('m_image',null,r.image_url);
    if(r.drawing_url) updateUploadPreview('m_drawing',null,r.drawing_url); lockModelFormUntilRule();
  }catch(e){
    if(msg){ msg.textContent='读取失败：'+(e.message||e); msg.style.display='block'; }
    else alert(e.message||e);
  }
}

async function copyModel(id){
  if(!NM_PERMS.create_model){alert('当前账号没有复制新增权限');return;}
  const msg=$('modelMsg');
  try{
    $('modelTitle').textContent='复制新增型号';
    $('modelForm').reset();
    if(msg){ msg.textContent='正在复制原型号资料……'; msg.style.display='block'; }
    if($('modelAudit')){ $('modelAudit').style.display='none'; $('modelAudit').innerHTML=''; }
    resetUploadPreview();
    openModal('modelModal');
    setTimeout(initPasteUpload,30);
    const r=await api('get_model',{qs:'id='+encodeURIComponent(id)});
    $('modelTitle').textContent='复制新增型号：'+(r.model_no||'');
    $('m_id').value='';
    if($('m_clone_source_id')) $('m_clone_source_id').value=r.id||id;
    if($('m_inbox_id')) $('m_inbox_id').value='';
    if($('m_rule')){
      $('m_rule').value=r.rule_id||'';
      if(!$('m_rule').value && r.prefix){
        const opt=Array.from($('m_rule').options).find(o=>String(o.dataset.prefix||'')===String(r.prefix||''));
        if(opt) $('m_rule').value=opt.value;
      }
    }
    updateDimensionFields();
    $('m_size').value=r.size_code||'';
    $('m_model').value='';
    $('m_product').value=r.product_name||r.series_name||r.web_series||'';
    $('m_customer').value=r.customer||'';
    $('m_status').value='草稿';
    const dt=r.dimension_type||($('openingField')&&!$('openingField').classList.contains('field-hidden')?'embedded_round':'diameter');
    if($('m_dim_type') && Array.from($('m_dim_type').options).some(o=>o.value===dt)) $('m_dim_type').value=dt;
    updateDimensionFields();
    if($('m_opening')) $('m_opening').value=r.dim_opening||'';
    if($('m_outer')) $('m_outer').value=r.dim_outer_d||'';
    if($('m_length')) $('m_length').value=r.dim_length||'';
    if($('m_width')) $('m_width').value=r.dim_width||'';
    if($('m_height')) $('m_height').value=r.dim_height||'';
    if($('m_remark')) $('m_remark').value=(r.remark||'') + ((r.remark||'')?'\n':'') + '复制来源型号：' + (r.model_no||'');
    resetUploadPreview();
    if(r.image_url) updateUploadPreview('m_image',null,r.image_url);
    if(r.drawing_url) updateUploadPreview('m_drawing',null,r.drawing_url);
    lockModelFormUntilRule();
    try{
      const rid=$('m_rule')&&$('m_rule').value;
      const size=$('m_size')&&$('m_size').value;
      if(rid && size){
        const nxt=await api('next_model',{qs:'rule_id='+encodeURIComponent(rid)+'&size='+encodeURIComponent(size)});
        if(nxt && nxt.model_no){ $('m_model').value=nxt.model_no; $('m_size').value=nxt.size_code||size; }
      }
    }catch(e2){}
    if($('autoModelTip')) $('autoModelTip').innerHTML='<span class="auto-model-chip">复制新增：已带入原资料，请编辑后保存为新型号</span>';
    if(msg){ msg.textContent='已复制原型号资料。请检查型号、尺寸、图片，编辑后点击保存型号。'; msg.style.display='block'; }
  }catch(e){
    if(msg){ msg.textContent='复制失败：'+(e.message||e); msg.style.display='block'; }
    else alert(e.message||e);
  }
}
$('modelForm').addEventListener('submit', async (ev)=>{ ev.preventDefault(); const box=$('modelMsg'); const doDispatch=!!NM_SAVE_TO_DISPATCH; const dispatchPayload=NM_DISPATCH_PENDING_DATA||{}; NM_SAVE_TO_DISPATCH=false; NM_DISPATCH_PENDING_DATA=null; try{ if(box){box.textContent=doDispatch?'正在保存型号并创建派工待办……':'正在保存……';box.style.display='block';} const fd=new FormData(ev.target); const res=await fetch('naming.php?action=save_model',{method:'POST',body:fd}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'保存失败'); if(doDispatch){ const msg=$('dispatchMsg')||box; const task=await createDispatchTaskForModel(j.data&&j.data.id,j.data&&j.data.model_no,dispatchPayload,msg); if(box){box.textContent='型号已保存，并已加入派工待办：#'+(task.task_id||'');box.style.display='block';} } else { if(box){box.textContent=j.msg;box.style.display='block';} setTimeout(()=>{ location.href='naming.php'; },600); } }catch(e){ if(box){box.textContent='保存失败：'+(e.message||e);box.style.display='block';} const dm=$('dispatchMsg'); if(dm&&doDispatch){dm.textContent='创建派工失败：'+(e.message||e);dm.classList.add('err');dm.style.display='block';} else if(!box) alert(e.message||e); } });
async function openRuleModal(){ if(!NM_PERMS.manage_rules){alert('当前账号没有规则管理权限');return;} openModal('ruleModal'); await loadRules(); }
async function loadRules(){ if(!NM_PERMS.manage_rules){return;} try{ const data=await api('rules'); const tb=$('ruleTable').querySelector('tbody'); tb.innerHTML=''; (data.rules||[]).forEach(r=>{ const tr=document.createElement('tr'); tr.innerHTML=`<td>${esc(r.category)}</td><td>${esc(r.item_name)}</td><td><b>${esc(r.prefix)}</b></td><td>${esc(r.default_size)}</td><td>${esc(r.seq_digits||2)}</td><td>${r.enabled==1?'启用':'停用'}</td><td><button type="button">编辑</button> <button type="button" class="danger">删除</button></td>`; tr.querySelector('button').onclick=()=>fillRule(r); tr.querySelector('.danger').onclick=()=>deleteRule(r.id); tb.appendChild(tr); }); }catch(e){alert(e.message)} }
function esc(s){ return String(s??'').replace(/[&<>'"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
function fillRule(r){ $('r_id').value=r.id||''; $('r_category').value=r.category||''; $('r_item').value=r.item_name||''; $('r_prefix').value=r.prefix||''; $('r_size').value=r.default_size||'075'; $('r_label').value=r.size_label||'开孔/孔宽'; $('r_digits').value=r.seq_digits||2; $('r_sort').value=r.sort_order||0; $('r_no4').checked=String(r.no_four)!=='0'; $('r_enabled').checked=String(r.enabled)!=='0'; }
function resetRuleForm(){ $('ruleForm').reset(); $('r_id').value=''; $('r_no4').checked=true; $('r_enabled').checked=true; }
$('ruleForm').addEventListener('submit', async (ev)=>{ ev.preventDefault(); try{ const body={id:$('r_id').value,category:$('r_category').value,item_name:$('r_item').value,prefix:$('r_prefix').value,default_size:$('r_size').value,size_label:$('r_label').value,seq_digits:$('r_digits').value,sort_order:$('r_sort').value,no_four:$('r_no4').checked?1:0,enabled:$('r_enabled').checked?1:0}; const res=await fetch('naming.php?action=save_rule',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'保存失败'); $('ruleMsg').textContent=j.msg; $('ruleMsg').style.display='block'; resetRuleForm(); await loadRules(); }catch(e){alert(e.message)} });
async function deleteRule(id){ if(!confirm('确定删除这个规则？已经生成过型号的规则不能删除。'))return; try{ const res=await fetch('naming.php?action=delete_rule',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})}); const j=await parseJsonResponse(res); if(!j.ok) throw new Error(j.msg||'删除失败'); await loadRules(); }catch(e){alert(e.message)} }
if($('m_rule')) $('m_rule').addEventListener('change', onRuleChanged);
if($('m_dim_type')) $('m_dim_type').addEventListener('change', ()=>{updateDimensionFields(); autoGenerateModelFromDimensions(true);});
['m_opening','m_outer','m_length','m_width','m_size'].forEach(id=>{ const el=$(id); if(el){ el.addEventListener('input',()=>autoGenerateModelFromDimensions(false)); el.addEventListener('blur',()=>autoGenerateModelFromDimensions(true)); }});
updateDimensionFields(); lockModelFormUntilRule();
applyModalSettings();
applyStaticWatermarks();
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ ['modelModal','filterModal','ruleModal','mediaModal','logModal','backupModal','settingModal'].forEach(id=>closeModal(id)); } });


// V3.0.8.29：手机端顶部功能折叠/展开。默认折叠，不占半屏。
function toggleMobileTop(force){
  try{
    var body=document.body;
    var shouldOpen = (typeof force==='boolean') ? force : !body.classList.contains('nm-mobile-menu-open');
    body.classList.toggle('nm-mobile-menu-open', shouldOpen);
    var btn=document.getElementById('mobileTopToggle');
    if(btn) btn.textContent = shouldOpen ? '收起功能' : '展开功能';
  }catch(e){}
}
(function(){
  function syncMobileFold(){
    var isMobile = window.matchMedia && window.matchMedia('(max-width:760px)').matches;
    var btn=document.getElementById('mobileTopToggle');
    if(!isMobile){
      document.body.classList.remove('nm-mobile-menu-open');
      if(btn) btn.textContent='展开功能';
    }else if(btn){
      btn.textContent = document.body.classList.contains('nm-mobile-menu-open') ? '收起功能' : '展开功能';
    }
  }
  window.addEventListener('resize', syncMobileFold, {passive:true});
  document.addEventListener('DOMContentLoaded', syncMobileFold);
  syncMobileFold();
})();
</script>
</body></html>
