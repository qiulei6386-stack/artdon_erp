<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
if (isset($_GET['action']) || isset($_POST['action'])) artdon_sso_require_api('bom');
else artdon_sso_require_page('bom');
if (!artdon_sso_can('bom', 'admin') && !artdon_sso_can('bom', 'export')) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        artdon_sso_json_error('当前账号没有 BOM 备份/恢复权限', 403, ['error_code' => 'PERMISSION_DENIED']);
    }
    artdon_sso_forbidden_page('bom');
}
/* ARTDON_SSO_GATE_V2_END */

/**
 * Artdon BOM Backup / Restore Tool V1.0
 * - Backup BOM projects, material library, rules/base lists and uploaded files.
 * - Restore by merge or overwrite.
 * Upload this file to the same folder as bom.php / config.php.
 */
ini_set('display_errors','0');
error_reporting(E_ALL);

$ROOT = __DIR__;
$BACKUP_DIR = $ROOT.'/bom_backups';
if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR, 0775, true);

require_once __DIR__ . '/includes/bootstrap.php';

function bombr_json($arr){
    while(ob_get_level()>0){ @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function bombr_pdo(){
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
function bombr_qid($x){ return '`'.str_replace('`','``',$x).'`'; }
function bombr_table_exists($pdo,$t){ $st=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'); $st->execute(array($t)); return (bool)$st->fetchColumn(); }
function bombr_cols($pdo,$t){ if(!bombr_table_exists($pdo,$t)) return array(); $st=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'); $st->execute(array($t)); return $st->fetchAll(PDO::FETCH_COLUMN); }
function bombr_show_create($pdo,$t){ try{ $r=$pdo->query('SHOW CREATE TABLE '.bombr_qid($t))->fetch(PDO::FETCH_ASSOC); return $r['Create Table'] ?? ''; }catch(Throwable $e){ return ''; } }
function bombr_current_user(){
    foreach(array('office_username','bom_username','plm_username','username','account') as $k){ if(!empty($_SESSION[$k])) return (string)$_SESSION[$k]; }
    foreach(array('office_user_id','bom_user_id','plm_user_id','user_id') as $k){ if(!empty($_SESSION[$k])) return 'user#'.(int)$_SESSION[$k]; }
    return 'unknown';
}
function bombr_safe_name($s){ return preg_replace('/[^A-Za-z0-9_\-.]/','_',basename((string)$s)); }
function bombr_rel($path, $root){
    $real = realpath($path);
    $rroot = realpath($root);
    if(!$real || !$rroot || strpos($real,$rroot)!==0) return '';
    return ltrim(str_replace('\\','/',substr($real, strlen($rroot))),'/');
}
function bombr_add_dir($zip,$dir,$zipPrefix,$root){
    if(!is_dir($dir)) return 0;
    $count=0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()) continue;
        $path=$f->getPathname();
        $rel=bombr_rel($path,$root);
        if($rel==='') continue;
        // 不把备份目录自身继续打包进去，避免套娃。
        if(strpos($rel,'bom_backups/')===0) continue;
        $zip->addFile($path, rtrim($zipPrefix,'/').'/'.$rel);
        $count++;
    }
    return $count;
}
function bombr_collect_db_file_paths($pdo,$tables,$root){
    $paths=array();
    foreach($tables as $t){
        if(!bombr_table_exists($pdo,$t)) continue;
        $cols=bombr_cols($pdo,$t);
        $imageCols=array_values(array_intersect($cols,array('image','product_image','file_path','path','url','thumb','cover','drawing_path','image_path')));
        if(!$imageCols && !in_array('rows_json',$cols,true)) continue;
        $select=$imageCols;
        if(in_array('rows_json',$cols,true)) $select[]='rows_json';
        if(!$select) continue;
        try{$rows=$pdo->query('SELECT '.implode(',',array_map('bombr_qid',$select)).' FROM '.bombr_qid($t).' LIMIT 50000')->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){continue;}
        foreach($rows as $r){
            foreach($r as $v){
                if($v===null || $v==='') continue;
                $s=(string)$v;
                // JSON 中也提取 uploads/... 路径
                if(strlen($s)>2 && ($s[0]=='[' || $s[0]=='{')){
                    if(preg_match_all('~(?:/)?uploads/[A-Za-z0-9_./%\-\x{4e00}-\x{9fa5}]+~u',$s,$m)){
                        foreach($m[0] as $mm) $paths[]=ltrim($mm,'/');
                    }
                }else{
                    if(strpos($s,'uploads/')!==false){
                        $paths[]=ltrim(parse_url($s, PHP_URL_PATH) ?: $s,'/');
                    }
                }
            }
        }
    }
    $out=array();
    foreach(array_unique($paths) as $rel){
        $rel=str_replace('..','',$rel);
        $abs=$root.'/'.$rel;
        if(is_file($abs)) $out[$rel]=$abs;
    }
    return $out;
}
function bombr_bom_tables($pdo,$includeUsers=false){
    $core=array('bom_projects','bom_materials','bom_base_lists','bom_kv','bom_settings','bom_user_settings','bom_column_settings','bom_view_settings');
    $tables=array();
    try{
        $rs=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'bom\\_%' ESCAPE '\\' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    }catch(Throwable $e){ $rs=array(); }
    foreach($core as $t){ if(bombr_table_exists($pdo,$t)) $tables[]=$t; }
    foreach($rs as $t){ if(!in_array($t,$tables,true)) $tables[]=$t; }
    if(!$includeUsers){
        $tables=array_values(array_filter($tables,function($t){ return !in_array($t,array('bom_users'),true) && stripos($t,'user')===false; }));
    }
    return $tables;
}
function bombr_status(){
    global $BACKUP_DIR,$ROOT;
    $pdo=bombr_pdo();
    $tables=bombr_bom_tables($pdo,false);
    $counts=array();
    foreach($tables as $t){ try{$counts[$t]=(int)$pdo->query('SELECT COUNT(*) FROM '.bombr_qid($t))->fetchColumn();}catch(Throwable $e){$counts[$t]='ERR';} }
    $files=glob($BACKUP_DIR.'/*.zip') ?: array();
    usort($files,function($a,$b){ return filemtime($b)-filemtime($a); });
    $list=array();
    foreach(array_slice($files,0,50) as $f){ $list[]=array('name'=>basename($f),'size'=>filesize($f),'time'=>date('Y-m-d H:i:s',filemtime($f))); }
    bombr_json(array('ok'=>true,'tables'=>$counts,'backup_dir'=>$BACKUP_DIR,'dir_exists'=>is_dir($BACKUP_DIR),'dir_writable'=>is_writable($BACKUP_DIR),'zip_available'=>class_exists('ZipArchive'),'backups'=>$list,'root'=>$ROOT));
}
function bombr_create_backup(){
    global $BACKUP_DIR,$ROOT;
    if(!class_exists('ZipArchive')) bombr_json(array('ok'=>false,'msg'=>'服务器未启用 PHP ZipArchive，无法生成 ZIP 备份。'));
    if(!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR,0775,true);
    if(!is_writable($BACKUP_DIR)) bombr_json(array('ok'=>false,'msg'=>'备份目录不可写：'.$BACKUP_DIR));
    $pdo=bombr_pdo();
    $includeUsers = isset($_POST['include_users']) && $_POST['include_users']=='1';
    $includeFiles = false;
    $tables=bombr_bom_tables($pdo,$includeUsers);
    $payload=array('meta'=>array('system'=>'Artdon BOM','backup_version'=>'1.0','created_at'=>date('Y-m-d H:i:s'),'created_by'=>bombr_current_user(),'include_users'=>$includeUsers?1:0,'include_files'=>0,'scope'=>'db_only_no_uploads'),'schemas'=>array(),'tables'=>array());
    foreach($tables as $t){
        try{
            $payload['schemas'][$t]=bombr_show_create($pdo,$t);
            $payload['tables'][$t]=$pdo->query('SELECT * FROM '.bombr_qid($t))->fetchAll(PDO::FETCH_ASSOC);
        }catch(Throwable $e){
            $payload['tables'][$t]=array();
            $payload['meta']['table_errors'][$t]=$e->getMessage();
        }
    }
    $name='bom_backup_'.date('Ymd_His').'.zip';
    $zipPath=$BACKUP_DIR.'/'.$name;
    $tmpJson=$BACKUP_DIR.'/bom_backup_'.uniqid().'.json';
    file_put_contents($tmpJson,json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    $zip=new ZipArchive();
    if($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){ @unlink($tmpJson); bombr_json(array('ok'=>false,'msg'=>'无法创建备份文件：'.$zipPath)); }
    $zip->addFile($tmpJson,'bom_backup.json');
    $fileCount=0;
    $includeFiles = false;
    $zip->addFromString('README.txt',"Artdon BOM backup\nCreated at: ".$payload['meta']['created_at']."\nTables: ".implode(', ',$tables)."\nFiles: ".$fileCount."\n");
    $zip->close();
    @unlink($tmpJson);
    bombr_json(array('ok'=>true,'msg'=>'备份完成','file'=>$name,'size'=>filesize($zipPath),'tables'=>array_map('count',$payload['tables']),'files'=>$fileCount));
}
function bombr_restore_table($pdo,$t,$rows,$mode,$schema=''){
    if(!bombr_table_exists($pdo,$t)){
        if($schema){ try{ $pdo->exec($schema); }catch(Throwable $e){} }
    }
    if(!bombr_table_exists($pdo,$t)) return array('ok'=>false,'restored'=>0,'msg'=>'表不存在，且无法自动创建：'.$t);
    $cols=bombr_cols($pdo,$t);
    if(!$cols) return array('ok'=>false,'restored'=>0,'msg'=>'表字段为空：'.$t);
    $restored=0; $skipped=0;
    if($mode==='overwrite'){
        try{ $pdo->exec('TRUNCATE TABLE '.bombr_qid($t)); }catch(Throwable $e){ $pdo->exec('DELETE FROM '.bombr_qid($t)); }
    }
    foreach($rows as $r){
        if(!is_array($r)){ $skipped++; continue; }
        $use=array();
        foreach($r as $k=>$v){ if(in_array($k,$cols,true)) $use[$k]=$v; }
        if(!$use){ $skipped++; continue; }
        $names=array_keys($use);
        $sql='REPLACE INTO '.bombr_qid($t).' ('.implode(',',array_map('bombr_qid',$names)).') VALUES ('.implode(',',array_fill(0,count($names),'?')).')';
        try{ $st=$pdo->prepare($sql); $st->execute(array_values($use)); $restored++; }catch(Throwable $e){ $skipped++; }
    }
    return array('ok'=>true,'restored'=>$restored,'skipped'=>$skipped);
}
function bombr_copy_restore_files($from,$root,$overwrite=true){
    $filesDir=$from.'/files';
    if(!is_dir($filesDir)) return 0;
    $count=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()) continue;
        $rel=str_replace('\\','/',substr($f->getPathname(), strlen($filesDir)+1));
        $rel=str_replace('..','',$rel);
        $target=$root.'/'.$rel;
        if(is_file($target) && !$overwrite) continue;
        if(!is_dir(dirname($target))) @mkdir(dirname($target),0775,true);
        if(@copy($f->getPathname(),$target)) $count++;
    }
    return $count;
}
function bombr_find_backup_json($dir){
    if(!is_dir($dir)) {
        return array('json_files'=>array(), 'error'=>'临时解压目录不存在：'.$dir);
    }
    $candidates = array($dir.'/bom_backup.json', $dir.'/backup.json', $dir.'/data.json');
    foreach($candidates as $file){ if(is_file($file)) return $file; }
    $found = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()) continue;
        $name = strtolower($f->getFilename());
        if(substr($name, -5) !== '.json') continue;
        $path = $f->getPathname();
        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if(is_array($data) && isset($data['tables']) && is_array($data['tables'])) return $path;
        $found[] = str_replace('\\','/',substr($path, strlen($dir)+1));
    }
    return array('json_files'=>$found);
}
function bombr_zip_entries($zipPath, $limit=30){
    $list = array();
    $zip = new ZipArchive();
    if($zip->open($zipPath) !== true) return $list;
    for($i=0; $i<min($zip->numFiles, $limit); $i++){
        $list[] = $zip->getNameIndex($i);
    }
    $zip->close();
    return $list;
}
function bombr_restore(){
    global $ROOT,$BACKUP_DIR;
    if(!class_exists('ZipArchive')) bombr_json(array('ok'=>false,'msg'=>'服务器未启用 PHP ZipArchive，无法解压 ZIP。'));
    if(empty($_FILES['backup_file']['tmp_name']) || !is_uploaded_file($_FILES['backup_file']['tmp_name'])) bombr_json(array('ok'=>false,'msg'=>'请选择要恢复的 ZIP 备份文件。'));
    $mode = 'merge';
    $restoreUsers = isset($_POST['restore_users']) && $_POST['restore_users']=='1';
    $restoreFiles = false;
    $overwriteFiles = false;
    if(!is_dir($BACKUP_DIR) && !@mkdir($BACKUP_DIR,0775,true)){
        bombr_json(array('ok'=>false,'msg'=>'备份目录不存在且创建失败：'.$BACKUP_DIR));
    }
    if(!is_writable($BACKUP_DIR)){
        bombr_json(array('ok'=>false,'msg'=>'备份目录不可写：'.$BACKUP_DIR));
    }
    $tmp=$BACKUP_DIR.'/restore_'.date('YmdHis').'_'.substr(md5(uniqid('',true)),0,6);
    if(!@mkdir($tmp,0775,true) && !is_dir($tmp)){
        bombr_json(array('ok'=>false,'msg'=>'恢复临时目录创建失败：'.$tmp));
    }
    $zip=new ZipArchive();
    $uploadPath = $_FILES['backup_file']['tmp_name'];
    if($zip->open($uploadPath)!==true) bombr_json(array('ok'=>false,'msg'=>'ZIP 文件无法打开。'));
    $extractOk = $zip->extractTo($tmp);
    $zip->close();
    if(!$extractOk || !is_dir($tmp)){
        bombr_json(array('ok'=>false,'msg'=>'ZIP 解压失败，请检查备份目录权限或 ZIP 文件是否损坏。','tmp'=>$tmp,'backup_dir'=>$BACKUP_DIR));
    }
    $jsonFile=bombr_find_backup_json($tmp);
    if(is_array($jsonFile)){
        bombr_json(array(
            'ok'=>false,
            'msg'=>isset($jsonFile['error']) ? $jsonFile['error'] : '备份包中没有可识别的 BOM 数据 JSON，不能恢复。',
            'json_files'=>$jsonFile['json_files'],
            'zip_entries'=>bombr_zip_entries($uploadPath)
        ));
    }
    $data=json_decode(file_get_contents($jsonFile),true);
    if(!is_array($data) || empty($data['tables']) || !is_array($data['tables'])) bombr_json(array('ok'=>false,'msg'=>'备份文件格式不正确。'));
    $pdo=bombr_pdo();
    $result=array();
    $tx=false;
    try{
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        if(!$pdo->inTransaction()){ $pdo->beginTransaction(); $tx=true; }
        foreach($data['tables'] as $t=>$rows){
            if(!$restoreUsers && (stripos($t,'user')!==false || $t==='bom_users')){ $result[$t]=array('ok'=>true,'restored'=>0,'skipped'=>0,'msg'=>'已跳过用户/账号表'); continue; }
            if(strpos($t,'bom_')!==0){ $result[$t]=array('ok'=>false,'restored'=>0,'msg'=>'非 BOM 表，已跳过'); continue; }
            $schema=$data['schemas'][$t] ?? '';
            $result[$t]=bombr_restore_table($pdo,$t,is_array($rows)?$rows:array(),$mode,$schema);
        }
        if($tx && $pdo->inTransaction()) $pdo->commit();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }catch(Throwable $e){
        if($tx && $pdo->inTransaction()) $pdo->rollBack();
        try{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable $ee){}
        bombr_json(array('ok'=>false,'msg'=>'数据库恢复失败：'.$e->getMessage(),'result'=>$result));
    }
    $fileCount=0;
    $restoreFiles = false;
    bombr_json(array('ok'=>true,'msg'=>'恢复完成','mode'=>$mode,'tables'=>$result,'files'=>$fileCount));
}
function bombr_delete_backup(){
    global $BACKUP_DIR;
    $f=bombr_safe_name($_POST['file'] ?? '');
    if($f==='' || substr($f,-4)!=='.zip') bombr_json(array('ok'=>false,'msg'=>'文件名不正确'));
    $path=$BACKUP_DIR.'/'.$f;
    if(is_file($path)) @unlink($path);
    bombr_json(array('ok'=>true,'msg'=>'已删除'));
}
function bombr_download(){
    global $BACKUP_DIR;
    $f=bombr_safe_name($_GET['download'] ?? '');
    $path=$BACKUP_DIR.'/'.$f;
    if($f==='' || !is_file($path)){ http_response_code(404); echo 'backup not found'; exit; }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$f.'"');
    header('Content-Length: '.filesize($path));
    readfile($path); exit;
}
function bombr_cleanup(){
    global $BACKUP_DIR;
    $days=max(1,(int)($_POST['days'] ?? 30));
    $cut=time()-$days*86400;
    $n=0; foreach(glob($BACKUP_DIR.'/*.zip') ?: array() as $f){ if(filemtime($f)<$cut){@unlink($f);$n++;} }
    bombr_json(array('ok'=>true,'msg'=>'已清理 '.$n.' 个旧备份'));
}

