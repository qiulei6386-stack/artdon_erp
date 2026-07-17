<?php
/* Artdon PLM / Portal 安全中心 V3.0.2
 * 真名替换文件：plm_admin.php
 * 备份 / 恢复自包含，不再只依赖 plm_api.php。
 */
@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('memory_limit','1024M');
@set_time_limit(900);

require_once __DIR__ . '/plm_auth.php';
plm_auth_require('admin','admin');
$ARTDON_AUTH = function_exists('plm_auth_payload') ? plm_auth_payload() : array();
$u = $ARTDON_AUTH['user'] ?? array();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function adm_now(){ return date('Y-m-d H:i:s'); }
function adm_user_name(){ global $u; return (string)($u['username'] ?? $u['display_name'] ?? 'admin'); }
function adm_json($arr,$code=200){
  while(ob_get_level()>0) @ob_end_clean();
  http_response_code((int)$code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function adm_ok($data=array()){ adm_json(array('ok'=>true,'data'=>$data)); }
function adm_fail($msg,$code=400,$extra=array()){ adm_json(array_merge(array('ok'=>false,'error'=>$msg,'msg'=>$msg),$extra),(int)$code); }
function adm_in(){
  $raw = file_get_contents('php://input');
  $j = $raw ? json_decode($raw,true) : null;
  return array_merge($_GET,$_POST,is_array($j)?$j:array());
}
function adm_safe_name($s){
  $s = basename(str_replace(array("\0",'\\'),array('', '/'),(string)$s));
  return preg_replace('/[^A-Za-z0-9._\-\x{4e00}-\x{9fa5}]/u','_',$s);
}
function adm_bytes($n){
  $n=(float)$n; $u=array('B','KB','MB','GB','TB'); $i=0;
  while($n>=1024 && $i<count($u)-1){$n/=1024;$i++;}
  return ($i===0?number_format($n,0):number_format($n,2)).' '.$u[$i];
}
function adm_backup_base(){
  static $base=null;
  if($base!==null) return $base;
  $candidates=array('/www/backup/artdon/plm_admin', __DIR__.'/backup/plm_admin', __DIR__.'/backups/plm_admin');
  foreach($candidates as $dir){
    if(!is_dir($dir)) @mkdir($dir,0755,true);
    if(is_dir($dir) && is_writable($dir)){ $base=realpath($dir) ?: $dir; return $base; }
  }
  throw new RuntimeException('备份目录不可写，请检查 /www/backup/artdon/ 权限');
}
function adm_db(){
  static $pdo=null;
  if($pdo instanceof PDO) return $pdo;
  if(function_exists('artdon_sso_db')){
    $x=artdon_sso_db();
    if($x instanceof PDO){
      $x->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
      $x->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
      $pdo=$x; return $pdo;
    }
  }
  $cfg=__DIR__.'/config.php'; if(is_file($cfg)) require_once $cfg;
  foreach(array('db','get_pdo','get_db','connect_db','database') as $fn){
    if(function_exists($fn)){
      $x=$fn();
      if($x instanceof PDO){
        $x->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $x->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $pdo=$x; return $pdo;
      }
    }
  }
  $host=defined('DB_HOST')?DB_HOST:(defined('MYSQL_HOST')?MYSQL_HOST:'127.0.0.1');
  $name=defined('DB_NAME')?DB_NAME:(defined('MYSQL_DB')?MYSQL_DB:'');
  $user=defined('DB_USER')?DB_USER:(defined('MYSQL_USER')?MYSQL_USER:'');
  $pass=defined('DB_PASS')?DB_PASS:(defined('MYSQL_PASS')?MYSQL_PASS:'');
  $port=defined('DB_PORT')?DB_PORT:3306;
  if($name==='' || $user==='') throw new RuntimeException('数据库配置不完整，请检查 config.php');
  $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,array(
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND=>'SET NAMES utf8mb4'
  ));
  return $pdo;
}
function adm_db_name(){
  if(defined('DB_NAME')) return (string)DB_NAME;
  if(defined('MYSQL_DB')) return (string)MYSQL_DB;
  try{ return (string)adm_db()->query('SELECT DATABASE()')->fetchColumn(); }catch(Throwable $e){ return ''; }
}
function adm_ident($name){ return '`'.str_replace('`','``',(string)$name).'`'; }
function adm_table_exists($table){
  try{ $st=adm_db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute(array((string)$table)); return (int)$st->fetchColumn() > 0; }catch(Throwable $e){ return false; }
}
function adm_table_count($table){
  try{ if(!adm_table_exists($table)) return 0; return (int)adm_db()->query('SELECT COUNT(*) FROM '.adm_ident($table))->fetchColumn(); }catch(Throwable $e){ return 0; }
}
function adm_all_tables(){
  $rows=adm_db()->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
  $out=array();
  foreach($rows as $r){ if(isset($r[1]) && strtoupper((string)$r[1])!=='BASE TABLE') continue; if(!empty($r[0])) $out[]=(string)$r[0]; }
  sort($out,SORT_NATURAL|SORT_FLAG_CASE);
  return $out;
}
function adm_dump_value($v,$pdo){ return $v===null ? 'NULL' : $pdo->quote((string)$v); }
function adm_write_sql_backup($prefix='manual_db'){
  $pdo=adm_db(); $base=adm_backup_base(); $db=adm_db_name();
  $file=$base.'/'.adm_safe_name($prefix).'_'.date('Ymd_His').'.sql';
  $fh=fopen($file,'wb'); if(!$fh) throw new RuntimeException('无法创建备份文件：'.$file);
  fwrite($fh,"-- Artdon SQL Backup\n-- Database: ".$db."\n-- Created: ".adm_now()."\n-- User: ".adm_user_name()."\n\n");
  fwrite($fh,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
  $tables=adm_all_tables(); $totalRows=0;
  foreach($tables as $table){
    fwrite($fh,"\n-- ----------------------------\n-- Table structure for ".$table."\n-- ----------------------------\n");
    fwrite($fh,'DROP TABLE IF EXISTS '.adm_ident($table).";\n");
    $row=$pdo->query('SHOW CREATE TABLE '.adm_ident($table))->fetch(PDO::FETCH_ASSOC);
    $create=$row['Create Table'] ?? array_values($row)[1] ?? '';
    fwrite($fh,$create.";\n\n");
    $stmt=$pdo->query('SELECT * FROM '.adm_ident($table));
    $cols=null; $batch=array(); $batchSize=80; $rows=0;
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
      if($cols===null) $cols=array_keys($r);
      $vals=array(); foreach($cols as $c){ $vals[]=adm_dump_value($r[$c]??null,$pdo); }
      $batch[]='('.implode(',',$vals).')'; $rows++; $totalRows++;
      if(count($batch)>=$batchSize){
        fwrite($fh,'INSERT INTO '.adm_ident($table).' ('.implode(',',array_map('adm_ident',$cols)).') VALUES ' . "\n" . implode(",\n",$batch).";\n");
        $batch=array();
      }
    }
    if($cols!==null && $batch){ fwrite($fh,'INSERT INTO '.adm_ident($table).' ('.implode(',',array_map('adm_ident',$cols)).') VALUES ' . "\n" . implode(",\n",$batch).";\n"); }
    fwrite($fh,"\n-- Rows: ".$rows."\n");
  }
  fwrite($fh,"\nSET FOREIGN_KEY_CHECKS=1;\n");
  fclose($fh);
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'tables'=>count($tables),'rows'=>$totalRows,'type'=>'sql','created_at'=>adm_now());
}
function adm_should_skip_file($real,$root,$backupBase){
  $real=str_replace('\\','/',$real); $root=str_replace('\\','/',$root); $backupBase=str_replace('\\','/',$backupBase);
  if(strpos($real,$backupBase)===0) return true;
  $rel=ltrim(substr($real,strlen($root)),'/');
  if($rel==='') return true;
  if(preg_match('#(^|/)(backup|backups|runtime/cache|cache|tmp|temp|node_modules|\.git)(/|$)#i',$rel)) return true;
  if(preg_match('/\.(zip|sql|bak|tar|gz|7z|rar)$/i',$rel)) return true;
  if(is_file($real) && filesize($real)>80*1024*1024) return true;
  return false;
}
function adm_add_files_to_zip($zip,$root,$folderInZip='site'){
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  $root=realpath($root); if(!$root) throw new RuntimeException('网站目录不存在');
  $backupBase=realpath(adm_backup_base()) ?: adm_backup_base();
  $count=0; $skipped=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach($it as $file){
    $real=$file->getPathname();
    if($file->isDir()) continue;
    if($file->isLink() || adm_should_skip_file($real,$root,$backupBase)){ $skipped++; continue; }
    $local=$folderInZip.'/'.str_replace('\\','/',substr($real,strlen($root)+1));
    if($zip->addFile($real,$local)) $count++; else $skipped++;
  }
  return array($count,$skipped);
}
function adm_write_files_backup($prefix='manual_files'){
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  $base=adm_backup_base(); $file=$base.'/'.adm_safe_name($prefix).'_'.date('Ymd_His').'.zip';
  $zip=new ZipArchive(); if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('无法创建 ZIP：'.$file);
  $zip->addFromString('README.txt',"Artdon file backup\nCreated: ".adm_now()."\nRoot: ".__DIR__."\n");
  list($count,$skipped)=adm_add_files_to_zip($zip,__DIR__,'site');
  $zip->close();
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'files'=>$count,'skipped'=>$skipped,'type'=>'zip','created_at'=>adm_now());
}
function adm_write_full_backup(){
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  $db=adm_write_sql_backup('inside_full_db');
  $base=adm_backup_base(); $file=$base.'/full_'.date('Ymd_His').'.zip';
  $zip=new ZipArchive(); if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('无法创建完整备份 ZIP');
  $zip->addFromString('README.txt',"Artdon full backup\nCreated: ".adm_now()."\nIncludes: database.sql + site files\n");
  $zip->addFile($db['path'],'database/database.sql');
  list($count,$skipped)=adm_add_files_to_zip($zip,__DIR__,'site');
  $zip->close();
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'db_file'=>$db['file'],'files'=>$count,'skipped'=>$skipped,'type'=>'full','created_at'=>adm_now());
}
function adm_list_backups(){
  $base=adm_backup_base(); $rows=array();
  foreach(glob($base.'/*') ?: array() as $p){
    if(!is_file($p)) continue;
    $ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));
    if(!in_array($ext,array('sql','zip','json'),true)) continue;
    $name=basename($p);
    $rows[]=array('file'=>$name,'type'=>$ext,'size'=>filesize($p),'size_label'=>adm_bytes(filesize($p)),'mtime'=>date('Y-m-d H:i:s',filemtime($p)),'download'=>'plm_admin.php?action=download_backup&file='.rawurlencode($name));
  }
  usort($rows,function($a,$b){return strcmp($b['mtime'],$a['mtime']);});
  return $rows;
}
function adm_backup_file_path($name){
  $base=realpath(adm_backup_base()); $name=adm_safe_name($name); $p=realpath($base.'/'.$name);
  if(!$p || strpos($p,$base)!==0 || !is_file($p)) throw new RuntimeException('备份文件不存在');
  return $p;
}
function adm_download_backup($name){
  $p=adm_backup_file_path($name); $ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));
  while(ob_get_level()>0) @ob_end_clean();
  header('Content-Type: '.($ext==='zip'?'application/zip':($ext==='sql'?'application/sql':'application/octet-stream')));
  header('Content-Disposition: attachment; filename="'.basename($p).'"; filename*=UTF-8\'\''.rawurlencode(basename($p)));
  header('Content-Length: '.filesize($p));
  readfile($p); exit;
}
function adm_split_sql($sql){
  $len=strlen($sql); $out=array(); $buf=''; $in=false; $quote=''; $escape=false;
  for($i=0;$i<$len;$i++){
    $ch=$sql[$i]; $buf.=$ch;
    if($in){
      if($escape){ $escape=false; continue; }
      if($ch==='\\'){ $escape=true; continue; }
      if($ch===$quote){ $in=false; $quote=''; }
      continue;
    }
    if($ch==="'" || $ch==='"' || $ch==='`'){ $in=true; $quote=$ch; continue; }
    if($ch===';'){ $stmt=trim($buf); if($stmt!=='' && $stmt!==';') $out[]=$stmt; $buf=''; }
  }
  $stmt=trim($buf); if($stmt!=='') $out[]=$stmt;
  return $out;
}
function adm_restore_sql_from_upload(){
  $confirm=trim((string)($_POST['confirm']??'')); if($confirm!=='RESTORE') throw new RuntimeException('确认词错误，请输入 RESTORE');
  if(empty($_FILES['sql_file']) || $_FILES['sql_file']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('请选择 SQL 文件');
  $orig=$_FILES['sql_file']['name']??'restore.sql';
  if(!preg_match('/\.sql$/i',$orig)) throw new RuntimeException('只允许上传 .sql 文件');
  $before=adm_write_sql_backup('before_restore_db');
  $sql=file_get_contents($_FILES['sql_file']['tmp_name']); if($sql===false || trim($sql)==='') throw new RuntimeException('SQL 文件为空或无法读取');
  $pdo=adm_db(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $done=0;
  foreach(adm_split_sql($sql) as $stmt){
    $t=trim($stmt); if($t==='' || preg_match('/^--/',$t)) continue;
    try{ $pdo->exec($t); $done++; }
    catch(Throwable $e){ throw new RuntimeException('SQL 恢复失败：'.$e->getMessage().'；语句片段：'.mb_substr($t,0,180,'UTF-8')); }
  }
  $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  return array('message'=>'数据库恢复完成','statements'=>$done,'before_backup'=>$before['file']);
}
function adm_restore_zip_from_upload(){
  $confirm=trim((string)($_POST['confirm']??'')); if($confirm!=='RESTORE_FILES') throw new RuntimeException('确认词错误，请输入 RESTORE_FILES');
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  if(empty($_FILES['zip_file']) || $_FILES['zip_file']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('请选择 ZIP 文件');
  $orig=$_FILES['zip_file']['name']??'restore.zip'; if(!preg_match('/\.zip$/i',$orig)) throw new RuntimeException('只允许上传 .zip 文件');
  $before=adm_write_files_backup('before_restore_files');
  $root=realpath(__DIR__); $zip=new ZipArchive();
  if($zip->open($_FILES['zip_file']['tmp_name'])!==true) throw new RuntimeException('ZIP 无法打开');
  $done=0; $skipped=0;
  for($i=0;$i<$zip->numFiles;$i++){
    $name=str_replace('\\','/',$zip->getNameIndex($i));
    if($name==='' || substr($name,-1)==='/'){ continue; }
    if(strpos($name,'../')!==false || strpos($name,'..\\')!==false || substr($name,0,1)==='/' || preg_match('/^[A-Za-z]:/',$name)){ $skipped++; continue; }
    if(strpos($name,'site/')===0) $rel=substr($name,5); else $rel=$name;
    if($rel==='' || $rel==='README.txt' || strpos($rel,'database/')===0){ $skipped++; continue; }
    $target=$root.'/'.$rel; $dir=dirname($target); if(!is_dir($dir)) @mkdir($dir,0755,true);
    $data=$zip->getFromIndex($i); if($data===false){ $skipped++; continue; }
    if(file_put_contents($target,$data)===false){ $skipped++; continue; }
    $done++;
  }
  $zip->close();
  return array('message'=>'文件恢复完成','files'=>$done,'skipped'=>$skipped,'before_backup'=>$before['file']);
}

function adm_write_sql_tables_backup($tables,$prefix='selected_db',$title='Artdon Selected SQL Backup'){
  $pdo=adm_db(); $base=adm_backup_base(); $db=adm_db_name();
  $clean=array();
  foreach((array)$tables as $t){ $t=(string)$t; if($t!=='' && preg_match('/^[A-Za-z0-9_]+$/',$t)) $clean[$t]=true; }
  $tables=array_keys($clean); sort($tables,SORT_NATURAL|SORT_FLAG_CASE);
  if(!$tables) throw new RuntimeException('没有可备份的数据表');
  $file=$base.'/'.adm_safe_name($prefix).'_'.date('Ymd_His').'.sql';
  $fh=fopen($file,'wb'); if(!$fh) throw new RuntimeException('无法创建备份文件：'.$file);
  fwrite($fh,"-- ".$title."\n-- Database: ".$db."\n-- Created: ".adm_now()."\n-- User: ".adm_user_name()."\n-- Tables: ".implode(', ',$tables)."\n\n");
  fwrite($fh,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
  $totalRows=0; $existing=0; $missing=array();
  foreach($tables as $table){
    if(!adm_table_exists($table)){ fwrite($fh,"\n-- Missing table: ".$table."\n"); $missing[]=$table; continue; }
    $existing++;
    fwrite($fh,"\n-- ----------------------------\n-- Table structure for ".$table."\n-- ----------------------------\n");
    fwrite($fh,'DROP TABLE IF EXISTS '.adm_ident($table).";\n");
    $row=$pdo->query('SHOW CREATE TABLE '.adm_ident($table))->fetch(PDO::FETCH_ASSOC);
    $create=$row['Create Table'] ?? array_values($row)[1] ?? '';
    fwrite($fh,$create.";\n\n");
    $stmt=$pdo->query('SELECT * FROM '.adm_ident($table));
    $cols=null; $batch=array(); $batchSize=80; $rows=0;
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
      if($cols===null) $cols=array_keys($r);
      $vals=array(); foreach($cols as $c){ $vals[]=adm_dump_value($r[$c]??null,$pdo); }
      $batch[]='('.implode(',',$vals).')'; $rows++; $totalRows++;
      if(count($batch)>=$batchSize){
        fwrite($fh,'INSERT INTO '.adm_ident($table).' ('.implode(',',array_map('adm_ident',$cols)).') VALUES ' . "\n" . implode(",\n",$batch).";\n");
        $batch=array();
      }
    }
    if($cols!==null && $batch){ fwrite($fh,'INSERT INTO '.adm_ident($table).' ('.implode(',',array_map('adm_ident',$cols)).') VALUES ' . "\n" . implode(",\n",$batch).";\n"); }
    fwrite($fh,"\n-- Rows: ".$rows."\n");
  }
  fwrite($fh,"\nSET FOREIGN_KEY_CHECKS=1;\n");
  fclose($fh);
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'tables'=>count($tables),'existing_tables'=>$existing,'missing_tables'=>$missing,'rows'=>$totalRows,'type'=>'sql','scope'=>'crm','created_at'=>adm_now());
}
function adm_crm_customer_contact_tables(){
  $tabs=array('crm_customers','crm_contacts'); $out=array();
  foreach($tabs as $t){ if(adm_table_exists($t)) $out[]=$t; }
  return $out;
}
function adm_crm_core_tables(){
  $tabs=array(
    'crm_customers','crm_contacts','crm_followups','crm_tasks','crm_reminders','crm_quotes','crm_quotations',
    'crm_customer_buffer','crm_customer_links','crm_mail_business_links','crm_sample_shipments','crm_visit_records',
    'crm_visits','crm_after_sales','crm_settings','crm_dict_options','crm_roles','crm_permissions','crm_user_roles'
  );
  $out=array(); foreach($tabs as $t){ if(adm_table_exists($t)) $out[]=$t; }
  return $out;
}
function adm_crm_all_tables(){
  $out=array();
  foreach(adm_all_tables() as $t){ if(preg_match('/^crm_/i',$t)) $out[]=$t; }
  sort($out,SORT_NATURAL|SORT_FLAG_CASE);
  return array_values(array_unique($out));
}
function adm_write_crm_customer_contact_backup(){
  $tabs=adm_crm_customer_contact_tables();
  if(!$tabs) throw new RuntimeException('没有找到 crm_customers / crm_contacts 表，无法做客户联系人专项备份');
  return adm_write_sql_tables_backup($tabs,'crm_customer_contacts','Artdon CRM Customer + Contact SQL Backup');
}
function adm_write_crm_core_backup(){
  $tabs=adm_crm_core_tables();
  if(!$tabs) throw new RuntimeException('没有找到 CRM 核心业务表，无法备份');
  return adm_write_sql_tables_backup($tabs,'crm_core_database','Artdon CRM Core Database SQL Backup');
}
function adm_write_crm_db_backup(){
  $tabs=adm_crm_all_tables();
  if(!$tabs) throw new RuntimeException('没有找到 crm_ 开头的数据表，无法做 CRM 数据库备份');
  return adm_write_sql_tables_backup($tabs,'crm_database','Artdon CRM Full Database SQL Backup');
}
function adm_crm_file_match($rel){
  $rel=str_replace('\\','/',(string)$rel); $base=basename($rel);
  if(preg_match('/^crm(_|\.|$)/i',$base)) return true;
  if(preg_match('/(^|\/)(crm|crm_[^\/]*|crm-[^\/]*|exmail|crm_exmail|promotion|crm_promotion)(\/|_|\.|-|$)/i',$rel)) return true;
  if(preg_match('/(^|\/)(uploads|upload|files|storage|attachments|attachment|data)(\/|$)/i',$rel) && preg_match('/crm|exmail|promotion/i',$rel)) return true;
  return false;
}
function adm_collect_crm_files($limit=0){
  $root=realpath(__DIR__); if(!$root) throw new RuntimeException('网站目录不存在');
  $backupBase=realpath(adm_backup_base()) ?: adm_backup_base();
  $files=array(); $skipped=0; $bytes=0;
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach($it as $file){
    $real=$file->getPathname(); if($file->isDir() || $file->isLink()) continue;
    if(adm_should_skip_file($real,$root,$backupBase)){ $skipped++; continue; }
    $rel=str_replace('\\','/',substr($real,strlen($root)+1));
    if(!adm_crm_file_match($rel)) continue;
    $files[]=array('path'=>$real,'rel'=>$rel,'size'=>@filesize($real)?:0); $bytes+=@filesize($real)?:0;
    if($limit>0 && count($files)>=$limit) break;
  }
  return array($files,$skipped,$bytes);
}
function adm_add_crm_files_to_zip($zip,$folderInZip='crm_site'){
  list($files,$skipped,$bytes)=adm_collect_crm_files(0); $count=0;
  foreach($files as $f){ if($zip->addFile($f['path'],$folderInZip.'/'.$f['rel'])) $count++; else $skipped++; }
  return array($count,$skipped,$bytes);
}
function adm_write_crm_files_backup(){
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  $base=adm_backup_base(); $file=$base.'/crm_files_'.date('Ymd_His').'.zip';
  $zip=new ZipArchive(); if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('无法创建 CRM 文件 ZIP：'.$file);
  $zip->addFromString('README.txt',"Artdon CRM files backup\nCreated: ".adm_now()."\nRoot: ".__DIR__."\nScope: crm*.php + crm/exmail/promotion named paths\n");
  list($count,$skipped,$bytes)=adm_add_crm_files_to_zip($zip,'crm_site');
  $zip->close();
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'files'=>$count,'skipped'=>$skipped,'source_bytes'=>$bytes,'source_size_label'=>adm_bytes($bytes),'type'=>'zip','scope'=>'crm_files','created_at'=>adm_now());
}
function adm_write_crm_full_backup(){
  if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive 未启用，请在宝塔 PHP 8.0 扩展里启用 zip');
  $db=adm_write_crm_db_backup();
  $base=adm_backup_base(); $file=$base.'/crm_full_'.date('Ymd_His').'.zip';
  $zip=new ZipArchive(); if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('无法创建 CRM 完整备份 ZIP');
  $zip->addFromString('README.txt',"Artdon CRM full backup\nCreated: ".adm_now()."\nIncludes: crm_database.sql + CRM files\n");
  $zip->addFile($db['path'],'crm_database/database.sql');
  list($count,$skipped,$bytes)=adm_add_crm_files_to_zip($zip,'crm_site');
  $zip->close();
  return array('file'=>basename($file),'path'=>$file,'size'=>filesize($file),'size_label'=>adm_bytes(filesize($file)),'db_file'=>$db['file'],'tables'=>$db['existing_tables'],'rows'=>$db['rows'],'files'=>$count,'skipped'=>$skipped,'source_size_label'=>adm_bytes($bytes),'type'=>'full','scope'=>'crm_full','created_at'=>adm_now());
}
function adm_crm_backup_preview(){
  list($files,$skipped,$bytes)=adm_collect_crm_files(2000);
  $sample=array(); foreach(array_slice($files,0,20) as $f){ $sample[]=$f['rel']; }
  return array(
    'database'=>adm_db_name(),
    'customer_contact_tables'=>adm_crm_customer_contact_tables(),
    'crm_core_tables'=>adm_crm_core_tables(),
    'crm_all_tables_count'=>count(adm_crm_all_tables()),
    'crm_all_tables'=>adm_crm_all_tables(),
    'crm_file_count'=>count($files),
    'crm_file_size_label'=>adm_bytes($bytes),
    'crm_file_sample'=>$sample,
    'note'=>'CRM 数据库=所有 crm_ 开头的数据表；CRM整站文件=crm/exmail/promotion 命名的程序文件和相关附件路径。通用 uploads 下没有 crm/exmail 命名的附件，建议另做完整网站备份兜底。'
  );
}

function adm_admin_overview(){
  $tables=adm_all_tables();
  $summary=array(
    'projects'=>adm_table_count('plm_projects') ?: adm_table_count('plm_project'),
    'models'=>adm_table_count('plm_models') ?: adm_table_count('plm_project_models'),
    'files'=>adm_table_count('plm_files') ?: adm_table_count('plm_project_files'),
    'logs'=>adm_table_count('plm_logs') ?: adm_table_count('plm_activity_logs'),
    'backup_files'=>count(adm_list_backups())
  );
  $health=array();
  $health[]=array('level'=>'ok','title'=>'登录权限','message'=>'当前页面已通过 plm_auth_require(admin, admin) 保护');
  $dir=adm_backup_base(); $health[]=array('level'=>is_writable($dir)?'ok':'bad','title'=>'备份目录','message'=>$dir.(is_writable($dir)?' 可写':' 不可写'));
  $health[]=array('level'=>class_exists('ZipArchive')?'ok':'warn','title'=>'ZIP 扩展','message'=>class_exists('ZipArchive')?'ZipArchive 已启用，可做文件备份/恢复':'ZipArchive 未启用，只能做 SQL 备份/恢复');
  $health[]=array('level'=>count($tables)>0?'ok':'bad','title'=>'数据库连接','message'=>'当前数据库：'.adm_db_name().'，表数量：'.count($tables));
  $rows=array();
  foreach($tables as $t){ $rows[]=array('table'=>$t,'rows'=>adm_table_count($t),'note'=>'数据库表','exists'=>true); }
  return array('summary'=>$summary,'health'=>$health,'tables'=>$rows,'backup_dir'=>$dir,'backups'=>adm_list_backups());
}
function adm_recent_activity($limit=180){
  $pdo=adm_db(); $limit=max(20,min(500,(int)$limit));
  $candidates=array('plm_logs','plm_activity_logs','plm_operation_logs','artdon_permission_audit_logs','artdon_login_logs');
  foreach($candidates as $t){
    if(!adm_table_exists($t)) continue;
    try{
      $cols=$pdo->query('SHOW COLUMNS FROM '.adm_ident($t))->fetchAll(PDO::FETCH_COLUMN);
      $time=in_array('created_at',$cols,true)?'created_at':(in_array('time',$cols,true)?'time':(in_array('updated_at',$cols,true)?'updated_at':'id'));
      $rs=$pdo->query('SELECT * FROM '.adm_ident($t).' ORDER BY '.adm_ident($time).' DESC LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
      $out=array();
      foreach($rs as $r){
        $out[]=array(
          'time'=>(string)($r['created_at']??$r['time']??$r['updated_at']??''),
          'action'=>(string)($r['action']??$r['action_key']??$r['module_key']??$r['result']??'记录'),
          'project'=>(string)($r['project_name']??$r['target_title']??$r['username']??''),
          'model'=>(string)($r['model_name']??$r['target_type']??''),
          'operator'=>(string)($r['operator']??$r['actor_username']??$r['username']??$r['created_by']??''),
          'detail'=>(string)($r['detail']??$r['note']??$r['error_text']??json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
        );
      }
      return $out;
    }catch(Throwable $e){}
  }
  return array();
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if($action!==''){
  try{
    if($action==='download_backup') adm_download_backup($_GET['file']??'');
    if($action==='admin_overview') adm_ok(adm_admin_overview());
    if($action==='recent_activity'){ $in=adm_in(); adm_ok(array('logs'=>adm_recent_activity((int)($in['limit']??180)))); }
    if($action==='backup_preview') adm_ok(array('preview'=>array('database'=>adm_db_name(),'tables'=>count(adm_all_tables()),'backup_dir'=>adm_backup_base(),'backups'=>adm_list_backups())));
    if($action==='list_backups') adm_ok(array('backups'=>adm_list_backups(),'backup_dir'=>adm_backup_base()));
    if($action==='backup_db') adm_ok(adm_write_sql_backup('manual_db'));
    if($action==='backup_files') adm_ok(adm_write_files_backup('manual_files'));
    if($action==='backup_full') adm_ok(adm_write_full_backup());
    if($action==='crm_backup_preview') adm_ok(array('preview'=>adm_crm_backup_preview()));
    if($action==='crm_backup_customer_contacts') adm_ok(adm_write_crm_customer_contact_backup());
    if($action==='crm_backup_core') adm_ok(adm_write_crm_core_backup());
    if($action==='crm_backup_db') adm_ok(adm_write_crm_db_backup());
    if($action==='crm_backup_files') adm_ok(adm_write_crm_files_backup());
    if($action==='crm_backup_full') adm_ok(adm_write_crm_full_backup());
    if($action==='restore_sql') adm_ok(adm_restore_sql_from_upload());
    if($action==='restore_zip') adm_ok(adm_restore_zip_from_upload());
    if($action==='delete_backup'){
      $in=adm_in(); $p=adm_backup_file_path($in['file']??''); @unlink($p); adm_ok(array('message'=>'已删除备份文件','backups'=>adm_list_backups()));
    }
    adm_fail('未知操作：'.$action,404);
  }catch(Throwable $e){ adm_fail($e->getMessage(),500); }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Artdon 安全中心 · 备份恢复</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#dbe4ef;--blue:#2563eb;--green:#16a34a;--red:#dc2626;--orange:#f59e0b;--purple:#7c3aed;--shadow:0 14px 36px rgba(15,23,42,.08)}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#eef6ff,#f8fbff);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif;color:var(--text);font-size:14px}.app{max-width:1520px;margin:auto;padding:18px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}.brand{font-weight:900;color:#1d4ed8}.top h1{margin:4px 0 2px;font-size:30px}.sub{font-size:12px;color:var(--muted);font-weight:800}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid var(--line);background:#fff;color:#1e293b;border-radius:12px;padding:9px 13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.good{background:#dcfce7;border-color:#bbf7d0;color:#15803d}.btn.warn{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.btn.danger{background:#fee2e2;border-color:#fecaca;color:#991b1b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}.card{background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);padding:15px}.card h2{font-size:20px;margin:0 0 12px}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:14px 0}.stat{background:#fff;border:1px solid var(--line);border-left:6px solid var(--blue);border-radius:16px;padding:12px;box-shadow:var(--shadow)}.stat.green{border-left-color:var(--green)}.stat.red{border-left-color:var(--red)}.stat.orange{border-left-color:var(--orange)}.stat.purple{border-left-color:var(--purple)}.stat b{display:block;font-size:24px}.stat span{font-size:12px;color:var(--muted);font-weight:900}.table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid #e5e7eb;padding:9px;text-align:left;vertical-align:top}.table th{font-size:12px;color:#64748b}.hint{font-size:12px;color:#64748b;font-weight:800;line-height:1.55}.pill{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:900;background:#e2e8f0;color:#334155}.pill.ok{background:#dcfce7;color:#15803d}.pill.warn{background:#fff7ed;color:#c2410c}.pill.bad{background:#fee2e2;color:#991b1b}.box{border:1px dashed #93c5fd;background:#eff6ff;border-radius:16px;padding:12px;margin:10px 0}pre{white-space:pre-wrap;background:#0f172a;color:#dbeafe;border-radius:16px;padding:12px;max-height:260px;overflow:auto}.full{grid-column:1/-1}.loglist{display:grid;gap:8px;max-height:560px;overflow:auto}.log{border:1px solid #e5e7eb;border-radius:14px;padding:10px;background:#f8fafc}.log b{display:block}.log small{display:block;color:#64748b;margin-top:4px}.toast{position:fixed;right:18px;bottom:18px;background:#111827;color:#fff;border-radius:14px;padding:13px 16px;box-shadow:0 18px 50px rgba(15,23,42,.28);display:none;z-index:50}.toast.show{display:block}.restoreBox{border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:16px;padding:12px;margin-top:10px}.backupList{max-height:360px;overflow:auto;border:1px solid #edf2f7;border-radius:16px}.backupList table{margin:0}input[type=file],input[type=text]{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:9px 11px;background:#fff}label{display:block;font-size:12px;color:#475569;font-weight:900;margin:8px 0 5px}@media(max-width:980px){.grid,.stats{grid-template-columns:1fr}.app{padding:12px}.top h1{font-size:24px}}
</style>
</head>
<body>
<div class="app">
  <div class="top">
    <div><div class="brand">中山雅大光电有限公司 · Artdon Lighting Limited</div><h1>安全中心 · 备份恢复 V3.0.4</h1><div class="sub">已包含 CRM专项备份：客户+联系人 / CRM核心业务 / CRM数据库 / CRM整站文件 / CRM完整备份</div></div>
    <div class="actions"><a class="btn" href="artdon_portal.php">返回首页</a><a class="btn" href="plm.php">返回 PLM</a><a class="btn" href="users.php">用户权限</a><a class="btn danger" href="logout.php">退出</a><button class="btn primary" onclick="loadAll()">刷新</button></div>
  </div>
  <div class="box" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><b>当前文件版本：V3.0.4 CRM专项备份版</b><div class="hint">如果你看不到这一行，说明服务器没有替换到这个文件，或浏览器/OPcache 还在读旧文件。</div></div><div id="stats" class="stats"></div>
  <div class="grid">
    <div class="card">
      <h2>一键备份</h2>
      <div class="box"><b>升级、导入、恢复前先备份。</b><div class="hint">数据库 SQL 不需要 ZIP 扩展；文件备份和完整备份需要宝塔 PHP 开启 zip 扩展。默认备份目录优先使用 /www/backup/artdon/plm_admin。</div></div>
      <div class="actions"><button class="btn good" onclick="runBackup('backup_db')">数据库 SQL 备份</button><button class="btn" onclick="runBackup('backup_files')">网站文件 ZIP 备份</button><button class="btn primary" onclick="runBackup('backup_full')">完整备份</button><button class="btn" onclick="previewBackup()">预览摘要</button></div>
      <pre id="backupPreview">等待操作...</pre>
    </div>
    <div class="card">
      <h2>CRM 专项备份</h2>
      <div class="box"><b>CRM 可以单独备，不影响 PLM / BOM / 报价。</b><div class="hint">客户+联系人只备 crm_customers / crm_contacts；CRM数据库会备份所有 crm_ 开头的数据表；CRM整站文件会备 crm*.php、邮箱、推广和 CRM 附件命名路径。</div></div>
      <div class="actions"><button class="btn good" onclick="runCrmBackup('crm_backup_customer_contacts')">客户+联系人 SQL</button><button class="btn" onclick="runCrmBackup('crm_backup_core')">CRM核心业务 SQL</button><button class="btn" onclick="runCrmBackup('crm_backup_db')">CRM数据库 SQL</button><button class="btn" onclick="runCrmBackup('crm_backup_files')">CRM整站文件 ZIP</button><button class="btn primary" onclick="runCrmBackup('crm_backup_full')">CRM完整备份</button><button class="btn" onclick="previewCrmBackup()">预览范围</button></div>
      <pre id="crmBackupPreview">等待操作...</pre>
    </div>
    <div class="card">
      <h2>数据健康检查</h2>
      <div id="healthBox" class="hint">正在加载...</div>
    </div>
    <div class="card full">
      <h2>备份文件列表</h2>
      <div class="actions" style="margin-bottom:10px"><button class="btn" onclick="loadBackups()">刷新备份列表</button></div>
      <div class="backupList"><table class="table" id="backupTable"><tr><td class="hint">正在加载...</td></tr></table></div>
    </div>
    <div class="card">
      <h2>恢复数据库 SQL</h2>
      <div class="restoreBox"><b>高风险：</b>会修改当前数据库。恢复前系统会自动生成一份当前数据库 SQL 快照。确认词必须输入 <b>RESTORE</b>。</div>
      <form id="sqlRestoreForm" onsubmit="restoreSql(event)"><label>SQL 文件</label><input name="sql_file" type="file" accept=".sql" required><label>确认词</label><input name="confirm" type="text" placeholder="输入 RESTORE" required><div class="actions" style="margin-top:12px"><button class="btn danger" type="submit">恢复 SQL</button></div></form>
    </div>
    <div class="card">
      <h2>恢复网站文件 ZIP</h2>
      <div class="restoreBox"><b>高风险：</b>会覆盖网站目录同名文件。恢复前系统会自动生成一份文件 ZIP 快照。确认词必须输入 <b>RESTORE_FILES</b>。</div>
      <form id="zipRestoreForm" onsubmit="restoreZip(event)"><label>ZIP 文件</label><input name="zip_file" type="file" accept=".zip" required><label>确认词</label><input name="confirm" type="text" placeholder="输入 RESTORE_FILES" required><div class="actions" style="margin-top:12px"><button class="btn danger" type="submit">恢复 ZIP 文件</button></div></form>
    </div>
    <div class="card full">
      <h2>最近操作日志</h2>
      <div class="actions" style="margin-bottom:10px"><button class="btn" onclick="loadLogs()">刷新日志</button><button class="btn warn" onclick="onlyWarnings()">只看异常/删除</button><button class="btn" onclick="renderLogs(lastLogs)">显示全部</button></div>
      <div id="logBox" class="loglist"></div>
    </div>
    <div class="card full">
      <h2>表数据概况</h2>
      <table class="table" id="tableBox"></table>
    </div>
  </div>
</div>
<div id="toast" class="toast"></div>
<script>
const API='plm_admin.php'; let overview=null,lastLogs=[];
const $=id=>document.getElementById(id); const esc=s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
async function api(action,data={},isForm=false){try{const opt={method:'POST',cache:'no-store'}; if(isForm){opt.body=data}else{opt.headers={'Content-Type':'application/json'};opt.body=JSON.stringify(data)} const r=await fetch(API+'?action='+encodeURIComponent(action),opt); const t=await r.text(); let j; try{j=JSON.parse(t)}catch(e){return {ok:false,error:'接口返回不是 JSON：'+t.slice(0,220)}} return j;}catch(e){return {ok:false,error:e.message}}}
function toast(s){const t=$('toast');t.textContent=s;t.className='toast show';setTimeout(()=>t.className='toast',2600)}
async function loadAll(){const r=await api('admin_overview'); if(!r.ok){toast(r.error||'加载失败');return} overview=r.data||{}; renderOverview(); await loadLogs(); await loadBackups();}
function renderOverview(){const s=overview.summary||{};$('stats').innerHTML=`<div class="stat"><b>${s.projects||0}</b><span>项目</span></div><div class="stat green"><b>${s.models||0}</b><span>样品/型号</span></div><div class="stat orange"><b>${s.files||0}</b><span>附件记录</span></div><div class="stat purple"><b>${s.logs||0}</b><span>操作日志</span></div><div class="stat red"><b>${s.backup_files||0}</b><span>备份文件</span></div>`;let health=overview.health||[];$('healthBox').innerHTML=health.map(h=>`<div style="margin:8px 0"><span class="pill ${h.level==='ok'?'ok':h.level==='bad'?'bad':'warn'}">${esc(h.level)}</span> <b>${esc(h.title)}</b><div class="hint">${esc(h.message)}</div></div>`).join('')||'暂无检查项';let rows=overview.tables||[];$('tableBox').innerHTML='<thead><tr><th>表名</th><th>行数</th><th>说明</th><th>状态</th></tr></thead><tbody>'+rows.map(t=>`<tr><td><b>${esc(t.table)}</b></td><td>${esc(t.rows)}</td><td>${esc(t.note)}</td><td><span class="pill ${t.exists?'ok':'bad'}">${t.exists?'存在':'缺失'}</span></td></tr>`).join('')+'</tbody>';}
async function runBackup(action){if(action==='backup_full'&&!confirm('完整备份可能较慢，确认开始？'))return; $('backupPreview').textContent='正在执行，请不要关闭页面...'; const r=await api(action); if(!r.ok){$('backupPreview').textContent=r.error||'备份失败'; toast(r.error||'备份失败'); return;} $('backupPreview').textContent=JSON.stringify(r.data,null,2); toast('备份完成'); await loadBackups(); await loadAll();}
async function runCrmBackup(action){if((action==='crm_backup_full'||action==='crm_backup_files')&&!confirm('CRM文件/完整备份可能较慢，确认开始？'))return; $('crmBackupPreview').textContent='正在执行 CRM 专项备份，请不要关闭页面...'; const r=await api(action); if(!r.ok){$('crmBackupPreview').textContent=r.error||'CRM备份失败'; toast(r.error||'CRM备份失败'); return;} $('crmBackupPreview').textContent=JSON.stringify(r.data,null,2); toast('CRM备份完成'); await loadBackups();}
async function previewCrmBackup(){const r=await api('crm_backup_preview'); if(!r.ok){toast(r.error||'CRM预览失败');return}$('crmBackupPreview').textContent=JSON.stringify(r.data.preview,null,2);}
async function loadBackups(){const r=await api('list_backups'); if(!r.ok){$('backupTable').innerHTML='<tr><td>'+esc(r.error||'加载失败')+'</td></tr>';return} const rows=(r.data&&r.data.backups)||[]; $('backupTable').innerHTML='<thead><tr><th>文件</th><th>类型</th><th>大小</th><th>时间</th><th>操作</th></tr></thead><tbody>'+(rows.length?rows.map(b=>`<tr><td><b>${esc(b.file)}</b></td><td>${esc(b.type)}</td><td>${esc(b.size_label)}</td><td>${esc(b.mtime)}</td><td><a class="btn" href="${esc(b.download)}">下载</a> <button class="btn danger" onclick="deleteBackup('${esc(b.file)}')">删除</button></td></tr>`).join(''):'<tr><td colspan="5" class="hint">暂无备份文件</td></tr>')+'</tbody>';}
async function deleteBackup(file){if(!confirm('确认删除备份文件：'+file+'？'))return; const r=await api('delete_backup',{file}); if(!r.ok){toast(r.error||'删除失败');return} toast('已删除'); loadBackups();}
async function previewBackup(){const r=await api('backup_preview'); if(!r.ok){toast(r.error||'预览失败');return}$('backupPreview').textContent=JSON.stringify(r.data.preview,null,2);}
async function restoreSql(e){e.preventDefault(); if(!confirm('确认恢复数据库？这会修改当前数据库。'))return; const fd=new FormData(e.target); const r=await api('restore_sql',fd,true); if(!r.ok){toast(r.error||'恢复失败');alert(r.error||'恢复失败');return} toast('SQL恢复完成'); alert(JSON.stringify(r.data,null,2)); loadAll();}
async function restoreZip(e){e.preventDefault(); if(!confirm('确认恢复网站文件？这会覆盖同名文件。'))return; const fd=new FormData(e.target); const r=await api('restore_zip',fd,true); if(!r.ok){toast(r.error||'恢复失败');alert(r.error||'恢复失败');return} toast('ZIP恢复完成'); alert(JSON.stringify(r.data,null,2)); loadAll();}
async function loadLogs(){const r=await api('recent_activity',{limit:180}); if(!r.ok){toast(r.error||'日志加载失败');return} lastLogs=(r.data&&r.data.logs)||[]; renderLogs(lastLogs);}
function renderLogs(rows){$('logBox').innerHTML=(rows||[]).map(l=>`<div class="log"><b>${esc(l.time||'-')} ｜ ${esc(l.action||'')}</b><small>${esc(l.project||'')} ${l.model?'｜ '+esc(l.model):''} ${l.operator?'｜ '+esc(l.operator):''}</small><div class="hint">${esc(l.detail||'')}</div></div>`).join('')||'<div class="hint">暂无日志</div>';}
function onlyWarnings(){renderLogs(lastLogs.filter(l=>/删除|失败|错误|重来|异常|取消|恢复/.test((l.action||'')+' '+(l.detail||''))))}
loadAll();
</script>
</body>
</html>