if(isset($_GET['download'])) bombr_download();
$action=$_GET['action'] ?? ($_POST['action'] ?? '');
try{
    if($action==='status') bombr_status();
    if($action==='create_backup') bombr_create_backup();
    if($action==='restore') bombr_restore();
    if($action==='delete_backup') bombr_delete_backup();
    if($action==='cleanup') bombr_cleanup();
}catch(Throwable $e){ bombr_json(array('ok'=>false,'msg'=>$e->getMessage())); }
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Artdon BOM 备份 / 恢复</title>
<style>
:root{--main:#2563eb;--ok:#16a34a;--danger:#dc2626;--line:#e5e7eb;--bg:#f3f6fb;--text:#0f172a;--muted:#64748b}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,"Microsoft YaHei",sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:22px}header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}.title{font-size:28px;font-weight:900}.sub{color:var(--muted);margin-top:5px;line-height:1.6}.card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 4px 20px rgba(15,23,42,.05);margin-bottom:14px;overflow:hidden}.card h2{font-size:18px;margin:0;padding:16px 18px;border-bottom:1px solid var(--line)}.body{padding:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}.hint{color:var(--muted);line-height:1.7}.warn{border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:14px;padding:12px 14px;line-height:1.7}.okbox{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:14px;padding:12px 14px;line-height:1.7}button,.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;background:var(--main);color:#fff;padding:10px 14px;font-weight:800;text-decoration:none;cursor:pointer}button.ghost,.btn.ghost{background:#fff;color:#0f172a;border:1px solid var(--line)}button.ok{background:var(--ok)}button.danger{background:var(--danger)}input,select{border:1px solid #cbd5e1;border-radius:12px;padding:10px 12px;background:#fff;min-height:40px}label{font-weight:800;color:#334155}.pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:999px;padding:5px 9px;background:#f8fafc;font-size:12px;color:#475569;margin:2px}table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:#fff}th,td{padding:11px 12px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}th{background:#f8fafc;color:#475569}tr:last-child td{border-bottom:0}.log{white-space:pre-wrap;background:#0f172a;color:#e5e7eb;border-radius:14px;padding:14px;min-height:90px;overflow:auto;font-family:ui-monospace,Menlo,Consolas,monospace}.checks{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:10px}.check{border:1px solid var(--line);border-radius:14px;background:#f8fafc;padding:12px}.check b{display:block;margin-bottom:5px}@media(max-width:760px){.grid{grid-template-columns:1fr}.wrap{padding:12px}header{display:block}}
</style></head><body><div class="wrap"><header><div><div class="title">BOM 备份 / 恢复</div><div class="sub">备份 BOM 成本单、共享物料库、规则/数据字典。当前版本固定为数据库合并备份/恢复，不处理 uploads。</div></div><div class="row"><a class="btn ghost" href="bom.php">返回 BOM</a><button class="ghost" onclick="loadStatus()">刷新状态</button></div></header>
<div class="warn"><b>说明：</b>本工具只处理 BOM 数据表和规则表，不备份/恢复 <code>uploads</code> 文件，不执行清空表覆盖恢复。恢复模式固定为合并恢复。</div>
<div class="grid">
  <section class="card"><h2>一键备份</h2><div class="body">
    <div class="checks"><label class="check"><input id="includeUsers" type="checkbox"> <b>包含旧 BOM 用户表</b><span class="hint">通常不需要；统一账号权限以当前系统为准</span></label><div class="check"><b>文件范围</b><span class="hint">本次接入不处理 uploads，只备份 BOM 数据表。</span></div></div>
    <div class="row"><button class="ok" onclick="createBackup()">创建 BOM 备份 ZIP</button><span class="hint">生成后可直接下载。</span></div>
  </div></section>
  <section class="card"><h2>导入恢复</h2><div class="body">
    <form id="restoreForm" onsubmit="restoreBackup(event)" enctype="multipart/form-data">
      <div class="row"><input type="file" name="backup_file" accept=".zip" required></div>
      <div class="row"><label>恢复模式</label><select name="mode"><option value="merge">合并恢复：不清空，按主键更新/新增</option></select></div>
      <div class="checks"><label class="check"><input name="restore_users" value="1" type="checkbox"> <b>恢复旧 BOM 用户表</b><span class="hint">一般不需要；当前登录权限以统一账号为准</span></label><div class="check"><b>文件恢复</b><span class="hint">禁用：不会复制或覆盖 uploads 文件。</span></div></div>
      <div class="row"><button class="danger" type="submit">开始合并恢复</button><span class="hint">不会清空表，不会恢复上传文件。</span></div>
    </form>
  </div></section>
</div>
<section class="card"><h2>系统状态</h2><div class="body"><div id="statusBox" class="hint">正在读取...</div></div></section>
<section class="card"><h2>已有备份</h2><div class="body"><div id="backupList" class="hint">正在读取...</div><div class="row"><button class="ghost" onclick="cleanupBackups()">清理 30 天前备份</button></div></div></section>
<section class="card"><h2>操作结果</h2><div class="body"><div id="log" class="log">等待操作...</div></div></section>
</div>
<script>
const $=id=>document.getElementById(id);
function log(x){ $('log').textContent = typeof x==='string'?x:JSON.stringify(x,null,2); }
async function api(url,opt){ const r=await fetch(url,opt||{}); const txt=await r.text(); try{return JSON.parse(txt)}catch(e){throw new Error('接口返回不是JSON：'+txt.slice(0,500));} }
function size(n){n=Number(n||0); if(n>1024*1024) return (n/1024/1024).toFixed(2)+' MB'; if(n>1024) return (n/1024).toFixed(1)+' KB'; return n+' B';}
async function loadStatus(){
  try{ const d=await api('bom_backup_restore.php?action=status'); log(d); if(!d.ok) throw new Error(d.msg||'状态读取失败');
    const pills=[]; for(const [k,v] of Object.entries(d.tables||{})) pills.push(`<span class="pill">${k}: <b>${v}</b></span>`);
    $('statusBox').innerHTML = `<div class="row"><span class="pill">备份目录：${d.dir_writable?'可写':'不可写'}</span><span class="pill">ZIP：${d.zip_available?'可用':'不可用'}</span></div><div>${pills.join(' ')||'未找到 BOM 表'}</div><div class="hint" style="margin-top:8px">目录：${d.backup_dir||''}</div>`;
    renderBackups(d.backups||[]);
  }catch(e){ $('statusBox').innerHTML='<span style="color:#dc2626">'+e.message+'</span>'; log(e.message); }
}
function renderBackups(list){ if(!list.length){$('backupList').innerHTML='暂无备份';return;} let html='<table><thead><tr><th>文件</th><th>大小</th><th>时间</th><th>操作</th></tr></thead><tbody>'; list.forEach(b=>{html+=`<tr><td><b>${b.name}</b></td><td>${size(b.size)}</td><td>${b.time}</td><td><a class="btn ghost" href="bom_backup_restore.php?download=${encodeURIComponent(b.name)}">下载</a> <button class="danger" onclick="deleteBackup('${b.name.replaceAll("'","\\'")}')">删除</button></td></tr>`}); html+='</tbody></table>'; $('backupList').innerHTML=html; }
async function createBackup(){
  const fd=new FormData(); fd.append('action','create_backup'); fd.append('include_files','0'); fd.append('include_users',$('includeUsers').checked?'1':'0');
  log('正在创建备份...');
  try{ const d=await api('bom_backup_restore.php',{method:'POST',body:fd}); log(d); if(d.ok){ alert('备份完成'); loadStatus(); } else alert(d.msg||'备份失败'); }catch(e){log(e.message);alert(e.message)}
}
async function restoreBackup(e){
  e.preventDefault();
  const f=$('restoreForm');
  const fd=new FormData(f); fd.append('action','restore');
  log('正在恢复，请不要关闭页面...');
  try{ const d=await api('bom_backup_restore.php',{method:'POST',body:fd}); log(d); if(d.ok){ alert('恢复完成，请回 BOM 刷新数据。'); loadStatus(); } else alert(d.msg||'恢复失败'); }catch(e2){log(e2.message);alert(e2.message)}
}
async function deleteBackup(name){ if(!confirm('删除备份：'+name+'？'))return; const fd=new FormData();fd.append('action','delete_backup');fd.append('file',name); const d=await api('bom_backup_restore.php',{method:'POST',body:fd}); log(d); loadStatus();}
async function cleanupBackups(){ const fd=new FormData();fd.append('action','cleanup');fd.append('days','30'); const d=await api('bom_backup_restore.php',{method:'POST',body:fd}); log(d); loadStatus();}
loadStatus();
</script></body></html>
