<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_api('quote');
/* ARTDON_SSO_GATE_V2_END */

require_once __DIR__ . '/includes/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    @session_name('ARTDON_SYS');
    @session_set_cookie_params(['lifetime'=>86400*30,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    @session_start();
}
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
// V6.8.4.41：修复审核驳回/通过时调用 s() 未定义导致失败。
// V6.8.5.3 / 2026-06-30：报价拉取 BOM 成本改为整灯 BOM 型号精确匹配；禁止物料库/物料名顶替整灯 BOM。
if (!function_exists('str_contains')) { function str_contains($haystack,$needle){ return $needle === '' || strpos((string)$haystack,(string)$needle) !== false; } }
if (!function_exists('str_starts_with')) { function str_starts_with($haystack,$needle){ return $needle === '' || strpos((string)$haystack,(string)$needle) === 0; } }

$pdo = db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!in_array($action,['download_backup','price_policy_export_excel','commission_rule_export'],true)) {
  ob_start();
  register_shutdown_function(function() {
    $err = error_get_last();
    if (!$err || !in_array((int)$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok'=>false,'msg'=>'服务器错误：'.$err['message'],'file'=>basename((string)$err['file']),'line'=>(int)$err['line']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  });
}
function ok($data=[]){ echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function fail($msg){ echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function fail_auth($msg='请先登录'){ echo json_encode(['ok'=>false,'msg'=>$msg,'auth_required'=>true], JSON_UNESCAPED_UNICODE); exit; }
function input_json(){ static $cache=null; if($cache!==null) return $cache; $raw=file_get_contents('php://input'); $j=json_decode($raw,true); $cache=is_array($j)?$j:($_POST?:[]); return $cache; }
function quote_size_text($bytes){
  $bytes=(float)$bytes;
  if($bytes>=1073741824) return number_format($bytes/1073741824,2).'GB';
  if($bytes>=1048576) return number_format($bytes/1048576,2).'MB';
  if($bytes>=1024) return number_format($bytes/1024,1).'KB';
  return number_format($bytes,0).'B';
}
function quote_ini_bytes($value){
  $value=trim((string)$value);
  if($value==='' || $value==='-1') return -1;
  $unit=strtolower(substr($value,-1));
  $num=(float)$value;
  if($unit==='g') return (int)($num*1073741824);
  if($unit==='m') return (int)($num*1048576);
  if($unit==='k') return (int)($num*1024);
  return (int)$num;
}
function quote_prepare_heavy_json_runtime($bytes=0){
  $bytes=max(0,(int)$bytes);
  // JSON 恢复会同时占用原始文本、解码数组和恢复前安全备份内存。
  $need=max(536870912, min(1610612736, $bytes*8 + 268435456));
  $current=quote_ini_bytes((string)ini_get('memory_limit'));
  if($current !== -1 && $current < $need) @ini_set('memory_limit', (string)ceil($need/1048576).'M');
  @set_time_limit(900);
  @ini_set('max_execution_time','900');
}
if (!function_exists('s')) { function s($v,$max=5000){ $v=trim((string)($v ?? '')); if($max>0){ if(function_exists('mb_strlen') && mb_strlen($v,'UTF-8')>$max){ $v=mb_substr($v,0,$max,'UTF-8'); } elseif(!function_exists('mb_strlen') && strlen($v)>$max){ $v=substr($v,0,$max); } } return $v; } }
function table_exists($pdo,$t){ $s=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"); $s->execute([$t]); return (bool)$s->fetchColumn(); }
function table_columns($pdo,$t){ if(!table_exists($pdo,$t)) return []; $s=$pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION"); $s->execute([$t]); return array_map(fn($r)=>$r['COLUMN_NAME'],$s->fetchAll(PDO::FETCH_ASSOC)); }
function ensure_col($pdo,$table,$col,$ddl){
  if(!table_exists($pdo,$table)) return;
  if(in_array($col,table_columns($pdo,$table),true)) return;
  try{ $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl"); }
  catch(Throwable $e){
    $msg=$e->getMessage();
    // 兼容已经执行过一半的旧版本升级：Duplicate column 不再阻断页面初始化。
    if(stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false || stripos($msg,'1060')!==false) return;
    throw $e;
  }
}
function quote_v640_substr($s,$start,$len){ return function_exists('mb_substr') ? mb_substr($s,$start,$len,'UTF-8') : substr($s,$start,$len); }
function quote_v640_doc_schema_fix($pdo){
  try{
    if(!table_exists($pdo,'quote_orders')) return;
    ensure_col($pdo,'quote_orders','customer_name',"`customer_name` VARCHAR(255) DEFAULT ''");
    ensure_col($pdo,'quote_orders','header_json',"`header_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','bank_json',"`bank_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','template_json',"`template_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','quote_status',"`quote_status` VARCHAR(80) DEFAULT 'Quotation sheet'");
    ensure_col($pdo,'quote_orders','status',"`status` VARCHAR(80) DEFAULT ''");
    try{ $pdo->exec("ALTER TABLE `quote_orders` ADD KEY `idx_customer_name` (`customer_name`)"); }catch(Throwable $e){}
    if(in_array('customer_json', table_columns($pdo,'quote_orders'), true)){
      $rs=rows($pdo,"SELECT id, customer_json FROM quote_orders WHERE (customer_name IS NULL OR customer_name='') ORDER BY id DESC LIMIT 3000");
      $up=$pdo->prepare("UPDATE quote_orders SET customer_name=? WHERE id=?");
      foreach($rs as $r){
        $a=json_decode((string)($r['customer_json']??''),true);
        $name='';
        if(is_array($a)){
          foreach(['company','name','customer_name','client_name','company_name','customer','buyer_name','contact'] as $k){
            if(isset($a[$k]) && trim((string)$a[$k])!==''){ $name=trim((string)$a[$k]); break; }
          }
        }
        if($name!=='') $up->execute([quote_v640_substr($name,0,255), intval($r['id'])]);
      }
    }
  }catch(Throwable $e){}
}

function ensure_quote_core_schema($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_no VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_quote_no (quote_no)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $defs=[
    'quote_date'=>"DATE NULL",
    'user_name'=>"VARCHAR(120) DEFAULT ''",
    'customer_id'=>"VARCHAR(120) DEFAULT ''",
    'customer_name'=>"VARCHAR(255) DEFAULT ''",
    'customer_json'=>"LONGTEXT NULL",
    'header_id'=>"INT NULL",
    'bank_id'=>"INT NULL",
    'template_id'=>"INT NULL",
    'header_json'=>"MEDIUMTEXT NULL",
    'bank_json'=>"MEDIUMTEXT NULL",
    'template_json'=>"MEDIUMTEXT NULL",
    'product_type'=>"VARCHAR(80) DEFAULT ''",
    'product_id'=>"INT DEFAULT 0",
    'product_json'=>"LONGTEXT NULL",
    'parts_json'=>"LONGTEXT NULL",
    'items_json'=>"LONGTEXT NULL",
    'qty'=>"DECIMAL(14,3) DEFAULT 0",
    'price'=>"DECIMAL(14,4) DEFAULT 0",
    'amount'=>"DECIMAL(14,2) DEFAULT 0",
    'currency'=>"VARCHAR(20) DEFAULT 'USD'",
    'exchange_rate'=>"DECIMAL(12,4) DEFAULT 1",
    'moq'=>"VARCHAR(80) DEFAULT ''",
    'color'=>"VARCHAR(120) DEFAULT ''",
    'cct'=>"VARCHAR(120) DEFAULT ''",
    'cri'=>"VARCHAR(120) DEFAULT ''",
    'ip'=>"VARCHAR(120) DEFAULT ''",
    'extra_spec'=>"TEXT NULL",
    'status'=>"VARCHAR(80) DEFAULT ''",
    'quote_status'=>"VARCHAR(80) DEFAULT 'Quotation sheet'",
    'version_no'=>"INT DEFAULT 1",
    'price_level_id'=>"VARCHAR(80) DEFAULT ''",
    'price_level_name'=>"VARCHAR(120) DEFAULT ''",
    'price_multiplier'=>"DECIMAL(12,4) DEFAULT 1",
    'converted_order_id'=>"INT DEFAULT 0",
    'converted_order_no'=>"VARCHAR(120) DEFAULT ''",
    'updated_at'=>"DATETIME NULL"
  ];
  foreach($defs as $col=>$ddl){ ensure_col($pdo,'quote_orders',$col,'`'.$col.'` '.$ddl); }
  foreach([
    'idx_quote_date'=>'quote_date',
    'idx_customer_name'=>'customer_name',
    'idx_user_name'=>'user_name',
    'idx_updated_at'=>'updated_at'
  ] as $idx=>$col){
    try{ $pdo->exec("ALTER TABLE `quote_orders` ADD KEY `$idx` (`$col`)"); }catch(Throwable $e){}
  }
  quote_v640_doc_schema_fix($pdo);
}
function rows($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
function row($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
function quote_select_columns_except($pdo,$table,array $exclude=[]){
  $cols=table_columns($pdo,$table);
  if(!$cols) return '*';
  $drop=array_flip($exclude);
  $use=array_values(array_filter($cols,fn($c)=>!isset($drop[$c])));
  return $use?('`'.implode('`,`',$use).'`'):'*';
}
function save_row($pdo,$table,$data,$fields){
  $id = intval($data['id'] ?? 0); $cols=[]; $vals=[]; $real=array_flip(table_columns($pdo,$table));
  foreach($fields as $f){ if(isset($real[$f]) && array_key_exists($f,$data)){ $cols[]=$f; $vals[]=$data[$f]; }}
  if($id){ if(!$cols) return $id; $sets = implode(',', array_map(fn($f)=>"`$f`=?", $cols)); $vals[]=$id; $pdo->prepare("UPDATE `$table` SET $sets WHERE id=?")->execute($vals); return $id; }
  if(!$cols) fail('没有可保存字段：'.$table);
  $names=implode(',', array_map(fn($f)=>"`$f`", $cols)); $qs=implode(',', array_fill(0,count($cols),'?')); $pdo->prepare("INSERT INTO `$table` ($names) VALUES ($qs)")->execute($vals); return $pdo->lastInsertId();
}
function norm_material($m){
  return [
    'id'=>$m['id']??($m['mid']??''),'category'=>$m['category']??($m['type']??''),'brand'=>$m['brand']??'',
    'name'=>$m['name']??($m['material_name']??''),'model'=>$m['model']??($m['code']??''),'spec'=>$m['spec']??($m['remark']??''),
    'price'=>floatval($m['price']??($m['unit_price']??0)),'unit'=>$m['unit']??'PCS','supplier'=>$m['supplier']??'','keyword'=>$m['keyword']??'','image'=>$m['image']??''
  ];
}
function get_materials($pdo){
  static $cache=null;
  if($cache!==null) return $cache;
  if(table_exists($pdo,'bom_materials')){ $where=in_array('is_active',table_columns($pdo,'bom_materials'))?'WHERE is_active=1':''; return $cache=array_map('norm_material', rows($pdo,"SELECT * FROM bom_materials $where ORDER BY id DESC LIMIT 5000")); }
  if(table_exists($pdo,'materials')) return $cache=array_map('norm_material', rows($pdo,"SELECT * FROM materials ORDER BY id DESC LIMIT 5000"));
  if(table_exists($pdo,'bom_kv')){
    $r=row($pdo,"SELECT data_json FROM bom_kv WHERE data_key='materials' LIMIT 1");
    $arr=$r?json_decode($r['data_json'],true):[]; if(!is_array($arr))$arr=[]; return $cache=array_map('norm_material',$arr);
  }
  return $cache=[];
}

function pick_existing_col($pdo,$t,$candidates,$fallback=''){
  $cols=table_columns($pdo,$t);
  foreach($candidates as $c){ if(in_array($c,$cols,true)) return $c; }
  return $fallback;
}
function first_existing_val($row,$keys,$def=''){
  foreach($keys as $k){ if(array_key_exists($k,$row) && $row[$k]!==null && $row[$k]!=='') return $row[$k]; }
  return $def;
}
function quote_customer_normalize_address_text($v): string {
  $s=trim(preg_replace('/\s+/u',' ',(string)($v ?? '')));
  return $s;
}
function quote_crm_address_map($pdo, array $customerIds): array {
  $customerIds=array_values(array_unique(array_filter(array_map('intval',$customerIds), fn($v)=>$v>0)));
  if(!$customerIds || !table_exists($pdo,'crm_customer_addresses')) return [];
  $cols=table_columns($pdo,'crm_customer_addresses');
  if(!in_array('customer_id',$cols,true) || !in_array('address',$cols,true)) return [];
  $out=[];
  try{
    foreach(array_chunk($customerIds,500) as $chunk){
      $ph=implode(',',array_fill(0,count($chunk),'?'));
      $where=["customer_id IN ($ph)"];
      if(in_array('deleted_at',$cols,true)) $where[]="(deleted_at IS NULL OR deleted_at='' OR deleted_at='0000-00-00 00:00:00')";
      $order=[];
      if(in_array('is_primary',$cols,true)) $order[]='is_primary DESC';
      if(in_array('address_type',$cols,true)) $order[]="FIELD(address_type,'HQ','Office','Billing','Shipping','Factory','Warehouse','Store','Project','Other')";
      $order[]='id ASC';
      $st=$pdo->prepare("SELECT * FROM crm_customer_addresses WHERE ".implode(' AND ',$where)." ORDER BY ".implode(',',$order)." LIMIT 20000");
      $st->execute($chunk);
      foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $cid=(int)($r['customer_id']??0);
        $address=quote_customer_normalize_address_text($r['address']??'');
        if($cid<=0 || $address==='') continue;
        if(!isset($out[$cid])) $out[$cid]=['address1'=>'','address2'=>'','addresses'=>[]];
        $item=[
          'type'=>(string)($r['address_type']??''),
          'label'=>(string)($r['address_type']??''),
          'country'=>(string)($r['country']??''),
          'city'=>(string)($r['city']??''),
          'address'=>$address,
          'is_primary'=>(int)($r['is_primary']??0),
        ];
        $key=function_exists('mb_strtolower') ? mb_strtolower($address,'UTF-8') : strtolower($address);
        $exists=false;
        foreach($out[$cid]['addresses'] as $existing){
          $existingKey=function_exists('mb_strtolower') ? mb_strtolower((string)($existing['address']??''),'UTF-8') : strtolower((string)($existing['address']??''));
          if($existingKey===$key){ $exists=true; break; }
        }
        if($exists) continue;
        $out[$cid]['addresses'][]=$item;
        if($out[$cid]['address1']==='') $out[$cid]['address1']=$address;
        elseif($out[$cid]['address2']==='') $out[$cid]['address2']=$address;
        $type=strtolower((string)($r['address_type']??''));
        if($type==='billing' && $out[$cid]['address1']==='') $out[$cid]['address1']=$address;
        if(in_array($type,['shipping','factory','warehouse','store','project'],true) && $out[$cid]['address2']==='') $out[$cid]['address2']=$address;
      }
    }
  }catch(Throwable $e){ return []; }
  foreach($out as $cid=>$payload){
    $out[$cid]['addresses_json']=json_encode($payload['addresses'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  return $out;
}
// V6.8.2：命名中心产品 ID 保存修复。
// 前端命名产品 ID 可能是 naming_naming_models_30，quote_orders.product_id 是 INT，
// 保存前必须只取最后的数字 30，避免 MySQL 1366 Incorrect integer value。
function quote_v682_normalize_int_id($v){
  if($v===null) return null;
  if(is_int($v)) return $v;
  if(is_float($v)) return (int)$v;
  $s=trim((string)$v);
  if($s==='') return null;
  if(preg_match('/^\d+$/',$s)) return (int)$s;
  if(preg_match('/(?:^|_)(\d+)$/',$s,$m)) return (int)$m[1];
  if(preg_match('/(\d+)$/',$s,$m)) return (int)$m[1];
  return 0;
}
function quote_v682_prepare_quote_save_data(&$d){
  if(array_key_exists('product_id',$d)){
    $d['product_id']=quote_v682_normalize_int_id($d['product_id']);
  }
}
// V6.4.2：命名中心图片路径标准化。兼容 naming_models.image_path / drawing_path，
// 也兼容旧数据把绝对服务器路径写进数据库的情况。
function quote_v642_encode_path($path){
  $path=str_replace('\\','/',(string)$path);
  $parts=explode('/',$path); $out=[];
  foreach($parts as $p){ $out[]=$p===''?'':rawurlencode(rawurldecode($p)); }
  return str_replace('%2F','/',implode('/',$out));
}
function quote_v642_public_asset_path($v){
  // V6.8.5.20：报价系统命名图片直接走官网 HTTPS 域名，不再用 PHP 代理，不走 IP 证书地址。
  $v=trim((string)$v);
  if($v==='') return '';
  $v=html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $v=str_replace(['\\', "\0"], ['/', ''], $v);
  if(preg_match('/^data:/i',$v)) return $v;
  $site='https://artdonlighting.com';
  if(preg_match('/^https?:\/\//i',$v)){
    $u=@parse_url($v);
    $path=(string)($u['path']??'');
    $query=isset($u['query']) && $u['query']!=='' ? '?'.$u['query'] : '';
    if(strpos($path,'/uploads/website/')===0) return $site.quote_v642_encode_path($path).$query;
    if(strpos($path,'/website/products/')===0) return $site.'/uploads'.quote_v642_encode_path($path).$query;
    // 老数据里用 IP 或 http 域名的官网图片，统一切成 artdonlighting.com，避免证书/IP 安全警告。
    $host=strtolower((string)($u['host']??''));
    if(in_array($host,['43.132.210.162','www.gallin.cn','gallin.cn'],true) && strpos($path,'/uploads/')===0) return $site.quote_v642_encode_path($path).$query;
    return $v;
  }
  $doc=isset($_SERVER['DOCUMENT_ROOT'])?str_replace('\\','/',rtrim((string)$_SERVER['DOCUMENT_ROOT'],'/')):'';
  $dir=str_replace('\\','/',__DIR__);
  foreach([$doc,$dir] as $base){
    if($base!=='' && strpos($v,$base.'/')===0){ $v=substr($v,strlen($base)+1); break; }
  }
  $clean=preg_replace('/[?#].*$/','',$v) ?: $v;
  $clean=ltrim(str_replace('\\','/',$clean),'/');
  if(strpos($clean,'uploads/website/')===0) return $site.'/'.quote_v642_encode_path($clean);
  if(strpos($clean,'website/products/')===0) return $site.'/uploads/'.quote_v642_encode_path($clean);
  if(strpos($clean,'uploads/naming/')===0) return quote_v642_encode_path($clean);
  $pos=strpos($clean,'uploads/website/');
  if($pos!==false) return $site.'/'.quote_v642_encode_path(substr($clean,$pos));
  $pos=strpos($clean,'uploads/naming/');
  if($pos!==false) return quote_v642_encode_path(substr($clean,$pos));
  $pos=strpos($clean,'uploads/');
  if($pos!==false && strpos($clean,'uploads/')!==0) $clean=substr($clean,$pos);
  return quote_v642_encode_path(ltrim($clean,'/'));
}
function quote_v642_pick_naming_image($r){
  // V6.8.5.20：按 naming_clean 已验证方案优先取官网产品图字段；尺寸图只作兜底。
  $img=first_existing_val($r,['web_image_url','cover_image_url','source_image_url','product_image','image_url','main_image','cover_image','cover_url','image_path','image','img','photo','picture','cover','main_pic','主图'],'');
  if($img==='') $img=first_existing_val($r,['web_dimension_url','dimension_image_url','source_drawing_url','source_dimension_url','drawing_url','dimension_url','size_image_url','web_drawing_url','drawing_path','drawing_image','drawing','尺寸图'],'');
  return quote_v642_public_asset_path($img);
}
// V6.4.3：报价尺寸按命名中心最终规则输出。
// 只有圆形才加 Φ；方形/方圆/线性/长条不加 Φ。只有嵌入式才输出 Cut out。
function quote_v643_text_blob($r){
  $parts=[];
  foreach(['dimension_type','category','item_name','product_name','name','series','type','model_no','model','code'] as $k){
    if(isset($r[$k]) && trim((string)$r[$k])!=='') $parts[]=trim((string)$r[$k]);
  }
  return implode(' ', $parts);
}
function quote_v643_is_embedded_naming($r){
  $t=strtolower(trim((string)first_existing_val($r,['dimension_type'],'')));
  if(in_array($t,['embedded_round','embedded_square','opening','recessed'],true)) return true;
  $txt=quote_v643_text_blob($r);
  return (bool)preg_match('/嵌入|无边|有边|开孔|recessed/i',$txt);
}
function quote_v643_is_not_round_text($txt){
  return (bool)preg_match('/方形|方圆|长方|线性|线条|长条|条形|K条|LUMI|linear|square|rect/i',(string)$txt);
}
function quote_v643_is_round_naming($r){
  $t=strtolower(trim((string)first_existing_val($r,['dimension_type'],'')));
  $txt=quote_v643_text_blob($r);
  if(quote_v643_is_not_round_text($txt)) return false;
  if(in_array($t,['embedded_round','diameter','round','circle','circular'],true)) return true;
  if(in_array($t,['embedded_square','box','square','rectangle'],true)) return false;
  return (bool)preg_match('/圆形|圆筒|筒灯|downlight|cylinder|round/i',$txt);
}
function quote_v642_dimension_size_from_naming($r){
  $l=trim((string)first_existing_val($r,['dim_length','length','长'],''));
  $w=trim((string)first_existing_val($r,['dim_width','width','宽'],''));
  $h=trim((string)first_existing_val($r,['dim_height','height','高'],''));
  $d=trim((string)first_existing_val($r,['dim_outer_d','diameter','dia','outer_diameter','直径'],''));
  $isRound=quote_v643_is_round_naming($r);
  if($l!=='' && $w!=='' && $h!=='') return $l.'*'.$w.'*'.$h;
  if($l!=='' && $w!=='') return $l.'*'.$w.($h!==''?'*'.$h:'');
  if($d!=='' && $h!=='') return ($isRound?'Φ':'').$d.'*'.$h;
  if($d!=='') return ($isRound?'Φ':'').$d.($h!==''?'*'.$h:'');
  return '';
}
function quote_v643_cutout_from_naming($r){
  if(!quote_v643_is_embedded_naming($r)) return '';
  return trim((string)first_existing_val($r,['dim_opening','cutout','hole','opening','cut_size','aperture','开孔'],''));
}
function quote_v643_fix_product_dimensions(&$p){
  $size=quote_v642_dimension_size_from_naming($p);
  if($size!=='') $p['size']=$size;
  if(!quote_v643_is_embedded_naming($p)) $p['cutout']='';
  else {
    $cut=quote_v643_cutout_from_naming($p);
    if($cut!=='') $p['cutout']=$cut;
  }
  $p['quote_display_size']=$p['size']??'';
  $p['quote_display_cutout']=$p['cutout']??'';
}
function norm_quote_customer($r){
  return [
    'id'=>$r['id']??'',
    'source'=>$r['source']??'quote',
    'crm_customer_id'=>$r['crm_customer_id']??'',
    'code'=>$r['code']??'',
    'company'=>$r['company']??($r['name']??''),
    'contact'=>$r['contact']??'',
    'email'=>$r['email']??'',
    'phone'=>$r['phone']??'',
    'country'=>$r['country']??'',
    'note'=>$r['note']??'',
    'owner'=>$r['owner']??'',
    'website'=>$r['website']??'',
    'address1'=>$r['address1']??'',
    'address2'=>$r['address2']??'',
    'addresses_json'=>$r['addresses_json']??'',
    'primary_contact'=>$r['primary_contact']??($r['contact']??''),
    'primary_contact_phone'=>$r['primary_contact_phone']??($r['phone']??''),
    'primary_contact_email'=>$r['primary_contact_email']??($r['email']??''),
    'contact_email'=>$r['contact_email']??($r['primary_contact_email']??($r['email']??'')),
    'contact_phone'=>$r['contact_phone']??($r['primary_contact_phone']??($r['phone']??'')),
    'mobile'=>$r['mobile']??($r['primary_contact_phone']??($r['phone']??''))
  ];
}
function get_quote_customers($pdo){
  if(!table_exists($pdo,'quote_customers')) return [];
  $cols=table_columns($pdo,'quote_customers');
  $rs=rows($pdo,"SELECT * FROM quote_customers ORDER BY id DESC LIMIT 3000");
  $out=[];
  foreach($rs as $r){
    $r['source']=$r['source']??'quote';
    $out[]=norm_quote_customer($r);
  }
  return $out;
}

function quote_crm_primary_contact($pdo, $customerId){
  $customerId=(int)$customerId;
  if($customerId<=0 || !table_exists($pdo,'crm_contacts')) return [];
  try{
    $cols=table_columns($pdo,'crm_contacts');
    $customerCol='';
    foreach(['customer_id','customerId','customer','cid','crm_customer_id'] as $cc){ if(in_array($cc,$cols,true)){ $customerCol=$cc; break; } }
    if($customerCol==='') return [];
    $where=["`$customerCol`=?"];
    if(in_array('deleted_at',$cols,true)) $where[]="(`deleted_at` IS NULL OR `deleted_at`='' OR `deleted_at`='0000-00-00 00:00:00')";
    if(in_array('is_deleted',$cols,true)) $where[]="COALESCE(`is_deleted`,0)=0";
    $orderParts=[];
    if(in_array('is_main',$cols,true)) $orderParts[]='is_main DESC';
    if(in_array('is_primary',$cols,true)) $orderParts[]='is_primary DESC';
    if(in_array('primary_contact',$cols,true)) $orderParts[]='primary_contact DESC';
    $orderParts[]='id ASC';
    $st=$pdo->prepare("SELECT * FROM crm_contacts WHERE ".implode(' AND ',$where)." ORDER BY ".implode(',',$orderParts)." LIMIT 20");
    $st->execute([$customerId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return [];
    $main=$rows[0];
    $pick=function($row,$keys){ return first_existing_val($row,$keys,''); };
    $name=$pick($main,['name','contact','contact_name','person','linkman']);
    $email=$pick($main,['email','mail','contact_email','email_address']);
    $phone=$pick($main,['phone','mobile','tel','telephone','whatsapp','contact_phone']);
    $whatsapp=$pick($main,['whatsapp','wechat','mobile','phone']);
    foreach($rows as $r){ if($email==='') $email=$pick($r,['email','mail','contact_email','email_address']); if($phone==='') $phone=$pick($r,['phone','mobile','tel','telephone','whatsapp','contact_phone']); if($whatsapp==='') $whatsapp=$pick($r,['whatsapp','wechat','mobile','phone']); }
    $main['_quote_primary_name']=$name;
    $main['_quote_primary_email']=$email;
    $main['_quote_primary_phone']=$phone;
    $main['_quote_primary_whatsapp']=$whatsapp;
    $main['_quote_contacts']=$rows;
    return $main;
  }catch(Throwable $e){ return []; }
}

function quote_crm_primary_contact_map($pdo, array $customerIds): array {
  $customerIds=array_values(array_unique(array_filter(array_map('intval',$customerIds), fn($v)=>$v>0)));
  if(!$customerIds || !table_exists($pdo,'crm_contacts')) return [];
  try{
    $cols=table_columns($pdo,'crm_contacts');
    $customerCol='';
    foreach(['customer_id','customerId','customer','cid','crm_customer_id'] as $cc){ if(in_array($cc,$cols,true)){ $customerCol=$cc; break; } }
    if($customerCol==='') return [];
    $whereBase=[];
    if(in_array('deleted_at',$cols,true)) $whereBase[]="(`deleted_at` IS NULL OR `deleted_at`='' OR `deleted_at`='0000-00-00 00:00:00')";
    if(in_array('is_deleted',$cols,true)) $whereBase[]="COALESCE(`is_deleted`,0)=0";
    $orderParts=[];
    if(in_array('is_main',$cols,true)) $orderParts[]='is_main DESC';
    if(in_array('is_primary',$cols,true)) $orderParts[]='is_primary DESC';
    if(in_array('primary_contact',$cols,true)) $orderParts[]='primary_contact DESC';
    $orderParts[]='id ASC';
    $pick=function($row,$keys){ return first_existing_val($row,$keys,''); };
    $rowsByCustomer=[];
    foreach(array_chunk($customerIds,500) as $chunk){
      $ph=implode(',',array_fill(0,count($chunk),'?'));
      $where=array_merge(["`$customerCol` IN ($ph)"],$whereBase);
      $st=$pdo->prepare("SELECT * FROM crm_contacts WHERE ".implode(' AND ',$where)." ORDER BY `$customerCol` ASC,".implode(',',$orderParts)." LIMIT 20000");
      $st->execute($chunk);
      foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        $cid=(int)($r[$customerCol]??0);
        if($cid<=0) continue;
        if(!isset($rowsByCustomer[$cid])) $rowsByCustomer[$cid]=[];
        if(count($rowsByCustomer[$cid])<20) $rowsByCustomer[$cid][]=$r;
      }
    }
    $out=[];
    foreach($rowsByCustomer as $cid=>$rows){
      if(!$rows) continue;
      $main=$rows[0];
      $name=$pick($main,['name','contact','contact_name','person','linkman']);
      $email=$pick($main,['email','mail','contact_email','email_address']);
      $phone=$pick($main,['phone','mobile','tel','telephone','whatsapp','contact_phone']);
      $whatsapp=$pick($main,['whatsapp','wechat','mobile','phone']);
      foreach($rows as $r){
        if($email==='') $email=$pick($r,['email','mail','contact_email','email_address']);
        if($phone==='') $phone=$pick($r,['phone','mobile','tel','telephone','whatsapp','contact_phone']);
        if($whatsapp==='') $whatsapp=$pick($r,['whatsapp','wechat','mobile','phone']);
      }
      $main['_quote_primary_name']=$name;
      $main['_quote_primary_email']=$email;
      $main['_quote_primary_phone']=$phone;
      $main['_quote_primary_whatsapp']=$whatsapp;
      $main['_quote_contacts']=$rows;
      $out[(int)$cid]=$main;
    }
    return $out;
  }catch(Throwable $e){ return []; }
}

function get_crm_customers($pdo){
  $tables=['crm_customers','crm_customer'];
  $table='';
  foreach($tables as $t){ if(table_exists($pdo,$t)){ $table=$t; break; } }
  if(!$table) return [];
  $cols=table_columns($pdo,$table);
  $idc=pick_existing_col($pdo,$table,['id','customer_id','cid'],'id');
  $order=in_array('updated_at',$cols,true)?'updated_at':$idc;
  $where=[];
  // V6.8.4.23：报价端读取 CRM 客户时必须只读“未删除”的客户。
  // CRM 删除客户是软删除 deleted_at=NOW()；旧逻辑没有过滤 deleted_at，导致已删的 3000 多个客户又被报价同步出来。
  if(in_array('deleted_at',$cols,true)) $where[]="(`deleted_at` IS NULL OR `deleted_at`='' OR `deleted_at`='0000-00-00 00:00:00')";
  if(in_array('is_deleted',$cols,true)) $where[]="COALESCE(`is_deleted`,0)=0";
  if(in_array('status',$cols,true)) $where[]="COALESCE(`status`,'') NOT IN ('已删除','删除','deleted','Deleted','DELETED')";
  $whereSql=$where ? ('WHERE '.implode(' AND ',$where)) : '';
  $rs=rows($pdo,"SELECT * FROM `$table` $whereSql ORDER BY `$order` DESC LIMIT 5000");
  $customerIds=[];
  foreach($rs as $r){
    $rid=first_existing_val($r,[$idc,'id','customer_id','cid'],'');
    if((int)$rid>0) $customerIds[]=(int)$rid;
  }
  $contactMap=quote_crm_primary_contact_map($pdo,$customerIds);
  $addressMap=quote_crm_address_map($pdo,$customerIds);
  $out=[]; $seen=[];
  foreach($rs as $r){
    $rid=first_existing_val($r,[$idc,'id','customer_id','cid'],'');
    $company=first_existing_val($r,['company','name','customer_name','client_name','company_name','customer','buyer_name'],'');
    if(trim((string)$company)==='') continue;
    $code=first_existing_val($r,['code','customer_code','client_code','short_code','customer_no','cust_code'],'');
    $mainContact=$contactMap[(int)$rid] ?? [];
    $primaryName=first_existing_val($mainContact,['_quote_primary_name','name','contact','contact_name','person','linkman'],'');
    $primaryEmail=first_existing_val($mainContact,['_quote_primary_email','email','mail','contact_email','email_address'],'');
    $primaryPhone=first_existing_val($mainContact,['_quote_primary_phone','phone','mobile','tel','telephone','whatsapp','contact_phone'],'');
    $email=first_existing_val($r,['email','customer_email','mail','contact_email','primary_contact_email','main_contact_email'],'') ?: $primaryEmail;
    $dedupeKey=$code!=='' ? ('code:'.quote_customer_norm_key($code)) : quote_customer_norm_key(($rid!==''?$rid:'').'||'.$company.'||'.$email);
    if($dedupeKey!=='' && isset($seen[$dedupeKey])) continue;
    if($dedupeKey!=='') $seen[$dedupeKey]=1;
    $crmAddress=$addressMap[(int)$rid] ?? [];
    $address1=first_existing_val($r,['address1','office_address','office_addr','address','addr','company_address','billing_address','billing_addr'],'') ?: ($crmAddress['address1'] ?? '');
    $address2=first_existing_val($r,['address2','factory_address','factory_addr','delivery_address','ship_address','shipping_address','shipping_addr'],'') ?: ($crmAddress['address2'] ?? '');
    $addressesJson=first_existing_val($r,['addresses_json','address_json','addresses','address_list','more_addresses'],'') ?: ($crmAddress['addresses_json'] ?? '');
    $out[]=[
      'id'=>'crm_'.$rid,
      'source'=>'crm',
      'crm_customer_id'=>$rid,
      'code'=>$code,
      'company'=>$company,
      'contact'=>first_existing_val($r,['contact','contact_name','main_contact','person','linkman','contact_person'],'') ?: $primaryName,
      'email'=>$email,
      'phone'=>first_existing_val($r,['phone','mobile','tel','telephone','whatsapp','contact_phone'],'') ?: $primaryPhone,
      'country'=>first_existing_val($r,['country','customer_country','region','market'],''),
      'note'=>first_existing_val($r,['note','remark','memo','comments'],''),
      'owner'=>first_existing_val($r,['owner','owner_name','user_name','sales','salesman','responsible'],''),
      'website'=>first_existing_val($r,['website','web','url','site','homepage','company_website'],''),
      'address1'=>$address1,
      'address2'=>$address2,
      'addresses_json'=>$addressesJson,
      'primary_contact'=>$primaryName ?: first_existing_val($r,['contact','contact_name','main_contact','person','linkman','contact_person'],''),
      'primary_contact_phone'=>$primaryPhone ?: first_existing_val($r,['phone','mobile','tel','telephone','whatsapp','contact_phone'],''),
      'primary_contact_email'=>$primaryEmail ?: $email,
    ];
  }
  return $out;
}
function merged_customers($pdo){
  $local=get_quote_customers($pdo);
  $crm=get_crm_customers($pdo);
  $seen=[]; $out=[];
  foreach($crm as $c){
    $key='crm:'.($c['crm_customer_id']?:strtolower($c['company']));
    if(isset($seen[$key])) continue; $seen[$key]=1; $out[]=$c;
  }
  foreach($local as $c){
    $ckey='quote:'.$c['id'];
    $sameCompany=strtolower(trim(($c['company']??'').'|'.($c['email']??'')));
    if($sameCompany && isset($seen['localmatch:'.$sameCompany])) continue;
    $out[]=$c;
  }
  return $out;
}
function crm_customer_count($pdo){ return count(get_crm_customers($pdo)); }

function quote_ensure_customer_schema($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) DEFAULT '',
    company VARCHAR(255) NOT NULL DEFAULT '',
    contact VARCHAR(160) DEFAULT '',
    email VARCHAR(255) DEFAULT '',
    phone VARCHAR(120) DEFAULT '',
    country VARCHAR(120) DEFAULT '',
    note TEXT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'quote',
    crm_customer_id VARCHAR(80) DEFAULT '',
    owner VARCHAR(160) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    KEY idx_source (source),
    KEY idx_crm_customer_id (crm_customer_id),
    KEY idx_company (company)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_col($pdo,'quote_customers','source',"`source` VARCHAR(30) NOT NULL DEFAULT 'quote'");
  ensure_col($pdo,'quote_customers','crm_customer_id',"`crm_customer_id` VARCHAR(80) DEFAULT ''");
  ensure_col($pdo,'quote_customers','owner',"`owner` VARCHAR(160) DEFAULT ''");
  ensure_col($pdo,'quote_customers','website',"`website` VARCHAR(255) DEFAULT ''");
  ensure_col($pdo,'quote_customers','address1',"`address1` VARCHAR(500) DEFAULT ''");
  ensure_col($pdo,'quote_customers','address2',"`address2` VARCHAR(500) DEFAULT ''");
  ensure_col($pdo,'quote_customers','addresses_json',"`addresses_json` MEDIUMTEXT NULL");
  ensure_col($pdo,'quote_customers','primary_contact',"`primary_contact` VARCHAR(160) DEFAULT ''");
  ensure_col($pdo,'quote_customers','primary_contact_phone',"`primary_contact_phone` VARCHAR(160) DEFAULT ''");
  ensure_col($pdo,'quote_customers','primary_contact_email',"`primary_contact_email` VARCHAR(180) DEFAULT ''");
  ensure_col($pdo,'quote_customers','updated_at',"`updated_at` DATETIME DEFAULT NULL");
  try{ $pdo->exec("ALTER TABLE quote_customers ADD KEY idx_source (source)"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE quote_customers ADD KEY idx_crm_customer_id (crm_customer_id)"); }catch(Throwable $e){}
}
function quote_customer_norm_key($v): string {
  $v=mb_strtolower(trim((string)$v),'UTF-8');
  $v=preg_replace('/\s+/u','',$v);
  $v=preg_replace('/[^\p{L}\p{N}@._\-]+/u','',$v);
  return (string)$v;
}
function quote_sync_crm_customer_cache($pdo){
  quote_ensure_customer_schema($pdo);
  $crm=get_crm_customers($pdo);
  $liveIds=[]; $liveNames=[]; $liveCodes=[]; $liveEmails=[];
  foreach($crm as $c){
    $id=trim((string)($c['crm_customer_id']??'')); if($id!=='') $liveIds[$id]=1;
    $name=quote_customer_norm_key($c['company']??''); if($name!=='') $liveNames[$name]=1;
    $code=quote_customer_norm_key($c['code']??''); if($code!=='') $liveCodes[$code]=1;
    $email=quote_customer_norm_key($c['email']??''); if($email!=='') $liveEmails[$email]=1;
  }
  $cached=rows($pdo,"SELECT id,crm_customer_id,company,code,email,source FROM quote_customers WHERE source='crm' OR crm_customer_id<>'' ORDER BY id ASC LIMIT 10000");
  $deleted=0; $kept=0; $dupLocalDeleted=0;
  foreach($cached as $r){
    $cid=trim((string)($r['crm_customer_id']??''));
    $name=quote_customer_norm_key($r['company']??'');
    $code=quote_customer_norm_key($r['code']??'');
    $email=quote_customer_norm_key($r['email']??'');
    $isLive=($cid!=='' && isset($liveIds[$cid])) || ($code!=='' && isset($liveCodes[$code])) || ($email!=='' && isset($liveEmails[$email])) || ($name!=='' && isset($liveNames[$name]));
    // CRM 客户现在实时从 CRM 表读取；报价端旧 CRM 缓存/重复缓存都删除，避免客户库越堆越多。
    $pdo->prepare("DELETE FROM quote_customers WHERE id=?")->execute([intval($r['id'])]);
    if($isLive) $dupLocalDeleted++; else $deleted++;
  }
  return ['count'=>count($crm),'deleted_stale'=>$deleted,'duplicate_local_deleted'=>$dupLocalDeleted,'cached_crm_rows'=>0,'live_ids'=>count($liveIds)];
}
function quote_align_customer_library_to_crm($pdo){
  quote_ensure_customer_schema($pdo);
  $crm=get_crm_customers($pdo);
  // 强制以 CRM 为唯一客户源：清空报价端本地客户/旧CRM缓存。历史报价、订单保存的是快照 JSON，不受影响。
  $st=$pdo->query("SELECT COUNT(*) FROM quote_customers");
  $before=(int)$st->fetchColumn();
  $pdo->exec("DELETE FROM quote_customers");
  return ['count'=>count($crm),'deleted_local'=>$before,'deleted_stale'=>0,'live_ids'=>count($crm)];
}
function quote_batch_delete_customers($pdo,$ids){
  quote_ensure_customer_schema($pdo);
  if(!is_array($ids)) $ids=[];
  $nums=[]; $skipped=0;
  foreach($ids as $id){
    $s=trim((string)$id);
    if($s==='' || str_starts_with($s,'crm_')){ $skipped++; continue; }
    $n=intval($s); if($n>0) $nums[]=$n; else $skipped++;
  }
  $nums=array_values(array_unique($nums));
  $deleted=0;
  if($nums){
    $ph=implode(',',array_fill(0,count($nums),'?'));
    $st=$pdo->prepare("DELETE FROM quote_customers WHERE id IN ($ph) AND COALESCE(source,'quote')<>'crm'");
    $st->execute($nums);
    $deleted=$st->rowCount();
  }
  return ['deleted'=>$deleted,'skipped'=>$skipped + max(0,count($nums)-$deleted)];
}



function all_tables($pdo){
  static $tables=null;
  if($tables!==null) return $tables;
  try{ $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); }
  catch(Throwable $e){ $tables=[]; }
  return $tables;
}
function norm_key($v){
  $v=strtoupper(trim((string)$v));
  $v=preg_replace('/[^A-Z0-9\.]+/','',$v);
  return $v;
}

function quote_no_no_nested($no){
  $no=trim((string)$no);
  $no=preg_replace('/-V\d+$/i','',$no);
  if(preg_match('/^(AT-\d{6}[A-Z0-9]+)((?:-\d{2})+)$/i',$no,$m)){
    preg_match_all('/-(\d{2})/',$m[2],$mm);
    $first=$mm[1][0]??'';
    return $m[1].($first!==''?'-'.$first:'');
  }
  return $no;
}
function product_type_from_category($v){
  $s=strtolower((string)$v);
  if(strpos($s,'导轨')!==false || strpos($s,'track')!==false) return 'track';
  if(strpos($s,'嵌入')!==false || strpos($s,'筒')!==false || strpos($s,'recess')!==false || strpos($s,'downlight')!==false) return 'recessed';
  if(strpos($s,'明装')!==false || strpos($s,'surface')!==false) return 'surface';
  if(strpos($s,'吊')!==false || strpos($s,'pendant')!==false) return 'pendant';
  if(strpos($s,'磁吸')!==false || strpos($s,'magnetic')!==false) return 'magnetic';
  if(strpos($s,'线性')!==false || strpos($s,'linear')!==false) return 'linear';
  if(strpos($s,'户外')!==false || strpos($s,'outdoor')!==false) return 'outdoor';
  if(strpos($s,'定制')!==false || strpos($s,'custom')!==false) return 'custom';
  return $v ? 'other' : 'other';
}

function quote_clean_naming_category($v,$type=''){
  $raw=trim((string)$v);
  $s=preg_replace('/型号命名|灯具命名|命名规则|规则|命名/u','',$raw);
  $s=preg_replace('/[\s　]+/u',' ',trim((string)$s));
  $both=mb_strtolower($raw.' '.$s,'UTF-8');
  if((mb_strpos($both,'外购')!==false || strpos($both,'outsour')!==false) && (mb_strpos($both,'嵌入')!==false || strpos($both,'recess')!==false || strpos($both,'downlight')!==false)) return '外购嵌入式灯具';
  if(mb_strpos($both,'磁吸')!==false || strpos($both,'magnetic')!==false) return '磁吸灯具';
  if(mb_strpos($both,'导轨')!==false || strpos($both,'track')!==false) return '导轨灯';
  if(mb_strpos($both,'嵌入')!==false || mb_strpos($both,'筒')!==false || strpos($both,'recess')!==false || strpos($both,'downlight')!==false) return '嵌入式灯具';
  if(mb_strpos($both,'明装')!==false || strpos($both,'surface')!==false) return '明装式';
  if(mb_strpos($both,'吊线')!==false || mb_strpos($both,'吊装')!==false || strpos($both,'pendant')!==false) return '吊线式';
  if(mb_strpos($both,'线性')!==false || strpos($both,'linear')!==false) return '线性灯';
  if(mb_strpos($both,'户外')!==false || strpos($both,'outdoor')!==false) return '户外灯';
  if($s!=='') return $s;
  $map=['track'=>'导轨灯','recessed'=>'嵌入式灯具','surface'=>'明装式','pendant'=>'吊线式','magnetic'=>'磁吸灯具','linear'=>'线性灯','outdoor'=>'户外灯','custom'=>'定制产品','other'=>'其它'];
  return $map[(string)$type] ?? (string)$type;
}
function num_first($row,$keys,$def=0){
  foreach($keys as $k){ if(isset($row[$k]) && is_numeric($row[$k]) && floatval($row[$k])>0) return floatval($row[$k]); }
  return $def;
}
function detect_naming_tables($pdo){
  $preferred=['naming_models','naming_products','product_naming','product_namings','naming_records','naming_items','named_products','product_models','model_namings','model_names','artdon_naming_products'];
  $tables=all_tables($pdo); $out=[];
  foreach($preferred as $t){ if(in_array($t,$tables,true)) $out[]=$t; }
  foreach($tables as $t){
    $lt=strtolower($t);
    if(in_array($t,$out,true)) continue;
    if(strpos($lt,'quote')!==false || strpos($lt,'bom')!==false || strpos($lt,'crm')!==false) continue;
    if(preg_match('/naming|named|model_name|product_model|product_name|model/i',$t)) $out[]=$t;
  }
  return array_slice($out,0,5);
}
function norm_naming_product($pdo,$table,$r){
  $rid=first_existing_val($r,['id','uid','product_id','model_id','record_id'],'');
  $catRaw=first_existing_val($r,['category','product_category','cat','品类','type','product_type','class','kind'],'');
  $cat=quote_clean_naming_category($catRaw);
  $code=first_existing_val($r,['model_no','model','product_model','quote_model','code','product_code','name_code','sku','number','型号'],'');
  $name=first_existing_val($r,['product_name','name','item_name','title','model_name','series_name','产品名称'],'');
  if($name==='' && $code!=='') $name=$code;
  if($code==='' && $name!=='') $code=$name;
  $width=first_existing_val($r,['dim_width','width','w','outer_width','宽'],'');
  $height=first_existing_val($r,['dim_height','height','h','outer_height','高'],'');
  $dia=first_existing_val($r,['dim_outer_d','diameter','dia','outer_diameter','直径'],'');
  $size=first_existing_val($r,['size','dimension','dimensions','product_size','outer_size','尺寸'],'');
  $dimSize=quote_v642_dimension_size_from_naming($r);
  if($dimSize!=='') $size=$dimSize;
  if($size==='' && ($dia!=='' || $height!=='' || $width!=='')) $size=trim(($dia?:$width).($height!==''?'*'.$height:''),'*');
  $cut=quote_v643_cutout_from_naming($r);
  $img=quote_v642_pick_naming_image($r);
  $power=first_existing_val($r,['power','watt','wattage','product_power','功率'],'');
  $ip=first_existing_val($r,['ip','ip_rating','waterproof','防护等级'],'');
  $color=first_existing_val($r,['color','colour','颜色'],'');
  $cct=first_existing_val($r,['cct','color_temp','color_temperature','色温'],'');
  $cri=first_existing_val($r,['cri','ra','显指'],'');
  return [
    'id'=>'naming_'.$table.'_'.$rid,
    'source'=>'naming',
    'source_label'=>'命名中心',
    'naming_table'=>$table,
    'naming_id'=>$rid,
    'type'=>product_type_from_category($catRaw?:$cat),
    'category'=>$cat,
    'quote_category'=>$cat,
    'category_raw'=>$catRaw,
    'series'=>first_existing_val($r,['series','series_name','系列'],''),
    'code'=>$code,
    'name'=>$name,
    'size'=>$size,
    'cutout'=>$cut,
    'dimension_type'=>first_existing_val($r,['dimension_type'],'') ?: (quote_v643_is_round_naming($r)?'diameter':'box'),
    'dim_opening'=>first_existing_val($r,['dim_opening','cutout','hole','opening','cut_size','aperture','开孔'],''),
    'dim_outer_d'=>first_existing_val($r,['dim_outer_d','diameter','dia','outer_diameter','直径'],''),
    'dim_length'=>first_existing_val($r,['dim_length','length','长'],''),
    'dim_width'=>first_existing_val($r,['dim_width','width','宽'],''),
    'dim_height'=>first_existing_val($r,['dim_height','height','高'],''),
    'is_embedded'=>quote_v643_is_embedded_naming($r)?1:0,
    'is_round_dimension'=>quote_v643_is_round_naming($r)?1:0,
    'power'=>$power,
    'cct'=>$cct,
    'cri'=>$cri,
    'ip'=>$ip,
    'color'=>$color,
    'moq'=>first_existing_val($r,['moq','MOQ'],'200'),
    'price_rmb'=>0,
    'price_usd'=>0,
    'cost_rmb'=>0,
    'cost_usd'=>0,
    'price_note'=>'来自命名中心，成本等待 BOM 匹配',
    'need_connector'=>'auto',
    'status'=>'active',
    'allow_quote'=>1,
    'is_active'=>1,
    'image'=>$img,
    'drawing_image'=>quote_v642_public_asset_path(first_existing_val($r,['web_dimension_url','dimension_image_url','source_drawing_url','source_dimension_url','drawing_url','dimension_url','size_image_url','web_drawing_url','drawing_path','drawing_image','drawing','尺寸图'],'')),
    'note'=>'命名中心产品：'.$table,
  ];
}
function get_naming_products($pdo){
  $out=[];
  foreach(detect_naming_tables($pdo) as $t){
    $cols=table_columns($pdo,$t); if(!$cols) continue;
    $hasModel=false; foreach(['model','model_no','product_model','quote_model','code','product_code','name','product_name','title'] as $c){ if(in_array($c,$cols,true)){ $hasModel=true; break; } }
    if(!$hasModel) continue;
    $where='';
    if(in_array('is_active',$cols,true)) $where='WHERE is_active=1';
    elseif(in_array('deleted',$cols,true)) $where='WHERE (deleted=0 OR deleted IS NULL)';
    $order=in_array('updated_at',$cols,true)?'updated_at':(in_array('id',$cols,true)?'id':$cols[0]);
    try{ $rs=rows($pdo,"SELECT * FROM `$t` $where ORDER BY `$order` DESC LIMIT 5000"); }
    catch(Throwable $e){ $rs=[]; }
    foreach($rs as $r){ $p=norm_naming_product($pdo,$t,$r); if(trim($p['code'].$p['name'])!=='') $out[]=$p; }
  }
  $seen=[]; $dedup=[];
  foreach($out as $p){ $k=norm_key($p['code']?:$p['name']); if(!$k) $k='ID'.$p['id']; if(isset($seen[$k])) continue; $seen[$k]=1; $dedup[]=$p; }
  return $dedup;
}
function detect_bom_cost_tables($pdo){
  // V6.8.5.3：报价系统只允许从“整灯 BOM 成本单”读取成本。
  // bom_materials / bom_items / materials 这类物料库、物料明细表绝不能作为整灯成本来源。
  $preferred=['bom_projects','bom_products','bom_product_costs','bom_costs','bom_records','bom_quote_costs','bom_orders','bom_order','bom_sheets','bom_sheet','bom_cost_sheets','bom_cost_sheet','bom_master','bom_list','cost_sheets','cost_sheet','cost_records','cost_record','product_costs','product_cost','costing_products'];
  $tables=all_tables($pdo); $out=[];
  foreach($preferred as $t){ if(in_array($t,$tables,true)) $out[]=$t; }
  foreach($tables as $t){
    $lt=strtolower($t); if(in_array($t,$out,true)) continue;
    if(strpos($lt,'quote')!==false || strpos($lt,'crm')!==false || strpos($lt,'log')!==false) continue;
    // 明确排除物料库/明细行。用户反馈：物料名里有 52.08012，也不能被当作整灯 BOM。
    if(preg_match('/(^|_)(material|materials|item|items|row|rows|line|lines|part|parts)($|_)/i',$lt)) continue;
    if((strpos($lt,'bom')!==false || strpos($lt,'cost')!==false) && (strpos($lt,'project')!==false || strpos($lt,'product')!==false || strpos($lt,'sheet')!==false || strpos($lt,'order')!==false || strpos($lt,'record')!==false || strpos($lt,'master')!==false)) $out[]=$t;
  }
  return array_slice(array_values(array_unique($out)),0,20);
}

function bom_extract_model_codes_from_text($v){
  $s=strtoupper((string)$v);
  $out=[];
  // 只提取完整型号，例如 52.08012 / D2.03501。后面紧跟中文物料名时仍能提取，
  // 但后续只有在“整灯 BOM 型号字段”里出现才会用于成本匹配。
  if(preg_match_all('/(?:[A-Z]{0,4})?\d{1,3}\.\d{3,6}(?:[A-Z0-9\-]{0,12})?/u', $s, $m)){
    foreach($m[0] as $x){ $k=norm_key($x); if($k) $out[]=$k; }
  }
  return array_values(array_unique($out));
}

function bom_strict_row_model_keys($r){
  // V6.8.5.3：整灯成本只能取 BOM 成本单自己的型号字段。
  // 禁止从物料名称、规格、备注、rows_json、bom_materials 等位置提取型号，防止“52.08012散热器后盖”被误认为 52.08012 整灯 BOM。
  $keys=[];
  foreach(['product_model','model','model_no','quote_model','code','product_code','name_code','sku','型号','产品型号','product_sku','bom_model'] as $c){
    if(isset($r[$c]) && $r[$c]!==null && trim((string)$r[$c])!==''){
      $raw=(string)$r[$c];
      $ex=bom_extract_model_codes_from_text($raw);
      if($ex) $keys=array_merge($keys,$ex);
      else { $k=norm_key($raw); if($k && strlen($k)>=5 && preg_match('/\d+\.\d+/', $k)) $keys[]=$k; }
    }
  }
  return array_values(array_unique(array_filter($keys)));
}

function bom_model_candidates_from_row($r){
  // 保留给调试使用；正式成本匹配请使用 bom_strict_row_model_keys()。
  return bom_strict_row_model_keys($r);
}

function bom_add_cost_map(&$map,$keys,$cost,$source,$updated='',$quality=50){
  $cost=floatval($cost); if($cost<=0) return;
  $quality=intval($quality);
  foreach($keys as $k){
    $k=norm_key($k); if(!$k) continue;
    $old=$map[$k]??null;
    $oldCost=floatval($old['cost_rmb']??0);
    $oldQ=intval($old['quality']??0);
    // V6.6.4：同一型号可能同时出现在“整张成本单”和“物料明细行”。
    // 报价要用整张成本单总成本，不能被芯片/透镜等单行成本覆盖。
    // 因此优先级：整张成本单 > 产品行 > 明细物料行；同级取较大的总成本。
    if(!$old || $oldCost<=0 || $quality>$oldQ || ($quality===$oldQ && $cost>$oldCost)){
      $map[$k]=['cost_rmb'=>$cost,'source_table'=>$source,'updated_at'=>$updated,'match_key'=>$k,'quality'=>$quality];
    }
  }
}
function bom_cost_from_json($node){
  // V6.6.4：先找“整张成本单”的总成本字段；不要把明细行的 amount/price 误当产品总成本。
  if(!is_array($node)) return 0;
  $sheetTotalKeys=[
    'total_cost_rmb','totalCostRmb','total_rmb','grand_total','grandTotal','cost_total','total_cost','totalCost','cost_rmb','bom_cost','bomCost','product_cost','productCost','final_cost','finalCost',
    'total_material_cost','material_total','materials_total','materialCost','material_cost_total','sum_cost','rmb_total',
    '总成本','总计成本','成本合计','合计成本','成本总计','总成本RMB','材料成本','材料成本合计'
  ];
  $direct=num_first($node,$sheetTotalKeys,0);
  if($direct>0) return $direct;

  // 如果这是明细行，才允许按行金额/数量*单价计算。
  $isLine=false;
  foreach(['qty','quantity','num','数量','unit_price','price','cost','unit_cost','line_total','subtotal','row_total','材料单价','单价'] as $k){
    if(array_key_exists($k,$node)){ $isLine=true; break; }
  }
  if($isLine){
    $rowPrice=num_first($node,['line_total','lineTotal','subtotal','sub_total','row_total','rowTotal','line_amount','amount_total','total','total_price','processing_total','surface_total','加工费','表面处理费','小计','金额小计'],0);
    if($rowPrice>0) return $rowPrice;
    $qty=num_first($node,['qty','quantity','num','数量'],1);
    $price=num_first($node,['unit_price','price','cost','unit_cost','material_price','材料单价','单价'],0);
    if($price>0) return $qty*$price;
  }

  // 没有总成本字段时，递归汇总明细行，适合成本单 JSON 只有材料明细的情况。
  $sum=0;
  foreach($node as $v){ if(is_array($v)) $sum += bom_cost_from_json($v); }
  return $sum;
}
function bom_json_records($arr){
  $out=[];
  if(!is_array($arr)) return $out;
  $stack=isset($arr[0])?$arr:[$arr];
  foreach($stack as $it){
    if(!is_array($it)) continue;
    $keys=bom_model_candidates_from_row($it);
    if($keys) $out[]=$it;
    foreach(['items','rows','materials','bom_items','bomRows','lines','list','data','products','children'] as $k){
      if(isset($it[$k]) && is_array($it[$k])) foreach(bom_json_records($it[$k]) as $r) $out[]=$r;
    }
  }
  return $out;
}
function bom_cost_from_row_direct($r){
  $keys=[
    'final_cost','finalCost','final_amount','grand_total','grandTotal','total_cost_rmb','totalRmb','total_rmb','cost_total','total_cost','totalCost','cost_rmb','bom_cost','bomCost','product_cost','productCost','unit_cost','unitCost','material_cost','materials_cost','total_material_cost','total_price','totalPrice','total_amount','totalAmount','amount_total','amount','sum','subtotal','sub_total','rmb_total','price_rmb','报价成本','成本价','总成本','总计成本','成本合计','合计成本','合计金额','总价','合计','总计'
  ];
  $v=num_first($r,$keys,0);
  if($v>0) return $v;
  // 字段名不固定时，按字段名关键词找数字，避免把型号 95.01012 当成本。
  foreach($r as $k=>$val){
    if(!is_numeric($val)) continue;
    $lk=strtolower((string)$k);
    $ck=(string)$k;
    if(preg_match('/cost|total|amount|sum|price|rmb|材料|成本|合计|总价|金额|小计|加工|表面|包装/u',$lk.$ck)){
      $fv=floatval($val);
      if($fv>0 && $fv<10000000) return $fv;
    }
  }
  return 0;
}
function bom_row_json_values($r){
  $vals=[];
  foreach($r as $k=>$v){
    if(!is_string($v)) continue;
    $sv=trim($v);
    if($sv==='') continue;
    if(($sv[0]??'')==='{' || ($sv[0]??'')==='[') $vals[]=$sv;
  }
  return $vals;
}
function bom_deep_model_keys($v){
  $keys=[];
  if(is_array($v)){
    $keys=array_merge($keys,bom_model_candidates_from_row($v));
    foreach($v as $vv){ $keys=array_merge($keys,bom_deep_model_keys($vv)); }
  } elseif(is_string($v)){
    if(preg_match_all('/\d{2,3}\.\d{3,6}/', $v, $m)) foreach($m[0] as $x) $keys[]=norm_key($x);
  }
  return array_values(array_unique(array_filter($keys)));
}
function bom_kv_candidate_rows($pdo){
  $out=[];
  if(!table_exists($pdo,'bom_kv')) return $out;
  $cols=table_columns($pdo,'bom_kv');
  if(!$cols) return $out;
  $keyCols=array_values(array_intersect(['data_key','k','key_name','key','kv_key','name','code'], $cols));
  $valCols=array_values(array_intersect(['data_json','json_data','v','value','val','json','content','data','text'], $cols));
  if(!$valCols){
    // 没有明显 JSON 字段时，也扫描所有文本字段。
    $valCols=$cols;
  }
  $select='*';
  try{ $rs=rows($pdo,"SELECT $select FROM bom_kv LIMIT 5000"); }catch(Throwable $e){ return $out; }
  foreach($rs as $r){
    foreach($valCols as $vc){
      if(!isset($r[$vc]) || !is_string($r[$vc]) || trim($r[$vc])==='') continue;
      $key=''; foreach($keyCols as $kc){ if(!empty($r[$kc])){ $key=(string)$r[$kc]; break; } }
      $out[]=['source'=>'bom_kv'.($key!==''?':'.$key:''),'value'=>$r[$vc],'row'=>$r];
    }
  }
  return $out;
}
function bom_precise_project_total_from_row($r){
  // 按 BOM 前端一致公式计算：行小计 = 数量*(单价+加工费+表面处理费1+表面处理费2)，总成本=材料行合计+人工费+包装/其它。
  $sum=0;
  $rows=[];
  $raw=$r['rows_json']??'';
  if(is_string($raw) && trim($raw)!==''){ $rows=json_decode($raw,true); if(!is_array($rows)) $rows=[]; }
  foreach($rows as $it){
    if(!is_array($it)) continue;
    $qty=num_first($it,['qty','quantity','num','数量'],0);
    $price=num_first($it,['price','unit_price','unitCost','unit_cost','单价'],0);
    $process=num_first($it,['process','process_cost','加工费'],0);
    $finish1=num_first($it,['finishCost','finish_cost','surface_cost','表面处理费','处理费1'],0);
    $finish2=num_first($it,['finishCost2','finish_cost2','surface_cost2','处理费2'],0);
    $sum += $qty * ($price + $process + $finish1 + $finish2);
  }
  $labor=num_first($r,['labor','labor_cost','人工费'],0);
  $other=num_first($r,['other','other_cost','package_cost','packing_cost','包装/其它','包装费'],0);
  return $sum + $labor + $other;
}
function bom_add_precise_projects_to_cost_map($pdo,&$map){
  if(!table_exists($pdo,'bom_projects')) return;
  $cols=table_columns($pdo,'bom_projects'); if(!$cols) return;
  $where=in_array('is_active',$cols,true)?' WHERE (is_active=1 OR is_active IS NULL)':'';
  $order=in_array('updated_at',$cols,true)?'updated_at':(in_array('id',$cols,true)?'id':$cols[0]);
  try{ $rs=rows($pdo,"SELECT * FROM `bom_projects`$where ORDER BY `$order` DESC LIMIT 10000"); }catch(Throwable $e){ return; }
  foreach($rs as $r){
    $keys=bom_strict_row_model_keys($r);
    $linkedSystem=strtoupper(trim((string)($r['linked_system']??'')));
    $linkedId=trim((string)($r['linked_id']??''));
    if($linkedId!=='' && (strpos($linkedSystem,'NAMING')!==false || strpos($linkedSystem,'命名')!==false)){
      $keys[]='NID'.$linkedId;
    }
    if(!$keys) continue;
    $cost=bom_precise_project_total_from_row($r);
    if($cost<=0) $cost=bom_cost_from_row_direct($r);
    bom_add_cost_map($map,$keys,$cost,'bom_projects.precise',first_existing_val($r,['updated_at','modified_at','created_at'],''),120);
  }
}


function get_bom_cost_map($pdo){
  static $cache=null;
  if($cache!==null) return $cache;
  // V6.8.5.3：只生成“整灯型号 → 整张 BOM 成本”的映射。
  // 彻底取消从物料库、物料明细行、rows_json 任意字符串、bom_kv 文本里抓型号做整灯成本。
  $map=[];
  bom_add_precise_projects_to_cost_map($pdo,$map);
  foreach(detect_bom_cost_tables($pdo) as $t){
    if($t==='bom_projects') continue;
    $cols=table_columns($pdo,$t); if(!$cols) continue;
    $order=in_array('updated_at',$cols,true)?'updated_at':(in_array('modified_at',$cols,true)?'modified_at':(in_array('id',$cols,true)?'id':$cols[0]));
    try{ $rs=rows($pdo,"SELECT * FROM `$t` ORDER BY `$order` DESC LIMIT 8000"); }catch(Throwable $e){ $rs=[]; }
    foreach($rs as $r){
      $keys=bom_strict_row_model_keys($r);
      if(!$keys) continue;
      $cost=bom_cost_from_row_direct($r);
      if($cost<=0) $cost=bom_cost_from_json($r);
      bom_add_cost_map($map,$keys,$cost,$t,first_existing_val($r,['updated_at','modified_at','created_at'],''),85);
    }
  }
  return $cache=$map;
}

function bom_debug_report($pdo,$model){
  $model=trim((string)$model);
  $nk=norm_key($model);
  $tables=detect_bom_cost_tables($pdo);
  $costMap=get_bom_cost_map($pdo);
  $hits=[];
  foreach($costMap as $k=>$v){ if($nk && (strpos($k,$nk)!==false || strpos($nk,$k)!==false)) $hits[$k]=$v; }
  $tableInfo=[];
  foreach($tables as $t){ $tableInfo[$t]=table_columns($pdo,$t); }
  $raw=[];
  foreach($tables as $t){
    $cols=table_columns($pdo,$t); if(!$cols) continue;
    try{ $rs=rows($pdo,"SELECT * FROM `$t` LIMIT 200"); }catch(Throwable $e){ $rs=[]; }
    foreach($rs as $r){
      $txt=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      if($nk && stripos(norm_key($txt),$nk)!==false){
        $raw[]=['table'=>$t,'keys'=>bom_model_candidates_from_row($r),'cost'=>bom_cost_from_row_direct($r),'sample'=>mb_substr($txt,0,800,'UTF-8')];
        if(count($raw)>=20) break 2;
      }
    }
  }
  return ['model'=>$model,'norm'=>$nk,'detected_tables'=>$tables,'table_columns'=>$tableInfo,'cost_hits'=>$hits,'raw_matches'=>$raw,'cost_map_count'=>count($costMap)];
}
function find_bom_cost_match($keys,$costMap){
  // V6.8.5.3：必须完整型号完全相等；不做包含、不做相似、不做名称匹配。
  $keys=array_values(array_unique(array_filter(array_map('norm_key',$keys))));
  $best=[null,null,null,-1,0];
  foreach($keys as $k){
    if(strlen($k)<5) continue;
    $isNid=(strpos($k,'NID')===0);
    if(!$isNid && !preg_match('/\d+\.\d+/', $k)) continue;
    foreach($costMap as $mk=>$row){
      $mk=norm_key($mk);
      if($mk!==$k) continue;
      $quality=intval($row['quality']??50);
      $cost=floatval($row['cost_rmb']??0);
      if($cost>0 && ($quality>$best[3] || ($quality===$best[3] && $cost>$best[4]))){
        $best=[$mk,$row,$isNid?'exact_naming_bind':'exact_model',$quality,$cost];
      }
    }
  }
  return [$best[0],$best[1],$best[2]];
}

function apply_bom_cost(&$p,$costMap){
  $keys=[];
  // V6.8.5.4：命名中心产品只按 naming_id 绑定的整灯 BOM 成本单匹配。
  // 这样 BOM 物料名里出现 52.08012，也不会被当作 52.08012 整灯 BOM。
  $source=strtolower(trim((string)($p['source']??'')));
  $namingId=trim((string)($p['naming_id']??''));
  if($source==='naming' && $namingId!==''){
    $keys[]='NID'.$namingId;
  }else{
    foreach([$p['code']??'', $p['model']??'', $p['model_no']??'', $p['naming_model_no']??''] as $v){
      $ex=bom_extract_model_codes_from_text($v);
      if($ex) $keys=array_merge($keys,$ex);
    }
  }
  [$mk,$hit,$mode]=find_bom_cost_match($keys,$costMap);
  if($hit){
    $c=floatval($hit['cost_rmb']??0); if($c>0){
      $p['cost_rmb']=$c; $p['price_rmb']=$c; $p['cost_usd']=$c/7; if(empty($p['price_usd'])) $p['price_usd']=$c/7;
      $p['bom_match']=1; $p['bom_match_key']=$mk; $p['bom_match_mode']=$mode; $p['bom_cost_source']=$hit['source_table']??''; $p['cost_updated_at']=$hit['updated_at']??''; $p['price_note']='BOM成本：RMB '.number_format($c,2).'；来源：'.($p['bom_cost_source']??'').'；匹配整灯型号：'.$mk;
      return true;
    }
  }
  $p['bom_match']=0;
  $p['bom_match_key']='';
  $p['bom_match_mode']='';
  $p['bom_cost_source']='';
  $p['cost_updated_at']='';
  return false;
}


function ensure_bom_quote_spec_schema($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS bom_quote_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    naming_id VARCHAR(120) DEFAULT '',
    product_model VARCHAR(120) NOT NULL,
    product_name VARCHAR(255) DEFAULT '',
    product_image LONGTEXT NULL,
    power VARCHAR(120) DEFAULT '',
    size VARCHAR(120) DEFAULT '',
    cutout VARCHAR(120) DEFAULT '',
    led TEXT NULL,
    driver TEXT NULL,
    optic TEXT NULL,
    accessories TEXT NULL,
    connector TEXT NULL,
    other TEXT NULL,
    quote_spec_json LONGTEXT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_model (product_model),
    KEY idx_naming_id (naming_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $defs=[
    'naming_id'=>"VARCHAR(120) DEFAULT ''", 'product_model'=>"VARCHAR(120) NOT NULL DEFAULT ''", 'product_name'=>"VARCHAR(255) DEFAULT ''", 'product_image'=>"LONGTEXT NULL",
    'power'=>"VARCHAR(120) DEFAULT ''", 'size'=>"VARCHAR(120) DEFAULT ''", 'cutout'=>"VARCHAR(120) DEFAULT ''",
    'led'=>"TEXT NULL", 'driver'=>"TEXT NULL", 'optic'=>"TEXT NULL", 'accessories'=>"TEXT NULL", 'connector'=>"TEXT NULL", 'other'=>"TEXT NULL",
    'quote_spec_json'=>"LONGTEXT NULL", 'note'=>"TEXT NULL", 'auto_generated'=>"TINYINT(1) NOT NULL DEFAULT 0", 'source_hash'=>"VARCHAR(64) DEFAULT ''", 'last_sync_at'=>"DATETIME NULL", 'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", 'updated_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
  ];
  $cols=table_columns($pdo,'bom_quote_specs');
  foreach($defs as $col=>$def){ if(!in_array($col,$cols,true)){ try{ $pdo->exec("ALTER TABLE bom_quote_specs ADD COLUMN `$col` $def"); }catch(Throwable $e){} } }
  try{ $pdo->exec("ALTER TABLE bom_quote_specs ADD KEY idx_model (`product_model`)"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE bom_quote_specs ADD KEY idx_naming_id (`naming_id`)"); }catch(Throwable $e){}
}
function qspec_blank($v){
  $v=trim((string)$v);
  if($v==='') return true;
  return preg_match('/^(0|\\-|\\/|n\\/?a|none|null|无|没有|不适用)$/iu',$v)===1;
}
function qspec_clean($v){ return qspec_blank($v)?'':trim((string)$v); }

function qspec_part_stopword($t){ return preg_match('/^(led|cob|driver|optic|optics|lens|reflector|adapter|connector|accessory|accessories|extra|chip|power|light|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接器|附件|配件|物料|材料)$/iu', trim((string)$t))===1; }
function qspec_drop_material_name_tokens($raw){ $tokens=preg_split('/\s+/u', trim((string)$raw)); $out=[]; foreach((array)$tokens as $t){ $t=trim($t); if($t==='' || preg_match('/[\x{4e00}-\x{9fff}]/u',$t) || qspec_part_stopword($t)) continue; $out[]=$t; } return $out; }
function qspec_model_from_ascii_text($raw,$hint=''){ $hint=qspec_clean($hint); if($hint!=='')return $hint; $toks=qspec_drop_material_name_tokens($raw); if(!$toks)return ''; $s=trim(implode(' ',$toks)); if(preg_match('/([A-Za-z0-9._-]+\s+Series\s*[A-Za-z0-9@._-]+(?:\s*[A-Za-z0-9@._-]+){0,2})/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(preg_match('/([A-Z]{1,8}[-_]?\d[A-Z0-9._-]*(?:\s+[A-Z0-9]{1,6}){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(preg_match('/(\d+(?:\.\d+)?\s*MM(?:\s+[A-Z0-9._-]+){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(count($toks)>4)return trim(implode(' ',array_slice($toks,-3))); if(count($toks)>1)return trim(implode(' ',array_slice($toks,1))); return $toks[0]??''; }
function qspec_brand_from_ascii_text($raw,$hint=''){ $hint=qspec_clean($hint); if($hint!=='')return $hint; $toks=qspec_drop_material_name_tokens($raw); return $toks[0]??''; }
function qspec_brand_model_only($input,$label=''){
  if(is_array($input)){
    $brand=qspec_clean($input['brand']??($input['manufacturer']??($input['factory']??($input['品牌']??''))));
    $model=qspec_clean($input['model']??($input['model_no']??($input['product_model']??($input['code']??($input['sku']??($input['型号']??($input['编码']??($input['series']??($input['series_name']??'')))))))));
    if($model==='') $model=qspec_model_from_ascii_text(trim(implode(' ',array_filter([$input['name']??'',$input['material_name']??'',$input['spec']??'',$input['description']??'',$input['物料名称']??'',$input['规格']??'']))));
    if($brand==='') $brand=qspec_brand_from_ascii_text(trim(implode(' ',array_filter([$input['brand']??'',$input['品牌']??'',$input['name']??'',$input['material_name']??'',$input['spec']??'']))));
    $out=trim(implode(' ',array_filter([$brand,$model]))); return $out!==''?$out:qspec_clean($input['spec']??($input['name']??''));
  }
  $raw=qspec_clean($input); if($raw==='')return ''; $brand=qspec_brand_from_ascii_text($raw); $model=qspec_model_from_ascii_text($raw); if($brand!==''&&$model!==''&&stripos($model,$brand)===0)return $model; $out=trim(implode(' ',array_filter([$brand,$model]))); if($out!=='')return $out; $tokens=qspec_drop_material_name_tokens($raw); return $tokens?trim(implode(' ',$tokens)):$raw;
}
function qspec_is_component_label($label){ $k=mb_strtolower(trim((string)$label),'UTF-8'); return in_array($k,['led','芯片','cob','driver','led driver','电源','驱动','optic','光学','透镜','反光杯','accessories','accessory','extra','附件','配件','connector','接头','连接头','adapter','other'],true); }
function qspec_is_accessory_label($label){
  $k=mb_strtolower(trim((string)$label),'UTF-8');
  return in_array($k,['accessories','accessory','extra','附件','配件'],true);
}
function qspec_is_packaging_text($txt){
  $s=mb_strtolower(trim((string)$txt),'UTF-8');
  if($s==='') return false;
  return preg_match('/纸卡|纸箱|纸盒|内盒|外盒|外箱|彩盒|包装|包材|吸塑|珍珠棉|泡棉|护角|标签|贴纸|说明书|吊牌|卡纸|opp\\s*袋|pe\\s*袋|poly\\s*bag|carton|inner\\s*box|outer\\s*box|gift\\s*box|color\\s*box|packing|packaging|label|manual|k\\s*=\\s*a|k6a|牛皮色/iu',$s)===1;
}
function qspec_component_label_key($label){
  $k=mb_strtolower(trim((string)$label),'UTF-8');
  if(in_array($k,['led','芯片','cob'],true)) return 'led';
  if(in_array($k,['driver','led driver','电源','驱动'],true)) return 'driver';
  if(in_array($k,['optic','光学','透镜','反光杯'],true)) return 'optic';
  if(in_array($k,['connector','接头','连接头','adapter'],true)) return 'connector';
  if(in_array($k,['accessories','accessory','extra','附件','配件'],true)) return 'accessories';
  return '';
}
function qspec_component_value_conflicts($label,$value){
  $key=qspec_component_label_key($label);
  $s=mb_strtolower(trim((string)$value),'UTF-8');
  if($key==='' || $s==='') return false;
  if(qspec_is_packaging_text($s)) return true;
  $isDriver=preg_match('/\\bled\\s*driver\\b|\\bdriver\\b|power\\s*supply|constant\\s*current|eaglerise|lifud|tridonic|mean\\s*well|电源|驱动|伊戈尔|恒流/iu',$s)===1;
  $isConnector=preg_match('/connector|adapter|track\\s*head|接头|转接|导轨头|连接器/iu',$s)===1;
  $isOptic=preg_match('/optic|optics|lens|reflector|dark\\s*series|herculux|透镜|反光杯|反光|光学|恒坤|honeycomb|蜂窝|格栅|防眩/iu',$s)===1;
  $isLed=preg_match('/\\b(cob|cree|osram|bridgelux|citizen|xpg|xhp|cxb|cxa|vhd|gen8)\\b|\\bled\\s*(chip|module|cob)\\b|\\b(chip|cob)\\s*led\\b|芯片|灯珠|普瑞/iu',$s)===1;
  if($key==='led') return $isDriver || $isConnector || $isOptic;
  if($key==='driver') return $isConnector || $isOptic || ($isLed && !$isDriver);
  if($key==='optic') return $isDriver || $isConnector || ($isLed && !$isOptic);
  if($key==='connector') return $isDriver || $isOptic || $isLed;
  if($key==='accessories') return $isDriver || $isConnector || $isOptic || $isLed;
  return false;
}
function qspec_sanitize_component_value($label,$value){
  if(qspec_component_value_conflicts($label,$value)) return '';
  return qspec_is_component_label($label)?qspec_brand_model_only($value,$label):qspec_clean($value);
}
function qspec_quote_name_of_node($node,$fallback=''){ $v=qspec_brand_model_only(is_array($node)?$node:$fallback); return $v!==''?$v:qspec_brand_model_only($fallback); }
function qspec_payload_from_row($r){
  $spec=[];
  foreach(['led'=>'LED','driver'=>'LED Driver','optic'=>'Optic','accessories'=>'Accessories','connector'=>'Connector','other'=>'Other'] as $k=>$label){
    $v=qspec_sanitize_component_value($label,$r[$k]??''); if($v!=='') $spec[$k]=['label'=>$label,'value'=>$v];
  }
  $extra=json_decode((string)($r['quote_spec_json']??''),true);
  if(is_array($extra)){
    foreach($extra as $k=>$v){
      if(is_array($v)){ $val=qspec_clean($v['value']??$v['text']??''); $lab=trim((string)($v['label']??$k)); }
      else { $val=qspec_clean($v); $lab=$k; }
      $val=qspec_sanitize_component_value($lab,$val); if($val!=='') $spec[$k]=['label'=>$lab,'value'=>$val];
    }
  }
  return $spec;
}

function qspec_json_values_from_row($r){
  $vals=[];
  foreach($r as $v){
    if(is_string($v)){
      $x=trim($v);
      if($x!=='' && (($x[0]??'')==='{' || ($x[0]??'')==='[')) $vals[]=$x;
    }
  }
  return $vals;
}
function qspec_bom_detail_json_values_from_row($r){
  $vals=[];
  foreach(['rows_json','items_json','materials_json','bom_rows','bom_items','detail_json','details_json','components_json','明细','物料明细'] as $k){
    if(isset($r[$k]) && is_string($r[$k])){
      $x=trim($r[$k]);
      if($x!=='' && (($x[0]??'')==='{' || ($x[0]??'')==='[')) $vals[]=$x;
    }
  }
  return $vals;
}
function qspec_flatten_nodes($v,&$out){
  if(is_array($v)){
    $out[]=$v;
    foreach($v as $vv) qspec_flatten_nodes($vv,$out);
  }
}
function qspec_text_of_node($n){
  if(!is_array($n)) return '';
  $parts=[];
  foreach(['category','type','kind','brand','name','material_name','model','code','spec','description','remark','supplier','分类','类型','品牌','名称','物料名称','型号','编码','规格','备注','供应商'] as $k){
    if(isset($n[$k]) && !is_array($n[$k]) && trim((string)$n[$k])!=='') $parts[]=$n[$k];
  }
  return trim(implode(' ', array_map('strval',$parts)));
}
function qspec_classify_component($txt){
  $s=strtolower((string)$txt);
  // 包装材料不属于灯具附件，不能进入报价单 Accessories。
  if(qspec_is_packaging_text($txt)) return '';
  // 先识别电源/接头/光学，再识别 LED，避免 “LED Driver” 被归到芯片。
  if(preg_match('/\bled\s*driver\b|\bdriver\b|power\s*supply|constant\s*current|eaglerise|lifud|tridonic|mean\s*well|电源|驱动|伊戈尔|恒流/u',$s)) return 'driver';
  if(preg_match('/connector|adapter|track\s*head|接头|转接|导轨头|连接器/u',$s)) return 'connector';
  if(preg_match('/optic|optics|lens|reflector|dark\s*series|herculux|透镜|反光杯|反光|光学|恒坤|honeycomb|蜂窝|格栅|防眩/u',$s)) return 'optic';
  // “光源面/光源面盖/散热器后盖”这类结构件不能被当成 LED 芯片；必须出现明确芯片/灯珠/LED Chip/COB 或常见芯片品牌/型号。
  if(preg_match('/\b(cob|cree|osram|bridgelux|citizen|xpg|xhp|cxb|cxa|vhd|gen8)\b|\bled\s*(chip|module|cob)\b|\b(chip|cob)\s*led\b|芯片|灯珠|普瑞/u',$s)) return 'led';
  if(preg_match('/accessor|附件|配件|面环|线材|吊绳|弹簧|安装件|螺丝|螺钉/u',$s)) return 'accessories';
  return '';
}
function qspec_guess_components_from_bom($pdo,$model){
  $nk=norm_key($model);
  $hits=[]; $sourceHashParts=[];
  if(!$nk) return ['hits'=>$hits,'source_hash'=>''];
  foreach(detect_bom_cost_tables($pdo) as $t){
    $cols=table_columns($pdo,$t); if(!$cols) continue;
    $order=in_array('updated_at',$cols,true)?'`updated_at` DESC':(in_array('id',$cols,true)?'`id` DESC':'1');
    try{ $rs=rows($pdo,"SELECT * FROM `$t` ORDER BY $order LIMIT 3000"); }catch(Throwable $e){ continue; }
    foreach($rs as $r){
      // 只有整张 BOM 成本单的型号字段等于当前型号，才允许从它的 rows_json 里提取 LED/电源/光学/配件。
      $rowKeys=bom_strict_row_model_keys($r);
      if(!in_array($nk,$rowKeys,true)) continue;
      $blob=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $sourceHashParts[]=$t.':'.substr(sha1($blob),0,12);
      $nodes=[];
      foreach(qspec_bom_detail_json_values_from_row($r) as $js){
        $a=json_decode($js,true);
        if(is_array($a)) qspec_flatten_nodes($a,$nodes);
      }
      $usedValues=[];
      foreach($nodes as $node){
        $txt=qspec_text_of_node($node);
        if($txt==='') continue;
        $cls=qspec_classify_component($txt);
        if($cls && empty($hits[$cls])){
          $val=qspec_quote_name_of_node($node,$txt);
          $vk=norm_key($val);
          if($val!=='' && ($vk==='' || empty($usedValues[$vk]))){
            $hits[$cls]=$val;
            if($vk!=='') $usedValues[$vk]=$cls;
          }
        }
      }
    }
  }
  return ['hits'=>$hits,'source_hash'=>sha1('qspec-classifier-v2|'.implode('|',array_slice($sourceHashParts,0,50)))];
}

function qspec_spec_json_from_fields($d){
  $arr=[];
  foreach(['led'=>'LED','driver'=>'LED Driver','optic'=>'Optic','accessories'=>'Accessories','connector'=>'Connector','other'=>'Other'] as $k=>$lab){
    $v=qspec_sanitize_component_value($lab,$d[$k]??'');
    if($v!=='') $arr[$k]=['label'=>$lab,'value'=>$v];
  }
  return json_encode($arr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function qspec_existing_row_for_product($pdo,$p){
  ensure_bom_quote_spec_schema($pdo);
  $model=trim((string)($p['code']??$p['model']??$p['model_no']??''));
  $nid=trim((string)($p['naming_id']??''));
  if($nid!==''){
    $r=row($pdo,"SELECT * FROM bom_quote_specs WHERE naming_id=? ORDER BY id DESC LIMIT 1",[$nid]);
    if($r) return $r;
  }
  if($model!==''){
    $r=row($pdo,"SELECT * FROM bom_quote_specs WHERE product_model=? ORDER BY id DESC LIMIT 1",[$model]);
    if($r) return $r;
  }
  return null;
}

function qspec_row_to_payload_rec($r){
  return [
    'id'=>$r['id']??'', 'naming_id'=>$r['naming_id']??'', 'product_model'=>$r['product_model']??'', 'product_name'=>$r['product_name']??'', 'product_image'=>$r['product_image']??'',
    'power'=>qspec_clean($r['power']??''), 'size'=>qspec_clean($r['size']??''), 'cutout'=>qspec_clean($r['cutout']??''), 'quote_spec'=>qspec_payload_from_row($r),
    'note'=>$r['note']??'', 'updated_at'=>$r['updated_at']??'', 'auto_generated'=>$r['auto_generated']??0, 'source_hash'=>$r['source_hash']??''
  ];
}
function qspec_apply_rec_to_product(&$p,$rec){
  if(!$rec) return false;
  foreach(['power','size','cutout'] as $k){ if(!empty($rec[$k])) $p[$k]=$rec[$k]; }
  if(empty($p['image']) && !empty($rec['product_image'])) $p['image']=$rec['product_image'];
  quote_v643_fix_product_dimensions($p);
  $p['bom_quote_spec_id']=$rec['id'];
  $p['quote_spec']=$rec['quote_spec'];
  $p['quote_spec_json']=json_encode($rec['quote_spec'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $p['quote_spec_source']='bom_quote_specs';
  $p['quote_spec_updated_at']=$rec['updated_at']??'';
  $p['quote_spec_auto_generated']=$rec['auto_generated']??0;
  return true;
}
function qspec_auto_sync_product($pdo,$p,$force=false){
  ensure_bom_quote_spec_schema($pdo);
  quote_v643_fix_product_dimensions($p);
  $model=trim((string)($p['code']??$p['model']??$p['model_no']??''));
  if($model==='') return ['ok'=>false,'msg'=>'缺少产品型号'];
  // V6.8.5.4：没有精确整灯 BOM 时，绝不读取/生成报价关键件。
  // 旧表 bom_quote_specs 里可能残留误生成记录（由物料名 52.08012... 生成），这里必须先拦截。
  $checkProduct=$p;
  $costMap=get_bom_cost_map($pdo);
  apply_bom_cost($checkProduct,$costMap);
  if(empty($checkProduct['bom_match'])){
    return ['ok'=>true,'created'=>false,'updated'=>false,'spec'=>null,'message'=>'没有精确整灯 BOM，不读取也不生成报价关键件'];
  }
  $existing=qspec_existing_row_for_product($pdo,$p);
  if($existing && !$force && empty($existing['auto_generated'])) return ['ok'=>true,'created'=>false,'updated'=>false,'spec'=>qspec_row_to_payload_rec($existing),'message'=>'已存在人工维护的报价关键件，直接读取'];
  $guess=qspec_guess_components_from_bom($pdo,$model);
  if($existing && !$force && !empty($existing['auto_generated']) && trim((string)($existing['source_hash']??''))===trim((string)($guess['source_hash']??''))){
    return ['ok'=>true,'created'=>false,'updated'=>false,'spec'=>qspec_row_to_payload_rec($existing),'message'=>'已存在当前 BOM 的自动报价关键件，直接读取'];
  }
  $hits=$guess['hits']??[];
  $data=[
    'naming_id'=>(string)($p['naming_id']??''),
    'product_model'=>$model,
    'product_name'=>(string)($p['name']??$model),
    'product_image'=>(string)($p['image']??''),
    'power'=>(string)($p['power']??''),
    'size'=>(string)($p['size']??''),
    'cutout'=>(string)($p['cutout']??''),
    'led'=>$hits['led']??'',
    'driver'=>$hits['driver']??'',
    'optic'=>$hits['optic']??'',
    'accessories'=>$hits['accessories']??'',
    'connector'=>$hits['connector']??'',
    'other'=>$hits['other']??'',
    'quote_spec_json'=>'',
    'note'=>'系统自动从命名系统+BOM提取；可在 bom_quote_spec.php 手动修正。',
    'auto_generated'=>1,
    'source_hash'=>$guess['source_hash']??'',
    'last_sync_at'=>date('Y-m-d H:i:s')
  ];
  $data['quote_spec_json']=qspec_spec_json_from_fields($data);
  $fields=['naming_id','product_model','product_name','product_image','power','size','cutout','led','driver','optic','accessories','connector','other','quote_spec_json','note','auto_generated','source_hash','last_sync_at'];
  if($existing){
    // 只有强制同步时才覆盖。人工维护的内容默认不覆盖。
    $sets=[];$vals=[];foreach($fields as $f){$sets[]="`$f`=?";$vals[]=$data[$f]??'';} $vals[]=$existing['id'];
    $pdo->prepare('UPDATE bom_quote_specs SET '.implode(',',$sets).' WHERE id=?')->execute($vals); $id=$existing['id']; $updated=true; $created=false;
  }else{
    $cols=$fields; $vals=[]; foreach($cols as $f)$vals[]=$data[$f]??'';
    $pdo->prepare('INSERT INTO bom_quote_specs (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute($vals);
    $id=$pdo->lastInsertId(); $updated=false; $created=true;
  }
  $r=row($pdo,'SELECT * FROM bom_quote_specs WHERE id=?',[$id]);
  return ['ok'=>true,'created'=>$created,'updated'=>$updated,'spec'=>qspec_row_to_payload_rec($r),'message'=>$created?'已自动生成报价关键件':'已强制同步报价关键件'];
}
function get_bom_quote_spec_map($pdo){
  ensure_bom_quote_spec_schema($pdo);
  $rs=rows($pdo,"SELECT * FROM bom_quote_specs ORDER BY updated_at DESC, id DESC");
  $map=[];
  foreach($rs as $r){
    $payload=qspec_payload_from_row($r);
    $rec=[
      'id'=>$r['id']??'', 'naming_id'=>$r['naming_id']??'', 'product_model'=>$r['product_model']??'', 'product_name'=>$r['product_name']??'', 'product_image'=>$r['product_image']??'',
      'power'=>qspec_clean($r['power']??''), 'size'=>qspec_clean($r['size']??''), 'cutout'=>qspec_clean($r['cutout']??''), 'quote_spec'=>$payload, 'note'=>$r['note']??'', 'updated_at'=>$r['updated_at']??''
    ];
    $keys=[];
    $model=trim((string)($r['product_model']??''));
    if($model!==''){
      $ex=bom_extract_model_codes_from_text($model);
      if($ex) $keys=array_merge($keys,$ex);
      else { $k=norm_key($model); if($k) $keys[]=$k; }
    }
    if(!empty($r['naming_id'])) $keys[]='NID:'.$r['naming_id'];
    foreach(array_unique($keys) as $k){ if($k && !isset($map[$k])) $map[$k]=$rec; }
  }
  return $map;
}

function find_bom_quote_spec_match($p,$specMap){
  $keys=[];
  foreach([$p['code']??'', $p['model']??'', $p['model_no']??'', $p['naming_model_no']??''] as $v){
    $ex=bom_extract_model_codes_from_text($v);
    if($ex) $keys=array_merge($keys,$ex);
    else { $k=norm_key($v); if($k) $keys[]=$k; }
  }
  if(!empty($p['naming_id'])) $keys[]='NID:'.$p['naming_id'];
  $keys=array_values(array_unique(array_filter($keys)));
  foreach($keys as $k){ if(isset($specMap[$k])) return $specMap[$k]; }
  return null;
}

function apply_bom_quote_spec(&$p,$specMap){
  // V6.8.5.4：报价关键件必须依附于“精确整灯 BOM”。
  // 当前产品没有精确 BOM 匹配时，旧 bom_quote_specs 残留也不能带出 LED/Optic/Driver。
  if(empty($p['bom_match'])){
    unset($p['bom_quote_spec_id'],$p['quote_spec'],$p['quote_spec_json'],$p['quote_spec_source'],$p['quote_spec_updated_at'],$p['quote_spec_auto_generated']);
    return false;
  }
  $hit=find_bom_quote_spec_match($p,$specMap);
  if(!$hit) return false;
  foreach(['power','size','cutout'] as $k){ if(!empty($hit[$k])) $p[$k]=$hit[$k]; }
  if(empty($p['image']) && !empty($hit['product_image'])) $p['image']=$hit['product_image'];
  quote_v643_fix_product_dimensions($p);
  $p['bom_quote_spec_id']=$hit['id'];
  $p['quote_spec']=$hit['quote_spec'];
  $p['quote_spec_json']=json_encode($hit['quote_spec'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $p['quote_spec_source']='bom_quote_specs';
  $p['quote_spec_updated_at']=$hit['updated_at']??'';
  return true;
}

function get_quote_products($pdo){
  if(!table_exists($pdo,'quote_products')) return [];
  $where=in_array('is_active',table_columns($pdo,'quote_products'))?'WHERE is_active=1 ':'';
  $rs=rows($pdo,"SELECT * FROM quote_products $where ORDER BY sort_order DESC,id DESC");
  foreach($rs as &$p){ $p['source']=$p['source']?:'quote'; $p['source_label']=$p['source_label']??'报价本地'; }
  return $rs;
}
function merged_quote_products($pdo){
  $costMap=get_bom_cost_map($pdo);
  $specMap=get_bom_quote_spec_map($pdo);
  $naming=get_naming_products($pdo);
  foreach($naming as &$p){ apply_bom_cost($p,$costMap); apply_bom_quote_spec($p,$specMap); }
  $local=get_quote_products($pdo);
  foreach($local as &$p){ apply_bom_cost($p,$costMap); apply_bom_quote_spec($p,$specMap); }
  $out=[]; $seen=[];
  foreach(array_merge($naming,$local) as $p){
    $k=norm_key(($p['code']??'')?:($p['name']??''));
    if(!$k) $k='ID'.($p['id']??count($out));
    if(isset($seen[$k])) continue; $seen[$k]=1; $out[]=$p;
  }
  return $out;
}

function ensure_table($pdo,$sql){ $pdo->exec($sql); }
function ensure_quote_log_schema($pdo){
  // 强制保证日志表结构完整。以前版本如果已经建过不完整的 quote_logs，CREATE IF NOT EXISTS 不会补字段，导致写日志失败但被静默忽略。

  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    updated_by VARCHAR(120) DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $rc=(int)$pdo->query("SELECT COUNT(*) FROM quote_system_settings WHERE setting_key='exchange_rate_usd'")->fetchColumn();
  if($rc===0){
    $st=$pdo->prepare("INSERT INTO quote_system_settings(setting_key,setting_value,updated_by) VALUES('exchange_rate_usd','7.0000','system')");
    $st->execute();
  }

  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $defs=[
    'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    'level'=>"VARCHAR(20) NOT NULL DEFAULT 'INFO'",
    'module'=>"VARCHAR(60) NOT NULL DEFAULT 'quotation'",
    'action'=>"VARCHAR(80) NOT NULL DEFAULT ''",
    'event'=>"VARCHAR(120) NOT NULL DEFAULT ''",
    'quote_id'=>"INT NULL",
    'quote_no'=>"VARCHAR(100) DEFAULT ''",
    'customer_id'=>"VARCHAR(100) DEFAULT ''",
    'customer_name'=>"VARCHAR(255) DEFAULT ''",
    'user_name'=>"VARCHAR(100) DEFAULT ''",
    'ip'=>"VARCHAR(80) DEFAULT ''",
    'user_agent'=>"VARCHAR(255) DEFAULT ''",
    'request_method'=>"VARCHAR(20) DEFAULT ''",
    'request_uri'=>"VARCHAR(255) DEFAULT ''",
    'summary'=>"TEXT NULL",
    'detail_json'=>"LONGTEXT NULL",
    'before_json'=>"LONGTEXT NULL",
    'after_json'=>"LONGTEXT NULL",
  ];
  $cols=table_columns($pdo,'quote_logs');
  foreach($defs as $col=>$def){
    if(!in_array($col,$cols,true)){
      try{ $pdo->exec("ALTER TABLE quote_logs ADD COLUMN `$col` $def"); }catch(Throwable $e){}
    }
  }
  foreach([
    'idx_created_at'=>'created_at',
    'idx_action'=>'action',
    'idx_quote_no'=>'quote_no',
    'idx_customer_name'=>'customer_name'
  ] as $idx=>$col){
    try{ $pdo->exec("ALTER TABLE quote_logs ADD KEY `$idx` (`$col`)"); }catch(Throwable $e){}
  }
}


/* V6.7.2：报价系统权限 + 共用 PLM 登录账号 */
function qperm_truthy($v){ $s=mb_strtolower(trim((string)$v),'UTF-8'); return in_array($s,['1','true','yes','y','on','启用','正常','active','enabled','是','允许','all'],true) || intval($v)===1; }
function qperm_pick_col($pdo,$t,$names){ $cols=table_columns($pdo,$t); foreach($names as $c){ if(in_array($c,$cols,true)) return $c; } return ''; }
function qperm_priority_tables(){
  return ['artdon_users','plm_users','office_users','users','system_users','sys_users','admin_users','app_users','profiles','dispatch_users','bom_users','crm_users'];
}
function qperm_table_is_account_like($t){
  $name=mb_strtolower((string)$t,'UTF-8');
  if($name==='') return false;
  // 不把登录日志、操作日志、邮箱账号、历史、权限、任务、通知等业务表当成账号表。
  if(preg_match('/(^|_)(log|logs|login_log|login_logs|history|histories|session|sessions|token|tokens|permission|permissions|notify|notification|notifications|task|tasks|todo|todos|quote|quotes|backup|backups|record|records|comment|comments|attachment|attachments|file|files|kv|cache|setting|settings)($|_)/i',$name)) return false;
  // 邮箱账号表不是系统登录账号，不能进入报价权限页。兼容 crm_mail_accounts、mail_accounts、email_accounts、imap/smtp 等命名。
  if(preg_match('/(mail|email|imap|smtp|mailbox|inbox|outbox|pop3|oauth|authorize|authorization)/i',$name)) return false;
  if(strpos($name,'log')!==false || strpos($name,'history')!==false || strpos($name,'session')!==false || strpos($name,'backup')!==false) return false;
  return preg_match('/(user|users|account|accounts|profile|profiles|member|members|staff|employee|admin)/i',$name)===1;
}
function qperm_table_has_password_col($pdo,$t){
  $cols=table_columns($pdo,$t);
  foreach(['password_hash','pass_hash','pwd_hash','hash','password','pass','pwd','user_pass','passwd'] as $c){
    if(in_array($c,$cols,true)) return true;
  }
  return false;
}
function qperm_user_tables($pdo){
  $priority=qperm_priority_tables();
  $tables=[];
  foreach($priority as $t){ if(table_exists($pdo,$t)) $tables[]=$t; }
  try{
    $rs=$pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('username','account','login_name','user_name','email','password','password_hash','pass','pwd') GROUP BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach($rs as $t){
      if(in_array($t,$tables,true)) continue;
      if(stripos($t,'quote_')===0) continue;
      if(!qperm_table_is_account_like($t)) continue;
      if(!qperm_table_has_password_col($pdo,$t)) continue;
      $tables[]=$t;
    }
  }catch(Throwable $e){}
  $out=[];
  foreach($tables as $t){
    $uc=qperm_pick_col($pdo,$t,['username','account','login_name','user_name','user','email','mobile','name']);
    if($uc==='') continue;
    $out[]=$t;
  }
  return $out;
}
function qperm_user_cols($pdo,$t){
  return [
    'id'=>qperm_pick_col($pdo,$t,['id','user_id','uid','account_id']),
    'username'=>qperm_pick_col($pdo,$t,['username','account','login_name','user_name','user','email','mobile','name']),
    'display'=>qperm_pick_col($pdo,$t,['real_name','display_name','fullname','full_name','nickname','nick','name','username','account','email']),
    'role'=>qperm_pick_col($pdo,$t,['role','role_name','user_role','type','level','position']),
    'status'=>qperm_pick_col($pdo,$t,['status','state','is_active','enabled','is_enabled','active']),
    'passwords'=>array_values(array_filter(array_map(fn($c)=>in_array($c,table_columns($pdo,$t),true)?$c:'',['password_hash','pass_hash','pwd_hash','hash','password','pass','pwd','user_pass','passwd'])))
  ];
}
function qperm_row_active($pdo,$t,$r){
  $c=qperm_user_cols($pdo,$t)['status']; if($c==='' || !array_key_exists($c,$r)) return true;
  $v=$r[$c]; if($v===null || $v==='') return true;
  if(in_array($c,['is_active','enabled','is_enabled','active'],true)) return intval($v)===1 || qperm_truthy($v);
  return in_array(mb_strtolower((string)$v,'UTF-8'),['active','enabled','启用','正常','1'],true);
}
function qperm_norm_user($pdo,$t,$r){
  $c=qperm_user_cols($pdo,$t);
  return [
    'user_table'=>$t,
    'user_id'=>(string)($c['id']!==''?($r[$c['id']]??''):($r['id']??'')),
    'username'=>(string)($c['username']!==''?($r[$c['username']]??''):''),
    'display_name'=>(string)($c['display']!==''?($r[$c['display']]??''):''),
    'role'=>(string)($c['role']!==''?($r[$c['role']]??''):''),
    'is_active'=>qperm_row_active($pdo,$t,$r)?1:0
  ];
}
function qperm_find_user($pdo,$username){
  $username=trim((string)$username); if($username==='') return null;
  foreach(qperm_user_tables($pdo) as $t){
    $c=qperm_user_cols($pdo,$t); $uc=$c['username']; if($uc==='') continue;
    try{ $st=$pdo->prepare("SELECT * FROM `$t` WHERE `$uc`=? LIMIT 1"); $st->execute([$username]); $r=$st->fetch(PDO::FETCH_ASSOC); }catch(Throwable $e){ $r=null; }
    if($r && qperm_row_active($pdo,$t,$r)){ $u=qperm_norm_user($pdo,$t,$r); if(qperm_is_hidden($pdo,$u)) continue; $u['_row']=$r; $u['_pwd_cols']=$c['passwords']; return $u; }
  }
  return null;
}
function qperm_verify_password($u,$password){
  $password=(string)$password; $r=$u['_row']??[]; $cols=$u['_pwd_cols']??[];
  if(!$cols) return false;
  foreach($cols as $c){
    $hash=(string)($r[$c]??''); if($hash==='') continue;
    if(password_verify($password,$hash)) return true;
    if(hash_equals($hash,$password)) return true;
    if(hash_equals(strtolower($hash),md5($password))) return true;
    if(hash_equals(strtolower($hash),sha1($password))) return true;
  }
  return false;
}
function qperm_is_admin($u){
  $name=mb_strtolower((string)($u['username']??''),'UTF-8'); $role=mb_strtolower((string)($u['role']??''),'UTF-8');
  if(in_array($name,['qiulei','qiulei6386','boss','admin','administrator','owner'],true)) return true;
  return str_contains($role,'boss') || str_contains($role,'admin') || str_contains($role,'owner') || str_contains($role,'管理员') || str_contains($role,'老板');
}
function qperm_default_perms($u){
  $keys=['can_access','quote_create','quote_edit','quote_review_view','quote_approve','quote_delete','history_view','customer_view','customer_manage','product_view','product_manage','material_view','doc_settings_manage','rate_manage','settings_manage','permission_manage','export_pdf_excel','order_convert','log_view','log_manage'];
  $all=[]; foreach($keys as $k) $all[$k]=0;
  if(qperm_is_admin($u)){ foreach($keys as $k) $all[$k]=1; return $all; }
  foreach(['can_access','quote_create','quote_edit','history_view','customer_view','customer_manage','product_view','material_view','doc_settings_manage','export_pdf_excel'] as $k) $all[$k]=1;
  return $all;
}
function ensure_quote_permission_schema($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_table VARCHAR(80) NOT NULL DEFAULT '',
    user_id VARCHAR(80) NOT NULL DEFAULT '',
    username VARCHAR(120) NOT NULL DEFAULT '',
    display_name VARCHAR(160) NOT NULL DEFAULT '',
    role VARCHAR(80) NOT NULL DEFAULT '',
    can_access TINYINT(1) NOT NULL DEFAULT 1,
    quote_create TINYINT(1) NOT NULL DEFAULT 1,
    quote_edit TINYINT(1) NOT NULL DEFAULT 1,
    quote_review_view TINYINT(1) NOT NULL DEFAULT 0,
    quote_approve TINYINT(1) NOT NULL DEFAULT 0,
    quote_delete TINYINT(1) NOT NULL DEFAULT 0,
    history_view TINYINT(1) NOT NULL DEFAULT 1,
    customer_view TINYINT(1) NOT NULL DEFAULT 1,
    customer_manage TINYINT(1) NOT NULL DEFAULT 1,
    product_view TINYINT(1) NOT NULL DEFAULT 1,
    product_manage TINYINT(1) NOT NULL DEFAULT 0,
    material_view TINYINT(1) NOT NULL DEFAULT 1,
    doc_settings_manage TINYINT(1) NOT NULL DEFAULT 1,
    rate_manage TINYINT(1) NOT NULL DEFAULT 0,
    settings_manage TINYINT(1) NOT NULL DEFAULT 0,
    permission_manage TINYINT(1) NOT NULL DEFAULT 0,
    export_pdf_excel TINYINT(1) NOT NULL DEFAULT 1,
    order_convert TINYINT(1) NOT NULL DEFAULT 0,
    log_view TINYINT(1) NOT NULL DEFAULT 0,
    log_manage TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(120) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quote_perm_user (user_table,user_id,username),
    KEY idx_quote_perm_username (username)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach(['doc_settings_manage'=>"TINYINT(1) NOT NULL DEFAULT 1",'rate_manage'=>"TINYINT(1) NOT NULL DEFAULT 0",'quote_review_view'=>"TINYINT(1) NOT NULL DEFAULT 0",'quote_approve'=>"TINYINT(1) NOT NULL DEFAULT 0"] as $qc=>$qd){ ensure_col($pdo,'quote_user_permissions',$qc,'`'.$qc.'` '.$qd); }
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_permission_hidden_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_table VARCHAR(80) NOT NULL DEFAULT '',
    user_id VARCHAR(80) NOT NULL DEFAULT '',
    username VARCHAR(120) NOT NULL DEFAULT '',
    display_name VARCHAR(160) NOT NULL DEFAULT '',
    hidden_by VARCHAR(120) NOT NULL DEFAULT '',
    hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uq_quote_hidden_user (user_table,user_id,username),
    KEY idx_quote_hidden_username (username)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function qperm_saved($pdo,$u){
  ensure_quote_permission_schema($pdo);
  $ut=(string)($u['user_table']??''); $uid=(string)($u['user_id']??''); $un=(string)($u['username']??'');
  $r=row($pdo,"SELECT * FROM quote_user_permissions WHERE user_table=? AND user_id=? AND username=? LIMIT 1",[$ut,$uid,$un]);
  if($r) return $r;
  // 账号来源清理后，同一账号可能从 dispatch_users / crm_users 合并到 artdon_users。
  // 这里按 username 兜底读取旧权限，避免去重后权限丢失。
  if($un!==''){
    $r=row($pdo,"SELECT * FROM quote_user_permissions WHERE username=? ORDER BY updated_at DESC,id DESC LIMIT 1",[$un]);
    if($r) return $r;
  }
  return null;
}
function qperm_central_user_id($u){
  $sid=(int)($_SESSION['artdon_user_id']??0); $sun=(string)($_SESSION['artdon_username']??'');
  if($sid>0 && $sun!=='' && strcasecmp($sun,(string)($u['username']??''))===0) return $sid;
  try{ if(!empty($u['username']) && function_exists('artdon_sso_user_by_username')){ $cu=artdon_sso_user_by_username((string)$u['username']); if($cu)return (int)$cu['id']; } }catch(Throwable $e){}
  return 0;
}
function qperm_effective($pdo,$u){
  $p=qperm_default_perms($u); $r=qperm_saved($pdo,$u);
  if($r){ foreach($p as $k=>$v){ if(array_key_exists($k,$r)) $p[$k]=intval($r[$k])?1:0; } }

  // V6.8.4.12：先按 users.php 统一权限中心六大权限兜底。
  // 修改报价 = 可维护表头/银行/付款条款；管理/设置 = 可维护汇率/权限/系统设置。
  if(!empty($p['quote_edit']) || !empty($p['settings_manage'])) $p['doc_settings_manage']=1;
  if(!empty($p['settings_manage'])) $p['rate_manage']=1;

  if(qperm_is_admin($u)){ foreach($p as $k=>$v) $p[$k]=1; }
  // 第三阶段：报价模块在统一权限中心保存过后，以每个账号的中央细分权限为准；没有保存的新细分项继续按上面的六大权限兜底。
  try{
    $cid=qperm_central_user_id($u);
    if($cid>0 && function_exists('artdon_perm_module_has_explicit') && artdon_perm_module_has_explicit($cid,'quote')){
      $fm=artdon_perm_effective_feature_map($cid,'quote',artdon_sso_user_by_id($cid));
      foreach($p as $k=>$v){ if(array_key_exists($k,$fm))$p[$k]=!empty($fm[$k])?1:0; }
      if(!array_key_exists('doc_settings_manage',$fm)) $p['doc_settings_manage']=(!empty($p['quote_edit'])||!empty($p['settings_manage']))?1:0;
      if(!array_key_exists('rate_manage',$fm)) $p['rate_manage']=!empty($p['settings_manage'])?1:0;
      if(!array_key_exists('quote_review_view',$fm)) $p['quote_review_view']=!empty($p['settings_manage'])||!empty($p['permission_manage'])?1:0;
      if(!array_key_exists('quote_approve',$fm)) $p['quote_approve']=!empty($p['settings_manage'])||!empty($p['permission_manage'])?1:0;
    }
  }catch(Throwable $e){}
  // 银行/抬头/条款属于报价文档配置：有报价修改权或系统设置权时，必须允许保存，避免前端能编辑但接口被挡。
  if(!empty($p['quote_edit']) || !empty($p['settings_manage'])) $p['doc_settings_manage']=1;
  if(!empty($p['settings_manage'])) $p['rate_manage']=1;
  return $p;
}
function qperm_public_user($pdo,$u){ $p=qperm_effective($pdo,$u); return ['user_table'=>$u['user_table']??'','user_id'=>(string)($u['user_id']??''),'username'=>$u['username']??'','display_name'=>$u['display_name']??($u['username']??''),'role'=>$u['role']??'','is_admin'=>qperm_is_admin($u)?1:0,'permissions'=>$p]; }
function qperm_is_hidden($pdo,$u){
  ensure_quote_permission_schema($pdo);
  $ut=(string)($u['user_table']??''); $uid=(string)($u['user_id']??''); $un=(string)($u['username']??'');
  if($un==='') return false;
  $r=row($pdo,"SELECT id FROM quote_permission_hidden_accounts WHERE user_table=? AND user_id=? AND username=? LIMIT 1",[$ut,$uid,$un]);
  return $r?true:false;
}
function qperm_current_user($pdo){
  $u=$_SESSION['quote_user']??null;
  if(is_array($u) && !empty($u['username'])){ $fresh=qperm_find_user($pdo,$u['username']); if($fresh) return $fresh; return $u; }
  foreach(['office_user_id','plm_user_id','user_id','bom_user_id','crm_user_id'] as $sid){
    if(empty($_SESSION[$sid])) continue; $id=(string)$_SESSION[$sid];
    foreach(qperm_user_tables($pdo) as $t){ $c=qperm_user_cols($pdo,$t); if($c['id']==='') continue; try{ $st=$pdo->prepare("SELECT * FROM `$t` WHERE `{$c['id']}`=? LIMIT 1"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); }catch(Throwable $e){ $r=null; }
      if($r && qperm_row_active($pdo,$t,$r)){ $u2=qperm_norm_user($pdo,$t,$r); if(qperm_is_hidden($pdo,$u2)) continue; return $u2; }
    }
  }
  return null;
}
function qperm_login($pdo,$username,$password){
  $u=qperm_find_user($pdo,$username); if(!$u || !qperm_verify_password($u,$password)) fail('账号或密码不正确。请使用 PLM/统一账号登录。');
  unset($u['_row'],$u['_pwd_cols']); $_SESSION['quote_user']=$u; $_SESSION['quote_permissions']=qperm_effective($pdo,$u);
  quote_log_event($pdo,['action'=>'login','event'=>'报价系统登录','user_name'=>$u['username'],'summary'=>'用户登录报价系统：'.$u['username'],'detail'=>['user'=>$u]]);
  return qperm_public_user($pdo,$u);
}
function qperm_require($pdo,$perm='can_access'){
  $u=qperm_current_user($pdo); if(!$u) fail_auth('请先用 PLM/统一账号登录报价系统');
  $p=qperm_effective($pdo,$u); $_SESSION['quote_user']=$u; $_SESSION['quote_permissions']=$p;
  if(empty($p['can_access'])) fail_auth('当前账号没有报价系统访问权限');
  if($perm && empty($p[$perm])) fail('当前账号没有权限：'.$perm);
  return [$u,$p];
}
function qperm_action_perm($action){
  $map=[
    'init'=>'can_access','list_bom_quote_specs'=>'product_view','ensure_bom_quote_spec'=>'product_view','sync_bom_quote_spec'=>'product_manage','save_bom_quote_spec'=>'product_manage','delete_bom_quote_spec'=>'product_manage',
    'sync_crm_customers'=>'customer_view','align_crm_customers'=>'customer_manage','batch_delete_customers'=>'customer_manage','clean_stale_crm_customers'=>'customer_manage','save_customer'=>'customer_manage','delete_customer'=>'customer_manage','save_product'=>'product_manage','delete_product'=>'product_manage','bom_debug'=>'material_view',
    'create_backup'=>'settings_manage','list_backups'=>'settings_manage','download_backup'=>'settings_manage','restore_backup'=>'settings_manage','save_header'=>'doc_settings_manage','delete_header'=>'doc_settings_manage','save_bank'=>'doc_settings_manage','delete_bank'=>'doc_settings_manage','save_template'=>'doc_settings_manage','delete_template'=>'doc_settings_manage','save_exchange_rate'=>'rate_manage','save_price_level'=>'settings_manage','delete_price_level'=>'settings_manage','save_option'=>'settings_manage','delete_option'=>'settings_manage',
    'price_policy_list'=>'product_view','price_policy_match'=>'product_view','price_tier_list'=>'product_view','price_policy_levels_list'=>'product_view','price_stock_log_list'=>'product_view','price_policy_options_list'=>'product_view',
    'commission_rule_list'=>'product_view','commission_options_list'=>'product_view','commission_calc_preview'=>'product_view','commission_rule_export'=>'product_view','commission_order_list'=>'product_view','commission_quote_list'=>'product_view','commission_quote_save'=>'product_view','commission_quote_lines_save'=>'product_view','commission_customer_check'=>'can_access','commission_reminder_list'=>'product_view','commission_reminder_save'=>'product_view','commission_reminder_toggle'=>'product_view',
    'price_policy_export_excel'=>'product_view','price_policy_import_excel'=>'product_manage',
    'price_policy_save'=>'product_manage','price_policy_batch_save'=>'product_manage','price_stock_adjust'=>'product_manage','price_policy_delete'=>'product_manage','price_policy_sync_naming_products'=>'product_manage','price_policy_sync_bom_costs'=>'product_manage','price_tier_save'=>'product_manage','price_tier_delete'=>'product_manage','price_policy_level_save'=>'product_manage','price_policy_level_delete'=>'product_manage','price_policy_option_save'=>'product_manage','price_policy_option_delete'=>'product_manage','price_policy_option_toggle'=>'product_manage','price_policy_option_sort'=>'product_manage','price_policy_options_init_defaults'=>'product_manage',
    'commission_rule_save'=>'product_manage','commission_rule_batch_save'=>'product_manage','commission_rule_delete'=>'product_manage','commission_rule_toggle'=>'product_manage','commission_rule_import'=>'product_manage','commission_option_save'=>'product_manage','commission_option_delete'=>'product_manage','commission_option_toggle'=>'product_manage','commission_options_init_defaults'=>'product_manage','commission_order_save'=>'product_manage','commission_order_batch_save'=>'product_manage','commission_item_save'=>'product_manage','commission_item_batch_save'=>'product_manage',
    'save_quote'=>'quote_edit','get_approved_quote_snapshot'=>'export_pdf_excel','push_order_crm_notice'=>'order_convert','list_pending_quotes'=>'quote_review_view','approve_quote'=>'quote_approve','reject_quote'=>'quote_approve','unapprove_quote'=>'quote_approve','delete_quote'=>'quote_delete','list_logs'=>'log_view','log_health'=>'log_view','delete_logs'=>'log_manage','log_event'=>'can_access','list_permission_users'=>'permission_manage','save_user_permission'=>'permission_manage','reset_user_permission'=>'permission_manage','delete_permission_user'=>'permission_manage','void_sales_order'=>'settings_manage','delete_test_order'=>'settings_manage'
  ];
  return $map[$action]??'can_access';
}
function qperm_user_table_rank($t){
  $priority=qperm_priority_tables();
  $i=array_search($t,$priority,true);
  return $i===false?999:$i;
}
function qperm_merge_user_item($pdo,$u){
  $pub=qperm_public_user($pdo,$u);
  $pub['saved']=qperm_saved($pdo,$u)?1:0;
  $pub['sources']=[$u['user_table']??''];
  $pub['source_count']=1;
  return $pub;
}
function qperm_list_users($pdo){
  ensure_quote_permission_schema($pdo);
  $bucket=[];
  foreach(qperm_user_tables($pdo) as $t){
    $c=qperm_user_cols($pdo,$t); if($c['username']==='') continue; $order=$c['id']?:$c['username'];
    try{ $rs=rows($pdo,"SELECT * FROM `$t` ORDER BY `$order` ASC LIMIT 1000"); }catch(Throwable $e){ $rs=[]; }
    foreach($rs as $r){
      if(!qperm_row_active($pdo,$t,$r)) continue;
      $u=qperm_norm_user($pdo,$t,$r);
      if(qperm_is_hidden($pdo,$u)) continue;
      $un=trim((string)$u['username']); if($un==='') continue;
      $key=mb_strtolower($un,'UTF-8');
      $rank=qperm_user_table_rank($u['user_table']??'');
      if(!isset($bucket[$key])){
        $bucket[$key]=['rank'=>$rank,'u'=>$u,'sources'=>[$u['user_table']??'']];
      }else{
        if(!in_array($u['user_table']??'', $bucket[$key]['sources'], true)) $bucket[$key]['sources'][]=$u['user_table']??'';
        $cur=$bucket[$key]['u'];
        $curRank=(int)$bucket[$key]['rank'];
        // 优先 PLM/统一账号表；同级时优先有显示名、角色信息的记录。
        $better=$rank<$curRank;
        if($rank===$curRank){
          $curScore=(trim((string)($cur['display_name']??''))!==''?1:0)+(trim((string)($cur['role']??''))!==''?1:0);
          $newScore=(trim((string)($u['display_name']??''))!==''?1:0)+(trim((string)($u['role']??''))!==''?1:0);
          $better=$newScore>$curScore;
        }
        if($better){ $bucket[$key]['u']=$u; $bucket[$key]['rank']=$rank; }
      }
    }
  }
  uasort($bucket,function($a,$b){
    if($a['rank']!==$b['rank']) return $a['rank']<=>$b['rank'];
    return strcmp((string)($a['u']['username']??''),(string)($b['u']['username']??''));
  });
  $out=[];
  foreach($bucket as $item){
    $pub=qperm_merge_user_item($pdo,$item['u']);
    $pub['sources']=array_values(array_unique(array_filter($item['sources'])));
    $pub['source_count']=count($pub['sources']);
    $out[]=$pub;
  }
  return $out;
}
function qperm_save_user_permission($pdo,$d,$actor){
  ensure_quote_permission_schema($pdo);
  $keys=['can_access','quote_create','quote_edit','quote_review_view','quote_approve','quote_delete','history_view','customer_view','customer_manage','product_view','product_manage','material_view','doc_settings_manage','rate_manage','settings_manage','permission_manage','export_pdf_excel','order_convert','log_view','log_manage'];
  $ut=(string)($d['user_table']??''); $uid=(string)($d['user_id']??''); $un=(string)($d['username']??''); if($un==='') fail('缺少账号');
  $vals=[]; foreach($keys as $k) $vals[$k]=!empty($d[$k])?1:0;
  $cols='user_table,user_id,username,display_name,role,'.implode(',',array_keys($vals)).',updated_by';
  $qs=implode(',',array_fill(0,6+count($vals),'?'));
  $params=array_merge([$ut,$uid,$un,(string)($d['display_name']??''),(string)($d['role']??'')],array_values($vals),[$actor['username']??'']);
  $updates="display_name=VALUES(display_name),role=VALUES(role),".implode(',',array_map(fn($k)=>"$k=VALUES($k)",array_keys($vals))).",updated_by=VALUES(updated_by),updated_at=NOW()";
  $pdo->prepare("INSERT INTO quote_user_permissions ($cols) VALUES ($qs) ON DUPLICATE KEY UPDATE $updates")->execute($params);
  // 旧报价权限页保存时同步统一权限中心，避免两套权限互相覆盖。
  try{
    if(function_exists('artdon_sso_user_by_username') && function_exists('artdon_perm_write_feature_map')){
      $cu=artdon_sso_user_by_username($un);
      if($cu){
        $caps=array(
          'view'=>!empty($vals['can_access'])?1:0,
          'create'=>(!empty($vals['quote_create'])||!empty($vals['order_convert']))?1:0,
          'edit'=>(!empty($vals['quote_edit'])||!empty($vals['customer_manage'])||!empty($vals['product_manage']))?1:0,
          'delete'=>!empty($vals['quote_delete'])?1:0,
          'export'=>!empty($vals['export_pdf_excel'])?1:0,
          'admin'=>(!empty($vals['quote_review_view'])||!empty($vals['quote_approve'])||!empty($vals['settings_manage'])||!empty($vals['permission_manage'])||!empty($vals['log_manage']))?1:0
        );
        artdon_perm_write_module_row((int)$cu['id'],'quote',$caps,'inherit',array(),(string)($actor['username']??''));
        artdon_perm_write_feature_map((int)$cu['id'],'quote',$vals,(string)($actor['username']??''));
      }
    }
  }catch(Throwable $e){}
  quote_log_event($pdo,['action'=>'save_user_permission','event'=>'保存报价权限','user_name'=>$actor['username']??'','summary'=>'保存账号权限：'.$un,'detail'=>$d]);
}
function qperm_delete_permission_user($pdo,$d,$actor){
  ensure_quote_permission_schema($pdo);
  $ut=(string)($d['user_table']??''); $uid=(string)($d['user_id']??''); $un=trim((string)($d['username']??''));
  if($un==='') fail('缺少账号');
  if(mb_strtolower($un,'UTF-8')===mb_strtolower((string)($actor['username']??''),'UTF-8')) fail('不能删除当前登录账号');
  $display=(string)($d['display_name']??'');
  $pdo->prepare('INSERT INTO quote_permission_hidden_accounts(user_table,user_id,username,display_name,hidden_by,note) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),hidden_by=VALUES(hidden_by),hidden_at=NOW(),note=VALUES(note)')
      ->execute([$ut,$uid,$un,$display,(string)($actor['username']??''),'从报价权限页删除/隐藏']);
  $pdo->prepare('DELETE FROM quote_user_permissions WHERE user_table=? AND user_id=? AND username=?')->execute([$ut,$uid,$un]);
  quote_log_event($pdo,['action'=>'delete_permission_user','event'=>'删除/隐藏报价账号','user_name'=>$actor['username']??'','summary'=>'从报价权限页删除/隐藏账号：'.$un,'detail'=>$d]);
}

/* ===== 价格策略中心 / MOQ & Tier Price（第 2 步：独立数据与接口） ===== */
function ensure_quote_price_policy_schema($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_policies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_source VARCHAR(50) DEFAULT '',
    product_id VARCHAR(120) DEFAULT '',
    naming_id VARCHAR(120) DEFAULT '',
    product_model VARCHAR(120) DEFAULT '',
    product_name VARCHAR(255) DEFAULT '',
    series VARCHAR(160) DEFAULT '',
    lamp_type VARCHAR(120) DEFAULT '',
    category VARCHAR(160) DEFAULT '',
    image LONGTEXT NULL,
    has_stock TINYINT(1) DEFAULT 0,
    stock_qty DECIMAL(12,2) DEFAULT 0,
    moq DECIMAL(12,2) DEFAULT 0,
    allow_below_moq TINYINT(1) DEFAULT 0,
    need_approval_below_moq TINYINT(1) DEFAULT 1,
    lead_time VARCHAR(120) DEFAULT '',
    currency VARCHAR(20) DEFAULT 'USD',
    price_mode VARCHAR(50) DEFAULT 'manual',
    level_id INT DEFAULT 0,
    base_cost DECIMAL(12,4) DEFAULT 0,
    base_price DECIMAL(12,4) DEFAULT 0,
    status VARCHAR(30) DEFAULT 'active',
    note TEXT NULL,
    created_by VARCHAR(120) DEFAULT '',
    updated_by VARCHAR(120) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_price_policy_model (product_model),
    KEY idx_price_policy_name (product_name),
    KEY idx_price_policy_naming (naming_id),
    KEY idx_price_policy_series (series),
    KEY idx_price_policy_lamp_type (lamp_type),
    KEY idx_price_policy_category (category),
    KEY idx_price_policy_stock (has_stock),
    KEY idx_price_policy_moq (moq),
    KEY idx_price_policy_mode (price_mode),
    KEY idx_price_policy_status (status),
    KEY idx_price_policy_level (level_id),
    KEY idx_price_policy_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_col($pdo,'quote_price_policies','lamp_type',"`lamp_type` VARCHAR(120) DEFAULT '' AFTER `series`");
  ensure_col($pdo,'quote_price_policies','source_lamp_type',"`source_lamp_type` VARCHAR(120) DEFAULT '' AFTER `series`");
  ensure_col($pdo,'quote_price_policies','source_category',"`source_category` VARCHAR(160) DEFAULT '' AFTER `lamp_type`");
  ensure_col($pdo,'quote_price_policies','stock_status_code',"`stock_status_code` VARCHAR(80) DEFAULT '' AFTER `stock_qty`");
  ensure_col($pdo,'quote_price_policies','moq_status_code',"`moq_status_code` VARCHAR(80) DEFAULT '' AFTER `moq`");
  ensure_col($pdo,'quote_price_policies','below_moq_rule_code',"`below_moq_rule_code` VARCHAR(80) DEFAULT '' AFTER `need_approval_below_moq`");
  ensure_col($pdo,'quote_price_policies','bom_cost_rmb',"`bom_cost_rmb` DECIMAL(12,4) DEFAULT 0 AFTER `level_id`");
  ensure_col($pdo,'quote_price_policies','estimated_sale_price_rmb',"`estimated_sale_price_rmb` DECIMAL(12,4) DEFAULT 0 AFTER `bom_cost_rmb`");
  ensure_col($pdo,'quote_price_policies','bom_cost_source',"`bom_cost_source` VARCHAR(160) DEFAULT '' AFTER `estimated_sale_price_rmb`");
  ensure_col($pdo,'quote_price_policies','bom_match_key',"`bom_match_key` VARCHAR(160) DEFAULT '' AFTER `bom_cost_source`");
  ensure_col($pdo,'quote_price_policies','bom_cost_updated_at',"`bom_cost_updated_at` VARCHAR(50) DEFAULT '' AFTER `bom_match_key`");
  static $pricePolicyIndexesReady=false;
  if(!$pricePolicyIndexesReady){
    $pricePolicyIndexesReady=true;
    $existing=[];foreach(rows($pdo,'SHOW INDEX FROM quote_price_policies') as $indexRow)$existing[(string)$indexRow['Key_name']]=1;
    $wanted=[
      'idx_price_policy_name'=>'product_name','idx_price_policy_series'=>'series','idx_price_policy_lamp_type'=>'lamp_type',
      'idx_price_policy_stock'=>'has_stock','idx_price_policy_moq'=>'moq','idx_price_policy_mode'=>'price_mode','idx_price_policy_updated'=>'updated_at'
    ];
    foreach($wanted as $indexName=>$column)if(!isset($existing[$indexName]))$pdo->exec("ALTER TABLE quote_price_policies ADD KEY `".$indexName."` (`".$column."`)");
  }
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    min_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    manual_price DECIMAL(12,4) DEFAULT NULL,
    auto_price DECIMAL(12,4) DEFAULT NULL,
    final_price DECIMAL(12,4) DEFAULT NULL,
    currency VARCHAR(20) DEFAULT 'USD',
    source VARCHAR(50) DEFAULT 'manual',
    note VARCHAR(255) DEFAULT '',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_price_tier_policy (policy_id),
    KEY idx_price_tier_qty (policy_id,min_qty),
    KEY idx_price_tier_source (source)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_policy_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level_name VARCHAR(120) NOT NULL,
    level_code VARCHAR(50) DEFAULT '',
    base_multiplier DECIMAL(10,4) DEFAULT 1.35,
    point_percent DECIMAL(10,4) DEFAULT 0,
    profit_percent DECIMAL(10,4) DEFAULT 0,
    sample_multiplier DECIMAL(10,4) DEFAULT 2.00,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    note VARCHAR(255) DEFAULT '',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_policy_level_active (is_active),
    KEY idx_policy_level_sort (sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_stock_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    policy_id INT NOT NULL,
    product_model VARCHAR(120) DEFAULT '',
    product_name VARCHAR(255) DEFAULT '',
    old_stock DECIMAL(12,2) DEFAULT 0,
    new_stock DECIMAL(12,2) DEFAULT 0,
    change_qty DECIMAL(12,2) DEFAULT 0,
    adjust_type VARCHAR(30) DEFAULT '',
    reason VARCHAR(500) DEFAULT '',
    operator VARCHAR(120) DEFAULT '',
    ip VARCHAR(80) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_price_stock_policy (policy_id,created_at),
    KEY idx_price_stock_model (product_model),
    KEY idx_price_stock_time (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_policy_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    option_group VARCHAR(80) NOT NULL,
    option_code VARCHAR(120) DEFAULT '',
    option_name VARCHAR(160) NOT NULL,
    option_value VARCHAR(255) DEFAULT '',
    extra_json LONGTEXT NULL,
    is_system TINYINT(1) DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    note TEXT NULL,
    created_by VARCHAR(120) DEFAULT '',
    updated_by VARCHAR(120) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_price_option_group_code (option_group,option_code),
    KEY idx_price_option_group (option_group,is_active,sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $count=(int)$pdo->query("SELECT COUNT(*) FROM quote_price_policy_levels")->fetchColumn();
  if($count===0){
    $st=$pdo->prepare("INSERT INTO quote_price_policy_levels(level_name,level_code,base_multiplier,point_percent,profit_percent,sample_multiplier,is_default,is_active,note,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?)");
    foreach([
      ['A级常规品','A',1.35,0,0,2.00,1,1,'默认常规产品等级',10],
      ['B级复杂品','B',1.50,0,0,2.00,0,1,'复杂产品等级',20],
      ['C级定制品','C',1.80,0,0,2.00,0,1,'定制产品等级',30],
      ['现货品','STOCK',1.20,0,0,2.00,0,1,'现货产品等级',40],
      ['样品价','SAMPLE',2.00,0,0,2.00,0,1,'样品价格等级',50],
    ] as $r){ $st->execute($r); }
  }
  quote_price_policy_options_init_defaults($pdo,null,false);
}
function quote_price_policy_actor($user){ return s($user['username']??($user['display_name']??''),120); }
function quote_price_policy_option_groups(){return ['product_level','price_mode','lead_time','tier_qty','currency','stock_status','moq_status','below_moq_rule','approval_reason','category_mapping'];}
function quote_price_policy_option_extra($row){$v=json_decode((string)($row['extra_json']??''),true);return is_array($v)?$v:[];}
function quote_price_policy_options_init_defaults($pdo,$user=null,$writeLog=true){
  $defaults=[
    'price_mode'=>[
      ['manual','手填阶梯价','manual',['description'=>'只使用手动阶梯价'],1,1,10],
      ['level_auto','等级自动生成','level_auto',['description'=>'按产品等级倍率自动生成'],1,0,20],
      ['mixed','自动生成 + 手动覆盖','mixed',['description'=>'自动生成，可逐档手动覆盖'],1,0,30],
    ],
    'lead_time'=>[
      ['stock','现货','现货',['min_days'=>0,'max_days'=>0,'is_stock'=>1],1,1,10],['3_5','3-5天','3-5天',['min_days'=>3,'max_days'=>5],1,0,20],
      ['7_10','7-10天','7-10天',['min_days'=>7,'max_days'=>10],1,0,30],['15_20','15-20天','15-20天',['min_days'=>15,'max_days'=>20],1,0,40],
      ['20_30','20-30天','20-30天',['min_days'=>20,'max_days'=>30],1,0,50],['25_35','25-35天','25-35天',['min_days'=>25,'max_days'=>35],1,0,60],
      ['45','45天','45天',['min_days'=>45,'max_days'=>45],1,0,70],['custom','自定义','自定义',[],1,0,80],
    ],
    'tier_qty'=>array_map(fn($q)=>[(string)$q,(string)$q,(string)$q,['description'=>$q.' PCS','default_show'=>1],1,$q===1?1:0,$q],[1,10,50,100,300,500,1000]),
    'currency'=>[
      ['USD','USD','USD',['currency_name'=>'美元','symbol'=>'$','exchange_rate'=>1],1,1,10],
      ['RMB','RMB','RMB',['currency_name'=>'人民币','symbol'=>'¥','exchange_rate'=>1],1,0,20],
      ['EUR','EUR','EUR',['currency_name'=>'欧元','symbol'=>'€','exchange_rate'=>1],1,0,30],
    ],
    'stock_status'=>[
      ['in_stock','有库存','有库存',['color'=>'#16a34a'],1,0,10],['no_stock','无库存','无库存',['color'=>'#94a3b8'],1,1,20],
      ['partial','部分库存','部分库存',['color'=>'#f59e0b'],1,0,30],['clearance','清仓库存','清仓库存',['color'=>'#ef4444'],1,0,40],['pending','待确认','待确认',['color'=>'#64748b'],1,0,50],
    ],
    'moq_status'=>[
      ['unset','未设置','未设置',['color'=>'#94a3b8'],1,1,10],['set','已设置','已设置',['color'=>'#2563eb'],1,0,20],
      ['approval','低于 MOQ 需审批','低于 MOQ 需审批',['color'=>'#f59e0b'],1,0,30],['small_allowed','允许小单','允许小单',['color'=>'#16a34a'],1,0,40],['small_forbidden','禁止小单','禁止小单',['color'=>'#ef4444'],1,0,50],
    ],
    'below_moq_rule'=>[
      ['warn','只提醒，不拦截','warn',['need_approval'=>0,'allow_save'=>1,'log'=>0],1,1,10],
      ['approval','需要特批','approval',['need_approval'=>1,'allow_save'=>1,'log'=>1],1,0,20],
      ['forbid','禁止保存','forbid',['need_approval'=>0,'allow_save'=>0,'log'=>1],1,0,30],
      ['allow_log','允许但记录日志','allow_log',['need_approval'=>0,'allow_save'=>1,'log'=>1],1,0,40],
    ],
    'approval_reason'=>[
      ['sample_test','客户样品测试','sample_test',[],1,0,10],['old_customer','老客户小单','old_customer',[],1,0,20],
      ['clearance','库存清仓','clearance',[],1,0,30],['boss','老板特批','boss',[],1,0,40],
      ['project_sample','项目打样','project_sample',[],1,0,50],['replenishment','补单','replenishment',[],1,0,60],['other','其它','other',[],1,0,70],
    ],
  ];
  $insert=$pdo->prepare("INSERT IGNORE INTO quote_price_policy_options(option_group,option_code,option_name,option_value,extra_json,is_system,is_default,is_active,sort_order,note,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
  $actor=$user?quote_price_policy_actor($user):'system';$added=0;
  foreach($defaults as $group=>$items)foreach($items as $r){$insert->execute([$group,$r[0],$r[1],$r[2],json_encode($r[3],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$r[4],(int)$r[5],1,(int)$r[6],'',$actor,$actor]);$added+=$insert->rowCount();}
  $linkedLevels=[];foreach(rows($pdo,"SELECT option_value,extra_json FROM quote_price_policy_options WHERE option_group='product_level'") as $existing){$ee=quote_price_policy_option_extra($existing);$linkedLevels[(int)($ee['legacy_level_id']??$existing['option_value']??0)]=1;}
  foreach(rows($pdo,'SELECT * FROM quote_price_policy_levels ORDER BY sort_order,id') as $level){
    if(isset($linkedLevels[(int)$level['id']]))continue;
    $code=trim((string)$level['level_code'])?:('LEVEL_'.$level['id']);$extra=['legacy_level_id'=>(int)$level['id'],'base_multiplier'=>(float)$level['base_multiplier'],'point_percent'=>(float)$level['point_percent'],'profit_percent'=>(float)$level['profit_percent']];
    $insert->execute(['product_level',$code,$level['level_name'],(string)$level['id'],json_encode($extra,JSON_UNESCAPED_UNICODE),(int)0,(int)$level['is_default'],(int)$level['is_active'],(int)$level['sort_order'],$level['note']??'',$actor,$actor]);$added+=$insert->rowCount();
  }
  if($writeLog&&$added)quote_log_event($pdo,['action'=>'price_policy_options_init_defaults','event'=>'初始化价格策略基础数据','user_name'=>$actor,'summary'=>'初始化价格策略选项 '.$added.' 项','detail'=>['added'=>$added]]);
  return ['added'=>$added];
}
function quote_price_policy_mode($v){$v=trim((string)$v);return $v===''?'manual':$v;}
function quote_price_tier_source($v){ $v=trim((string)$v); return in_array($v,['manual','level_auto','manual_override','approval'],true)?$v:'manual'; }
function quote_nullable_decimal($v){ return ($v===''||$v===null)?null:(is_numeric($v)?(float)$v:null); }
function quote_price_policy_options_list($pdo){
  ensure_quote_price_policy_schema($pdo);$group=trim((string)($_GET['option_group']??''));
  $args=[];$where='';if($group!==''){if(!in_array($group,quote_price_policy_option_groups(),true))fail('无效的设置分组');$where=' WHERE option_group=?';$args[]=$group;}
  $options=rows($pdo,'SELECT * FROM quote_price_policy_options'.$where.' ORDER BY option_group,is_active DESC,is_default DESC,sort_order ASC,id ASC',$args);
  foreach($options as &$o)$o['extra']=quote_price_policy_option_extra($o);unset($o);
  return ['options'=>$options,'groups'=>quote_price_policy_option_groups()];
}
function quote_price_policy_option_used($pdo,$o){
  $g=$o['option_group']??'';$code=(string)($o['option_code']??'');$value=(string)($o['option_value']??'');
  if($g==='product_level')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE level_id=?',[(int)$value])['c'];
  if($g==='price_mode')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE price_mode=?',[$code])['c'];
  if($g==='lead_time')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE lead_time=?',[$value])['c'];
  if($g==='currency')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE currency=?',[$code])['c'];
  if($g==='stock_status')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE stock_status_code=?',[$code])['c'];
  if($g==='moq_status')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE moq_status_code=?',[$code])['c'];
  if($g==='below_moq_rule')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies WHERE below_moq_rule_code=?',[$code])['c'];
  if($g==='tier_qty')return (int)row($pdo,'SELECT COUNT(*) c FROM quote_price_tiers WHERE min_qty=?',[(float)$value])['c'];
  return 0;
}
function quote_price_policy_level_sync_from_option($pdo,$id,$d,$extra){
  $legacyId=(int)($extra['legacy_level_id']??$d['option_value']??0);
  $level=['level_name'=>$d['option_name'],'level_code'=>$d['option_code'],'base_multiplier'=>(float)($extra['base_multiplier']??1.35),'point_percent'=>(float)($extra['point_percent']??0),'profit_percent'=>(float)($extra['profit_percent']??0),'sample_multiplier'=>(float)($extra['sample_multiplier']??2),'is_default'=>(int)$d['is_default'],'is_active'=>(int)$d['is_active'],'note'=>$d['note'],'sort_order'=>(int)$d['sort_order']];
  if($legacyId>0)$level['id']=$legacyId;
  if($level['is_default'])$pdo->exec('UPDATE quote_price_policy_levels SET is_default=0');
  $legacyId=(int)save_row($pdo,'quote_price_policy_levels',$level,['level_name','level_code','base_multiplier','point_percent','profit_percent','sample_multiplier','is_default','is_active','note','sort_order']);
  $extra['legacy_level_id']=$legacyId;$pdo->prepare('UPDATE quote_price_policy_options SET option_value=?,extra_json=? WHERE id=?')->execute([(string)$legacyId,json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
  foreach(rows($pdo,'SELECT * FROM quote_price_policies WHERE level_id=?',[$legacyId]) as $p){quote_price_policy_recalculate_tiers($pdo,(int)$p['id']);$pdo->prepare('UPDATE quote_price_policies SET estimated_sale_price_rmb=? WHERE id=?')->execute([quote_price_policy_estimated_sale_rmb($pdo,$p),(int)$p['id']]);}
}
function quote_price_policy_reapply_category_mappings($pdo){
  $st=$pdo->prepare('UPDATE quote_price_policies SET category=?,lamp_type=? WHERE id=?');$count=0;
  foreach(rows($pdo,'SELECT id,source_category,source_lamp_type,category,lamp_type FROM quote_price_policies') as $p){
    $sc=(string)($p['source_category']?:$p['category']);$sl=(string)($p['source_lamp_type']?:$p['lamp_type']);[$category,$lamp]=quote_price_policy_mapped_category($pdo,$sc,$sl);
    if($category!==(string)$p['category']||$lamp!==(string)$p['lamp_type']){$st->execute([$category,$lamp,(int)$p['id']]);$count++;}
  }return $count;
}
function quote_price_policy_option_save($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);$before=$id?row($pdo,'SELECT * FROM quote_price_policy_options WHERE id=?',[$id]):null;
  $group=trim((string)($d['option_group']??($before['option_group']??'')));if(!in_array($group,quote_price_policy_option_groups(),true))fail('无效的设置分组');
  $name=s($d['option_name']??'',160);if($name==='')fail('请填写名称');
  $code=s($d['option_code']??'',120);if($code==='')$code=preg_replace('/[^a-z0-9_]+/i','_',strtolower(trim((string)($d['option_value']??$name))))?:('option_'.time());
  $extra=$d['extra']??($d['extra_json']??[]);if(is_string($extra))$extra=json_decode($extra,true);if(!is_array($extra))$extra=[];
  $actor=quote_price_policy_actor($user);$row=['id'=>$id,'option_group'=>$group,'option_code'=>$code,'option_name'=>$name,'option_value'=>s($d['option_value']??$code,255),'extra_json'=>json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'is_system'=>(int)($before['is_system']??($d['is_system']??0)),'is_default'=>empty($d['is_default'])?0:1,'is_active'=>array_key_exists('is_active',$d)?(empty($d['is_active'])?0:1):1,'sort_order'=>(int)($d['sort_order']??0),'note'=>(string)($d['note']??''),'updated_by'=>$actor];
  if(!$id)$row['created_by']=$actor;
  if($row['is_default'])$pdo->prepare('UPDATE quote_price_policy_options SET is_default=0 WHERE option_group=?')->execute([$group]);
  try{$id=(int)save_row($pdo,'quote_price_policy_options',$row,['option_group','option_code','option_name','option_value','extra_json','is_system','is_default','is_active','sort_order','note','created_by','updated_by']);}catch(Throwable $e){fail('保存失败：同一分组内代码不能重复');}
  if($group==='product_level')quote_price_policy_level_sync_from_option($pdo,$id,$row,$extra);
  if($group==='category_mapping')quote_price_policy_reapply_category_mappings($pdo);
  $after=row($pdo,'SELECT * FROM quote_price_policy_options WHERE id=?',[$id]);
  quote_log_event($pdo,['action'=>'price_policy_option_save','event'=>'保存价格策略设置','user_name'=>$actor,'summary'=>'保存'.$group.'：'.$name,'detail'=>['id'=>$id,'group'=>$group],'before'=>$before,'after'=>$after]);
  return ['id'=>$id,'option'=>$after];
}
function quote_price_policy_option_toggle($pdo,$d,$user){
  $id=(int)($d['id']??0);$o=row($pdo,'SELECT * FROM quote_price_policy_options WHERE id=?',[$id]);if(!$o)fail('设置项不存在');
  return quote_price_policy_option_save($pdo,array_merge($o,['id'=>$id,'is_active'=>empty($d['is_active'])?0:1,'extra'=>quote_price_policy_option_extra($o)]),$user);
}
function quote_price_policy_option_delete($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);$o=row($pdo,'SELECT * FROM quote_price_policy_options WHERE id=?',[$id]);if(!$o)fail('设置项不存在');
  $used=quote_price_policy_option_used($pdo,$o);$actor=quote_price_policy_actor($user);
  if(!empty($o['is_system'])||$used>0){
    $pdo->prepare('UPDATE quote_price_policy_options SET is_active=0,is_default=0,updated_by=? WHERE id=?')->execute([$actor,$id]);
    if($o['option_group']==='product_level'&&(int)$o['option_value']>0)$pdo->prepare('UPDATE quote_price_policy_levels SET is_active=0,is_default=0 WHERE id=?')->execute([(int)$o['option_value']]);
    $result='disabled';
  }else{$pdo->prepare('DELETE FROM quote_price_policy_options WHERE id=?')->execute([$id]);$result='deleted';}
  if($o['option_group']==='category_mapping')quote_price_policy_reapply_category_mappings($pdo);
  quote_log_event($pdo,['action'=>'price_policy_option_delete','event'=>$result==='deleted'?'删除价格策略设置':'停用价格策略设置','user_name'=>$actor,'summary'=>($result==='deleted'?'删除':'停用').'：'.$o['option_name'],'detail'=>['id'=>$id,'used'=>$used],'before'=>$o]);
  return ['id'=>$id,'result'=>$result,'used'=>$used];
}
function quote_price_policy_option_sort($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$items=$d['items']??[];if(!is_array($items))fail('排序数据无效');$st=$pdo->prepare('UPDATE quote_price_policy_options SET sort_order=?,updated_by=? WHERE id=?');$actor=quote_price_policy_actor($user);
  foreach($items as $i=>$r)$st->execute([(int)($r['sort_order']??(($i+1)*10)),$actor,(int)($r['id']??0)]);
  quote_log_event($pdo,['action'=>'price_policy_option_sort','event'=>'排序价格策略设置','user_name'=>$actor,'summary'=>'调整价格策略设置顺序','detail'=>['items'=>$items]]);return ['updated'=>count($items)];
}
function quote_price_policy_list($pdo){
  ensure_quote_price_policy_schema($pdo);
  $where=[];$args=[];
  $q=trim((string)($_GET['keyword']??$_GET['q']??''));if($q!==''){$where[]='(p.product_model LIKE ? OR p.product_name LIKE ? OR p.series LIKE ? OR p.lamp_type LIKE ? OR p.category LIKE ?)';for($i=0;$i<5;$i++)$args[]='%'.$q.'%';}
  $model=trim((string)($_GET['product_model']??$_GET['model']??'')); if($model!==''){ $where[]='p.product_model LIKE ?';$args[]='%'.$model.'%'; }
  $name=trim((string)($_GET['product_name']??$_GET['name']??'')); if($name!==''){ $where[]='p.product_name LIKE ?';$args[]='%'.$name.'%'; }
  foreach(['series','lamp_type','category','price_mode','status'] as $field){ $v=trim((string)($_GET[$field]??'')); if($v!==''){ $where[]='p.`'.$field.'`=?';$args[]=$v; } }
  if(isset($_GET['has_stock']) && $_GET['has_stock']!==''){ $where[]='p.has_stock=?';$args[]=(int)!!$_GET['has_stock']; }
  $moqStatus=trim((string)($_GET['moq_status']??''));if($moqStatus!==''){if($moqStatus==='set')$where[]='p.moq>0';elseif($moqStatus==='unset')$where[]='p.moq<=0';else{$where[]='p.moq_status_code=?';$args[]=$moqStatus;}}
  $level=(int)($_GET['product_level']??$_GET['level_id']??0); if($level>0){ $where[]='p.level_id=?';$args[]=$level; }
  $page=max(1,(int)($_GET['page']??1));$pageSize=max(8,min(200,(int)($_GET['page_size']??$_GET['limit']??20)));
  $sortMap=['product_model'=>'p.product_model','product_name'=>'p.product_name','series'=>'p.series','lamp_type'=>'p.lamp_type','category'=>'p.category','has_stock'=>'p.has_stock','moq'=>'p.moq','price_mode'=>'p.price_mode','product_level'=>'p.level_id','level_id'=>'p.level_id','status'=>'p.status','updated_at'=>'p.updated_at'];
  $sortField=(string)($_GET['sort_field']??'updated_at');$sortSql=$sortMap[$sortField]??$sortMap['updated_at'];$sortOrder=strtoupper((string)($_GET['sort_order']??'DESC'))==='ASC'?'ASC':'DESC';
  $countSql='SELECT COUNT(*) c FROM quote_price_policies p'.($where?' WHERE '.implode(' AND ',$where):'');
  $filteredTotal=(int)row($pdo,$countSql,$args)['c'];$totalPages=max(1,(int)ceil($filteredTotal/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;
  $sql="SELECT p.*,l.level_name,l.level_code,(SELECT COUNT(*) FROM quote_price_tiers t WHERE t.policy_id=p.id) AS tier_count,(SELECT GROUP_CONCAT(CONCAT(t.min_qty,'→',COALESCE(t.final_price,'-')) ORDER BY t.sort_order ASC,t.min_qty ASC SEPARATOR ' | ') FROM quote_price_tiers t WHERE t.policy_id=p.id) AS tier_summary FROM quote_price_policies p LEFT JOIN quote_price_policy_levels l ON l.id=p.level_id";
  if($where)$sql.=' WHERE '.implode(' AND ',$where);
  $sql.=' ORDER BY '.$sortSql.' '.$sortOrder.',p.id DESC LIMIT '.$pageSize.' OFFSET '.$offset;
  $list=rows($pdo,$sql,$args);$allTotal=(int)row($pdo,'SELECT COUNT(*) c FROM quote_price_policies',[])['c'];
  return [
    'list'=>$list,'policies'=>$list,
    'total'=>$filteredTotal,'all_total'=>$allTotal,'filtered_total'=>$filteredTotal,
    'page'=>$page,'page_size'=>$pageSize,'total_pages'=>$totalPages,
    'series'=>array_values(array_filter(array_map(fn($r)=>(string)$r['series'],rows($pdo,"SELECT DISTINCT series FROM quote_price_policies WHERE series<>'' ORDER BY series ASC LIMIT 1000")))),
    'lamp_types'=>array_values(array_filter(array_map(fn($r)=>(string)$r['lamp_type'],rows($pdo,"SELECT DISTINCT lamp_type FROM quote_price_policies WHERE lamp_type<>'' ORDER BY lamp_type ASC LIMIT 500")))),
    'categories'=>array_values(array_filter(array_map(fn($r)=>(string)$r['category'],rows($pdo,"SELECT DISTINCT category FROM quote_price_policies WHERE category<>'' ORDER BY category ASC LIMIT 500"))))
  ];
}
function quote_commission_schema($pdo){
  static $done=false;if($done)return;$done=true;
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,rule_name VARCHAR(160) DEFAULT '',target_type VARCHAR(50) DEFAULT '',target_name VARCHAR(160) DEFAULT '',target_contact VARCHAR(160) DEFAULT '',
    commission_mode VARCHAR(50) DEFAULT 'percent',commission_value DECIMAL(12,4) DEFAULT 0,currency VARCHAR(20) DEFAULT 'USD',calc_base VARCHAR(50) DEFAULT 'order_amount',settle_node VARCHAR(50) DEFAULT 'payment_received',settle_status VARCHAR(50) DEFAULT 'unsettled',
    apply_scope VARCHAR(50) DEFAULT 'all',customer_id VARCHAR(120) DEFAULT '',customer_name VARCHAR(255) DEFAULT '',product_model VARCHAR(120) DEFAULT '',category VARCHAR(160) DEFAULT '',
    estimated_commission DECIMAL(12,4) DEFAULT 0,settled_amount DECIMAL(12,4) DEFAULT 0,is_active TINYINT(1) DEFAULT 1,note TEXT NULL,created_by VARCHAR(120) DEFAULT '',updated_by VARCHAR(120) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_commission_target(target_type,target_name),KEY idx_commission_mode(commission_mode),KEY idx_commission_customer(customer_id),KEY idx_commission_product(product_model),KEY idx_commission_status(is_active,settle_status),KEY idx_commission_updated(updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,quote_id INT DEFAULT 0,order_id INT DEFAULT 0,quote_no VARCHAR(120) DEFAULT '',order_no VARCHAR(120) DEFAULT '',rule_id INT DEFAULT 0,rule_name VARCHAR(160) DEFAULT '',
    target_type VARCHAR(50) DEFAULT '',target_name VARCHAR(160) DEFAULT '',commission_mode VARCHAR(50) DEFAULT '',commission_value DECIMAL(12,4) DEFAULT 0,calc_base VARCHAR(50) DEFAULT '',
    base_amount DECIMAL(12,4) DEFAULT 0,commission_amount DECIMAL(12,4) DEFAULT 0,currency VARCHAR(20) DEFAULT 'USD',settle_node VARCHAR(50) DEFAULT 'payment_received',
    settle_status VARCHAR(50) DEFAULT 'unsettled',settled_amount DECIMAL(12,4) DEFAULT 0,settled_at DATETIME NULL,settled_by VARCHAR(120) DEFAULT '',snapshot_json LONGTEXT NULL,note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_commission_order_rule(order_id,rule_id),KEY idx_commission_order(order_id),KEY idx_commission_quote(quote_id),KEY idx_commission_settle(settle_status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach(['commission_scope'=>"VARCHAR(30) DEFAULT 'order'",'receivable_effect'=>"VARCHAR(50) DEFAULT 'none'",'deduct_amount'=>'DECIMAL(12,4) DEFAULT 0','deduct_confirmed'=>'TINYINT(1) DEFAULT 0','deduct_reason'=>"VARCHAR(255) DEFAULT ''",'deduct_note'=>'TEXT NULL'] as $c=>$ddl) ensure_col($pdo,'quote_commission_snapshots',$c,$ddl);
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,snapshot_id INT DEFAULT 0,order_id INT DEFAULT 0,order_item_id INT DEFAULT 0,order_no VARCHAR(120) DEFAULT '',quote_no VARCHAR(120) DEFAULT '',
    item_index INT DEFAULT 0,product_model VARCHAR(120) DEFAULT '',customer_model VARCHAR(120) DEFAULT '',product_name VARCHAR(255) DEFAULT '',color VARCHAR(80) DEFAULT '',
    qty DECIMAL(12,2) DEFAULT 0,unit_price DECIMAL(12,4) DEFAULT 0,amount DECIMAL(12,4) DEFAULT 0,is_commission_enabled TINYINT(1) DEFAULT 1,
    target_type VARCHAR(50) DEFAULT '',target_name VARCHAR(160) DEFAULT '',commission_mode VARCHAR(50) DEFAULT '',commission_value DECIMAL(12,4) DEFAULT 0,
    calc_base VARCHAR(50) DEFAULT 'product_amount',base_amount DECIMAL(12,4) DEFAULT 0,commission_amount DECIMAL(12,4) DEFAULT 0,currency VARCHAR(20) DEFAULT 'USD',
    receivable_effect VARCHAR(50) DEFAULT 'none',settle_status VARCHAR(50) DEFAULT 'unsettled',note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_commission_line_item(order_item_id),KEY idx_commission_line_order(order_id),KEY idx_commission_line_model(product_model)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_item_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,order_id INT NOT NULL DEFAULT 0,order_item_id INT NOT NULL DEFAULT 0,order_no VARCHAR(120) DEFAULT '',quote_no VARCHAR(120) DEFAULT '',
    product_model VARCHAR(120) DEFAULT '',product_name VARCHAR(255) DEFAULT '',qty DECIMAL(12,3) DEFAULT 0,product_amount DECIMAL(12,4) DEFAULT 0,
    target_type VARCHAR(50) DEFAULT '',target_name VARCHAR(160) DEFAULT '',commission_mode VARCHAR(50) DEFAULT 'percent',commission_value DECIMAL(12,4) DEFAULT 0,
    calc_base VARCHAR(50) DEFAULT 'product_amount',base_amount DECIMAL(12,4) DEFAULT 0,commission_amount DECIMAL(12,4) DEFAULT 0,currency VARCHAR(20) DEFAULT 'USD',
    settle_node VARCHAR(50) DEFAULT 'manual',settle_status VARCHAR(50) DEFAULT 'unsettled',settled_amount DECIMAL(12,4) DEFAULT 0,snapshot_json LONGTEXT NULL,note TEXT NULL,
    created_by VARCHAR(120) DEFAULT '',updated_by VARCHAR(120) DEFAULT '',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_commission_order_item(order_item_id),KEY idx_commission_item_order(order_id),KEY idx_commission_item_model(product_model),KEY idx_commission_item_settle(settle_status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_options (
    id INT AUTO_INCREMENT PRIMARY KEY,option_group VARCHAR(80) NOT NULL,option_code VARCHAR(120) DEFAULT '',option_name VARCHAR(160) NOT NULL,option_value VARCHAR(255) DEFAULT '',extra_json LONGTEXT NULL,
    is_system TINYINT(1) DEFAULT 0,is_default TINYINT(1) DEFAULT 0,is_active TINYINT(1) DEFAULT 1,sort_order INT DEFAULT 0,note TEXT NULL,created_by VARCHAR(120) DEFAULT '',updated_by VARCHAR(120) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_commission_option(option_group,option_code),KEY idx_commission_option_group(option_group,is_active,sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_commission_reminder_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,scene VARCHAR(80) DEFAULT '',trigger_condition VARCHAR(120) DEFAULT '',remind_level VARCHAR(50) DEFAULT 'warning',
    action_mode VARCHAR(80) DEFAULT 'remind',require_reason TINYINT(1) DEFAULT 0,is_active TINYINT(1) DEFAULT 1,sort_order INT DEFAULT 0,note TEXT NULL,
    created_by VARCHAR(120) DEFAULT '',updated_by VARCHAR(120) DEFAULT '',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_commission_reminder(scene,trigger_condition),KEY idx_commission_reminder_active(scene,is_active,sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach(['customer_scope'=>"VARCHAR(30) DEFAULT 'all'",'customer_id'=>"VARCHAR(120) DEFAULT ''",'customer_code'=>"VARCHAR(120) DEFAULT ''",'customer_name'=>"VARCHAR(255) DEFAULT ''"] as $c=>$ddl)ensure_col($pdo,'quote_commission_reminder_rules',$c,$ddl);
  try{$pdo->exec('ALTER TABLE quote_commission_reminder_rules DROP INDEX uk_commission_reminder');}catch(Throwable $e){}
  try{$pdo->exec('ALTER TABLE quote_commission_reminder_rules ADD UNIQUE KEY uk_commission_reminder_customer(scene,trigger_condition,customer_scope,customer_id)');}catch(Throwable $e){}
  ensure_col($pdo,'quote_orders','commission_json','LONGTEXT NULL');
  $actor='system';$st=$pdo->prepare("INSERT IGNORE INTO quote_commission_reminder_rules(scene,trigger_condition,remind_level,action_mode,require_reason,is_active,sort_order,created_by,updated_by) VALUES(?,?,?,?,0,1,?,?,?)");
  foreach([['quote_customer_selected','customer_has_commission_rule','warning','must_confirm',10],['quote_customer_selected','customer_has_commission_history','warning','must_confirm',20],['payment_create','order_has_commission','warning','remind',30],['payment_create','commission_affects_receivable','danger','must_confirm',40]] as $x)$st->execute([$x[0],$x[1],$x[2],$x[3],$x[4],$actor,$actor]);
  quote_commission_init_defaults($pdo,false);
}
function quote_commission_customer_check($pdo,$d,$user){
  quote_commission_schema($pdo);$cid=s($d['customer_id']??'',120);$name=s($d['customer_name']??'',255);$code=s($d['customer_code']??'',120);
  $rule=null;$args=[];$parts=[];
  if($cid!==''){$parts[]='customer_id=?';$args[]=$cid;}
  if($name!==''){$parts[]='customer_name=?';$args[]=$name;}
  if($code!==''){$parts[]='customer_id=?';$args[]=$code;}
  if($parts)$rule=row($pdo,"SELECT * FROM quote_commission_rules WHERE is_active=1 AND (".implode(' OR ',$parts).") ORDER BY updated_at DESC,id DESC LIMIT 1",$args);
  $histArgs=[];$histWhere=[];
  if($cid!==''){$histWhere[]='o.customer_id=?';$histArgs[]=$cid;}
  if($name!==''){$histWhere[]='o.customer_name=?';$histArgs[]=$name;}
  $history=$histWhere?rows($pdo,"SELECT o.order_date,o.order_no,o.quote_no,o.customer_name,s.* FROM quote_commission_snapshots s JOIN quote_sales_orders o ON o.id=s.order_id WHERE (".implode(' OR ',$histWhere).") ORDER BY COALESCE(o.order_date,o.created_at) DESC,s.id DESC LIMIT 5",$histArgs):[];
  $reminders=rows($pdo,"SELECT * FROM quote_commission_reminder_rules WHERE scene='quote_customer_selected' AND is_active=1 AND (COALESCE(customer_scope,'all')='all' OR customer_id=? OR customer_code=? OR customer_name=?) ORDER BY sort_order,id",[$cid,$code,$name]);$activeTriggers=array_column($reminders,'trigger_condition');$explicitReminder=false;foreach($reminders as $rr)if(($rr['customer_scope']??'all')==='specific'){$explicitReminder=true;break;}$shouldRemind=(bool)($explicitReminder||($rule&&in_array('customer_has_commission_rule',$activeTriggers,true))||($history&&in_array('customer_has_commission_history',$activeTriggers,true)));
  $result=['has_commission'=>(bool)($rule||$history||$explicitReminder),'has_rule'=>(bool)$rule,'has_history'=>(bool)$history,'has_explicit_reminder'=>$explicitReminder,'should_remind'=>$shouldRemind,'customer_name'=>$name,'latest_rule'=>$rule?:null,'latest_snapshot'=>$history[0]??null,'recent_records'=>$history,'reminder_rules'=>$reminders];
  if($result['has_commission'])quote_log_event($pdo,['action'=>'commission_customer_reminder_triggered','event'=>'选择客户触发佣金提醒','quote_id'=>(int)($d['quote_id']??0),'quote_no'=>$d['quote_no']??'','customer_id'=>$cid,'customer_name'=>$name,'user_name'=>$user['username']??'','summary'=>'客户存在佣金规则或历史：'.$name,'detail'=>$result]);
  return $result;
}
function quote_commission_reminder_list($pdo){quote_commission_schema($pdo);return ['list'=>rows($pdo,'SELECT * FROM quote_commission_reminder_rules ORDER BY sort_order,id')];}
function quote_commission_reminder_save($pdo,$d,$user){
  quote_commission_schema($pdo);$id=(int)($d['id']??0);$before=$id?row($pdo,'SELECT * FROM quote_commission_reminder_rules WHERE id=?',[$id]):null;$actor=quote_price_policy_actor($user);
  $scope=($d['customer_scope']??'all')==='specific'?'specific':'all';$r=['id'=>$id,'scene'=>s($d['scene']??'',80),'trigger_condition'=>s($d['trigger_condition']??'',120),'customer_scope'=>$scope,'customer_id'=>$scope==='specific'?s($d['customer_id']??'',120):'','customer_code'=>$scope==='specific'?s($d['customer_code']??'',120):'','customer_name'=>$scope==='specific'?s($d['customer_name']??'',255):'','remind_level'=>s($d['remind_level']??'warning',50),'action_mode'=>s($d['action_mode']??'remind',80),'require_reason'=>empty($d['require_reason'])?0:1,'is_active'=>array_key_exists('is_active',$d)?(empty($d['is_active'])?0:1):1,'sort_order'=>(int)($d['sort_order']??0),'note'=>s($d['note']??'',5000),'updated_by'=>$actor];if(!$r['scene']||!$r['trigger_condition'])fail('提醒场景和触发条件不能为空');if($scope==='specific'&&$r['customer_id']===''&&$r['customer_code']===''&&$r['customer_name']==='')fail('请选择指定客户');if(!$id)$r['created_by']=$actor;
  $id=(int)save_row($pdo,'quote_commission_reminder_rules',$r,['scene','trigger_condition','customer_scope','customer_id','customer_code','customer_name','remind_level','action_mode','require_reason','is_active','sort_order','note','created_by','updated_by']);$after=row($pdo,'SELECT * FROM quote_commission_reminder_rules WHERE id=?',[$id]);quote_log_event($pdo,['action'=>'commission_reminder_save','event'=>'保存佣金提醒设置','user_name'=>$actor,'summary'=>$after['scene'].' / '.$after['trigger_condition'].' / '.($after['customer_name']?:'全部客户'),'before'=>$before,'after'=>$after]);return ['rule'=>$after];
}
function quote_commission_init_defaults($pdo,$writeLog=true){
  $defs=[
    'target_type'=>[['sales','业务员'],['agent','代理'],['referrer','介绍人'],['customer','客户'],['other','其它']],
    'commission_mode'=>[['percent','百分比抽点'],['fixed_order','固定金额'],['fixed_unit','每件固定'],['profit_percent','毛利抽点']],
    'calc_base'=>[['order_amount','订单金额'],['product_amount','产品金额'],['received_amount','已收款金额'],['gross_profit','毛利']],
    'settle_node'=>[['order_confirmed','订单确认后'],['deposit_received','收订金后'],['shipped','出货后'],['payment_received','收全款后'],['manual','手动结算']],
    'settle_status'=>[['unsettled','未结算'],['pending','待结算'],['partial','部分结算'],['settled','已结算'],['cancelled','已取消']],
    'commission_reason'=>[['agent','代理佣金'],['referral','客户介绍费'],['sales','业务提成'],['rebate','项目返点'],['approval','老板特批'],['adjustment','补差价'],['other','其它']],
    'currency'=>[['USD','USD'],['RMB','RMB'],['EUR','EUR']]
  ];$st=$pdo->prepare('INSERT IGNORE INTO quote_commission_options(option_group,option_code,option_name,option_value,is_system,is_active,sort_order,created_by,updated_by) VALUES(?,?,?,?,1,1,?,?,?)');$actor='system';$added=0;
  foreach($defs as $group=>$items)foreach($items as $i=>$x){$st->execute([$group,$x[0],$x[1],$x[0],($i+1)*10,$actor,$actor]);$added+=$st->rowCount();}
  if($writeLog&&$added)quote_log_event($pdo,['action'=>'commission_options_init_defaults','event'=>'初始化佣金设置','user_name'=>$actor,'summary'=>'初始化佣金选项 '.$added.' 项']);return ['added'=>$added];
}
function quote_commission_options_list($pdo){quote_commission_schema($pdo);return ['options'=>rows($pdo,'SELECT * FROM quote_commission_options ORDER BY option_group,is_active DESC,sort_order,id')];}
function quote_commission_option_save($pdo,$d,$user){
  quote_commission_schema($pdo);$id=(int)($d['id']??0);$before=$id?row($pdo,'SELECT * FROM quote_commission_options WHERE id=?',[$id]):null;$actor=quote_price_policy_actor($user);
  $row=['id'=>$id,'option_group'=>s($d['option_group']??'',80),'option_code'=>s($d['option_code']??'',120),'option_name'=>s($d['option_name']??'',160),'option_value'=>s($d['option_value']??($d['option_code']??''),255),'extra_json'=>is_string($d['extra_json']??'')?$d['extra_json']:json_encode($d['extra']??[],JSON_UNESCAPED_UNICODE),'is_system'=>(int)($before['is_system']??0),'is_default'=>empty($d['is_default'])?0:1,'is_active'=>array_key_exists('is_active',$d)?(empty($d['is_active'])?0:1):1,'sort_order'=>(int)($d['sort_order']??0),'note'=>(string)($d['note']??''),'updated_by'=>$actor];if(!$row['option_group']||!$row['option_code']||!$row['option_name'])fail('分组、代码和名称不能为空');if(!$id)$row['created_by']=$actor;
  $id=(int)save_row($pdo,'quote_commission_options',$row,['option_group','option_code','option_name','option_value','extra_json','is_system','is_default','is_active','sort_order','note','created_by','updated_by']);$after=row($pdo,'SELECT * FROM quote_commission_options WHERE id=?',[$id]);quote_log_event($pdo,['action'=>'commission_option_save','event'=>'保存佣金设置','user_name'=>$actor,'summary'=>$after['option_name'],'before'=>$before,'after'=>$after]);return ['id'=>$id,'option'=>$after];
}
function quote_commission_calc($mode,$value,$base,$qty){$value=(float)$value;$base=(float)$base;$qty=(float)$qty;if($mode==='percent')return round($base*$value/100,2);if($mode==='fixed_order')return round($value,2);if($mode==='fixed_unit')return round($qty*$value,2);return null;}
function quote_commission_order_list($pdo,$d){
  quote_commission_schema($pdo);$where=[];$args=[];$q=s($d['keyword']??'',160);
  if($q!==''){$where[]='(o.order_no LIKE ? OR o.quote_no LIKE ? OR o.customer_name LIKE ? OR o.user_name LIKE ? OR s.target_name LIKE ? OR s.note LIKE ?)';for($i=0;$i<6;$i++)$args[]='%'.$q.'%';}
  $settle=s($d['settle_status']??'',50);if($settle!==''){$where[]='COALESCE(s.settle_status,?)=?';$args[]='unsettled';$args[]=$settle;}
  if(!empty($d['missing_only']))$where[]='s.id IS NULL';$currency=s($d['currency']??'',20);if($currency!==''){$where[]='o.currency=?';$args[]=$currency;}
  $sqlWhere=$where?' WHERE '.implode(' AND ',$where):'';$size=max(10,min(200,(int)($d['page_size']??50)));$page=max(1,(int)($d['page']??1));
  $join=' LEFT JOIN quote_commission_snapshots s ON s.id=(SELECT sx.id FROM quote_commission_snapshots sx WHERE sx.order_id=o.id ORDER BY (sx.rule_id=0) DESC,sx.id DESC LIMIT 1)';
  $total=(int)(row($pdo,'SELECT COUNT(*) c FROM quote_sales_orders o'.$join.$sqlWhere,$args)['c']??0);$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$offset=($page-1)*$size;
  $list=rows($pdo,'SELECT o.id AS order_id,o.order_no,o.quote_no,o.customer_name,o.user_name,o.amount AS order_amount,o.qty AS total_qty,o.currency AS order_currency,o.paid_amount,o.payment_status,o.shipment_status,o.status AS order_status,o.created_at AS order_created_at,s.id AS snapshot_id,s.rule_id,s.target_name,s.target_type,s.commission_mode,s.commission_value,s.calc_base,s.base_amount,s.commission_amount,s.currency,s.settle_node,s.settle_status,s.settled_amount,s.note,s.settled_at,s.settled_by FROM quote_sales_orders o'.$join.$sqlWhere.' ORDER BY COALESCE(o.order_date,o.created_at) DESC,o.id DESC LIMIT '.$size.' OFFSET '.$offset,$args);
  $orderIds=array_values(array_filter(array_map(fn($x)=>(int)$x['order_id'],$list)));$grouped=[];
  if($orderIds){$marks=implode(',',array_fill(0,count($orderIds),'?'));$itemRows=rows($pdo,"SELECT i.id AS order_item_id,i.order_id,i.item_index,i.product_code AS product_model,i.product_name,i.qty,i.amount AS product_amount,i.unit_price,i.color,c.id AS item_commission_id,c.target_name,c.target_type,c.commission_mode,c.commission_value,c.calc_base,c.base_amount,c.commission_amount,c.currency,c.settle_node,c.settle_status,c.settled_amount,c.note FROM quote_sales_order_items i LEFT JOIN quote_commission_item_snapshots c ON c.order_item_id=i.id WHERE i.order_id IN ($marks) ORDER BY i.order_id,i.item_index,i.id",$orderIds);foreach($itemRows as $it)$grouped[(int)$it['order_id']][]=$it;}
  foreach($list as &$o)$o['items']=$grouped[(int)$o['order_id']]??[];unset($o);
  return ['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$size,'total_pages'=>$pages];
}
function quote_commission_order_save($pdo,$d,$user){
  quote_commission_schema($pdo);$orderId=(int)($d['order_id']??0);$o=row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]);if(!$o)fail('订单不存在');
  $mode=s($d['commission_mode']??'percent',50);$value=max(0,(float)($d['commission_value']??0));$baseCode=s($d['calc_base']??'order_amount',50);$base=$baseCode==='received_amount'?(float)($o['paid_amount']??0):(float)($o['amount']??0);$commission=quote_commission_calc($mode,$value,$base,(float)($o['qty']??0));if($commission===null)fail('毛利数据不足，暂不能计算');
  $old=row($pdo,'SELECT * FROM quote_commission_snapshots WHERE order_id=? AND rule_id=0 LIMIT 1',[$orderId]);$actor=quote_price_policy_actor($user);$snapshot=['source'=>'manual_order_commission','operator'=>$actor,'time'=>date('Y-m-d H:i:s'),'before'=>$old,'order'=>['id'=>$orderId,'order_no'=>$o['order_no'],'quote_no'=>$o['quote_no'],'amount'=>$o['amount'],'qty'=>$o['qty']]];
  $params=[(int)($o['source_quote_id']??0),$o['quote_no'],$o['order_no'],s($d['target_type']??'other',50),s($d['target_name']??'',160),$mode,$value,$baseCode,$base,$commission,s($d['currency']??$o['currency'],20),s($d['settle_node']??'manual',50),s($d['settle_status']??'unsettled',50),max(0,(float)($d['settled_amount']??0)),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),s($d['note']??'订单佣金',5000)];
  if($old){$pdo->prepare('UPDATE quote_commission_snapshots SET quote_id=?,quote_no=?,order_no=?,target_type=?,target_name=?,commission_mode=?,commission_value=?,calc_base=?,base_amount=?,commission_amount=?,currency=?,settle_node=?,settle_status=?,settled_amount=?,snapshot_json=?,note=?,updated_at=NOW() WHERE id=?')->execute(array_merge($params,[(int)$old['id']]));$id=(int)$old['id'];}
  else{$pdo->prepare("INSERT INTO quote_commission_snapshots(quote_id,order_id,quote_no,order_no,rule_id,rule_name,target_type,target_name,commission_mode,commission_value,calc_base,base_amount,commission_amount,currency,settle_node,settle_status,settled_amount,snapshot_json,note) VALUES(?,?,?,?,0,'订单手填佣金',?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge(array_slice($params,0,3),[$orderId],array_slice($params,3)));$id=(int)$pdo->lastInsertId();}
  $after=row($pdo,'SELECT * FROM quote_commission_snapshots WHERE id=?',[$id]);quote_log_event($pdo,['action'=>'commission_order_save','event'=>'保存订单佣金','user_name'=>$actor,'summary'=>(string)$o['order_no'].' / '.(string)($after['target_name']??''),'before'=>$old,'after'=>$after]);return ['id'=>$id,'snapshot'=>$after];
}
function quote_commission_order_batch_save($pdo,$d,$user){$saved=[];$errors=[];foreach(($d['items']??[]) as $x){try{$saved[]=quote_commission_order_save($pdo,$x,$user);}catch(Throwable $e){$errors[]=['order_id'=>$x['order_id']??0,'reason'=>$e->getMessage()];}}return ['saved'=>$saved,'errors'=>$errors];}
function quote_commission_quote_list($pdo,$d){
  quote_commission_schema($pdo);$q=s($d['keyword']??'',160);$where='';$args=[];if($q!==''){$where=' WHERE (quote_no LIKE ? OR customer_name LIKE ? OR user_name LIKE ?)';$args=['%'.$q.'%','%'.$q.'%','%'.$q.'%'];}
  $rows=rows($pdo,"SELECT id,quote_no,quote_date,customer_id,customer_name,user_name,amount,qty,currency,approval_status,status,items_json,commission_json,created_at FROM quote_orders".$where." ORDER BY COALESCE(quote_date,created_at) DESC,id DESC LIMIT 500",$args);$list=[];
  foreach($rows as $r){$c=json_decode((string)($r['commission_json']??''),true);if(!is_array($c))$c=[];$items=json_decode((string)($r['items_json']??'[]'),true);if(!is_array($items))$items=[];$lineMap=[];foreach(($c['lines']??[]) as $x)$lineMap[(int)($x['item_index']??-1)]=$x;$children=[];
    foreach($items as $i=>$it){$p=$it['product']??[];$line=$lineMap[$i]??[];$children[]=['order_item_id'=>-((int)$r['id']*1000+$i+1),'quote_item_index'=>$i,'order_id'=>-(int)$r['id'],'item_index'=>$i+1,'image'=>$p['image']??'','product_model'=>$p['code']??$p['model']??'','customer_model'=>$it['customer_code']??'','product_name'=>$p['name']??'','color'=>$it['color']??'','qty'=>(float)($it['qty']??0),'unit_price'=>(float)($it['price']??0),'product_amount'=>(float)($it['amount']??0),'is_commission_enabled'=>array_key_exists('value',$line)?1:0,'target_type'=>'other','target_name'=>$line['target_name']??'','commission_mode'=>$line['mode']??'percent','commission_value'=>$line['value']??0,'calc_base'=>'product_amount','base_amount'=>(float)($it['amount']??0),'commission_amount'=>(float)($line['estimated_amount']??0),'currency'=>$line['currency']??$r['currency'],'receivable_effect'=>$c['commission_receivable_effect']??'none','settle_status'=>'unsettled','note'=>$line['note']??''];}
    $list[]=['entity_type'=>'quote','quote_id'=>(int)$r['id'],'order_id'=>-(int)$r['id'],'order_no'=>'报价 · '.$r['quote_no'],'quote_no'=>$r['quote_no'],'customer_name'=>$r['customer_name'],'user_name'=>$r['user_name'],'order_amount'=>$r['amount'],'total_qty'=>$r['qty'],'order_currency'=>$r['currency'],'paid_amount'=>0,'payment_status'=>'报价阶段','shipment_status'=>'未转订单','order_status'=>$r['approval_status']?:$r['status'],'order_created_at'=>$r['created_at'],'snapshot_id'=>0,'rule_id'=>$c['commission_rule_id']??0,'target_name'=>$c['commission_target_name']??'','target_type'=>'other','commission_mode'=>$c['commission_mode']??'percent','commission_value'=>$c['commission_value']??0,'calc_base'=>$c['commission_calc_base']??'order_amount','base_amount'=>$r['amount'],'commission_amount'=>$c['commission_estimated_amount']??0,'currency'=>$c['commission_currency']??$r['currency'],'settle_node'=>$c['commission_settle_node']??'manual','settle_status'=>$c['commission_confirm_status']??'unconfirmed','settled_amount'=>0,'commission_scope'=>!empty($c['lines'])?'line':'order','receivable_effect'=>$c['commission_receivable_effect']??'none','note'=>$c['commission_note']??'','items'=>$children];}
  return ['list'=>$list,'total'=>count($list)];
}
function quote_commission_quote_save($pdo,$d,$user){
  quote_commission_schema($pdo);$id=(int)($d['quote_id']??abs((int)($d['order_id']??0)));$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$id]);if(!$q)fail('报价不存在');$before=json_decode((string)($q['commission_json']??''),true);if(!is_array($before))$before=[];$mode=s($d['commission_mode']??($before['commission_mode']??'percent'),50);$value=max(0,(float)($d['commission_value']??($before['commission_value']??0)));$amount=(float)$q['amount'];$commission=quote_commission_calc($mode,$value,$amount,(float)$q['qty']);if($commission===null)$commission=(float)($before['commission_estimated_amount']??0);
  $after=array_merge($before,['commission_required'=>$value>0?1:0,'commission_confirm_status'=>$before['commission_confirm_status']??'quote_manual','commission_source'=>'quote_commission_table','commission_target_name'=>s($d['target_name']??($before['commission_target_name']??''),160),'commission_mode'=>$mode,'commission_value'=>$value,'commission_calc_base'=>s($d['calc_base']??($before['commission_calc_base']??'order_amount'),50),'commission_estimated_amount'=>$commission,'commission_currency'=>s($d['currency']??$q['currency'],20),'commission_receivable_effect'=>s($d['receivable_effect']??($before['commission_receivable_effect']??'none'),50),'commission_settle_node'=>s($d['settle_node']??($before['commission_settle_node']??'manual'),50),'commission_note'=>s($d['note']??($before['commission_note']??''),5000),'updated_at'=>date('c')]);$pdo->prepare('UPDATE quote_orders SET commission_json=? WHERE id=?')->execute([json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);quote_log_event($pdo,['action'=>'commission_quote_save','event'=>'保存报价佣金','quote_id'=>$id,'quote_no'=>$q['quote_no'],'customer_name'=>$q['customer_name'],'user_name'=>quote_price_policy_actor($user),'before'=>$before,'after'=>$after]);return ['quote_id'=>$id,'commission'=>$after];
}
function quote_commission_quote_lines_save($pdo,$d,$user){
  $group=[];foreach(($d['items']??[]) as $x){$qid=(int)($x['quote_id']??0);if(!$qid&&isset($x['order_id']))$qid=abs((int)$x['order_id']);if($qid)$group[$qid][]=$x;}$saved=[];$errors=[];
  foreach($group as $qid=>$edits){try{$q=row($pdo,'SELECT * FROM quote_orders WHERE id=?',[$qid]);if(!$q)throw new RuntimeException('报价不存在');$c=json_decode((string)($q['commission_json']??''),true);if(!is_array($c))$c=[];$items=json_decode((string)($q['items_json']??'[]'),true);if(!is_array($items))$items=[];$lines=$c['lines']??[];if(!is_array($lines))$lines=[];foreach($edits as $e){$idx=(int)($e['quote_item_index']??0);$it=$items[$idx]??[];$p=$it['product']??[];$mode=s($e['commission_mode']??'percent',50);$value=max(0,(float)($e['commission_value']??0));$base=(float)($it['amount']??0);$est=quote_commission_calc($mode,$value,$base,(float)($it['qty']??0));$lines[$idx]=['item_index'=>$idx,'item_key'=>(string)($p['code']??$p['model']??$idx),'product_model'=>$p['code']??$p['model']??'','product_name'=>$p['name']??'','qty'=>(float)($it['qty']??0),'unit_price'=>(float)($it['price']??0),'amount'=>$base,'included_in_price'=>$e['included_in_price']??'included','mode'=>$mode,'value'=>$value,'estimated_amount'=>$est??0,'currency'=>$e['currency']??$q['currency'],'target_name'=>$e['target_name']??'','note'=>$e['note']??''];}$c['lines']=array_values($lines);$c['commission_scope']='line';$c['commission_confirm_status']='line_confirmed';$c['commission_estimated_amount']=array_sum(array_map(fn($x)=>(float)($x['estimated_amount']??0),$c['lines']));$pdo->prepare('UPDATE quote_orders SET commission_json=? WHERE id=?')->execute([json_encode($c,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$qid]);$saved[]=$qid;}catch(Throwable $e){$errors[]=['quote_id'=>$qid,'reason'=>$e->getMessage()];}}
  return ['saved'=>$saved,'errors'=>$errors];
}
function quote_commission_item_save($pdo,$d,$user){
  quote_commission_schema($pdo);$itemId=(int)($d['order_item_id']??0);$it=row($pdo,'SELECT i.*,o.order_no,o.quote_no,o.currency AS order_currency FROM quote_sales_order_items i JOIN quote_sales_orders o ON o.id=i.order_id WHERE i.id=? LIMIT 1',[$itemId]);if(!$it)fail('订单产品不存在');
  $mode=s($d['commission_mode']??'percent',50);$value=max(0,(float)($d['commission_value']??0));$baseCode=s($d['calc_base']??'product_amount',50);$base=(float)($it['amount']??0);$commission=quote_commission_calc($mode,$value,$base,(float)($it['qty']??0));if($commission===null)fail('毛利数据不足，暂不能计算');
  $old=row($pdo,'SELECT * FROM quote_commission_item_snapshots WHERE order_item_id=? LIMIT 1',[$itemId]);$actor=quote_price_policy_actor($user);$snapshot=['source'=>'manual_order_item_commission','operator'=>$actor,'time'=>date('Y-m-d H:i:s'),'before'=>$old,'item'=>['id'=>$itemId,'order_id'=>$it['order_id'],'product_model'=>$it['product_code'],'product_name'=>$it['product_name'],'qty'=>$it['qty'],'amount'=>$it['amount']]];
  $row=['id'=>(int)($old['id']??0),'order_id'=>(int)$it['order_id'],'order_item_id'=>$itemId,'order_no'=>$it['order_no'],'quote_no'=>$it['quote_no'],'product_model'=>$it['product_code'],'product_name'=>$it['product_name'],'qty'=>$it['qty'],'product_amount'=>$it['amount'],'target_type'=>s($d['target_type']??'other',50),'target_name'=>s($d['target_name']??'',160),'commission_mode'=>$mode,'commission_value'=>$value,'calc_base'=>$baseCode,'base_amount'=>$base,'commission_amount'=>$commission,'currency'=>s($d['currency']??$it['order_currency'],20),'settle_node'=>s($d['settle_node']??'manual',50),'settle_status'=>s($d['settle_status']??'unsettled',50),'settled_amount'=>max(0,(float)($d['settled_amount']??0)),'snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'note'=>s($d['note']??'产品佣金',5000),'updated_by'=>$actor];
  if(!$old)$row['created_by']=$actor;$id=(int)save_row($pdo,'quote_commission_item_snapshots',$row,['order_id','order_item_id','order_no','quote_no','product_model','product_name','qty','product_amount','target_type','target_name','commission_mode','commission_value','calc_base','base_amount','commission_amount','currency','settle_node','settle_status','settled_amount','snapshot_json','note','created_by','updated_by']);$after=row($pdo,'SELECT * FROM quote_commission_item_snapshots WHERE id=?',[$id]);quote_log_event($pdo,['action'=>'commission_item_save','event'=>'保存产品佣金','user_name'=>$actor,'summary'=>$it['order_no'].' / '.$it['product_code'],'before'=>$old,'after'=>$after]);return ['id'=>$id,'snapshot'=>$after];
}
function quote_commission_item_batch_save($pdo,$d,$user){$saved=[];$errors=[];foreach(($d['items']??[]) as $x){try{$saved[]=quote_commission_item_save($pdo,$x,$user);}catch(Throwable $e){$errors[]=['order_item_id'=>$x['order_item_id']??0,'reason'=>$e->getMessage()];}}return ['saved'=>$saved,'errors'=>$errors];}
function quote_commission_rule_list($pdo){
  quote_commission_schema($pdo);$w=[];$a=[];$q=trim((string)($_GET['keyword']??''));if($q!==''){$w[]='(target_name LIKE ? OR customer_name LIKE ? OR product_model LIKE ? OR rule_name LIKE ? OR note LIKE ?)';for($i=0;$i<5;$i++)$a[]='%'.$q.'%';}
  foreach(['target_type','commission_mode','calc_base','settle_node','settle_status','currency'] as $f){$v=trim((string)($_GET[$f]??''));if($v!==''){$w[]='`'.$f.'`=?';$a[]=$v;}}if(isset($_GET['status'])&&$_GET['status']!==''){$w[]='is_active=?';$a[]=(int)$_GET['status'];}
  $size=max(10,min(200,(int)($_GET['page_size']??50)));$page=max(1,(int)($_GET['page']??1));$where=$w?' WHERE '.implode(' AND ',$w):'';$total=(int)row($pdo,'SELECT COUNT(*) c FROM quote_commission_rules'.$where,$a)['c'];$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);$offset=($page-1)*$size;
  $list=rows($pdo,'SELECT * FROM quote_commission_rules'.$where.' ORDER BY updated_at DESC,id DESC LIMIT '.$size.' OFFSET '.$offset,$a);return ['list'=>$list,'total'=>$total,'page'=>$page,'page_size'=>$size,'total_pages'=>$pages];
}
function quote_commission_rule_save($pdo,$d,$user){
  quote_commission_schema($pdo);$id=(int)($d['id']??0);$before=$id?row($pdo,'SELECT * FROM quote_commission_rules WHERE id=?',[$id]):null;$actor=quote_price_policy_actor($user);$allowed=['rule_name','target_type','target_name','target_contact','commission_mode','commission_value','currency','calc_base','settle_node','settle_status','apply_scope','customer_id','customer_name','product_model','category','settled_amount','is_active','note'];
  foreach(['commission_value','settled_amount'] as $f)if(isset($d[$f]))$d[$f]=max(0,(float)$d[$f]);$d['updated_by']=$actor;if(!$id)$d['created_by']=$actor;$d['id']=$id;$id=(int)save_row($pdo,'quote_commission_rules',$d,array_merge($allowed,['created_by','updated_by']));$after=row($pdo,'SELECT * FROM quote_commission_rules WHERE id=?',[$id]);quote_log_event($pdo,['action'=>'commission_rule_save','event'=>'保存佣金规则','user_name'=>$actor,'summary'=>($after['target_name']?:$after['rule_name']),'before'=>$before,'after'=>$after]);return ['id'=>$id,'rule'=>$after];
}
function quote_commission_batch_save($pdo,$d,$user){$saved=[];$errors=[];foreach(($d['items']??[]) as $x){try{$r=quote_commission_rule_save($pdo,$x,$user);$saved[]=['id'=>$r['id']];}catch(Throwable $e){$errors[]=['id'=>$x['id']??0,'reason'=>$e->getMessage()];}}return ['saved'=>$saved,'errors'=>$errors,'saved_count'=>count($saved),'failed_count'=>count($errors)];}
function quote_commission_export($pdo,$user){
  quote_commission_schema($pdo);$headers=['规则名称','佣金对象','对象类型','联系方式','佣金模式','佣金值','计算基准','币种','结算节点','适用范围','指定客户','指定产品','指定分类','状态','备注'];$fields=['rule_name','target_name','target_type','target_contact','commission_mode','commission_value','calc_base','currency','settle_node','apply_scope','customer_name','product_model','category','is_active','note'];$data=rows($pdo,'SELECT * FROM quote_commission_rules ORDER BY updated_at DESC,id DESC');
  while(ob_get_level()>0)@ob_end_clean();header('Content-Type: application/vnd.ms-excel; charset=UTF-8');header('Content-Disposition: attachment; filename="commission_rules_'.date('Ymd_His').'.xls"');echo "\xEF\xBB\xBF".'<table border="1"><tr>';foreach($headers as $h)echo '<th>'.htmlspecialchars($h,ENT_QUOTES,'UTF-8').'</th>';echo '</tr>';foreach($data as $r){echo '<tr>';foreach($fields as $f){$v=$r[$f]??'';if($f==='is_active')$v=$v?'启用':'停用';echo '<td>'.htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8').'</td>';}echo '</tr>';}echo '</table>';quote_log_event($pdo,['action'=>'commission_rule_export','event'=>'导出佣金规则','user_name'=>quote_price_policy_actor($user),'summary'=>'导出佣金规则 '.count($data).' 条']);exit;
}
function quote_commission_import($pdo,$user){
  quote_commission_schema($pdo);$rows=quote_price_excel_rows_from_upload($_FILES['file']??[]);if(count($rows)<2)fail('Excel 没有可导入的数据');$head=array_shift($rows);$map=[];foreach($head as $c=>$v)$map[trim((string)$v)]=$c;$get=function($r,$k)use($map){return isset($map[$k])?(string)($r[$map[$k]]??''):'';};$ok=0;$bad=0;$errors=[];
  foreach($rows as $i=>$r){$name=trim($get($r,'规则名称'));$target=trim($get($r,'佣金对象'));if($name===''&&$target==='')continue;try{$old=row($pdo,'SELECT id FROM quote_commission_rules WHERE rule_name=? AND target_name=? LIMIT 1',[$name,$target]);$d=['id'=>(int)($old['id']??0),'rule_name'=>$name,'target_name'=>$target,'target_type'=>$get($r,'对象类型'),'target_contact'=>$get($r,'联系方式'),'commission_mode'=>$get($r,'佣金模式')?:'percent','commission_value'=>(float)$get($r,'佣金值'),'calc_base'=>$get($r,'计算基准')?:'order_amount','currency'=>strtoupper($get($r,'币种')?:'USD'),'settle_node'=>$get($r,'结算节点')?:'payment_received','apply_scope'=>$get($r,'适用范围')?:'all','customer_name'=>$get($r,'指定客户'),'product_model'=>$get($r,'指定产品'),'category'=>$get($r,'指定分类'),'is_active'=>!in_array(trim($get($r,'状态')),['停用','0'],true),'note'=>$get($r,'备注')];quote_commission_rule_save($pdo,$d,$user);$ok++;}catch(Throwable $e){$bad++;$errors[]=['line'=>$i+2,'reason'=>$e->getMessage()];}}
  $result=['success'=>$ok,'failed'=>$bad,'errors'=>$errors];quote_log_event($pdo,['action'=>'commission_rule_import','event'=>'导入佣金规则','user_name'=>quote_price_policy_actor($user),'summary'=>'导入佣金规则：成功 '.$ok.'，失败 '.$bad,'detail'=>$result]);return $result;
}
function quote_price_policy_match($pdo){
  ensure_quote_price_policy_schema($pdo);
  $model=trim((string)($_GET['product_model']??''));
  $namingId=trim((string)($_GET['naming_id']??''));
  $qty=max(0,(float)($_GET['qty']??0));
  if($model===''&&$namingId==='')return ['matched'=>0,'message'=>'未设置价格策略，当前使用旧报价逻辑'];
  $where=["p.status='active'"];$args=[];
  if($namingId!==''){$where[]='p.naming_id=?';$args[]=$namingId;}
  if($model!==''){$where[]='UPPER(p.product_model)=UPPER(?)';$args[]=$model;}
  $policy=row($pdo,'SELECT p.*,l.level_name,l.level_code FROM quote_price_policies p LEFT JOIN quote_price_policy_levels l ON l.id=p.level_id WHERE '.implode(' AND ',$where).' ORDER BY p.id ASC LIMIT 1',$args);
  if(!$policy && $model!=='' && $namingId!==''){
    $policy=row($pdo,"SELECT p.*,l.level_name,l.level_code FROM quote_price_policies p LEFT JOIN quote_price_policy_levels l ON l.id=p.level_id WHERE p.status='active' AND UPPER(p.product_model)=UPPER(?) ORDER BY p.id ASC LIMIT 1",[$model]);
  }
  if(!$policy)return ['matched'=>0,'message'=>'未设置价格策略，当前使用旧报价逻辑'];
  $tier=row($pdo,"SELECT * FROM quote_price_tiers WHERE policy_id=? AND min_qty<=? AND final_price IS NOT NULL ORDER BY min_qty DESC,FIELD(source,'approval','manual','manual_override','level_auto') ASC,id DESC LIMIT 1",[(int)$policy['id'],$qty]);
  $source=$tier['source']??'';$labels=['approval'=>'特批','manual'=>'手填','manual_override'=>'手动覆盖','level_auto'=>'等级生成'];
  return [
    'matched'=>$tier?1:0,
    'has_policy'=>1,
    'policy_id'=>(int)$policy['id'],
    'product_model'=>(string)$policy['product_model'],
    'moq'=>(float)$policy['moq'],
    'has_stock'=>(int)$policy['has_stock'],
    'stock_qty'=>(float)$policy['stock_qty'],
    'allow_below_moq'=>(int)$policy['allow_below_moq'],
    'need_approval_below_moq'=>(int)$policy['need_approval_below_moq'],
    'lead_time'=>(string)$policy['lead_time'],
    'currency'=>(string)$policy['currency'],
    'price_mode'=>(string)$policy['price_mode'],
    'level_id'=>(int)$policy['level_id'],
    'level_name'=>(string)($policy['level_name']??''),
    'tier_id'=>(int)($tier['id']??0),
    'matched_min_qty'=>$tier?(float)$tier['min_qty']:null,
    'final_price'=>$tier?(float)$tier['final_price']:null,
    'price_source'=>$source,
    'price_source_label'=>$labels[$source]??'',
    'message'=>$tier?'':'已设置价格策略，但当前数量没有可匹配的阶梯价，继续使用旧报价逻辑'
  ];
}
function quote_price_policy_first_value($row,$keys,$default=''){
  foreach($keys as $key){ if(array_key_exists($key,$row) && trim((string)$row[$key])!=='') return trim((string)$row[$key]); }
  return $default;
}
function quote_price_policy_lamp_type($row){
  $raw=quote_price_policy_first_value($row,['lamp_type','type','product_type','dimension_type','category','item_name'],'');
  $nameText=implode(' ',array_filter([(string)($row['product_name']??''),(string)($row['series_name']??''),(string)($row['web_series']??'')]));
  $text=implode(' ',array_filter([(string)($row['category']??''),(string)($row['item_name']??''),$raw,$nameText]));
  if(preg_match('/线性|线条|linear/i',$nameText))return '线性灯';
  foreach([
    ['外购产品','/外购|outsource|purchased/i'],['嵌入式','/嵌入|无边|有边|recessed|downlight/i'],
    ['导轨灯','/导轨|track/i'],['磁吸灯','/磁吸|magnetic/i'],['明装灯','/明装|surface/i'],['吊线灯','/吊线|吊装|pendant/i'],['户外灯','/户外|outdoor/i']
  ] as $rule){if(preg_match($rule[1],$text))return $rule[0];}
  return $raw;
}
function quote_price_policy_mapped_category($pdo,$sourceCategory,$sourceLampType){
  $maps=rows($pdo,"SELECT * FROM quote_price_policy_options WHERE option_group='category_mapping' AND is_active=1 ORDER BY sort_order,id");
  foreach($maps as $m){$e=quote_price_policy_option_extra($m);$sc=trim((string)($e['source_category']??''));$sl=trim((string)($e['source_lamp_type']??''));
    if(($sc===''||$sc===$sourceCategory)&&($sl===''||$sl===$sourceLampType))return [(string)($e['display_category']??$sourceCategory),(string)($e['display_lamp_type']??$sourceLampType)];
  }
  return [$sourceCategory,$sourceLampType];
}
function quote_price_policy_estimated_sale_rmb($pdo,$policy,$bomCost=null){
  $cost=$bomCost===null?(float)($policy['bom_cost_rmb']??0):(float)$bomCost;if($cost<=0)return 0;
  $levelId=(int)($policy['level_id']??0);$multiplier=0;
  if($levelId>0){$level=row($pdo,'SELECT base_multiplier FROM quote_price_policy_levels WHERE id=? LIMIT 1',[$levelId]);$multiplier=(float)($level['base_multiplier']??0);}
  if($multiplier<=0){$def=row($pdo,'SELECT base_multiplier FROM quote_price_policy_levels WHERE is_default=1 AND is_active=1 ORDER BY sort_order,id LIMIT 1');$multiplier=(float)($def['base_multiplier']??1.35);}
  return round($cost*($multiplier>0?$multiplier:1.35),4);
}
function quote_price_policy_sync_bom_costs($pdo,$user,$writeLog=true){
  ensure_quote_price_policy_schema($pdo);$costMap=get_bom_cost_map($pdo);$actor=quote_price_policy_actor($user);$matched=0;$missing=0;$changed=0;
  // BOM 只提供整灯成本；预计卖价始终在价格策略中心按“成本 × 产品等级倍率”计算。
  $costUp=$pdo->prepare('UPDATE quote_price_policies SET bom_cost_rmb=?,bom_cost_source=?,bom_match_key=?,bom_cost_updated_at=?,updated_by=?,updated_at=NOW() WHERE id=?');
  $saleUp=$pdo->prepare('UPDATE quote_price_policies SET estimated_sale_price_rmb=? WHERE id=?');
  foreach(rows($pdo,'SELECT * FROM quote_price_policies ORDER BY id') as $policy){
    $product=['source'=>$policy['product_source']?:'naming','naming_id'=>$policy['naming_id'],'code'=>$policy['product_model'],'model'=>$policy['product_model'],'model_no'=>$policy['product_model']];
    $ok=apply_bom_cost($product,$costMap);$cost=$ok?(float)($product['cost_rmb']??0):0;$estimated=quote_price_policy_estimated_sale_rmb($pdo,$policy,$cost);
    $source=$ok?(string)($product['bom_cost_source']??''):'';$key=$ok?(string)($product['bom_match_key']??''):'';$updated=$ok?(string)($product['cost_updated_at']??''):'';
    if($ok)$matched++;else$missing++;
    $costChanged=abs((float)($policy['bom_cost_rmb']??0)-$cost)>.0001||$source!==(string)($policy['bom_cost_source']??'')||$key!==(string)($policy['bom_match_key']??'');
    $saleChanged=abs((float)($policy['estimated_sale_price_rmb']??0)-$estimated)>.0001;
    if($costChanged)$costUp->execute([$cost,$source,$key,$updated,$actor,(int)$policy['id']]);
    if($saleChanged)$saleUp->execute([$estimated,(int)$policy['id']]);
    if($costChanged||$saleChanged)$changed++;
  }
  $result=['total'=>$matched+$missing,'matched'=>$matched,'missing'=>$missing,'changed'=>$changed,'cost_map_count'=>count($costMap)];
  if($writeLog)quote_log_event($pdo,['action'=>'price_policy_sync_bom_costs','event'=>'同步价格策略BOM成本','user_name'=>$actor,'summary'=>'BOM成本同步：匹配 '.$matched.'，未匹配 '.$missing.'，更新 '.$changed,'detail'=>$result]);
  return $result;
}
function quote_price_policy_sync_naming_products($pdo,$user){
  ensure_quote_price_policy_schema($pdo);
  if(!table_exists($pdo,'naming_models')) fail('命名系统产品表 naming_models 不存在');
  $cols=table_columns($pdo,'naming_models');
  if(!in_array('id',$cols,true)||!in_array('model_no',$cols,true)) fail('命名系统产品表缺少 id 或 model_no 字段');
  $where=["model_no<>''"];
  if(in_array('website_deleted',$cols,true)) $where[]='COALESCE(website_deleted,0)=0';
  if(in_array('status',$cols,true)) $where[]="LOWER(COALESCE(status,'')) NOT IN ('停用','已删除','disabled','inactive','deleted')";
  $models=rows($pdo,'SELECT * FROM naming_models WHERE '.implode(' AND ',$where).' ORDER BY id ASC LIMIT 20000');
  $byNaming=[];$byModel=[];
  foreach(rows($pdo,'SELECT id,naming_id,product_model FROM quote_price_policies ORDER BY id ASC') as $p){
    $nid=trim((string)($p['naming_id']??''));$model=strtoupper(trim((string)($p['product_model']??'')));
    if($nid!=='')$byNaming[$nid]=(int)$p['id'];if($model!==''&&!isset($byModel[$model]))$byModel[$model]=(int)$p['id'];
  }
  $actor=quote_price_policy_actor($user);$created=0;$updated=0;$skipped=0;$errors=[];
  $update=$pdo->prepare('UPDATE quote_price_policies SET product_source=?,product_id=?,naming_id=?,product_model=?,product_name=?,series=?,source_lamp_type=?,lamp_type=?,source_category=?,category=?,image=?,updated_by=?,updated_at=NOW() WHERE id=?');
  $insert=$pdo->prepare("INSERT INTO quote_price_policies(product_source,product_id,naming_id,product_model,product_name,series,source_lamp_type,lamp_type,source_category,category,image,has_stock,stock_qty,stock_status_code,moq,moq_status_code,allow_below_moq,need_approval_below_moq,below_moq_rule_code,lead_time,currency,price_mode,level_id,base_cost,base_price,status,note,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,0,'no_stock',0,'unset',0,1,'approval','','USD','manual',0,0,0,'active','',?,?)");
  foreach($models as $m){
    try{
      $namingId=trim((string)($m['id']??''));$model=trim((string)($m['model_no']??''));if($namingId===''||$model===''){ $skipped++;continue; }
      $source=quote_price_policy_first_value($m,['source_system'],'naming');if($source==='')$source='naming';
      $name=quote_price_policy_first_value($m,['product_name','series_name','web_series','item_name','lamp_type'],$model);
      $series=quote_price_policy_first_value($m,['web_series','series_name','product_name'],'');
      $sourceLampType=quote_price_policy_lamp_type($m);
      $sourceCategory=quote_price_policy_first_value($m,['category','item_name','lamp_type'],'');
      [$category,$lampType]=quote_price_policy_mapped_category($pdo,$sourceCategory,$sourceLampType);
      $image=quote_price_policy_first_value($m,['image_path','web_image_url','source_image_url'],'');
      $key=strtoupper($model);$id=$byNaming[$namingId]??($byModel[$key]??0);
      if($id>0){
        $update->execute([$source,$namingId,$namingId,$model,$name,$series,$sourceLampType,$lampType,$sourceCategory,$category,$image,$actor,$id]);$updated++;
      }else{
        $insert->execute([$source,$namingId,$namingId,$model,$name,$series,$sourceLampType,$lampType,$sourceCategory,$category,$image,$actor,$actor]);$id=(int)$pdo->lastInsertId();$created++;$byNaming[$namingId]=$id;$byModel[$key]=$id;
      }
    }catch(Throwable $e){$skipped++;if(count($errors)<20)$errors[]=['naming_id'=>$m['id']??'','model'=>$m['model_no']??'','error'=>$e->getMessage()];}
  }
  $result=['source_total'=>count($models),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors];
  $result['bom_costs']=quote_price_policy_sync_bom_costs($pdo,$user,false);
  quote_log_event($pdo,['action'=>'price_policy_sync_naming_products','event'=>'同步命名产品到价格策略','user_name'=>$actor,'summary'=>'同步命名产品：新增 '.$created.'，更新 '.$updated.'，跳过 '.$skipped,'detail'=>$result]);
  return $result;
}
function quote_price_qty_factor($qty,$pdo=null){
  $qty=(float)$qty;$factor=1.30;
  $rules=[[1,1.30],[10,1.15],[50,1.05],[100,1.00],[300,.95],[500,.90],[1000,.88]];
  if($pdo){$configured=[];foreach(rows($pdo,"SELECT option_value,extra_json FROM quote_price_policy_options WHERE option_group='tier_qty' AND is_active=1 ORDER BY CAST(option_value AS DECIMAL(12,2)),sort_order,id") as $o){$q=(float)$o['option_value'];if($q<=0)continue;$e=quote_price_policy_option_extra($o);$configured[]=[$q,(float)($e['price_factor']??quote_price_qty_factor($q,null))];}if($configured)$rules=$configured;}
  foreach($rules as $r){if($qty>=$r[0])$factor=$r[1];}
  return $factor;
}
function quote_price_policy_auto_base($pdo,$policy){
  $basePrice=(float)($policy['base_price']??0);if($basePrice>0)return $basePrice;
  $cost=(float)($policy['base_cost']??0);if($cost<=0)return null;
  $levelId=(int)($policy['level_id']??0);$level=$levelId>0?row($pdo,'SELECT * FROM quote_price_policy_levels WHERE id=? AND is_active=1 LIMIT 1',[$levelId]):null;
  if(!$level)return null;return $cost*(float)($level['base_multiplier']??1.35);
}
function quote_price_tier_calculated($pdo,$policy,$tier){
  $mode=quote_price_policy_mode($policy['price_mode']??'manual');$manual=quote_nullable_decimal($tier['manual_price']??null);$requested=quote_price_tier_source($tier['source']??'manual');
  $base=quote_price_policy_auto_base($pdo,$policy);$auto=$base===null?null:round($base*quote_price_qty_factor($tier['min_qty']??0,$pdo),4);
  if($requested==='approval'&&$manual!==null)return ['manual_price'=>$manual,'auto_price'=>$auto,'final_price'=>$manual,'source'=>'approval'];
  if($mode==='manual'||!in_array($mode,['level_auto','mixed'],true))return ['manual_price'=>$manual,'auto_price'=>null,'final_price'=>$manual,'source'=>'manual'];
  if($mode==='level_auto')return ['manual_price'=>$manual,'auto_price'=>$auto,'final_price'=>$auto,'source'=>'level_auto'];
  if($manual!==null)return ['manual_price'=>$manual,'auto_price'=>$auto,'final_price'=>$manual,'source'=>'manual_override'];
  return ['manual_price'=>null,'auto_price'=>$auto,'final_price'=>$auto,'source'=>'level_auto'];
}
function quote_price_policy_recalculate_tiers($pdo,$policyId){
  $policy=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[(int)$policyId]);if(!$policy)return 0;$count=0;
  $up=$pdo->prepare('UPDATE quote_price_tiers SET manual_price=?,auto_price=?,final_price=?,source=?,currency=?,updated_at=NOW() WHERE id=?');
  foreach(rows($pdo,'SELECT * FROM quote_price_tiers WHERE policy_id=? ORDER BY min_qty ASC,id ASC',[(int)$policyId]) as $tier){$calc=quote_price_tier_calculated($pdo,$policy,$tier);$up->execute([$calc['manual_price'],$calc['auto_price'],$calc['final_price'],$calc['source'],$policy['currency']??'USD',(int)$tier['id']]);$count++;}
  return $count;
}
function quote_price_policy_save($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);$actor=quote_price_policy_actor($user);
  if($id>0 && !row($pdo,'SELECT id FROM quote_price_policies WHERE id=? LIMIT 1',[$id])) fail('价格策略不存在');
  $before=$id>0?row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$id]):null;
  if($id<=0){$d=array_merge(['has_stock'=>0,'stock_qty'=>0,'stock_status_code'=>'no_stock','moq'=>0,'moq_status_code'=>'unset','allow_below_moq'=>0,'need_approval_below_moq'=>1,'below_moq_rule_code'=>'approval','lead_time'=>'','currency'=>'USD','price_mode'=>'manual','level_id'=>0,'base_cost'=>0,'base_price'=>0,'status'=>'active','note'=>''],$d);}
  if(array_key_exists('price_mode',$d))$d['price_mode']=quote_price_policy_mode($d['price_mode']);
  if(array_key_exists('currency',$d))$d['currency']=strtoupper(s($d['currency'],20))?:'USD';
  if(array_key_exists('status',$d)){$policyStatus=(string)$d['status'];$d['status']=in_array($policyStatus,['active','inactive'],true)?$policyStatus:'active';}
  foreach(['has_stock','allow_below_moq','need_approval_below_moq'] as $k)if(array_key_exists($k,$d))$d[$k]=empty($d[$k])?0:1;
  if(array_key_exists('stock_status_code',$d)){
    $d['stock_status_code']=s($d['stock_status_code'],80);
    if(in_array($d['stock_status_code'],['in_stock','partial','clearance'],true))$d['has_stock']=1;
    elseif(in_array($d['stock_status_code'],['no_stock'],true))$d['has_stock']=0;
  }
  foreach(['moq_status_code','below_moq_rule_code'] as $k)if(array_key_exists($k,$d))$d[$k]=s($d[$k],80);
  foreach(['stock_qty','moq','base_cost','base_price'] as $k)if(array_key_exists($k,$d))$d[$k]=max(0,(float)$d[$k]);
  if(array_key_exists('level_id',$d))$d['level_id']=max(0,(int)$d['level_id']);$d['updated_by']=$actor;if($id<=0)$d['created_by']=$actor;
  $fields=['product_source','product_id','naming_id','product_model','product_name','series','source_lamp_type','lamp_type','source_category','category','image','has_stock','stock_qty','stock_status_code','moq','moq_status_code','allow_below_moq','need_approval_below_moq','below_moq_rule_code','lead_time','currency','price_mode','level_id','base_cost','base_price','status','note','created_by','updated_by'];
  $id=(int)save_row($pdo,'quote_price_policies',$d,$fields);
  $savedPolicy=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$id]);
  $estimated=quote_price_policy_estimated_sale_rmb($pdo,$savedPolicy);$pdo->prepare('UPDATE quote_price_policies SET estimated_sale_price_rmb=? WHERE id=?')->execute([$estimated,$id]);
  $recalculated=quote_price_policy_recalculate_tiers($pdo,$id);
  $after=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$id]);
  if($before&&array_key_exists('stock_qty',$d)&&abs((float)($before['stock_qty']??0)-(float)($after['stock_qty']??0))>0.000001){
    $oldStock=(float)($before['stock_qty']??0);$newStock=(float)($after['stock_qty']??0);
    $pdo->prepare('INSERT INTO quote_price_stock_logs(policy_id,product_model,product_name,old_stock,new_stock,change_qty,adjust_type,reason,operator,ip) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$after['product_model']??'',$after['product_name']??'',$oldStock,$newStock,$newStock-$oldStock,'correct',s($d['stock_reason']??'价格策略表直接维护',500),$actor,s($_SERVER['REMOTE_ADDR']??'',80)]);
  }
  quote_log_event($pdo,['action'=>'price_policy_save','event'=>'保存价格策略','user_name'=>$actor,'summary'=>'保存价格策略：'.($after['product_model']??('ID '.$id)),'detail'=>array_merge($d,['id'=>$id,'product_model'=>$after['product_model']??'']),'before'=>$before,'after'=>$after]);
  return ['id'=>$id,'policy'=>$after,'recalculated_tiers'=>$recalculated];
}
function quote_price_policy_batch_save($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$items=$d['items']??[];if(!is_array($items)||!$items)fail('没有需要保存的修改');
  if(count($items)>2000)fail('一次最多保存 2000 行');
  $allowed=['has_stock','stock_qty','moq','lead_time','currency','price_mode','level_id','status','note'];
  $saved=[];$errors=[];
  foreach($items as $index=>$item){
    try{
      if(!is_array($item))throw new RuntimeException('数据格式不正确');
      $id=(int)($item['id']??0);if($id<=0)throw new RuntimeException('缺少策略ID');
      if(!row($pdo,'SELECT id FROM quote_price_policies WHERE id=? LIMIT 1',[$id]))throw new RuntimeException('价格策略不存在');
      $payload=['id'=>$id];foreach($allowed as $field)if(array_key_exists($field,$item))$payload[$field]=$item[$field];
      if(count($payload)===1)throw new RuntimeException('没有可保存的字段');
      if(isset($payload['currency'])&&!in_array(strtoupper((string)$payload['currency']),['USD','RMB'],true))throw new RuntimeException('币种只允许 USD 或 RMB');
      $result=quote_price_policy_save($pdo,$payload,$user);$saved[]=['id'=>$id,'policy'=>$result['policy']??null];
    }catch(Throwable $e){$errors[]=['index'=>$index,'id'=>(int)($item['id']??0),'reason'=>$e->getMessage()];}
  }
  $result=['saved'=>$saved,'saved_count'=>count($saved),'failed_count'=>count($errors),'errors'=>$errors];
  quote_log_event($pdo,['action'=>'price_policy_batch_save','event'=>'批量保存价格策略','user_name'=>quote_price_policy_actor($user),'summary'=>'批量保存价格策略：成功 '.count($saved).'，失败 '.count($errors),'detail'=>$result]);
  return $result;
}
function quote_price_stock_adjust($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['policy_id']??$d['id']??0);if($id<=0)fail('缺少价格策略ID');
  $type=trim((string)($d['adjust_type']??''));if(!in_array($type,['increase','decrease','correct'],true))fail('库存调整方式不正确');
  $qty=(float)($d['qty']??0);if($qty<0)fail('调整数量不能小于 0');if($type!=='correct'&&$qty<=0)fail('调整数量必须大于 0');
  $reason=s($d['reason']??'',500);if($reason==='')fail('请填写库存调整原因');
  $actor=quote_price_policy_actor($user);$ip=s($_SERVER['REMOTE_ADDR']??'',80);
  $pdo->beginTransaction();
  try{
    $st=$pdo->prepare('SELECT * FROM quote_price_policies WHERE id=? FOR UPDATE');$st->execute([$id]);$policy=$st->fetch(PDO::FETCH_ASSOC);if(!$policy)throw new RuntimeException('价格策略不存在');
    $old=(float)($policy['stock_qty']??0);$new=$type==='increase'?$old+$qty:($type==='decrease'?$old-$qty:$qty);if($new<0)throw new RuntimeException('库存不能小于 0');
    $change=$new-$old;$has=$new>0?1:0;
    $pdo->prepare('UPDATE quote_price_policies SET stock_qty=?,has_stock=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$new,$has,$actor,$id]);
    $pdo->prepare('INSERT INTO quote_price_stock_logs(policy_id,product_model,product_name,old_stock,new_stock,change_qty,adjust_type,reason,operator,ip) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$policy['product_model']??'',$policy['product_name']??'',$old,$new,$change,$type,$reason,$actor,$ip]);
    $logId=(int)$pdo->lastInsertId();$pdo->commit();
    $after=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$id]);
    quote_log_event($pdo,['action'=>'price_stock_adjust','event'=>'调整价格策略库存','user_name'=>$actor,'summary'=>'库存调整：'.($policy['product_model']??$id).' '.$old.' → '.$new,'detail'=>['log_id'=>$logId,'product_model'=>$policy['product_model']??'','adjust_type'=>$type,'qty'=>$qty,'reason'=>$reason],'before'=>$policy,'after'=>$after]);
    return ['policy'=>$after,'log'=>row($pdo,'SELECT * FROM quote_price_stock_logs WHERE id=? LIMIT 1',[$logId])];
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function quote_price_stock_log_list($pdo){
  ensure_quote_price_policy_schema($pdo);$id=(int)($_GET['policy_id']??0);if($id<=0)fail('缺少价格策略ID');
  return ['logs'=>rows($pdo,'SELECT * FROM quote_price_stock_logs WHERE policy_id=? ORDER BY id DESC LIMIT 100',[$id])];
}
function quote_price_policy_delete($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);if($id<=0)fail('缺少价格策略ID');
  $before=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$id]);if(!$before)fail('价格策略不存在');
  $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM quote_price_tiers WHERE policy_id=?')->execute([$id]);$pdo->prepare('DELETE FROM quote_price_policies WHERE id=?')->execute([$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  quote_log_event($pdo,['action'=>'price_policy_delete','event'=>'删除价格策略','user_name'=>quote_price_policy_actor($user),'summary'=>'删除价格策略：'.($before['product_model']??$id),'detail'=>['id'=>$id],'before'=>$before]);
  return ['id'=>$id];
}
function quote_price_tier_list($pdo){
  ensure_quote_price_policy_schema($pdo);$policyId=(int)($_GET['policy_id']??0);if($policyId<=0)fail('缺少价格策略ID');
  return ['policy'=>row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$policyId]),'tiers'=>rows($pdo,'SELECT * FROM quote_price_tiers WHERE policy_id=? ORDER BY sort_order ASC,min_qty ASC,id ASC',[$policyId])];
}
function quote_price_tier_save($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);$policyId=(int)($d['policy_id']??0);if($policyId<=0||!row($pdo,'SELECT id FROM quote_price_policies WHERE id=? LIMIT 1',[$policyId]))fail('价格策略不存在');
  if($id>0&&!row($pdo,'SELECT id FROM quote_price_tiers WHERE id=? AND policy_id=? LIMIT 1',[$id,$policyId]))fail('阶梯价不存在或不属于当前策略');
  $before=$id>0?row($pdo,'SELECT * FROM quote_price_tiers WHERE id=? LIMIT 1',[$id]):null;
  $policy=row($pdo,'SELECT * FROM quote_price_policies WHERE id=? LIMIT 1',[$policyId]);
  $d['policy_id']=$policyId;$d['min_qty']=max(0,(float)($d['min_qty']??0));$d['manual_price']=quote_nullable_decimal($d['manual_price']??null);
  $d['currency']=strtoupper(s($policy['currency']??($d['currency']??'USD'),20))?:'USD';$d['source']=quote_price_tier_source($d['source']??'manual');$d['sort_order']=(int)($d['sort_order']??0);$d['note']=s($d['note']??'',255);
  $calc=quote_price_tier_calculated($pdo,$policy,$d);$d=array_merge($d,$calc);
  $id=(int)save_row($pdo,'quote_price_tiers',$d,['policy_id','min_qty','manual_price','auto_price','final_price','currency','source','note','sort_order']);
  $after=row($pdo,'SELECT * FROM quote_price_tiers WHERE id=? LIMIT 1',[$id]);
  quote_log_event($pdo,['action'=>'price_tier_save','event'=>'保存阶梯价','user_name'=>quote_price_policy_actor($user),'summary'=>'保存阶梯价：'.($policy['product_model']??('策略 '.$policyId)).' / 数量 '.$d['min_qty'],'detail'=>array_merge($d,['id'=>$id,'product_model'=>$policy['product_model']??'']),'before'=>$before,'after'=>$after]);
  return ['id'=>$id,'tier'=>$after];
}
function quote_price_tier_delete($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);if($id<=0)fail('缺少阶梯价ID');$before=row($pdo,'SELECT * FROM quote_price_tiers WHERE id=? LIMIT 1',[$id]);if(!$before)fail('阶梯价不存在');
  $pdo->prepare('DELETE FROM quote_price_tiers WHERE id=?')->execute([$id]);quote_log_event($pdo,['action'=>'price_tier_delete','event'=>'删除阶梯价','user_name'=>quote_price_policy_actor($user),'summary'=>'删除阶梯价：ID '.$id,'detail'=>['id'=>$id],'before'=>$before]);return ['id'=>$id];
}
function quote_price_policy_levels_list($pdo){ ensure_quote_price_policy_schema($pdo);return ['levels'=>rows($pdo,'SELECT * FROM quote_price_policy_levels ORDER BY is_active DESC,is_default DESC,sort_order ASC,id ASC')]; }
function quote_price_policy_level_save($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);if($id>0&&!row($pdo,'SELECT id FROM quote_price_policy_levels WHERE id=? LIMIT 1',[$id]))fail('产品等级不存在');$name=s($d['level_name']??'',120);if($name==='')fail('请填写产品等级名称');$d['level_name']=$name;$d['level_code']=s($d['level_code']??'',50);
  foreach(['base_multiplier','point_percent','profit_percent','sample_multiplier'] as $k)$d[$k]=max(0,(float)($d[$k]??($k==='base_multiplier'?1.35:($k==='sample_multiplier'?2:0))));
  $d['is_default']=empty($d['is_default'])?0:1;$d['is_active']=array_key_exists('is_active',$d)?(empty($d['is_active'])?0:1):1;$d['sort_order']=(int)($d['sort_order']??0);$d['note']=s($d['note']??'',255);
  if($d['is_default'])$pdo->exec('UPDATE quote_price_policy_levels SET is_default=0');
  $id=(int)save_row($pdo,'quote_price_policy_levels',$d,['level_name','level_code','base_multiplier','point_percent','profit_percent','sample_multiplier','is_default','is_active','note','sort_order']);
  $tierCount=0;foreach(rows($pdo,'SELECT id FROM quote_price_policies WHERE level_id=?',[$id]) as $policy)$tierCount+=quote_price_policy_recalculate_tiers($pdo,(int)$policy['id']);
  quote_log_event($pdo,['action'=>'price_policy_level_save','event'=>'保存价格策略等级','user_name'=>quote_price_policy_actor($user),'summary'=>'保存价格策略等级：'.$name,'detail'=>array_merge($d,['id'=>$id,'recalculated_tiers'=>$tierCount])]);return ['id'=>$id,'level'=>row($pdo,'SELECT * FROM quote_price_policy_levels WHERE id=? LIMIT 1',[$id]),'recalculated_tiers'=>$tierCount];
}
function quote_price_policy_level_delete($pdo,$d,$user){
  ensure_quote_price_policy_schema($pdo);$id=(int)($d['id']??0);if($id<=0)fail('缺少产品等级ID');$before=row($pdo,'SELECT * FROM quote_price_policy_levels WHERE id=? LIMIT 1',[$id]);if(!$before)fail('产品等级不存在');
  $pdo->prepare('UPDATE quote_price_policy_levels SET is_active=0,is_default=0 WHERE id=?')->execute([$id]);quote_log_event($pdo,['action'=>'price_policy_level_delete','event'=>'停用价格策略等级','user_name'=>quote_price_policy_actor($user),'summary'=>'停用价格策略等级：'.($before['level_name']??$id),'detail'=>['id'=>$id],'before'=>$before]);return ['id'=>$id,'status'=>'inactive'];
}

function quote_price_excel_xml($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_XML1,'UTF-8');}
function quote_price_excel_col($n){$s='';while($n>0){$m=($n-1)%26;$s=chr(65+$m).$s;$n=intdiv($n-1,26);}return $s;}
function quote_price_excel_cell($col,$row,$value,$style=0,$numeric=false){$ref=quote_price_excel_col($col).$row;$s=$style?' s="'.$style.'"':'';if($numeric&&$value!==null&&$value!==''&&is_numeric($value))return '<c r="'.$ref.'"'.$s.'><v>'.(0+$value).'</v></c>';return '<c r="'.$ref.'" t="inlineStr"'.$s.'><is><t xml:space="preserve">'.quote_price_excel_xml($value).'</t></is></c>';}
function quote_price_excel_zip($files){
  $d=getdate();$dosTime=(($d['hours']&0x1F)<<11)|(($d['minutes']&0x3F)<<5)|(int)(($d['seconds']&0x3E)/2);$dosDate=((($d['year']-1980)&0x7F)<<9)|(($d['mon']&0x0F)<<5)|($d['mday']&0x1F);$out='';$central='';$offset=0;$count=0;
  foreach($files as $name=>$data){$data=(string)$data;$crc=crc32($data);if($crc<0)$crc+=4294967296;$size=strlen($data);$nlen=strlen($name);$local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0).$name.$data;$out.=$local;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,0x0314,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0,0,0,0,0,$offset).$name;$offset+=strlen($local);$count++;}
  $cdOffset=strlen($out);$cdSize=strlen($central);return $out.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,$cdSize,$cdOffset,0);
}
function quote_price_policy_export_excel($pdo,$user){
  ensure_quote_price_policy_schema($pdo);$headers=['产品型号','产品名称','系列','分类','是否有库存','库存数量','MOQ','是否允许低于 MOQ','低于 MOQ 是否需审批','交期','币种','价格模式','产品等级','起订数量','手动价','系统生成价','最终价','备注'];
  $data=rows($pdo,"SELECT p.*,l.level_name,t.min_qty,t.manual_price,t.auto_price,t.final_price,t.note AS tier_note,t.id AS tier_id FROM quote_price_policies p LEFT JOIN quote_price_policy_levels l ON l.id=p.level_id LEFT JOIN quote_price_tiers t ON t.policy_id=p.id ORDER BY p.product_model ASC,t.sort_order ASC,t.min_qty ASC,t.id ASC");
  $sheetRows=[];$cells=[];foreach($headers as $i=>$h)$cells[]=quote_price_excel_cell($i+1,1,$h,1);$sheetRows[]='<row r="1" ht="28" customHeight="1">'.implode('',$cells).'</row>';$r=2;
  foreach($data as $x){$hasTier=!empty($x['tier_id']);$vals=[$x['product_model'],$x['product_name'],$x['series'],$x['category'],!empty($x['has_stock'])?'是':'否',$x['stock_qty'],$x['moq'],!empty($x['allow_below_moq'])?'是':'否',!empty($x['need_approval_below_moq'])?'是':'否',$x['lead_time'],$x['currency'],$x['price_mode'],$x['level_name']??'',$hasTier?$x['min_qty']:'',$hasTier?$x['manual_price']:'',$hasTier?$x['auto_price']:'',$hasTier?$x['final_price']:'',$hasTier?($x['tier_note']??''):($x['note']??'')];$numeric=[6,7,14,15,16,17];$cells=[];foreach($vals as $i=>$v)$cells[]=quote_price_excel_cell($i+1,$r,$v,2,in_array($i+1,$numeric,true));$sheetRows[]='<row r="'.$r.'">'.implode('',$cells).'</row>';$r++;}
  $last=max(1,$r-1);$widths=[16,24,22,22,12,12,12,18,20,18,10,18,16,12,14,14,14,28];$cols='<cols>';foreach($widths as $i=>$w)$cols.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';$cols.='</cols>';
  $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="21"/>'.$cols.'<sheetData>'.implode('',$sheetRows).'</sheetData><autoFilter ref="A1:R'.$last.'"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
  $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Microsoft YaHei"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Microsoft YaHei"/></font><font><sz val="10"/><name val="Microsoft YaHei"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8DEE9"/></left><right style="thin"><color rgb="FFD8DEE9"/></right><top style="thin"><color rgb="FFD8DEE9"/></top><bottom style="thin"><color rgb="FFD8DEE9"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="4" fontId="2" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
  $files=['[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>','_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="价格策略" sheetId="1" r:id="rId1"/></sheets></workbook>','xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>','xl/worksheets/sheet1.xml'=>$sheet,'xl/styles.xml'=>$styles];
  $bin=quote_price_excel_zip($files);quote_log_event($pdo,['action'=>'price_policy_export_excel','event'=>'导出价格策略 Excel','user_name'=>quote_price_policy_actor($user),'summary'=>'导出价格策略 Excel：'.count($data).' 行']);while(ob_get_level()>0)@ob_end_clean();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="price_policy_'.date('Ymd_His').'.xlsx"');header('Content-Length: '.strlen($bin));echo $bin;exit;
}
function quote_price_zip_entries($zip){
  if(strlen($zip)>25000000)throw new RuntimeException('Excel 文件不能超过 25MB');$eocd=strrpos($zip,"PK\x05\x06");if($eocd===false)throw new RuntimeException('不是有效的 XLSX 文件');$end=unpack('vdisk/vstart/vdiskCount/vcount/Vsize/Voffset/vcomment',substr($zip,$eocd+4,18));$count=(int)($end['count']??0);if($count<=0||$count>3000)throw new RuntimeException('Excel ZIP 目录异常');$pos=(int)$end['offset'];$out=[];
  for($i=0;$i<$count;$i++){if(substr($zip,$pos,4)!=="PK\x01\x02")throw new RuntimeException('Excel ZIP 目录损坏');$h=unpack('vverMade/vverNeed/vflag/vmethod/vtime/vdate/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset',substr($zip,$pos+4,42));$name=substr($zip,$pos+46,$h['nameLen']);$local=(int)$h['offset'];$lh=unpack('vnameLen/vextraLen',substr($zip,$local+26,4));$start=$local+30+$lh['nameLen']+$lh['extraLen'];$raw=substr($zip,$start,$h['comp']);if($h['method']===0)$data=$raw;elseif($h['method']===8)$data=@gzinflate($raw);else{$data=false;}if($data===false)throw new RuntimeException('Excel 包含不支持的压缩格式');if(strlen($data)>50000000)throw new RuntimeException('Excel 工作表过大');$out[$name]=$data;$pos+=46+$h['nameLen']+$h['extraLen']+$h['commentLen'];}
  return $out;
}
function quote_price_excel_rows_from_upload($file){
  if(empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('请选择要导入的 Excel 文件');$zip=file_get_contents($file['tmp_name']);$entries=quote_price_zip_entries($zip);$shared=[];
  if(isset($entries['xl/sharedStrings.xml'])){$x=@simplexml_load_string($entries['xl/sharedStrings.xml']);if($x)foreach($x->si as $si){$txt=(string)$si->t;if($txt===''&&isset($si->r))foreach($si->r as $run)$txt.=(string)$run->t;$shared[]=$txt;}}
  $sheet=$entries['xl/worksheets/sheet1.xml']??'';if($sheet==='')throw new RuntimeException('Excel 缺少第一个工作表');$x=@simplexml_load_string($sheet);if(!$x)throw new RuntimeException('Excel 工作表 XML 无法解析');$rows=[];
  foreach($x->sheetData->row as $row){$vals=[];foreach($row->c as $cell){$ref=(string)$cell['r'];preg_match('/^([A-Z]+)/',$ref,$m);$letters=$m[1]??'A';$col=0;for($i=0;$i<strlen($letters);$i++)$col=$col*26+(ord($letters[$i])-64);$type=(string)$cell['t'];if($type==='inlineStr'){$v=(string)$cell->is->t;if($v===''&&isset($cell->is->r))foreach($cell->is->r as $run)$v.=(string)$run->t;}else{$v=(string)$cell->v;if($type==='s')$v=$shared[(int)$v]??'';}$vals[$col]=$v;}if($vals)$rows[]=$vals;}
  return $rows;
}
function quote_price_excel_bool($v){$v=strtolower(trim((string)$v));return in_array($v,['1','yes','true','是','有','允许','需要'],true)?1:0;}
function quote_price_excel_mode($v){$v=trim((string)$v);$map=['手填阶梯价'=>'manual','手填'=>'manual','产品等级自动生成'=>'level_auto','等级生成'=>'level_auto','自动生成 + 手动覆盖'=>'mixed','自动生成+手动覆盖'=>'mixed','混合'=>'mixed'];return quote_price_policy_mode($map[$v]??$v);}
function quote_price_policy_import_excel($pdo,$user){
  ensure_quote_price_policy_schema($pdo);$rows=quote_price_excel_rows_from_upload($_FILES['file']??[]);if(count($rows)<2)fail('Excel 没有可导入的数据');$headerRow=array_shift($rows);$headers=[];foreach($headerRow as $col=>$name){$key=preg_replace('/\s+/u',' ',trim((string)$name));if($key!=='')$headers[$key]=$col;}$required=['产品型号'];foreach($required as $h)if(!isset($headers[$h]))fail('Excel 缺少字段：'.$h);
  $val=function($row,$name)use($headers){$c=$headers[$name]??0;return $c?(string)($row[$c]??''):'';};$success=0;$failed=0;$errors=[];$actor=quote_price_policy_actor($user);
  foreach($rows as $index=>$excelRow){$line=$index+2;$model=trim($val($excelRow,'产品型号'));if($model==='')continue;try{$policy=row($pdo,'SELECT * FROM quote_price_policies WHERE UPPER(product_model)=UPPER(?) ORDER BY id ASC LIMIT 1',[$model]);if(!$policy)throw new RuntimeException('找不到对应价格策略');$d=['id'=>(int)$policy['id']];
      foreach(['产品名称'=>'product_name','系列'=>'series','分类'=>'category','交期'=>'lead_time','币种'=>'currency'] as $cn=>$field){$v=trim($val($excelRow,$cn));if($v!=='')$d[$field]=$v;}
      foreach(['是否有库存'=>'has_stock','是否允许低于 MOQ'=>'allow_below_moq','低于 MOQ 是否需审批'=>'need_approval_below_moq'] as $cn=>$field){if(isset($headers[$cn]))$d[$field]=quote_price_excel_bool($val($excelRow,$cn));}
      foreach(['库存数量'=>'stock_qty','MOQ'=>'moq'] as $cn=>$field){$v=trim($val($excelRow,$cn));if($v!==''&&is_numeric($v))$d[$field]=(float)$v;}
      $mode=trim($val($excelRow,'价格模式'));if($mode!=='')$d['price_mode']=quote_price_excel_mode($mode);$levelName=trim($val($excelRow,'产品等级'));if($levelName!==''){$level=row($pdo,'SELECT id FROM quote_price_policy_levels WHERE level_name=? OR level_code=? ORDER BY is_active DESC,id ASC LIMIT 1',[$levelName,$levelName]);if(!$level)throw new RuntimeException('产品等级不存在：'.$levelName);$d['level_id']=(int)$level['id'];}
      $min=trim($val($excelRow,'起订数量'));$note=trim($val($excelRow,'备注'));if($min===''&&$note!=='')$d['note']=$note;quote_price_policy_save($pdo,$d,$user);
      if($min!==''){if(!is_numeric($min))throw new RuntimeException('起订数量不是数字');$manual=trim($val($excelRow,'手动价'));$existing=row($pdo,'SELECT id FROM quote_price_tiers WHERE policy_id=? AND min_qty=? ORDER BY id ASC LIMIT 1',[(int)$policy['id'],(float)$min]);$td=['policy_id'=>(int)$policy['id'],'min_qty'=>(float)$min,'manual_price'=>$manual===''?null:$manual,'currency'=>$d['currency']??$policy['currency'],'note'=>$note,'sort_order'=>(int)round((float)$min)];if($existing)$td['id']=(int)$existing['id'];quote_price_tier_save($pdo,$td,$user);}
      $success++;
    }catch(Throwable $e){$failed++;if(count($errors)<100)$errors[]=['line'=>$line,'product_model'=>$model,'reason'=>$e->getMessage()];}}
  $result=['success'=>$success,'failed'=>$failed,'errors'=>$errors,'total_rows'=>count($rows)];quote_log_event($pdo,['action'=>'price_policy_import_excel','event'=>'导入价格策略 Excel','user_name'=>$actor,'summary'=>'导入价格策略 Excel：成功 '.$success.'，失败 '.$failed,'detail'=>$result]);return $result;
}

function ensure_quote_settings($pdo){
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_headers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL DEFAULT '',
    company VARCHAR(255) NOT NULL DEFAULT '',
    from_text TEXT NULL,
    stamp TEXT NULL,
    show_stamp TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_col($pdo,'quote_headers','show_stamp','`show_stamp` TINYINT(1) NOT NULL DEFAULT 0');
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL DEFAULT '',
    text MEDIUMTEXT NULL,
    extra_terms MEDIUMTEXT NULL,
    extra_terms_font_size DECIMAL(4,1) NOT NULL DEFAULT 7.5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_col($pdo,'quote_banks','extra_terms','`extra_terms` MEDIUMTEXT NULL');
  ensure_col($pdo,'quote_banks','extra_terms_font_size','`extra_terms_font_size` DECIMAL(4,1) NOT NULL DEFAULT 7.5');
  try{ $pdo->exec("ALTER TABLE quote_banks MODIFY COLUMN `text` MEDIUMTEXT NULL"); }catch(Throwable $e){}
  try{ $pdo->exec("ALTER TABLE quote_banks MODIFY COLUMN `extra_terms` MEDIUMTEXT NULL"); }catch(Throwable $e){}
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL DEFAULT '',
    terms_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $hc=(int)$pdo->query("SELECT COUNT(*) FROM quote_headers")->fetchColumn();
  if($hc===0){
    $st=$pdo->prepare("INSERT INTO quote_headers(name,company,from_text,stamp,show_stamp) VALUES(?,?,?,?,?)");
    $st->execute(['默认抬头','Gallin Industrial (HK) Limited','',"GALLIN INDUSTRIAL (HK) LIMITED\n加林实业香港有限公司",0]);
  }
  $bc=(int)$pdo->query("SELECT COUNT(*) FROM quote_banks")->fetchColumn();
  if($bc===0){
    $st=$pdo->prepare("INSERT INTO quote_banks(name,text) VALUES(?,?)");
    $st->execute(['默认银行信息','']);
  }
  $tc=(int)$pdo->query("SELECT COUNT(*) FROM quote_templates")->fetchColumn();
  if($tc===0){
    $terms=[['PI Number:','QTNO'],['Payment:','40% Deposit before production'],['','60% payment before shipment'],['Price Terms:','EXWORK'],['Quoted Date:','DATE'],['Delivery Date:','25-35Days After Confirmed'],['Quoted Valid','Within 10 days']];
    $st=$pdo->prepare("INSERT INTO quote_templates(name,terms_json) VALUES(?,?)");
    $st->execute(['默认报价条款',json_encode($terms,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  }
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_price_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    multiplier DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    note VARCHAR(255) DEFAULT '',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_key VARCHAR(60) NOT NULL,
    value VARCHAR(120) NOT NULL,
    label VARCHAR(120) DEFAULT '',
    note VARCHAR(255) DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_group_key (group_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $cnt=(int)$pdo->query("SELECT COUNT(*) FROM quote_price_levels")->fetchColumn();
  if($cnt===0){
    $st=$pdo->prepare("INSERT INTO quote_price_levels(name,multiplier,note,is_default,is_active,sort_order) VALUES(?,?,?,?,?,?)");
    foreach([
      ['A级',1.25,'大客户 / 老客户',0,1,10],
      ['B级',1.35,'常规报价',1,1,20],
      ['C级',1.50,'新客户 / 小批量',0,1,30],
      ['样品价',2.00,'样品报价',0,1,40],
    ] as $r){ $st->execute($r); }
  }
  $cnt=(int)$pdo->query("SELECT COUNT(*) FROM quote_options WHERE group_key='color'")->fetchColumn();
  if($cnt===0){
    $st=$pdo->prepare("INSERT INTO quote_options(group_key,value,label,note,is_active,sort_order) VALUES('color',?,?,?,?,?)");
    foreach([
      ['White','White','常用白色',1,10],['Black','Black','常用黑色',1,20],['Silver','Silver','银色',1,30],['Grey','Grey','灰色',1,40],['Gold','Gold','金色',1,50],['Custom','Custom','定制颜色',1,60],
    ] as $r){ $st->execute($r); }
  }

  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    updated_by VARCHAR(120) DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $rc=(int)$pdo->query("SELECT COUNT(*) FROM quote_system_settings WHERE setting_key='exchange_rate_usd'")->fetchColumn();
  if($rc===0){
    $st=$pdo->prepare("INSERT INTO quote_system_settings(setting_key,setting_value,updated_by) VALUES('exchange_rate_usd','7.0000','system')");
    $st->execute();
  }

  ensure_table($pdo,"CREATE TABLE IF NOT EXISTS quote_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    level VARCHAR(20) NOT NULL DEFAULT 'INFO',
    module VARCHAR(60) NOT NULL DEFAULT 'quotation',
    action VARCHAR(80) NOT NULL DEFAULT '',
    event VARCHAR(120) NOT NULL DEFAULT '',
    quote_id INT NULL,
    quote_no VARCHAR(100) DEFAULT '',
    customer_id VARCHAR(100) DEFAULT '',
    customer_name VARCHAR(255) DEFAULT '',
    user_name VARCHAR(100) DEFAULT '',
    ip VARCHAR(80) DEFAULT '',
    user_agent VARCHAR(255) DEFAULT '',
    request_method VARCHAR(20) DEFAULT '',
    request_uri VARCHAR(255) DEFAULT '',
    summary TEXT NULL,
    detail_json LONGTEXT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    KEY idx_created_at (created_at),
    KEY idx_action (action),
    KEY idx_quote_no (quote_no),
    KEY idx_customer_name (customer_name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_quote_log_schema($pdo);
  ensure_bom_quote_spec_schema($pdo);
}
function get_price_levels($pdo){
  ensure_quote_settings($pdo);
  return rows($pdo,"SELECT * FROM quote_price_levels WHERE is_active=1 ORDER BY is_default DESC, sort_order ASC, id ASC");
}
function get_options($pdo,$group=''){
  ensure_quote_settings($pdo);
  if($group!=='') return rows($pdo,"SELECT * FROM quote_options WHERE group_key=? AND is_active=1 ORDER BY sort_order ASC, id ASC",[$group]);
  return rows($pdo,"SELECT * FROM quote_options WHERE is_active=1 ORDER BY group_key ASC, sort_order ASC, id ASC");
}

function get_quote_system_settings($pdo){
  ensure_quote_settings($pdo);
  $out=['exchange_rate_usd'=>7.0000];
  try{
    $rs=rows($pdo,"SELECT setting_key,setting_value FROM quote_system_settings");
    foreach($rs as $r){ $out[(string)$r['setting_key']]=$r['setting_value']; }
  }catch(Throwable $e){}
  $v=floatval($out['exchange_rate_usd']??7);
  if($v<=0)$v=7;
  $out['exchange_rate_usd']=$v;
  return $out;
}
function save_quote_system_setting($pdo,$key,$value,$user=''){
  ensure_quote_settings($pdo);
  $st=$pdo->prepare("INSERT INTO quote_system_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
  $st->execute([(string)$key,(string)$value,(string)$user]);
}

function qlog_ip(){
  foreach(['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k){ if(!empty($_SERVER[$k])) return substr(trim(explode(',',$_SERVER[$k])[0]),0,80); }
  return '';
}
function qlog_json($v,$limit=60000){
  if($v===null || $v==='') return null;
  $s=is_string($v)?$v:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(strlen($s)>$limit) $s=substr($s,0,$limit).'...TRUNCATED';
  return $s;
}
function qlog_customer_name($d){
  if(isset($d['customer_name'])) return (string)$d['customer_name'];
  if(isset($d['customer_json'])){ $a=json_decode((string)$d['customer_json'],true); if(is_array($a)) return (string)($a['company']??$a['name']??''); }
  if(isset($d['customer']) && is_array($d['customer'])) return (string)($d['customer']['company']??$d['customer']['name']??'');
  return '';
}
function qlog_summary($action,$d){
  $no=$d['quote_no']??''; $cn=qlog_customer_name($d); $id=$d['id']??'';
  $map=[
    'save_quote'=>'保存报价单','delete_quote'=>'删除报价单','save_customer'=>'保存报价客户','delete_customer'=>'删除报价客户','batch_delete_customers'=>'批量删除报价客户','clean_stale_crm_customers'=>'清理失效CRM客户','sync_crm_customers'=>'同步 CRM 客户','align_crm_customers'=>'强制对齐CRM客户','save_product'=>'保存产品','delete_product'=>'删除产品','save_header'=>'保存公司抬头','delete_header'=>'删除公司抬头','save_bank'=>'保存银行信息','delete_bank'=>'删除银行信息','save_template'=>'保存条款模板','delete_template'=>'删除条款模板','save_price_level'=>'保存报价等级','delete_price_level'=>'停用报价等级','save_option'=>'保存下拉选项','delete_option'=>'停用下拉选项','log_event'=>'前端操作'
  ];
  $txt=$map[$action]??$action;
  if($no) $txt.='：'.$no;
  if($cn) $txt.=' / 客户：'.$cn;
  if($id && !$no) $txt.=' / ID:'.$id;
  if(isset($d['amount'])) $txt.=' / 金额：'.$d['amount'];
  if(isset($d['qty'])) $txt.=' / 数量：'.$d['qty'];
  return $txt;
}
function quote_log_event($pdo,$arg=[]){
  try{
    ensure_quote_log_schema($pdo);
    $d=$arg['detail']??[];
    $st=$pdo->prepare("INSERT INTO quote_logs(level,module,action,event,quote_id,quote_no,customer_id,customer_name,user_name,ip,user_agent,request_method,request_uri,summary,detail_json,before_json,after_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
      substr((string)($arg['level']??'INFO'),0,20), substr((string)($arg['module']??'quotation'),0,60), substr((string)($arg['action']??''),0,80), substr((string)($arg['event']??''),0,120),
      isset($arg['quote_id'])?intval($arg['quote_id']):(isset($d['quote_id'])?intval($d['quote_id']):(isset($d['id'])&&($arg['action']??'')==='save_quote'?intval($d['id']):null)),
      substr((string)($arg['quote_no']??($d['quote_no']??'')),0,100), substr((string)($arg['customer_id']??($d['customer_id']??'')),0,100), substr((string)($arg['customer_name']??qlog_customer_name($d)),0,255),
      substr((string)($arg['user_name']??($d['user_name']??($_COOKIE['user_name']??''))),0,100), qlog_ip(), substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255), substr((string)($_SERVER['REQUEST_METHOD']??''),0,20), substr((string)($_SERVER['REQUEST_URI']??''),0,255),
      (string)($arg['summary']??qlog_summary((string)($arg['action']??''),$d)), qlog_json($d), qlog_json($arg['before']??null), qlog_json($arg['after']??null)
    ]);
  }catch(Throwable $ignore){}
}
function qlog_list($pdo){
  ensure_quote_log_schema($pdo);
  $where=[]; $p=[];
  $kw=trim((string)($_GET['kw']??''));
  if($kw!==''){ $where[]="(action LIKE ? OR event LIKE ? OR summary LIKE ? OR quote_no LIKE ? OR customer_name LIKE ? OR user_name LIKE ? OR ip LIKE ?)"; for($i=0;$i<7;$i++)$p[]='%'.$kw.'%'; }
  // 注意：URL 里的 action=list_logs 是接口路由参数，不能当成日志 action 过滤，否则会检到总数但列表显示 0。
  foreach(['level','quote_no','customer_name'] as $f){ if(isset($_GET[$f]) && $_GET[$f]!==''){ $where[]="$f LIKE ?"; $p[]='%'.$_GET[$f].'%'; } }
  $op = trim((string)($_GET['op'] ?? $_GET['log_action'] ?? ''));
  if($op !== ''){ $where[]="action LIKE ?"; $p[]='%'.$op.'%'; }
  if(!empty($_GET['date_from'])){ $where[]='created_at >= ?'; $p[]=$_GET['date_from'].' 00:00:00'; }
  if(!empty($_GET['date_to'])){ $where[]='created_at <= ?'; $p[]=$_GET['date_to'].' 23:59:59'; }
  $sql='FROM quote_logs '.($where?'WHERE '.implode(' AND ',$where):'');
  $limit=max(20,min(500,intval($_GET['limit']??200))); $offset=max(0,intval($_GET['offset']??0));
  $total=(int)row($pdo,'SELECT COUNT(*) c '.$sql,$p)['c'];
  $logs=rows($pdo,'SELECT * '.$sql.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset,$p);
  return ['logs'=>$logs,'total'=>$total,'limit'=>$limit,'offset'=>$offset];
}


/* ===== ARTDON_QUOTE_V6_8_5_BACKUP_RESTORE_START ===== */
function qbackup_dir(){
  $dir=__DIR__.'/uploads/quote_backups';
  if(!is_dir($dir)) @mkdir($dir,0775,true);
  @chmod($dir,0777);
  if(!is_dir($dir) || !is_writable($dir)){
    $perm=is_dir($dir)?substr(sprintf('%o', fileperms($dir)), -4):'missing';
    fail('备份目录不可写：uploads/quote_backups，当前权限 '.$perm.'。请将该目录授权给 Web 用户写入。');
  }
  return $dir;
}
function qbackup_allowed_table($t){
  $t=(string)$t;
  if($t==='bom_quote_specs') return true;
  return preg_match('/^quote_[A-Za-z0-9_]+$/',$t)===1;
}
function qbackup_table_list($pdo){
  $rows=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
  $out=[];
  foreach($rows as $r){ $t=(string)($r[0]??''); if(qbackup_allowed_table($t)) $out[]=$t; }
  sort($out,SORT_NATURAL|SORT_FLAG_CASE);
  return $out;
}
function qbackup_file_list(){
  $names=['quotation.php','quote_api.php','crm_quote_pdf.php','crm_quote_excel.php','quote_order_api.php','quote_order_doc.php','quote_order_excel.php','quote_order_pdf.php','quote_order_config.php'];
  $out=[];
  foreach($names as $n){
    $p=__DIR__.'/'.$n;
    if(is_file($p)) $out[$n]=['name'=>$n,'size'=>filesize($p),'mtime'=>date('Y-m-d H:i:s',filemtime($p)),'sha1'=>sha1_file($p)];
  }
  return $out;
}
function qbackup_make($pdo,$actor=null,$tag='manual'){
  $dir=qbackup_dir();
  $meta=['system'=>'Artdon Quotation','version'=>'V6.8.5','tag'=>$tag,'created_at'=>date('Y-m-d H:i:s'),'created_by'=>(string)($actor['username']??$actor['display_name']??''),'host'=>(string)($_SERVER['HTTP_HOST']??''),'tables'=>[]];
  $payload=['meta'=>$meta,'tables'=>[],'files'=>qbackup_file_list()];
  foreach(qbackup_table_list($pdo) as $t){
    $create='';
    try{ $r=$pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_ASSOC); $create=(string)($r['Create Table']??''); }catch(Throwable $e){}
    $cols=table_columns($pdo,$t);
    $rs=rows($pdo,'SELECT * FROM `'.$t.'`',[]);
    $payload['tables'][$t]=['create_sql'=>$create,'columns'=>$cols,'rows'=>$rs,'count'=>count($rs)];
    $payload['meta']['tables'][$t]=count($rs);
  }
  $name='quote_backup_'.date('Ymd_His').'_'.preg_replace('/[^A-Za-z0-9_\-]+/','_',strtolower($tag)).'.json';
  $path=$dir.'/'.$name;
  $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if($json===false) fail('备份JSON生成失败');
  if(file_put_contents($path,$json)===false) fail('备份文件写入失败，请检查 uploads/quote_backups 权限');
  return ['file'=>$name,'size'=>filesize($path),'created_at'=>$payload['meta']['created_at'],'table_count'=>count($payload['tables']),'row_count'=>array_sum($payload['meta']['tables']),'tables'=>$payload['meta']['tables']];
}
function qbackup_safe_name($name){
  $name=basename((string)$name);
  return preg_match('/^quote_backup_[A-Za-z0-9_\-]+\.json$/',$name)?$name:'';
}
function qbackup_list(){
  $dir=qbackup_dir(); $out=[];
  foreach(glob($dir.'/quote_backup_*.json')?:[] as $p){
    $meta=[]; $fh=@fopen($p,'rb'); $head=$fh?fread($fh,8192):''; if($fh)fclose($fh);
    // 为避免大文件读取，这里只显示文件信息；具体内容恢复时再解析。
    $out[]=['file'=>basename($p),'size'=>filesize($p),'mtime'=>date('Y-m-d H:i:s',filemtime($p)),'download'=>'quote_api.php?action=download_backup&file='.rawurlencode(basename($p))];
  }
  usort($out,function($a,$b){ return strcmp((string)$b['mtime'],(string)$a['mtime']); });
  return $out;
}
function qbackup_download($file){
  $safe=qbackup_safe_name($file); if($safe==='') fail('备份文件名不合法');
  $path=qbackup_dir().'/'.$safe; if(!is_file($path)) fail('备份文件不存在');
  while(ob_get_level()) @ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$safe.'"');
  header('Content-Length: '.filesize($path));
  readfile($path); exit;
}
function qbackup_read_upload(){
  if(!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])){
    if(!empty($_FILES['backup_file']['error'])){
      fail('备份文件上传失败，错误码：'.intval($_FILES['backup_file']['error']));
    }
    $size=(int)($_FILES['backup_file']['size'] ?? filesize($_FILES['backup_file']['tmp_name']));
    if($size>200*1024*1024) fail('备份文件过大（'.quote_size_text($size).'），已拒绝恢复。请拆分或联系管理员临时提高服务器限制。');
    quote_prepare_heavy_json_runtime($size);
    $raw=file_get_contents($_FILES['backup_file']['tmp_name']);
    if($raw===false || $raw==='') fail('备份文件读取失败，请重新上传 JSON 文件');
    return $raw;
  }
  if(!empty($_POST['backup_json'])) {
    $raw=(string)$_POST['backup_json'];
    $size=strlen($raw);
    if($size>200*1024*1024) fail('备份文件过大（'.quote_size_text($size).'），已拒绝恢复。请拆分或联系管理员临时提高服务器限制。');
    quote_prepare_heavy_json_runtime($size);
    return $raw;
  }
  $contentType=strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  if(strpos($contentType,'application/json')!==false){
    $raw=file_get_contents('php://input');
    quote_prepare_heavy_json_runtime(strlen((string)$raw));
    $d=json_decode((string)$raw,true);
    if(is_array($d) && !empty($d['backup_json'])) return (string)$d['backup_json'];
  }
  fail('请先选择备份文件');
}
function qbackup_restore($pdo,$actor=null){
  $confirm=(string)($_POST['confirm']??'');
  if($confirm==='') {
    $contentType=strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if(strpos($contentType,'application/json')!==false) {
      $d=input_json(); $confirm=(string)($d['confirm']??'');
    }
  }
  if($confirm!=='RESTORE_QUOTE') fail('恢复前请在确认框输入 RESTORE_QUOTE');
  $raw=qbackup_read_upload();
  $rawSize=strlen($raw);
  if($rawSize>200*1024*1024) fail('备份文件过大（'.quote_size_text($rawSize).'），已拒绝恢复。请拆分或联系管理员临时提高服务器限制。');
  quote_prepare_heavy_json_runtime($rawSize);
  $bak=json_decode($raw,true);
  if(!is_array($bak) || empty($bak['tables']) || !is_array($bak['tables'])) {
    $err=json_last_error_msg();
    fail('备份文件格式不正确或 JSON 无法解析：'.$err.'。文件大小：'.quote_size_text($rawSize));
  }
  unset($raw);
  // 恢复前自动生成一次安全备份，避免误操作后无法回退。
  $pre=qbackup_make($pdo,$actor,'before_restore');
  $restored=[];
  $pdo->beginTransaction();
  try{
    try{ $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); }catch(Throwable $e){}
    foreach($bak['tables'] as $t=>$pack){
      if(!qbackup_allowed_table($t)) continue;
      $create=trim((string)($pack['create_sql']??''));
      if(!table_exists($pdo,$t) && $create!==''){
        $pdo->exec($create);
      }
      if(!table_exists($pdo,$t)) continue;
      $rows=is_array($pack['rows']??null)?$pack['rows']:[];
      $pdo->exec('DELETE FROM `'.$t.'`');
      $cols=table_columns($pdo,$t);
      $inserted=0;
      foreach($rows as $r){
        if(!is_array($r)) continue;
        $keys=array_values(array_intersect(array_keys($r),$cols));
        if(!$keys) continue;
        $sql='INSERT INTO `'.$t.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';
        $st=$pdo->prepare($sql);
        $vals=[]; foreach($keys as $k) $vals[]=$r[$k];
        $st->execute($vals); $inserted++;
      }
      $restored[$t]=$inserted;
    }
    try{ $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }catch(Throwable $e){}
    $pdo->commit();
  }catch(Throwable $e){
    try{ $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }catch(Throwable $ignore){}
    if($pdo->inTransaction()) $pdo->rollBack();
    fail('恢复失败：'.$e->getMessage().'；恢复前安全备份：'.$pre['file']);
  }
  quote_log_event($pdo,['action'=>'restore_backup_done','event'=>'恢复报价系统备份','user_name'=>$actor['username']??'','summary'=>'已恢复报价系统备份，恢复前安全备份：'.$pre['file'],'detail'=>['pre_backup'=>$pre,'restored'=>$restored,'meta'=>$bak['meta']??[]]]);
  return ['restored'=>$restored,'pre_backup'=>$pre];
}
/* ===== ARTDON_QUOTE_V6_8_5_BACKUP_RESTORE_END ===== */

/* ===== V6.8.4.45 报价审核流程 START ===== */
function quote_approval_schema(PDO $pdo): void {
  try{
    if(!table_exists($pdo,'quote_orders')) return;
    ensure_col($pdo,'quote_orders','approval_status',"`approval_status` VARCHAR(30) NOT NULL DEFAULT 'pending'");
    ensure_col($pdo,'quote_orders','submitted_by',"`submitted_by` VARCHAR(120) DEFAULT ''");
    ensure_col($pdo,'quote_orders','submitted_at',"`submitted_at` DATETIME NULL");
    ensure_col($pdo,'quote_orders','approved_by',"`approved_by` VARCHAR(120) DEFAULT ''");
    ensure_col($pdo,'quote_orders','approved_at',"`approved_at` DATETIME NULL");
    ensure_col($pdo,'quote_orders','rejected_by',"`rejected_by` VARCHAR(120) DEFAULT ''");
    ensure_col($pdo,'quote_orders','rejected_at',"`rejected_at` DATETIME NULL");
    ensure_col($pdo,'quote_orders','approval_note',"`approval_note` TEXT NULL");
    ensure_col($pdo,'quote_orders','approval_items_json',"`approval_items_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','approval_log_json',"`approval_log_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','approved_snapshot_json',"`approved_snapshot_json` LONGTEXT NULL");
    ensure_col($pdo,'quote_orders','locked_at',"`locked_at` DATETIME NULL");
    ensure_col($pdo,'quote_orders','reject_reason_category',"`reject_reason_category` VARCHAR(120) DEFAULT ''");
    ensure_col($pdo,'quote_orders','reject_reason_custom',"`reject_reason_custom` VARCHAR(255) DEFAULT ''");
    ensure_col($pdo,'quote_orders','reject_reason_detail',"`reject_reason_detail` TEXT NULL");
    ensure_col($pdo,'quote_orders','header_json',"`header_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','bank_json',"`bank_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','template_json',"`template_json` MEDIUMTEXT NULL");
    ensure_col($pdo,'quote_orders','quote_status',"`quote_status` VARCHAR(80) DEFAULT 'Quotation sheet'");
    ensure_col($pdo,'quote_orders','status',"`status` VARCHAR(80) DEFAULT ''");
    try{ $pdo->exec("ALTER TABLE `quote_orders` ADD KEY `idx_quote_approval_status` (`approval_status`,`submitted_at`)"); }catch(Throwable $e){}
  }catch(Throwable $e){}
}
function quote_approval_status_of(array $q): string {
  $s=strtolower(trim((string)($q['approval_status']??'')));
  return in_array($s,['pending','approved','rejected','draft'],true)?$s:'pending';
}
function quote_normalize_doc_status($v): string {
  $s=trim((string)($v ?? ''));
  if($s==='') return 'Quotation sheet';
  if(preg_match('/订购合同|purchase|contract/iu',$s)) return '订购合同';
  if(preg_match('/proforma|invoice/i',$s)) return 'PROFORMA INVOICE';
  return 'Quotation sheet';
}
function quote_can_approve(array $u, array $perms): bool {
  return qperm_is_admin($u) || !empty($perms['quote_approve']);
}
function quote_require_approver(array $u, array $perms): void {
  if(!quote_can_approve($u,$perms)) fail('当前账号没有审核权限：请到统一权限中心 → 报价系统 → 勾选“审核报价/驳回/改价”。');
}
function quote_apply_review_items(array $items): array {
  $out=[];
  foreach($items as $it){
    if(!is_array($it)) continue;
    $qty=max(0,(float)($it['qty']??0));
    $price=max(0,(float)($it['price']??$it['unit_price']??0));
    $mult=max(0,(float)($it['price_multiplier']??$it['approved_multiplier']??$it['multiplier']??0));
    $it['qty']=$qty;
    $it['price']=$price;
    $it['unit_price']=$price;
    $it['amount']=$qty*$price;
    $it['manual_price']=true;
    $it['approved_price']=$price;
    $it['approved_qty']=$qty;
    if($mult>0){ $it['price_multiplier']=$mult; $it['approved_multiplier']=$mult; }
    $out[]=$it;
  }
  return $out;
}
function quote_merge_review_items(array $savedItems, array $reviewItems): array {
  if(!$savedItems) fail('原报价没有完整产品明细，已停止审核，未修改任何数据');
  if(count($savedItems)!==count($reviewItems)) fail('审核明细与原报价产品数量不一致，已停止审核，未修改任何数据');
  $out=[];
  foreach($savedItems as $idx=>$saved){
    if(!is_array($saved)) fail('原报价第'.($idx+1).'项产品资料异常，已停止审核');
    $review=$reviewItems[$idx]??null;
    if(!is_array($review)) fail('审核第'.($idx+1).'项资料异常，已停止审核');
    $qty=max(0,(float)($review['qty']??$saved['qty']??0));
    $price=max(0,(float)($review['price']??$review['unit_price']??$saved['price']??$saved['unit_price']??0));
    $mult=max(0,(float)($review['price_multiplier']??$review['approved_multiplier']??$review['multiplier']??$saved['price_multiplier']??0));
    // 审核只允许调整数量、单价及倍率；产品、图片、规格和部件始终以已保存报价为准。
    $merged=$saved;
    $merged['qty']=$qty;
    $merged['price']=$price;
    $merged['unit_price']=$price;
    $merged['amount']=$qty*$price;
    $merged['manual_price']=true;
    $merged['approved_price']=$price;
    $merged['approved_qty']=$qty;
    if($mult>0){ $merged['price_multiplier']=$mult; $merged['approved_multiplier']=$mult; }
    $out[]=$merged;
  }
  return $out;
}
function quote_decode_items_json($raw): array {
  $arr=json_decode((string)$raw,true);
  return is_array($arr)?$arr:[];
}
function quote_item_name_for_log(array $it, int $idx): string {
  $p=$it['product']??[]; if(!is_array($p)) $p=[];
  $name=trim((string)($p['name']??$p['product_name']??$it['name']??''));
  $code=trim((string)($p['code']??$p['model']??$it['product_model']??''));
  $out=trim(($code!==''?$code.' ':'').$name);
  return $out!==''?$out:'第'.($idx+1).'项';
}
function quote_money_log($v): string { return number_format((float)$v,2,'.',''); }
function quote_review_item_changes(array $before, array $after): array {
  $changes=[]; $n=max(count($before),count($after));
  for($i=0;$i<$n;$i++){
    $b=is_array($before[$i]??null)?$before[$i]:[]; $a=is_array($after[$i]??null)?$after[$i]:[];
    $oldQty=(float)($b['qty']??0); $newQty=(float)($a['qty']??0);
    $oldPrice=(float)($b['price']??$b['unit_price']??0); $newPrice=(float)($a['price']??$a['unit_price']??0);
    $oldMult=(float)($b['price_multiplier']??$b['approved_multiplier']??0); $newMult=(float)($a['price_multiplier']??$a['approved_multiplier']??0);
    $oldAmount=(float)($b['amount']??($oldQty*$oldPrice)); $newAmount=(float)($a['amount']??($newQty*$newPrice));
    $qtyChanged=abs($oldQty-$newQty)>0.000001; $priceChanged=abs($oldPrice-$newPrice)>0.000001; $multChanged=($oldMult>0 || $newMult>0) && abs($oldMult-$newMult)>0.000001; $amountChanged=abs($oldAmount-$newAmount)>0.000001;
    if($qtyChanged||$priceChanged||$multChanged||$amountChanged){
      $changes[]=[
        'index'=>$i,
        'name'=>quote_item_name_for_log($a?:$b,$i),
        'old_qty'=>$oldQty+0,'new_qty'=>$newQty+0,'qty_changed'=>$qtyChanged?1:0,
        'old_multiplier'=>quote_money_log($oldMult),'new_multiplier'=>quote_money_log($newMult),'multiplier_changed'=>$multChanged?1:0,
        'old_price'=>quote_money_log($oldPrice),'new_price'=>quote_money_log($newPrice),'price_changed'=>$priceChanged?1:0,
        'old_amount'=>quote_money_log($oldAmount),'new_amount'=>quote_money_log($newAmount),'amount_changed'=>$amountChanged?1:0
      ];
    }
  }
  return $changes;
}
function quote_append_approval_log(PDO $pdo, int $quoteId, array $entry): void {
  if($quoteId<=0) return;
  try{
    quote_approval_schema($pdo);
    $st=$pdo->prepare('SELECT approval_log_json FROM quote_orders WHERE id=? LIMIT 1'); $st->execute([$quoteId]);
    $logs=json_decode((string)$st->fetchColumn(),true); if(!is_array($logs)) $logs=[];
    $logs[]=$entry; if(count($logs)>200) $logs=array_slice($logs,-200);
    $pdo->prepare('UPDATE quote_orders SET approval_log_json=? WHERE id=?')->execute([json_encode($logs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$quoteId]);
  }catch(Throwable $e){ try{ quote_log_event($pdo,['level'=>'WARN','action'=>'approval_log_error','event'=>'审核日志写入失败','summary'=>$e->getMessage(),'detail'=>['quote_id'=>$quoteId]]); }catch(Throwable $ignore){} }
}
function quote_crm_ensure_reminder_table(PDO $pdo): void {
  try{
    ensure_table($pdo,"CREATE TABLE IF NOT EXISTS crm_reminders(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      reminder_key VARCHAR(190) NOT NULL UNIQUE,
      module VARCHAR(80) DEFAULT 'crm',
      module_label VARCHAR(120) DEFAULT '',
      title VARCHAR(255) DEFAULT '',
      content TEXT NULL,
      level VARCHAR(20) DEFAULT 'mid',
      status VARCHAR(40) DEFAULT '未读',
      target_type VARCHAR(80) DEFAULT '',
      target_id VARCHAR(120) DEFAULT '',
      customer_id INT DEFAULT 0,
      customer_name VARCHAR(255) DEFAULT '',
      owner VARCHAR(120) DEFAULT '',
      source_table VARCHAR(120) DEFAULT '',
      source_id VARCHAR(120) DEFAULT '',
      due_at DATETIME NULL,
      remind_at DATETIME NULL,
      read_at DATETIME NULL,
      done_at DATETIME NULL,
      snooze_until DATETIME NULL,
      created_by VARCHAR(120) DEFAULT '',
      updated_by VARCHAR(120) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(owner),INDEX(status),INDEX(module),INDEX(remind_at),INDEX(done_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(table_exists($pdo,'crm_reminders')){
      foreach([
        'reminder_key'=>"`reminder_key` VARCHAR(190) DEFAULT ''",
        'module'=>"`module` VARCHAR(80) DEFAULT 'crm'",
        'module_label'=>"`module_label` VARCHAR(120) DEFAULT ''",
        'title'=>"`title` VARCHAR(255) DEFAULT ''",
        'content'=>"`content` TEXT NULL",
        'level'=>"`level` VARCHAR(20) DEFAULT 'mid'",
        'status'=>"`status` VARCHAR(40) DEFAULT '未读'",
        'target_type'=>"`target_type` VARCHAR(80) DEFAULT ''",
        'target_id'=>"`target_id` VARCHAR(120) DEFAULT ''",
        'customer_id'=>"`customer_id` INT DEFAULT 0",
        'customer_name'=>"`customer_name` VARCHAR(255) DEFAULT ''",
        'owner'=>"`owner` VARCHAR(120) DEFAULT ''",
        'source_table'=>"`source_table` VARCHAR(120) DEFAULT ''",
        'source_id'=>"`source_id` VARCHAR(120) DEFAULT ''",
        'remind_at'=>"`remind_at` DATETIME NULL",
        'read_at'=>"`read_at` DATETIME NULL",
        'done_at'=>"`done_at` DATETIME NULL",
        'created_by'=>"`created_by` VARCHAR(120) DEFAULT ''",
        'updated_by'=>"`updated_by` VARCHAR(120) DEFAULT ''",
        'created_at'=>"`created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
        'updated_at'=>"`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
      ] as $c=>$ddl){ try{ ensure_col($pdo,'crm_reminders',$c,$ddl); }catch(Throwable $e){} }
      try{ $pdo->exec("ALTER TABLE crm_reminders ADD UNIQUE KEY uq_quote_reminder_key (reminder_key)"); }catch(Throwable $e){}
    }
  }catch(Throwable $e){}
}
function quote_review_actor_text(array $u): string {
  $name=trim((string)($u['display_name']??$u['real_name']??$u['name']??''));
  $user=trim((string)($u['username']??''));
  if($name!=='' && $user!=='' && strcasecmp($name,$user)!==0) return $name.' / '.$user;
  return $user!==''?$user:($name!==''?$name:'未知审核人');
}

function quote_sales_owner_from_approval_log(array $q): string {
  $logs=json_decode((string)($q['approval_log_json']??''),true);
  if(!is_array($logs) || !count($logs)) return '';
  foreach($logs as $log){
    if(!is_array($log)) continue;
    $action=strtolower(trim((string)($log['action']??'')));
    if($action==='submit'){
      $u=trim((string)($log['user']??''));
      if($u!=='') return $u;
    }
  }
  foreach($logs as $log){
    if(!is_array($log)) continue;
    $action=strtolower(trim((string)($log['action']??'')));
    if(in_array($action,['submit','resubmit'],true)){
      $u=trim((string)($log['user']??''));
      if($u!=='') return $u;
    }
  }
  return '';
}
function quote_sales_owner_from_quote(array $q, string $fallback=''): string {
  $fromLog=quote_sales_owner_from_approval_log($q);
  if($fromLog!=='') return $fromLog;
  foreach(['user_name','sales','owner','created_by','submitted_by'] as $k){
    $v=trim((string)($q[$k]??''));
    if($v!=='' && $v!=='0') return $v;
  }
  return trim($fallback);
}

function quote_repair_sales_owner_from_logs(PDO $pdo): int {
  try{
    if(!table_exists($pdo,'quote_orders')) return 0;
    $cols=table_columns($pdo,'quote_orders');
    if(!in_array('approval_log_json',$cols,true) || !in_array('user_name',$cols,true)) return 0;
    $rows=rows($pdo,"SELECT id,user_name,approval_log_json FROM quote_orders WHERE approval_log_json IS NOT NULL AND approval_log_json<>'' ORDER BY id DESC LIMIT 3000");
    $up=$pdo->prepare('UPDATE quote_orders SET user_name=? WHERE id=?');
    $n=0;
    foreach($rows as $r){
      $owner=quote_sales_owner_from_approval_log($r);
      if($owner!=='' && trim((string)($r['user_name']??''))!==$owner){ $up->execute([$owner,(int)$r['id']]); $n++; }
    }
    return $n;
  }catch(Throwable $e){ return 0; }
}
function quote_latest_approval_log_entry(array $q): array {
  $logs=json_decode((string)($q['approval_log_json']??''),true);
  if(!is_array($logs) || !count($logs)) return [];
  $last=end($logs);
  return is_array($last)?$last:[];
}
function quote_review_time_from_quote(array $q, string $kind): string {
  if($kind==='approved' && !empty($q['approved_at'])) return (string)$q['approved_at'];
  if($kind==='rejected' && !empty($q['rejected_at'])) return (string)$q['rejected_at'];
  return date('Y-m-d H:i:s');
}
function quote_review_kind_label(string $kind): string {
  if($kind==='submitted') return '提交审核';
  if($kind==='resubmitted') return '重新提交审核';
  if($kind==='approved') return '审核通过';
  if($kind==='rejected') return '审核驳回';
  if($kind==='unapproved') return '反审退回';
  return '审核状态更新';
}
function quote_review_build_crm_content(array $q, array $u, string $kind, string $note=''): string {
  $label=quote_review_kind_label($kind);
  $time=quote_review_time_from_quote($q,$kind);
  $actor=quote_review_actor_text($u);
  $no=(string)($q['quote_no']??'');
  $customer=(string)($q['customer_name']??'');
  $amount=(string)($q['currency']??'USD').' '.(string)($q['amount']??'0');
  $timeLabel=($kind==='rejected')?'驳回时间':(($kind==='approved')?'审核时间':(in_array($kind,['submitted','resubmitted'],true)?'提交时间':'反审时间'));
  $actorLabel=($kind==='rejected')?'驳回人':(($kind==='approved')?'审核人':(in_array($kind,['submitted','resubmitted'],true)?'提交人':'反审人'));
  $lines=[
    '操作：'.$label,
    '报价号：'.$no,
    '客户：'.$customer,
    '金额：'.$amount,
    $actorLabel.'：'.$actor,
    $timeLabel.'：'.$time
  ];
  $log=quote_latest_approval_log_entry($q);
  $logNote=trim((string)($log['note']??''));
  if($kind==='rejected'){
    $cat=trim((string)($q['reject_reason_category']??($log['reason_category']??'')));
    $custom=trim((string)($q['reject_reason_custom']??($log['reason_custom']??'')));
    $detail=trim((string)($q['reject_reason_detail']??($log['reason_detail']??'')));
    if($cat!=='') $lines[]='驳回分类：'.$cat;
    if($custom!=='') $lines[]='自定义分类：'.$custom;
    if($detail!=='') $lines[]='驳回原因：'.$detail;
    if($detail==='' && trim($note)!=='') $lines[]='驳回说明：'.trim($note);
  }else{
    if(trim($note)!=='') $lines[]='审核备注：'.trim($note);
    elseif($logNote!=='') $lines[]='审核备注：'.$logNote;
  }
  $changes=$log['changes']??[];
  if(is_array($changes) && count($changes)){
    $lines[]='审核改动：'.count($changes).' 项';
    foreach(array_slice($changes,0,8) as $ch){
      if(!is_array($ch)) continue;
      $parts=[];
      if(!empty($ch['qty_changed'])) $parts[]='数量 '.$ch['old_qty'].'→'.$ch['new_qty'];
      if(!empty($ch['price_changed'])) $parts[]='单价 '.$ch['old_price'].'→'.$ch['new_price'];
      if(!empty($ch['multiplier_changed'])) $parts[]='倍率 '.$ch['old_multiplier'].'→'.$ch['new_multiplier'];
      if(!empty($ch['amount_changed'])) $parts[]='金额 '.$ch['old_amount'].'→'.$ch['new_amount'];
      if($parts) $lines[]='- '.(string)($ch['name']??'报价项').'：'.implode('，',$parts);
    }
  }
  $lines[]='来源：报价系统';
  return implode("\n",$lines);
}
function quote_push_crm_review_message(PDO $pdo, array $q, array $u, string $kind, string $note=''): void {
  try{
    quote_crm_ensure_reminder_table($pdo);
    $qid=(int)($q['id']??0);
    if($qid<=0) return;
    $label=quote_review_kind_label($kind);
    $no=(string)($q['quote_no']??('ID '.$qid));
    $customer=(string)($q['customer_name']??'');
    $level=($kind==='approved')?'mid':'high';
    $title='报价审核通知：'.$label.' '.$no.($customer!==''?' / '.$customer:'');
    $content=quote_review_build_crm_content($q,$u,$kind,$note);
    $owner=quote_sales_owner_from_quote($q,trim((string)($u['username']??'')));
    if($owner==='') $owner=trim((string)($u['username']??''));
    if(table_exists($pdo,'crm_reminders')){
      $key='quote_audit_notice:'.$kind.':'.$qid.':'.date('YmdHis').':'.substr(md5($content.microtime(true)),0,6);
      $sql="INSERT INTO crm_reminders(
        reminder_key,module,module_label,title,content,level,status,target_type,target_id,customer_id,customer_name,owner,source_table,source_id,remind_at,created_by,updated_by,created_at,updated_at
      ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),level=VALUES(level),status='未读',read_at=NULL,done_at=NULL,remind_at=NOW(),owner=VALUES(owner),updated_by=VALUES(updated_by),updated_at=NOW()";
      $st=$pdo->prepare($sql);
      $st->execute([
        $key,'quote','报价审核通知',$title,$content,$level,'未读','quote_orders',(string)$qid,(int)($q['customer_id']??0),$customer,$owner,'quote_orders',(string)$qid,$owner,(string)($u['username']??'')
      ]);
    }
    quote_push_crm_quote_notification($pdo,$q,$u,$kind,$title,$content,$owner);
    try{ quote_log_event($pdo,['action'=>'crm_quote_review_message','event'=>'写入CRM审核消息','quote_id'=>$qid,'quote_no'=>$no,'customer_name'=>$customer,'summary'=>$label.'已写入CRM消息：'.$no,'detail'=>['kind'=>$kind,'owner'=>$owner,'title'=>$title,'content'=>$content]]); }catch(Throwable $ignore){}
  }catch(Throwable $e){
    try{ quote_log_event($pdo,['level'=>'WARN','action'=>'crm_quote_review_message_error','event'=>'CRM审核消息写入失败','summary'=>$e->getMessage(),'detail'=>['quote_id'=>$q['id']??0,'kind'=>$kind,'note'=>$note]]); }catch(Throwable $ignore){}
  }
}
function quote_crm_user_ids_by_names(PDO $pdo, array $names): array {
  $names=array_values(array_unique(array_filter(array_map(function($v){ return trim((string)$v); },$names),function($v){ return $v!=='' && $v!=='0'; })));
  if(!$names || !table_exists($pdo,'crm_users')) return [];
  $cols=table_columns($pdo,'crm_users');
  $matchCols=array_values(array_intersect(['username','user_name','name','real_name','english_name','email'], $cols));
  if(!in_array('id',$cols,true) || !$matchCols) return [];
  $where=[]; $params=[];
  foreach($matchCols as $c){
    $where[]='`'.$c.'` IN ('.implode(',',array_fill(0,count($names),'?')).')';
    foreach($names as $n) $params[]=$n;
  }
  try{
    $rs=rows($pdo,'SELECT id FROM crm_users WHERE '.implode(' OR ',$where),$params);
    return array_values(array_unique(array_map('intval',array_column($rs,'id'))));
  }catch(Throwable $e){ return []; }
}
function quote_crm_actor_user_id(array $u): int {
  try{ if(function_exists('qperm_central_user_id')){ $id=(int)qperm_central_user_id($u); if($id>0) return $id; } }catch(Throwable $e){}
  return (int)($_SESSION['artdon_user_id'] ?? $_SESSION['crm_user_id'] ?? 0);
}
function quote_crm_review_user_ids(PDO $pdo, array $u, string $owner=''): array {
  $ids=[];
  if(table_exists($pdo,'quote_user_permissions')){
    try{
      $rs=rows($pdo,"SELECT username FROM quote_user_permissions WHERE quote_review_view=1 OR quote_approve=1 OR settings_manage=1 OR permission_manage=1",[]);
      $ids=array_merge($ids,quote_crm_user_ids_by_names($pdo,array_column($rs,'username')));
    }catch(Throwable $e){}
  }
  $ownerIds=quote_crm_user_ids_by_names($pdo,[$owner,(string)($u['username']??'')]);
  return array_values(array_unique(array_merge($ids,$ownerIds)));
}
function quote_push_crm_quote_notification(PDO $pdo, array $q, array $u, string $kind, string $title, string $content, string $owner=''): void {
  if(!function_exists('create_system_notification')) return;
  $qid=(int)($q['id']??0); if($qid<=0) return;
  $typeMap=[
    'submitted'=>'quote_submitted',
    'resubmitted'=>'quote_submitted',
    'approved'=>'quote_completed',
    'rejected'=>'quote_rejected',
    'unapproved'=>'quote_unapproved',
  ];
  $type=$typeMap[$kind] ?? 'quote_confirming';
  $recipientIds=in_array($kind,['submitted','resubmitted'],true) ? quote_crm_review_user_ids($pdo,$u,$owner) : quote_crm_user_ids_by_names($pdo,[$owner,(string)($u['username']??'')]);
  $actorId=quote_crm_actor_user_id($u);
  if(!$recipientIds && $actorId>0) $recipientIds=[$actorId];
  $payload=[
    'module'=>'quote',
    'source_module'=>'quote_orders',
    'source_id'=>(string)$qid,
    'target_module'=>'quote',
    'target_id'=>(string)$qid,
    'target_url'=>'quotation.php?quote_id='.$qid,
    'related_quote_id'=>(string)(($q['quote_no']??'') ?: $qid),
    'related_customer_id'=>(int)($q['customer_id']??0),
    'quote_id'=>$qid,
    'quote_no'=>(string)($q['quote_no']??''),
    'customer_name'=>(string)($q['customer_name']??''),
    'currency'=>(string)($q['currency']??''),
    'amount'=>(string)($q['amount']??''),
    'approval_status'=>(string)($q['approval_status']??''),
    'created_by'=>$actorId,
    'severity'=>($kind==='rejected')?'warning':'normal',
    'dedupe_key'=>'quote:'.$type.':'.$qid,
  ];
  foreach(array_values(array_unique(array_map('intval',$recipientIds))) as $uid){
    if($uid>0) create_system_notification($uid,$type,$title,$content,$payload);
  }
}
function quote_ensure_crm_tasks_table(PDO $pdo): void {
  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_tasks (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      task_type VARCHAR(60) NOT NULL DEFAULT 'customer_followup',
      title VARCHAR(255) NOT NULL,
      description TEXT NULL,
      source_type VARCHAR(60) NOT NULL DEFAULT '',
      source_id VARCHAR(80) NOT NULL DEFAULT '',
      customer_id INT UNSIGNED NULL,
      contact_id INT UNSIGNED NULL,
      opportunity_id INT UNSIGNED NULL,
      quote_id VARCHAR(80) NULL,
      assigned_user_id INT UNSIGNED NULL,
      collaborator_user_ids_json JSON NULL,
      priority VARCHAR(30) NOT NULL DEFAULT 'normal',
      status VARCHAR(40) NOT NULL DEFAULT 'pending',
      due_at DATETIME NULL,
      reminder_at DATETIME NULL,
      last_reminded_at DATETIME NULL,
      completed_at DATETIME NULL,
      completed_by INT UNSIGNED NULL,
      result VARCHAR(120) NOT NULL DEFAULT '',
      result_note TEXT NULL,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      deleted_at DATETIME NULL,
      KEY idx_task_type (task_type),
      KEY idx_task_status (status),
      KEY idx_task_due (due_at),
      KEY idx_task_customer (customer_id),
      KEY idx_task_assignee (assigned_user_id),
      KEY idx_task_deleted (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }catch(Throwable $e){}
}
function quote_upsert_crm_quote_followup_task(PDO $pdo, array $q, array $u): void {
  try{
    quote_ensure_crm_tasks_table($pdo);
    if(!table_exists($pdo,'crm_tasks')) return;
    $qid=(int)($q['id']??0); if($qid<=0) return;
    $quoteNo=(string)($q['quote_no']??('ID '.$qid));
    $customer=(string)($q['customer_name']??'');
    $customerId=(int)preg_replace('/\D+/','',(string)($q['customer_id']??'')) ?: null;
    $owner=quote_sales_owner_from_quote($q,(string)($u['username']??''));
    $assignees=quote_crm_user_ids_by_names($pdo,[$owner,(string)($u['username']??'')]);
    $assigned=$assignees[0]??quote_crm_actor_user_id($u);
    $createdBy=quote_crm_actor_user_id($u) ?: null;
    $title='报价未回复：'.$quoteNo.($customer!==''?' / '.$customer:'');
    $desc='报价已审核完成，请跟进客户是否回复、是否需要转订单/订金/出货。'."\n".'金额：'.(string)($q['currency']??'').' '.(string)($q['amount']??'');
    $existing=row($pdo,"SELECT * FROM crm_tasks WHERE task_type='quote_followup' AND source_type='quote' AND source_id=? AND deleted_at IS NULL LIMIT 1",[(string)$qid]);
    if($existing){
      if(in_array((string)($existing['status']??''),['done','closed','cancelled'],true)) return;
      $pdo->prepare("UPDATE crm_tasks SET title=?, description=?, customer_id=?, quote_id=?, assigned_user_id=?, priority='important', status='pending', due_at=COALESCE(due_at, DATE_ADD(NOW(), INTERVAL 3 DAY)), reminder_at=COALESCE(reminder_at, DATE_ADD(NOW(), INTERVAL 1 DAY)), updated_at=NOW() WHERE id=?")
        ->execute([$title,$desc,$customerId,$quoteNo,$assigned ?: null,(int)$existing['id']]);
    }else{
      $pdo->prepare("INSERT INTO crm_tasks (task_type,title,description,source_type,source_id,customer_id,contact_id,opportunity_id,quote_id,assigned_user_id,collaborator_user_ids_json,priority,status,due_at,reminder_at,created_by,created_at,updated_at) VALUES ('quote_followup',?,?,?,?,NULL,NULL,?,?,JSON_ARRAY(),'important','pending',DATE_ADD(NOW(), INTERVAL 3 DAY),DATE_ADD(NOW(), INTERVAL 1 DAY),?,NOW(),NOW())")
        ->execute([$title,$desc,'quote',(string)$qid,$customerId,$quoteNo,$assigned ?: null,$createdBy]);
    }
  }catch(Throwable $e){
    try{ quote_log_event($pdo,['level'=>'WARN','action'=>'quote_followup_task_error','event'=>'报价跟进任务生成失败','quote_id'=>(int)($q['id']??0),'summary'=>$e->getMessage()]); }catch(Throwable $ignore){}
  }
}
function quote_complete_crm_quote_followup_task(PDO $pdo, array $order, array $u): void {
  try{
    if(!table_exists($pdo,'crm_tasks')) return;
    $sourceId=(string)((int)($order['source_quote_id']??0) ?: '');
    $quoteNo=trim((string)($order['quote_no']??''));
    $where=[]; $params=[];
    if($sourceId!==''){ $where[]="(source_type='quote' AND source_id=?)"; $params[]=$sourceId; }
    if($quoteNo!==''){ $where[]='quote_id=?'; $params[]=$quoteNo; }
    if(!$where) return;
    $pdo->prepare("UPDATE crm_tasks SET status='done', result='已转订单', result_note=CONCAT(COALESCE(result_note,''), IF(COALESCE(result_note,'')='', '', '\n'), '报价已转订单：', ?), completed_at=COALESCE(completed_at,NOW()), completed_by=?, updated_at=NOW() WHERE task_type='quote_followup' AND status NOT IN ('done','closed','cancelled') AND deleted_at IS NULL AND (".implode(' OR ',$where).")")
      ->execute(array_merge([(string)($order['order_no']??''), (int)(quote_crm_actor_user_id($u) ?: 0)], $params));
  }catch(Throwable $e){}
}
function quote_push_crm_approved_reminder(PDO $pdo, array $q, array $u): void {
  quote_push_crm_review_message($pdo,$q,$u,'approved',(string)($q['approval_note']??''));
  quote_upsert_crm_quote_followup_task($pdo,$q,$u);
}
function quote_push_crm_review_reminder(PDO $pdo, array $q, array $u, string $kind, string $note=''): void {
  quote_push_crm_review_message($pdo,$q,$u,$kind,$note);
}

function quote_order_owner_from_order(PDO $pdo, array $order, array $actor=[]): string {
  foreach(['user_name','sales','owner','created_by','submitted_by'] as $k){ $v=trim((string)($order[$k]??'')); if($v!=='' && $v!=='0') return $v; }
  try{
    $quoteNo=trim((string)($order['quote_no']??'')); $sourceId=(int)($order['source_quote_id']??0);
    if($sourceId>0 && table_exists($pdo,'quote_orders')){ $q=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$sourceId]); if($q){ $o=quote_sales_owner_from_quote($q,''); if($o!=='') return $o; } }
    if($quoteNo!=='' && table_exists($pdo,'quote_orders')){ $q=row($pdo,'SELECT * FROM quote_orders WHERE quote_no=? ORDER BY id DESC LIMIT 1',[$quoteNo]); if($q){ $o=quote_sales_owner_from_quote($q,''); if($o!=='') return $o; } }
  }catch(Throwable $e){}
  return trim((string)($actor['username']??''));
}
function quote_push_crm_order_notice(PDO $pdo, array $order, array $u, string $kind='converted'): void {
  try{
    quote_crm_ensure_reminder_table($pdo);
    $oid=(int)($order['id']??0); if($oid<=0) return;
    $orderNo=(string)($order['order_no']??('订单#'.$oid));
    $quoteNo=(string)($order['quote_no']??'');
    $customer=(string)($order['customer_name']??'');
    $currency=(string)($order['currency']??'');
    $amount=(string)($order['amount']??'0');
    $owner=quote_order_owner_from_order($pdo,$order,$u);
    if($owner==='') $owner=trim((string)($u['username']??''));
    $actor=quote_review_actor_text($u);
    $title='报价已转订单：'.$orderNo.($customer!==''?' / '.$customer:'');
    $lines=[
      '操作：报价转订单',
      '订单号：'.$orderNo,
      '来源报价：'.$quoteNo,
      '客户：'.$customer,
      '金额：'.$currency.' '.$amount,
      '数量：'.(string)($order['qty']??''),
      '操作人：'.$actor,
      '订单日期：'.(string)($order['order_date']??date('Y-m-d')),
      '来源：报价系统'
    ];
    $content=implode("\n",array_filter($lines,function($x){ return trim((string)$x)!==''; }));
    if(table_exists($pdo,'crm_reminders')){
      $key='quote_order_converted:'.$oid.':'.date('YmdHis').':'.substr(md5($content.microtime(true)),0,6);
      $sql="INSERT INTO crm_reminders(
        reminder_key,module,module_label,title,content,level,status,target_type,target_id,customer_id,customer_name,owner,source_table,source_id,remind_at,created_by,updated_by,created_at,updated_at
      ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),status='未读',read_at=NULL,done_at=NULL,remind_at=NOW(),owner=VALUES(owner),updated_by=VALUES(updated_by),updated_at=NOW()";
      $st=$pdo->prepare($sql);
      $st->execute([$key,'order','订单通知',$title,$content,'mid','未读','quote_sales_orders',(string)$oid,(int)($order['customer_id']??0),$customer,$owner,'quote_sales_orders',(string)$oid,$owner,(string)($u['username']??'')]);
    }
    quote_push_crm_order_sys_notification($pdo,$order,$u,$title,$content,$owner);
    quote_complete_crm_quote_followup_task($pdo,$order,$u);
    try{ quote_log_event($pdo,['action'=>'crm_quote_order_message','event'=>'写入CRM订单通知','quote_no'=>$quoteNo,'customer_name'=>$customer,'summary'=>'订单转化通知已写入CRM：'.$orderNo,'detail'=>['order_id'=>$oid,'order_no'=>$orderNo,'owner'=>$owner,'title'=>$title,'content'=>$content]]); }catch(Throwable $ignore){}
  }catch(Throwable $e){
    try{ quote_log_event($pdo,['level'=>'WARN','action'=>'crm_quote_order_message_error','event'=>'CRM订单通知写入失败','summary'=>$e->getMessage(),'detail'=>['order'=>$order,'kind'=>$kind]]); }catch(Throwable $ignore){}
  }
}
function quote_push_crm_order_sys_notification(PDO $pdo, array $order, array $u, string $title, string $content, string $owner=''): void {
  if(!function_exists('create_system_notification')) return;
  $oid=(int)($order['id']??0); if($oid<=0) return;
  $actorId=quote_crm_actor_user_id($u);
  $recipientIds=quote_crm_user_ids_by_names($pdo,[$owner,(string)($u['username']??''),(string)($order['user_name']??''),(string)($order['created_by']??'')]);
  if(!$recipientIds && $actorId>0) $recipientIds=[$actorId];
  $payload=[
    'module'=>'quote',
    'source_module'=>'quote_sales_orders',
    'source_id'=>(string)$oid,
    'target_module'=>'quote',
    'target_id'=>(string)$oid,
    'target_url'=>'quotation.php?order_id='.$oid,
    'related_quote_id'=>(string)(($order['quote_no']??'') ?: ($order['source_quote_id']??'')),
    'related_customer_id'=>(int)($order['customer_id']??0),
    'order_id'=>$oid,
    'order_no'=>(string)($order['order_no']??''),
    'quote_no'=>(string)($order['quote_no']??''),
    'customer_name'=>(string)($order['customer_name']??''),
    'currency'=>(string)($order['currency']??''),
    'amount'=>(string)($order['amount']??''),
    'created_by'=>$actorId,
    'dedupe_key'=>'quote:quote_order_converted:'.$oid,
  ];
  foreach(array_values(array_unique(array_map('intval',$recipientIds))) as $uid){
    if($uid>0) create_system_notification($uid,'quote_order_converted',$title,$content,$payload);
  }
}

/* ===== V6.8.4.45 报价审核流程 END ===== */

/* ===== V6.8.5.1 订单作废 / 测试订单彻底删除 START ===== */
function quote_order_table_exists(PDO $pdo, string $table): bool { return table_exists($pdo,$table); }
function quote_order_col_exists(PDO $pdo, string $table, string $col): bool { return quote_order_table_exists($pdo,$table) && in_array($col, table_columns($pdo,$table), true); }
function quote_order_fetch_all(PDO $pdo, string $table, string $where, array $params=[]): array {
  if(!quote_order_table_exists($pdo,$table)) return [];
  return rows($pdo,"SELECT * FROM `$table` WHERE $where",$params);
}
function quote_order_in_sql(array $ids): string { return implode(',', array_fill(0,count($ids),'?')); }
function quote_order_get(PDO $pdo, int $orderId): array {
  if($orderId<=0) fail('订单ID无效');
  if(!quote_order_table_exists($pdo,'quote_sales_orders')) fail('订单表 quote_sales_orders 不存在');
  $o=row($pdo,'SELECT * FROM quote_sales_orders WHERE id=? LIMIT 1',[$orderId]);
  if(!$o) fail('订单不存在或已删除');
  return $o;
}
function quote_order_related_snapshot(PDO $pdo, int $orderId): array {
  $order=quote_order_get($pdo,$orderId);
  $shipments=quote_order_fetch_all($pdo,'quote_shipments','order_id=?',[$orderId]);
  $shipIds=[]; foreach($shipments as $s){ $sid=(int)($s['id']??0); if($sid>0) $shipIds[]=$sid; }
  $snap=[
    'quote_sales_orders'=>[$order],
    'quote_sales_order_items'=>quote_order_fetch_all($pdo,'quote_sales_order_items','order_id=?',[$orderId]),
    'quote_order_payments'=>quote_order_fetch_all($pdo,'quote_order_payments','order_id=?',[$orderId]),
    'quote_shipments'=>$shipments,
    'quote_shipment_items'=>quote_order_fetch_all($pdo,'quote_shipment_items','order_id=?',[$orderId]),
    'quote_shipment_cartons'=>quote_order_fetch_all($pdo,'quote_shipment_cartons','order_id=?',[$orderId]),
  ];
  if($shipIds){
    $ph=quote_order_in_sql($shipIds);
    $extraItems=quote_order_fetch_all($pdo,'quote_shipment_items','shipment_id IN ('.$ph.')',$shipIds);
    $extraCartons=quote_order_fetch_all($pdo,'quote_shipment_cartons','shipment_id IN ('.$ph.')',$shipIds);
    $seen=[]; foreach($snap['quote_shipment_items'] as $r){ $seen[(string)($r['id']??json_encode($r))]=1; }
    foreach($extraItems as $r){ $k=(string)($r['id']??json_encode($r)); if(!isset($seen[$k])){ $seen[$k]=1; $snap['quote_shipment_items'][]=$r; } }
    $seen=[]; foreach($snap['quote_shipment_cartons'] as $r){ $seen[(string)($r['id']??json_encode($r))]=1; }
    foreach($extraCartons as $r){ $k=(string)($r['id']??json_encode($r)); if(!isset($seen[$k])){ $seen[$k]=1; $snap['quote_shipment_cartons'][]=$r; } }
  }
  return $snap;
}
function quote_order_archive_dir(): string {
  $dir=__DIR__.'/uploads/quote_order_delete_archive';
  if(!is_dir($dir)) @mkdir($dir,0775,true);
  return $dir;
}
function quote_order_safe_filename($s): string {
  $s=preg_replace('/[^A-Za-z0-9_\-]+/','_', (string)$s);
  return trim($s,'_') ?: 'order';
}
function quote_order_archive_snapshot(PDO $pdo, int $orderId, array $actor, string $mode, string $reason=''): array {
  $snapshot=quote_order_related_snapshot($pdo,$orderId);
  $order=$snapshot['quote_sales_orders'][0]??[];
  $meta=[
    'system'=>'Artdon Quotation ERP',
    'action'=>$mode,
    'order_id'=>$orderId,
    'order_no'=>(string)($order['order_no']??''),
    'quote_no'=>(string)($order['quote_no']??''),
    'created_at'=>date('Y-m-d H:i:s'),
    'created_by'=>(string)($actor['username']??$actor['display_name']??''),
    'reason'=>$reason,
  ];
  $payload=['meta'=>$meta,'tables'=>$snapshot];
  $dir=quote_order_archive_dir();
  if(!is_writable($dir)) fail('订单归档目录不可写：'.$dir);
  $file='quote_order_'.$mode.'_'.date('Ymd_His').'_'.quote_order_safe_filename($meta['order_no'] ?: $orderId).'.json';
  $path=$dir.'/'.$file;
  $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if($json===false || file_put_contents($path,$json)===false) fail('订单归档备份写入失败');
  return ['file'=>$file,'path'=>$path,'meta'=>$meta,'counts'=>array_map('count',$snapshot)];
}
function quote_order_update_source_quote(PDO $pdo, array $order): void {
  if(!quote_order_table_exists($pdo,'quote_orders')) return;
  $sets=[];
  if(quote_order_col_exists($pdo,'quote_orders','converted_order_id')) $sets[]='converted_order_id=0';
  if(quote_order_col_exists($pdo,'quote_orders','converted_order_no')) $sets[]="converted_order_no=''";
  if(quote_order_col_exists($pdo,'quote_orders','updated_at')) $sets[]='updated_at=NOW()';
  if(!$sets) return;
  $where=[]; $params=[];
  if(!empty($order['source_quote_id'])){ $where[]='id=?'; $params[]=(int)$order['source_quote_id']; }
  if(!empty($order['quote_no'])){ $where[]='quote_no=?'; $params[]=(string)$order['quote_no']; }
  if(quote_order_col_exists($pdo,'quote_orders','converted_order_id')){ $where[]='converted_order_id=?'; $params[]=(int)($order['id']??0); }
  if(!$where) return;
  try{ $pdo->prepare('UPDATE quote_orders SET '.implode(',',$sets).' WHERE '.implode(' OR ',$where))->execute($params); }catch(Throwable $e){}
}
function quote_order_append_note_sql(PDO $pdo, string $table, string $reason): string {
  if(quote_order_col_exists($pdo,$table,'note')) return ', note=CONCAT(COALESCE(note,\'\'), ?)';
  return '';
}
function quote_void_sales_order(PDO $pdo, array $d, array $actor): array {
  $orderId=(int)($d['id']??$d['order_id']??0);
  $reason=s($d['reason']??'手动作废订单',500);
  $order=quote_order_get($pdo,$orderId);
  $archive=quote_order_archive_snapshot($pdo,$orderId,$actor,'void',$reason);
  $note="\n[作废] ".date('Y-m-d H:i:s').' '.($actor['username']??'').' '.$reason;
  $pdo->beginTransaction();
  try{
    $set=["status='已作废'"]; $params=[];
    if(quote_order_col_exists($pdo,'quote_sales_orders','shipment_status')) $set[]="shipment_status='已作废'";
    if(quote_order_col_exists($pdo,'quote_sales_orders','payment_status')) $set[]="payment_status='已作废'";
    if(quote_order_col_exists($pdo,'quote_sales_orders','updated_by')){ $set[]='updated_by=?'; $params[]=(string)($actor['username']??''); }
    if(quote_order_col_exists($pdo,'quote_sales_orders','updated_at')) $set[]='updated_at=NOW()';
    if(quote_order_col_exists($pdo,'quote_sales_orders','note')){ $set[]='note=CONCAT(COALESCE(note,\'\'), ?)'; $params[]=$note; }
    $params[]=$orderId;
    $pdo->prepare('UPDATE quote_sales_orders SET '.implode(',',$set).' WHERE id=?')->execute($params);
    if(quote_order_table_exists($pdo,'quote_shipments')){
      $set=["status='已作废'"]; $params=[];
      if(quote_order_col_exists($pdo,'quote_shipments','updated_at')) $set[]='updated_at=NOW()';
      if(quote_order_col_exists($pdo,'quote_shipments','note')){ $set[]='note=CONCAT(COALESCE(note,\'\'), ?)'; $params[]=$note; }
      $params[]=$orderId;
      $pdo->prepare('UPDATE quote_shipments SET '.implode(',',$set).' WHERE order_id=?')->execute($params);
    }
    quote_order_update_source_quote($pdo,$order);
    $pdo->commit();
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); fail('作废订单失败：'.$e->getMessage()); }
  quote_log_event($pdo,['action'=>'void_sales_order','event'=>'作废销售订单','user_name'=>$actor['username']??'','quote_id'=>(int)($order['source_quote_id']??0),'quote_no'=>$order['quote_no']??'','customer_name'=>$order['customer_name']??'','summary'=>'作废订单：'.($order['order_no']??$orderId),'detail'=>['order_id'=>$orderId,'reason'=>$reason,'archive'=>$archive],'before'=>$order]);
  return ['id'=>$orderId,'order_no'=>$order['order_no']??'','status'=>'已作废','archive_file'=>$archive['file'],'message'=>'订单已作废，关联出货批次/PL/CI同步作废，原报价已允许重新转订单。'];
}
function quote_delete_test_order(PDO $pdo, array $d, array $actor): array {
  $orderId=(int)($d['id']??$d['order_id']??0);
  $confirm=(string)($d['confirm']??'');
  if($confirm!=='DELETE_ORDER') fail('缺少确认词 DELETE_ORDER');
  $reason=s($d['reason']??'删除测试订单',500);
  $snapshot=quote_order_related_snapshot($pdo,$orderId);
  $order=$snapshot['quote_sales_orders'][0]??[];
  $archive=quote_order_archive_snapshot($pdo,$orderId,$actor,'delete',$reason);
  $shipIds=[]; foreach(($snapshot['quote_shipments']??[]) as $s){ $sid=(int)($s['id']??0); if($sid>0) $shipIds[]=$sid; }
  $pdo->beginTransaction();
  try{
    if($shipIds){ $ph=quote_order_in_sql($shipIds);
      if(quote_order_table_exists($pdo,'quote_shipment_cartons')) $pdo->prepare('DELETE FROM quote_shipment_cartons WHERE shipment_id IN ('.$ph.')')->execute($shipIds);
      if(quote_order_table_exists($pdo,'quote_shipment_items')) $pdo->prepare('DELETE FROM quote_shipment_items WHERE shipment_id IN ('.$ph.')')->execute($shipIds);
    }
    if(quote_order_table_exists($pdo,'quote_shipment_cartons')) $pdo->prepare('DELETE FROM quote_shipment_cartons WHERE order_id=?')->execute([$orderId]);
    if(quote_order_table_exists($pdo,'quote_shipment_items')) $pdo->prepare('DELETE FROM quote_shipment_items WHERE order_id=?')->execute([$orderId]);
    if(quote_order_table_exists($pdo,'quote_shipments')) $pdo->prepare('DELETE FROM quote_shipments WHERE order_id=?')->execute([$orderId]);
    if(quote_order_table_exists($pdo,'quote_order_payments')) $pdo->prepare('DELETE FROM quote_order_payments WHERE order_id=?')->execute([$orderId]);
    if(quote_order_table_exists($pdo,'quote_sales_order_items')) $pdo->prepare('DELETE FROM quote_sales_order_items WHERE order_id=?')->execute([$orderId]);
    if(quote_order_table_exists($pdo,'quote_sales_orders')) $pdo->prepare('DELETE FROM quote_sales_orders WHERE id=?')->execute([$orderId]);
    quote_order_update_source_quote($pdo,$order);
    $pdo->commit();
  }catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); fail('删除测试订单失败：'.$e->getMessage()); }
  quote_log_event($pdo,['action'=>'delete_test_order','event'=>'彻底删除测试订单','user_name'=>$actor['username']??'','quote_id'=>(int)($order['source_quote_id']??0),'quote_no'=>$order['quote_no']??'','customer_name'=>$order['customer_name']??'','summary'=>'彻底删除测试订单：'.($order['order_no']??$orderId),'detail'=>['order_id'=>$orderId,'reason'=>$reason,'archive'=>$archive,'deleted_counts'=>array_map('count',$snapshot)],'before'=>$order]);
  return ['id'=>$orderId,'order_no'=>$order['order_no']??'','archive_file'=>$archive['file'],'deleted_counts'=>array_map('count',$snapshot),'message'=>'测试订单已彻底删除，关联出货批次、PL/CI、收款记录、订单明细已同步清理。'];
}
/* ===== V6.8.5.1 订单作废 / 测试订单彻底删除 END ===== */


try{
 ensure_quote_core_schema($pdo);
 ensure_quote_settings($pdo);
 ensure_quote_price_policy_schema($pdo);
 ensure_quote_permission_schema($pdo);
 quote_approval_schema($pdo);
 if($action==='login'){ $d=input_json(); ok(qperm_login($pdo,$d['username']??'', $d['password']??'')); }
 if($action==='logout'){ $u=qperm_current_user($pdo); if($u) quote_log_event($pdo,['action'=>'logout','event'=>'报价系统退出','user_name'=>$u['username']??'','summary'=>'用户退出报价系统：'.($u['username']??'')]); unset($_SESSION['quote_user'],$_SESSION['quote_permissions']); ok(); }
 if($action==='permission_feature_defs'){
   $defs=function_exists('artdon_sso_quote_feature_defs')?artdon_sso_quote_feature_defs():[
     'doc_settings_manage'=>['label'=>'报价资料设置：表头/银行/付款条款','group'=>'资料设置','level'=>'普通'],
     'quote_review_view'=>['label'=>'查看未审核列表','group'=>'报价审核','level'=>'敏感'],
     'quote_approve'=>['label'=>'审核报价/驳回/改价','group'=>'报价审核','level'=>'高危'],
     'rate_manage'=>['label'=>'汇率设置','group'=>'系统管理','level'=>'高危']
   ];
   ok(['module'=>'quote','features'=>$defs]);
 }
 if($action==='auth_status'){ $u=qperm_current_user($pdo); if(!$u) ok(['logged_in'=>0,'user'=>null,'permissions'=>[],'login_source'=>'PLM/统一账号']); $pub=qperm_public_user($pdo,$u); ok(['logged_in'=>1,'user'=>$pub,'permissions'=>$pub['permissions'],'login_source'=>$pub['user_table']]); }
 $needPerm=qperm_action_perm($action); [$__quote_user,$__quote_perms]=qperm_require($pdo,$needPerm);
 if($action==='create_backup'){ $res=qbackup_make($pdo,$__quote_user,'manual'); quote_log_event($pdo,['action'=>'create_backup','event'=>'生成报价备份','user_name'=>$__quote_user['username']??'','summary'=>'生成报价备份：'.$res['file'],'detail'=>$res]); ok($res); }
 if($action==='list_backups'){ ok(['backups'=>qbackup_list()]); }
 if($action==='download_backup'){ qbackup_download($_GET['file']??''); }
 if($action==='restore_backup'){ ok(qbackup_restore($pdo,$__quote_user)); }
 if($action==='list_permission_users'){ ok(['users'=>qperm_list_users($pdo),'current'=>qperm_public_user($pdo,$__quote_user)]); }
 if($action==='save_user_permission'){ $d=input_json(); qperm_save_user_permission($pdo,$d,$__quote_user); ok(['users'=>qperm_list_users($pdo)]); }
 if($action==='reset_user_permission'){ $d=input_json(); $pdo->prepare('DELETE FROM quote_user_permissions WHERE user_table=? AND user_id=? AND username=?')->execute([(string)($d['user_table']??''),(string)($d['user_id']??''),(string)($d['username']??'')]); quote_log_event($pdo,['action'=>'reset_user_permission','event'=>'重置报价权限','user_name'=>$__quote_user['username']??'','summary'=>'重置账号权限：'.($d['username']??''),'detail'=>$d]); ok(['users'=>qperm_list_users($pdo)]); }
 if($action==='delete_permission_user'){ $d=input_json(); qperm_delete_permission_user($pdo,$d,$__quote_user); ok(['users'=>qperm_list_users($pdo)]); }
 if($action==='log_health'){
   $d=input_json();
   quote_log_event($pdo,['action'=>'log_health','event'=>'日志自检','summary'=>'手动写入日志自检：如果能看到这条，说明日志表和接口正常','detail'=>$d]);
   $cnt=(int)row($pdo,'SELECT COUNT(*) c FROM quote_logs',[])['c'];
   $last=row($pdo,'SELECT * FROM quote_logs ORDER BY id DESC LIMIT 1',[]);
   ok(['table'=>'quote_logs','count'=>$cnt,'last'=>$last]);
 }
 if($action==='list_logs'){ ok(qlog_list($pdo)); }
 if($action==='delete_logs'){
   $d=input_json();
   if(($d['mode']??'')==='older' && intval($d['days']??0)>0){ $days=intval($d['days']); $pdo->prepare('DELETE FROM quote_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')->execute([$days]); quote_log_event($pdo,['action'=>'delete_logs','event'=>'清理旧日志','summary'=>'清理 '.$days.' 天以前的报价日志','detail'=>$d]); ok(); }
   if(($d['confirm']??'')==='DELETE_ALL_LOGS'){ $pdo->exec('TRUNCATE TABLE quote_logs'); quote_log_event($pdo,['action'=>'delete_logs','event'=>'清空全部日志','summary'=>'已清空全部报价日志','detail'=>$d]); ok(); }
   fail('请确认日志清理方式');
 }
 if($action==='log_event'){
   $d=input_json();
   quote_log_event($pdo,['level'=>$d['level']??'INFO','action'=>$d['log_action']??'front_event','event'=>$d['event']??'前端操作','summary'=>$d['summary']??'', 'quote_no'=>$d['quote_no']??'', 'customer_name'=>$d['customer_name']??'', 'detail'=>$d]);
   ok();
 }

 if($action==='void_sales_order'){
   $d=input_json();
   ok(quote_void_sales_order($pdo,$d,$__quote_user));
 }
 if($action==='delete_test_order'){
   $d=input_json();
   ok(quote_delete_test_order($pdo,$d,$__quote_user));
 }
 if($action==='push_order_crm_notice'){
   $d=input_json();
   $orderId=(int)($d['order_id']??$d['id']??0);
   $order=[];
   if($orderId>0) $order=quote_order_get($pdo,$orderId);
   elseif(!empty($d['order_no']) && quote_order_table_exists($pdo,'quote_sales_orders')) $order=row($pdo,'SELECT * FROM quote_sales_orders WHERE order_no=? ORDER BY id DESC LIMIT 1',[s($d['order_no'],120)]) ?: [];
   if(!$order && !empty($d['order_no'])) $order=['id'=>0,'order_no'=>s($d['order_no'],120),'quote_no'=>s($d['quote_no']??'',120),'customer_name'=>s($d['customer_name']??'',255),'amount'=>(float)($d['amount']??0),'currency'=>s($d['currency']??'',20)];
   if(empty($order) || (int)($order['id']??0)<=0) fail('订单不存在，CRM通知未写入');
   quote_push_crm_order_notice($pdo,$order,$__quote_user,'converted');
   ok(['notified'=>1,'order_id'=>(int)($order['id']??0),'order_no'=>$order['order_no']??'']);
 }
 if($action && !in_array($action,['init','list_logs','delete_logs','log_event','log_health'],true)){
   $d=input_json();
   quote_log_event($pdo,['action'=>$action,'event'=>'接口请求','detail'=>$d]);
 }
 if($action==='bom_debug'){ ok(bom_debug_report($pdo, $_GET['model'] ?? $_POST['model'] ?? '')); }
if($action==='ensure_bom_quote_spec' || $action==='sync_bom_quote_spec'){
   $d=input_json();
   $force=($action==='sync_bom_quote_spec') || !empty($d['force']);
   $p=$d['product'] ?? $d;
   $res=qspec_auto_sync_product($pdo,$p,$force);
   if(empty($res['ok'])) fail($res['msg'] ?? '同步报价关键件失败');
   quote_log_event($pdo,['action'=>$action,'event'=>$force?'强制同步报价关键件':'自动检查报价关键件','summary'=>($res['message']??'').'：'.($p['code']??$p['model']??''),'detail'=>['product'=>$p,'result'=>$res]]);
   ok($res);
 }
if($action==='init'){
   quote_v640_doc_schema_fix($pdo);
   quote_ensure_customer_schema($pdo);
   $ownerRepairCount=0;
   ok([
    'me'=>qperm_public_user($pdo,$__quote_user),
    'permissions'=>$__quote_perms,
    'customers'=>merged_customers($pdo),
    'crm_customer_count'=>crm_customer_count($pdo),
    'products'=>merged_quote_products($pdo),
    'naming_product_count'=>count(get_naming_products($pdo)),
    'bom_cost_count'=>count(get_bom_cost_map($pdo)),
    'bom_quote_spec_count'=>table_exists($pdo,'bom_quote_specs')?(int)row($pdo,'SELECT COUNT(*) c FROM bom_quote_specs',[])['c']:0,
    'headers'=>rows($pdo,"SELECT * FROM quote_headers ORDER BY id DESC"),
    'banks'=>rows($pdo,"SELECT * FROM quote_banks ORDER BY id DESC"),
    'templates'=>rows($pdo,"SELECT * FROM quote_templates ORDER BY id DESC"),
    // 首屏只返回摘要；大体积报价明细在页面可用后异步补齐。
    'quotes'=>rows($pdo,"SELECT ".quote_select_columns_except($pdo,'quote_orders',['approved_snapshot_json','approval_items_json','items_json','parts_json']).", '[]' AS items_json, '{}' AS parts_json, 0 AS _detail_loaded FROM quote_orders ORDER BY id DESC LIMIT 1000"),
    'materials'=>get_materials($pdo),
    'price_levels'=>get_price_levels($pdo),
    'options'=>get_options($pdo),
    'system_settings'=>get_quote_system_settings($pdo),
    'owner_repair_count'=>$ownerRepairCount
   ]);
 }

 if($action==='list_quote_details'){
   ok(['quotes'=>rows($pdo,"SELECT ".quote_select_columns_except($pdo,'quote_orders',['approved_snapshot_json','approval_items_json']).", 1 AS _detail_loaded FROM quote_orders ORDER BY id DESC LIMIT 1000")]);
 }

 if($action==='get_quote_detail'){
   $id=(int)($_GET['id']??0); if($id<=0) fail('缺少报价ID');
   $q=row($pdo,"SELECT ".quote_select_columns_except($pdo,'quote_orders',['approved_snapshot_json','approval_items_json']).", 1 AS _detail_loaded FROM quote_orders WHERE id=? LIMIT 1",[$id]);
   if(!$q) fail('报价不存在');
   ok(['quote'=>$q]);
 }

 if($action==='get_approved_quote_snapshot'){
   $d=input_json(); $id=(int)($d['id']??0);
   if($id<=0) fail('缺少报价ID，已停止导出');
   $q=row($pdo,'SELECT id,quote_no,approval_status,approved_snapshot_json FROM quote_orders WHERE id=? LIMIT 1',[$id]);
   if(!$q) fail('报价不存在，已停止导出');
   if(strtolower((string)($q['approval_status']??''))!=='approved') fail('报价尚未审核通过，已停止导出');
   $snap=json_decode((string)($q['approved_snapshot_json']??''),true);
   if(!is_array($snap) || !$snap) fail('审核快照不存在，已停止导出，请重新审核');
   if((int)($snap['id']??0)!==$id || (string)($snap['quote_no']??'')!==(string)$q['quote_no']) fail('审核快照与报价不一致，已停止导出');
   $items=json_decode((string)($snap['items_json']??''),true);
   if(!is_array($items) || !$items) fail('审核快照没有产品明细，已停止导出');
   unset($snap['approved_snapshot_json']);
   ok(['quote'=>$snap,'item_count'=>count($items)]);
 }

 if($action==='price_policy_list'){ ok(quote_price_policy_list($pdo)); }
 if($action==='commission_rule_list'){ ok(quote_commission_rule_list($pdo)); }
 if($action==='commission_quote_list'){ ok(quote_commission_quote_list($pdo,input_json())); }
 if($action==='commission_quote_save'){ ok(quote_commission_quote_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_quote_lines_save'){ ok(quote_commission_quote_lines_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_customer_check'){ ok(quote_commission_customer_check($pdo,input_json(),$__quote_user)); }
 if($action==='commission_reminder_list'){ ok(quote_commission_reminder_list($pdo)); }
 if($action==='commission_reminder_save'){ ok(quote_commission_reminder_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_reminder_toggle'){ $d=input_json();quote_commission_schema($pdo);$old=row($pdo,'SELECT * FROM quote_commission_reminder_rules WHERE id=?',[(int)($d['id']??0)]);$pdo->prepare('UPDATE quote_commission_reminder_rules SET is_active=?,updated_by=?,updated_at=NOW() WHERE id=?')->execute([empty($d['is_active'])?0:1,quote_price_policy_actor($__quote_user),(int)($d['id']??0)]);quote_log_event($pdo,['action'=>'commission_reminder_toggle','event'=>'切换佣金提醒设置','user_name'=>quote_price_policy_actor($__quote_user),'before'=>$old,'after'=>row($pdo,'SELECT * FROM quote_commission_reminder_rules WHERE id=?',[(int)($d['id']??0)])]);ok(); }
 if($action==='commission_order_list'){ ok(quote_commission_order_list($pdo,input_json())); }
 if($action==='commission_order_save'){ ok(quote_commission_order_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_order_batch_save'){ ok(quote_commission_order_batch_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_item_save'){ ok(quote_commission_item_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_item_batch_save'){ ok(quote_commission_item_batch_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_rule_export'){ quote_commission_export($pdo,$__quote_user); }
 if($action==='commission_rule_import'){ ok(quote_commission_import($pdo,$__quote_user)); }
 if($action==='commission_rule_save'){ ok(quote_commission_rule_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_rule_batch_save'){ ok(quote_commission_batch_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_rule_toggle'){ $d=input_json();ok(quote_commission_rule_save($pdo,['id'=>(int)($d['id']??0),'is_active'=>empty($d['is_active'])?0:1],$__quote_user)); }
 if($action==='commission_rule_delete'){ $d=input_json();$id=(int)($d['id']??0);$before=row($pdo,'SELECT * FROM quote_commission_rules WHERE id=?',[$id]);$pdo->prepare('DELETE FROM quote_commission_rules WHERE id=?')->execute([$id]);quote_log_event($pdo,['action'=>'commission_rule_delete','event'=>'删除佣金规则','user_name'=>quote_price_policy_actor($__quote_user),'summary'=>$before['target_name']??('ID '.$id),'before'=>$before]);ok(['id'=>$id]); }
 if($action==='commission_options_list'){ ok(quote_commission_options_list($pdo)); }
 if($action==='commission_options_init_defaults'){ quote_commission_schema($pdo);ok(quote_commission_init_defaults($pdo,true)); }
 if($action==='commission_option_save'){ ok(quote_commission_option_save($pdo,input_json(),$__quote_user)); }
 if($action==='commission_option_toggle'){ $d=input_json();$o=row($pdo,'SELECT * FROM quote_commission_options WHERE id=?',[(int)($d['id']??0)]);if(!$o)fail('设置不存在');ok(quote_commission_option_save($pdo,array_merge($o,['is_active'=>empty($d['is_active'])?0:1]),$__quote_user)); }
 if($action==='commission_option_delete'){ $d=input_json();$id=(int)($d['id']??0);$o=row($pdo,'SELECT * FROM quote_commission_options WHERE id=?',[$id]);if(!$o)fail('设置不存在');if(!empty($o['is_system'])){$pdo->prepare('UPDATE quote_commission_options SET is_active=0 WHERE id=?')->execute([$id]);ok(['result'=>'disabled']);}$pdo->prepare('DELETE FROM quote_commission_options WHERE id=?')->execute([$id]);ok(['result'=>'deleted']); }
 if($action==='commission_calc_preview'){ $d=input_json();$amount=quote_commission_calc((string)($d['commission_mode']??'percent'),$d['commission_value']??0,$d['base_amount']??0,$d['total_qty']??0);ok(['commission_amount'=>$amount,'available'=>$amount===null?0:1,'message'=>$amount===null?'毛利数据不足，暂不能计算。':'']); }
 if($action==='price_policy_match'){ ok(quote_price_policy_match($pdo)); }
 if($action==='price_policy_export_excel'){ quote_price_policy_export_excel($pdo,$__quote_user); }
 if($action==='price_policy_import_excel'){ ok(quote_price_policy_import_excel($pdo,$__quote_user)); }
 if($action==='price_policy_save'){ ok(quote_price_policy_save($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_batch_save'){ ok(quote_price_policy_batch_save($pdo,input_json(),$__quote_user)); }
 if($action==='price_stock_adjust'){ ok(quote_price_stock_adjust($pdo,input_json(),$__quote_user)); }
 if($action==='price_stock_log_list'){ ok(quote_price_stock_log_list($pdo)); }
 if($action==='price_policy_delete'){ ok(quote_price_policy_delete($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_sync_naming_products'){ ok(quote_price_policy_sync_naming_products($pdo,$__quote_user)); }
 if($action==='price_policy_sync_bom_costs'){ ok(quote_price_policy_sync_bom_costs($pdo,$__quote_user,true)); }
 if($action==='price_tier_list'){ ok(quote_price_tier_list($pdo)); }
 if($action==='price_tier_save'){ ok(quote_price_tier_save($pdo,input_json(),$__quote_user)); }
 if($action==='price_tier_delete'){ ok(quote_price_tier_delete($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_levels_list'){ ok(quote_price_policy_levels_list($pdo)); }
 if($action==='price_policy_level_save'){ ok(quote_price_policy_level_save($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_level_delete'){ ok(quote_price_policy_level_delete($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_options_list'){ ok(quote_price_policy_options_list($pdo)); }
 if($action==='price_policy_option_save'){ ok(quote_price_policy_option_save($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_option_delete'){ ok(quote_price_policy_option_delete($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_option_toggle'){ ok(quote_price_policy_option_toggle($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_option_sort'){ ok(quote_price_policy_option_sort($pdo,input_json(),$__quote_user)); }
 if($action==='price_policy_options_init_defaults'){ ok(quote_price_policy_options_init_defaults($pdo,$__quote_user,true)); }

 if($action==='list_bom_quote_specs') { ensure_bom_quote_spec_schema($pdo); ok(['specs'=>rows($pdo,"SELECT * FROM bom_quote_specs ORDER BY updated_at DESC, id DESC LIMIT 5000")]); }
 if($action==='save_bom_quote_spec') {
   ensure_bom_quote_spec_schema($pdo); $d=input_json();
   $model=trim((string)($d['product_model']??$d['model']??'')); if($model==='') fail('缺少产品型号');
   $json=$d['quote_spec_json']??'';
   if($json==='' || is_array($json)){
     $arr=[]; foreach(['led'=>'LED','driver'=>'LED Driver','optic'=>'Optic','accessories'=>'Accessories','connector'=>'Connector','other'=>'Other'] as $k=>$lab){ if(!qspec_blank($d[$k]??'')) $arr[$k]=['label'=>$lab,'value'=>trim((string)$d[$k])]; }
     $json=json_encode($arr,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   }
   $old=row($pdo,"SELECT id FROM bom_quote_specs WHERE product_model=? ORDER BY id DESC LIMIT 1",[$model]);
   if($old) $d['id']=$old['id'];
   $d['product_model']=$model; $d['quote_spec_json']=$json;
   $id=save_row($pdo,'bom_quote_specs',$d,['naming_id','product_model','product_name','product_image','power','size','cutout','led','driver','optic','accessories','connector','other','quote_spec_json','note']);
   ok(['id'=>$id]);
 }
 if($action==='delete_bom_quote_spec') { ensure_bom_quote_spec_schema($pdo); $d=input_json(); if(!empty($d['id'])) $pdo->prepare('DELETE FROM bom_quote_specs WHERE id=?')->execute([intval($d['id'])]); elseif(!empty($d['product_model'])) $pdo->prepare('DELETE FROM bom_quote_specs WHERE product_model=?')->execute([$d['product_model']]); ok(); }

 if($action==='sync_crm_customers') { quote_ensure_customer_schema($pdo); $res=quote_sync_crm_customer_cache($pdo); ok($res); }
 if($action==='align_crm_customers') { quote_ensure_customer_schema($pdo); $d=input_json(); if(($d['confirm']??'')!=='ALIGN_CRM') fail('缺少确认词 ALIGN_CRM'); ok(quote_align_customer_library_to_crm($pdo)); }
 if($action==='clean_stale_crm_customers') { quote_ensure_customer_schema($pdo); $d=input_json(); if(($d['confirm']??'')!=='SYNC_DELETE') fail('缺少确认词 SYNC_DELETE'); ok(quote_sync_crm_customer_cache($pdo)); }
 if($action==='batch_delete_customers') { $d=input_json(); ok(quote_batch_delete_customers($pdo,$d['ids']??[])); }
 if($action==='save_customer') { quote_ensure_customer_schema($pdo); $d=input_json(); $d['source']='quote'; $d['crm_customer_id']=''; ok(['id'=>save_row($pdo,'quote_customers',$d,['code','company','contact','email','phone','country','website','address1','address2','addresses_json','primary_contact','primary_contact_phone','primary_contact_email','note','source','crm_customer_id'])]); }
 if($action==='delete_customer') { quote_ensure_customer_schema($pdo); $d=input_json(); $id=trim((string)($d['id']??'')); if($id==='' || str_starts_with($id,'crm_')) fail('CRM实时客户不能在报价系统删除，请在CRM删除后同步。'); $pdo->prepare("DELETE FROM quote_customers WHERE id=? AND COALESCE(source,'quote')<>'crm'")->execute([intval($id)]); ok(); }
 if($action==='save_product') { $d=input_json(); ok(['id'=>save_row($pdo,'quote_products',$d,['type','source','category','series','install','supplier','tags','code','name','size','cutout','power','power_range','ip','color','moq','price_rmb','price_usd','price_note','need_connector','status','sort_order','note','image','cost_rmb','bom_project_uid','plm_project_id','plm_model_id','bom_version_no','cost_updated_at','allow_quote','is_active'])]); }
 if($action==='delete_product') { $d=input_json(); $pdo->prepare("DELETE FROM quote_products WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='save_header') { $d=input_json(); ok(['id'=>save_row($pdo,'quote_headers',$d,['name','company','from_text','stamp','show_stamp'])]); }
 if($action==='delete_header') { $d=input_json(); $pdo->prepare("DELETE FROM quote_headers WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='save_bank') { ensure_quote_settings($pdo); $d=input_json(); $id=save_row($pdo,'quote_banks',$d,['name','text','extra_terms','extra_terms_font_size']); quote_log_event($pdo,['action'=>'save_bank','event'=>'保存银行信息','user_name'=>$__quote_user['username']??'','summary'=>'保存银行信息：'.($d['name']??''),'detail'=>$d]); ok(['id'=>$id]); }
 if($action==='delete_bank') { $d=input_json(); $pdo->prepare("DELETE FROM quote_banks WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='save_template') { $d=input_json(); ok(['id'=>save_row($pdo,'quote_templates',$d,['name','terms_json'])]); }
 if($action==='delete_template') { $d=input_json(); $pdo->prepare("DELETE FROM quote_templates WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='save_exchange_rate') { $d=input_json(); $v=floatval($d['exchange_rate_usd']??0); if($v<=0 || $v>20) fail('汇率数值不正确'); save_quote_system_setting($pdo,'exchange_rate_usd',number_format($v,4,'.',''),$__quote_user['username']??''); quote_log_event($pdo,['action'=>'save_exchange_rate','event'=>'保存报价汇率','user_name'=>$__quote_user['username']??'','summary'=>'保存 USD/RMB 汇率：'.number_format($v,4,'.',''),'detail'=>$d]); ok(['exchange_rate_usd'=>$v]); }
 if($action==='save_price_level') { $d=input_json(); if(!empty($d['is_default'])){ $pdo->exec("UPDATE quote_price_levels SET is_default=0"); } ok(['id'=>save_row($pdo,'quote_price_levels',$d,['name','multiplier','note','is_default','is_active','sort_order'])]); }
 if($action==='delete_price_level') { $d=input_json(); $pdo->prepare("UPDATE quote_price_levels SET is_active=0 WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='save_option') { $d=input_json(); ok(['id'=>save_row($pdo,'quote_options',$d,['group_key','value','label','note','is_active','sort_order'])]); }
 if($action==='delete_option') { $d=input_json(); $pdo->prepare("UPDATE quote_options SET is_active=0 WHERE id=?")->execute([intval($d['id'])]); ok(); }
 if($action==='list_pending_quotes') { quote_approval_schema($pdo); ok(['quotes'=>rows($pdo,"SELECT * FROM quote_orders WHERE COALESCE(approval_status,'pending')<>'approved' ORDER BY COALESCE(submitted_at,updated_at,created_at) DESC, id DESC LIMIT 1000")]); }
 if($action==='approve_quote') {
   quote_approval_schema($pdo); quote_require_approver($__quote_user,$__quote_perms);
   $d=input_json(); $id=(int)($d['id']??0); if($id<=0) fail('缺少报价ID');
   $q=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]); if(!$q) fail('报价不存在');
   $salesOwner=quote_sales_owner_from_quote($q,(string)($q['user_name']??($q['submitted_by']??'')));
   $items=$d['items']??null;
   if(is_array($items) && count($items)>0){
     $savedItems=quote_decode_items_json($q['items_json']??'[]');
     $items=quote_merge_review_items($savedItems,$items);
     $qty=0; $amount=0; foreach($items as $it){ $qty+=(float)($it['qty']??0); $amount+=(float)($it['amount']??0); }
     $first=$items[0]??[];
     $pdo->prepare("UPDATE quote_orders SET user_name=?,items_json=?,approval_items_json=?,qty=?,price=?,amount=?,approval_status='approved',approved_by=?,approved_at=NOW(),approval_note=?,rejected_by='',rejected_at=NULL WHERE id=?")
       ->execute([$salesOwner,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$qty,(float)($first['price']??0),$amount,(string)($__quote_user['username']??''),s($d['note']??'',5000),$id]);
   } else {
     $pdo->prepare("UPDATE quote_orders SET user_name=?,approval_status='approved',approved_by=?,approved_at=NOW(),approval_note=?,rejected_by='',rejected_at=NULL WHERE id=?")
       ->execute([$salesOwner,(string)($__quote_user['username']??''),s($d['note']??'',5000),$id]);
   }
   $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]);
   $beforeItems=quote_decode_items_json($q['items_json']??'[]');
   $afterItems=quote_decode_items_json(($after['items_json']??'')!==''?($after['items_json']??'[]'):($q['items_json']??'[]'));
   $approvalChanges=quote_review_item_changes($beforeItems,$afterItems);
   quote_append_approval_log($pdo,$id,[
     'action'=>'approve','time'=>date('Y-m-d H:i:s'),'user'=>(string)($__quote_user['username']??''),'user_name'=>(string)($__quote_user['display_name']??$__quote_user['username']??''),
     'note'=>s($d['note']??'',5000),'changes'=>$approvalChanges,
     'before_amount'=>quote_money_log((float)($q['amount']??0)),'after_amount'=>quote_money_log((float)($after['amount']??0))
   ]);
   $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]);
   if($after){ try{ $pdo->prepare('UPDATE quote_orders SET approved_snapshot_json=?, locked_at=COALESCE(locked_at,NOW()) WHERE id=?')->execute([json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$id]); $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]); }catch(Throwable $e){} }
   quote_push_crm_approved_reminder($pdo,$after?:$q,$__quote_user);
   quote_log_event($pdo,['action'=>'approve_quote','event'=>'报价审核通过','quote_id'=>$id,'quote_no'=>$q['quote_no']??'','customer_name'=>qlog_customer_name($after?:$q),'summary'=>'报价审核通过：'.($q['quote_no']??''),'detail'=>array_merge($d,['changes'=>$approvalChanges]),'before'=>$q,'after'=>$after]);
   ok(['quote'=>$after,'approval_status'=>'approved']);
 }
 if($action==='reject_quote') {
   quote_approval_schema($pdo); quote_require_approver($__quote_user,$__quote_perms);
   $d=input_json(); $id=(int)($d['id']??0); if($id<=0) fail('缺少报价ID');
   $q=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]); if(!$q) fail('报价不存在');
   $salesOwner=quote_sales_owner_from_quote($q,(string)($q['user_name']??($q['submitted_by']??'')));
   $cat=s($d['reason_category']??$d['reject_reason_category']??'',120);
   $custom=s($d['reason_custom']??$d['reject_reason_custom']??'',255);
   $detail=s($d['reason_detail']??$d['reject_reason_detail']??$d['note']??'',5000);
   $parts=[];
   if($cat!=='') $parts[]='驳回分类：'.$cat;
   if($custom!=='') $parts[]='自定义分类：'.$custom;
   if($detail!=='') $parts[]='驳回原因：'.$detail;
   $note=s(implode("
",$parts),5000);
   if($note==='') $note='报价被驳回，未填写具体原因。';
   $pdo->prepare("UPDATE quote_orders SET user_name=?,approval_status='rejected',rejected_by=?,rejected_at=NOW(),approval_note=?,reject_reason_category=?,reject_reason_custom=?,reject_reason_detail=? WHERE id=?")->execute([$salesOwner,(string)($__quote_user['username']??''),$note,$cat,$custom,$detail,$id]);
   quote_append_approval_log($pdo,$id,[
     'action'=>'reject','time'=>date('Y-m-d H:i:s'),'user'=>(string)($__quote_user['username']??''),'user_name'=>(string)($__quote_user['display_name']??$__quote_user['username']??''),
     'note'=>$note,'reason_category'=>$cat,'reason_custom'=>$custom,'reason_detail'=>$detail,'changes'=>[]
   ]);
   $afterReject=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]);
   quote_push_crm_review_reminder($pdo,$afterReject?:$q,$__quote_user,'rejected',$note);
   quote_log_event($pdo,['action'=>'reject_quote','event'=>'报价审核驳回','quote_id'=>$id,'quote_no'=>$q['quote_no']??'','customer_name'=>qlog_customer_name($q),'summary'=>'报价审核驳回：'.($q['quote_no']??''),'detail'=>array_merge($d,['approval_note'=>$note,'reason_category'=>$cat,'reason_custom'=>$custom,'reason_detail'=>$detail]),'before'=>$q,'after'=>$afterReject]);
   ok(['approval_status'=>'rejected','reason_category'=>$cat,'reason_custom'=>$custom,'reason_detail'=>$detail,'note'=>$note]);
 }
 if($action==='unapprove_quote') {
   quote_approval_schema($pdo); quote_require_approver($__quote_user,$__quote_perms);
   $d=input_json(); $id=(int)($d['id']??0); if($id<=0) fail('缺少报价ID');
   $q=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]); if(!$q) fail('报价不存在');
   $salesOwner=quote_sales_owner_from_quote($q,(string)($q['user_name']??($q['submitted_by']??'')));
   if(quote_approval_status_of($q)!=='approved') fail('只有已审核报价才能反审');
   $note=s($d['note']??'',5000);
   $pdo->prepare("UPDATE quote_orders SET user_name=?,approval_status='pending',approved_by='',approved_at=NULL,rejected_by='',rejected_at=NULL,approval_note=? WHERE id=?")->execute([$salesOwner,$note,$id]);
   quote_append_approval_log($pdo,$id,[
     'action'=>'unapprove','time'=>date('Y-m-d H:i:s'),'user'=>(string)($__quote_user['username']??''),'user_name'=>(string)($__quote_user['display_name']??$__quote_user['username']??''),
     'note'=>$note,'changes'=>[]
   ]);
   $afterUnapprove=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[$id]);
   quote_push_crm_review_reminder($pdo,$afterUnapprove?:$q,$__quote_user,'unapproved',$note);
   quote_log_event($pdo,['action'=>'unapprove_quote','event'=>'报价反审退回','quote_id'=>$id,'quote_no'=>$q['quote_no']??'','customer_name'=>qlog_customer_name($q),'summary'=>'报价反审退回待审核：'.($q['quote_no']??''),'detail'=>$d,'before'=>$q,'after'=>$afterUnapprove]);
   ok(['quote'=>$afterUnapprove,'approval_status'=>'pending']);
 }

 if($action==='save_quote') {
   quote_v640_doc_schema_fix($pdo);
   quote_commission_schema($pdo);
   $d=input_json();
   $d['quote_status']=quote_normalize_doc_status($d['quote_status'] ?? $d['status'] ?? 'Quotation sheet');
   if(empty($d['status']) || preg_match('/Quotation|PROFORMA|invoice|订购合同/i',(string)($d['status']??''))) $d['status']=$d['quote_status'];
   if(isset($d['quote_no'])) $d['quote_no']=quote_no_no_nested($d['quote_no']);
   quote_v682_prepare_quote_save_data($d);
   quote_approval_schema($pdo);
   $d['customer_name']=qlog_customer_name($d);
   $d['approval_status']='pending';
   $d['submitted_by']=$__quote_user['username']??'';
   $d['submitted_at']=date('Y-m-d H:i:s');
   $d['approved_by']='';
   $d['approved_at']=null;
   $d['rejected_by']='';
   $d['rejected_at']=null;
   $d['approval_note']='';
   if(empty($d['user_name'])) $d['user_name']=$__quote_user['username']??'';
   // 同一报价编号只保存一次：没有传 id 时，自动按 quote_no 找到原记录并更新。
   if(empty($d['id']) && !empty($d['quote_no']) && table_exists($pdo,'quote_orders')){
     $st=$pdo->prepare('SELECT id FROM quote_orders WHERE quote_no=? ORDER BY id DESC LIMIT 1');
     $st->execute([$d['quote_no']]);
     $oldId=$st->fetchColumn();
     if($oldId) $d['id']=$oldId;
   }
   $before=null;
   if(!empty($d['id'])) $before=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($d['id'])]);
   if($before){
     if(quote_approval_status_of($before)==='approved') fail('这张报价已经审核通过，已锁定快照，不能再修改。请复制/另存新版本后重新报价。');
     $oldOwner=quote_sales_owner_from_quote($before,(string)($d['user_name']??($__quote_user['username']??'')));
     if($oldOwner!=='') $d['user_name']=$oldOwner;
   } elseif(empty($d['user_name'])) {
     $d['user_name']=$__quote_user['username']??'';
   }
   if(!$before){
     $cj=json_decode((string)($d['customer_json']??'{}'),true);if(!is_array($cj))$cj=[];
     $check=quote_commission_customer_check($pdo,['customer_id'=>$d['customer_id']??'','customer_name'=>$d['customer_name']??'','customer_code'=>$cj['code']??'','quote_id'=>0,'quote_no'=>$d['quote_no']??''],$__quote_user);
     if(!empty($check['has_commission'])&&!empty($check['should_remind'])){
       $commission=json_decode((string)($d['commission_json']??''),true);
       if(!is_array($commission)||($commission['commission_confirm_status']??'')!=='line_confirmed'||empty($commission['acknowledged']))fail('该客户必须在保存前逐项确认产品佣金');
       $quoteItems=json_decode((string)($d['items_json']??'[]'),true);if(!is_array($quoteItems))$quoteItems=[];
       $commissionLines=$commission['lines']??[];if(!is_array($commissionLines)||count($commissionLines)!==count($quoteItems))fail('佣金明细与报价产品数量不一致，请重新确认');
       foreach($commissionLines as $i=>$line){if(!in_array(($line['included_in_price']??''),['included','excluded'],true)||!array_key_exists('value',$line)||$line['value']==='')fail('第 '.($i+1).' 行产品佣金尚未明确填写');}
     }
   }
   $id=save_row($pdo,'quote_orders',$d,['quote_no','quote_date','user_name','customer_id','customer_name','customer_json','header_id','bank_id','template_id','header_json','bank_json','template_json','product_type','product_id','product_json','parts_json','items_json','qty','price','amount','currency','exchange_rate','moq','color','cct','cri','ip','extra_spec','status','quote_status','version_no','price_level_id','price_level_name','price_multiplier','commission_json','approval_status','submitted_by','submitted_at','approved_by','approved_at','rejected_by','rejected_at','approval_note']);
   $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($id)]);
   $items=json_decode((string)($d['items_json']??'[]'),true); if(!is_array($items)) $items=[];
   foreach($items as $item){
     $snap=$item['price_policy_snapshot']??null;
     if(!is_array($snap)||empty($item['manual_price']))continue;
     $strategy=$item['strategy_price']??null;$manual=$item['price']??null;
     if($strategy===null||$manual===null||abs((float)$strategy-(float)$manual)<0.000001)continue;
     $model=(string)($item['product']['code']??$item['product']['model']??$snap['product_model']??'');
     quote_log_event($pdo,[
       'action'=>'quote_manual_price_override','event'=>'报价手动改价','quote_id'=>$id,'quote_no'=>$d['quote_no']??'',
       'customer_name'=>qlog_customer_name($d),'user_name'=>$__quote_user['username']??'',
       'summary'=>'手动改价：'.$model.'，策略价 '.$strategy.' → '.$manual,
       'detail'=>['original_strategy_price'=>(float)$strategy,'manual_price'=>(float)$manual,'operator'=>$__quote_user['username']??'','time'=>date('Y-m-d H:i:s'),'quote_no'=>$d['quote_no']??'','product_model'=>$model,'qty'=>(float)($item['qty']??0),'price_source'=>$snap['price_source']??'','policy_snapshot'=>$snap]
     ]);
   }
   quote_append_approval_log($pdo,(int)$id,[
     'action'=>$before?'resubmit':'submit','time'=>date('Y-m-d H:i:s'),'user'=>(string)($__quote_user['username']??''),'user_name'=>(string)($__quote_user['display_name']??$__quote_user['username']??''),
     'note'=>$before?'修改报价后重新提交审核':'提交报价审核','changes'=>[],'amount'=>quote_money_log((float)($d['amount']??0))
  ]);
  $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($id)]);
  quote_push_crm_review_reminder($pdo,$after?:$d,$__quote_user,$before?'resubmitted':'submitted',$before?'修改报价后重新提交审核':'提交报价审核');
  quote_log_event($pdo,['action'=>'save_quote_done','event'=>'报价保存完成','quote_id'=>$id,'quote_no'=>$d['quote_no']??'','customer_name'=>qlog_customer_name($d),'summary'=>'报价保存完成：'.($d['quote_no']??'').'，产品 '.count($items).' 个，数量 '.($d['qty']??'').'，金额 '.($d['currency']??'').' '.($d['amount']??''),'detail'=>$d,'before'=>$before,'after'=>$after]);
  if(array_key_exists('commission_json',$d))quote_log_event($pdo,['action'=>'quote_commission_confirmation_saved','event'=>'保存报价佣金确认状态','quote_id'=>$id,'quote_no'=>$d['quote_no']??'','customer_name'=>qlog_customer_name($d),'user_name'=>$__quote_user['username']??'','summary'=>'保存报价内部佣金状态','before'=>$before['commission_json']??null,'after'=>$d['commission_json']]);
  ok(['id'=>$id,'approval_status'=>'pending']);
}
 if($action==='delete_quote') { $d=input_json(); $before=null; if(!empty($d['id'])) $before=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($d['id'])]); $pdo->prepare("DELETE FROM quote_orders WHERE id=?")->execute([intval($d['id'])]); quote_log_event($pdo,['action'=>'delete_quote_done','event'=>'报价删除完成','quote_id'=>intval($d['id']??0),'quote_no'=>$before['quote_no']??'','customer_name'=>qlog_customer_name($before?:[]),'summary'=>'删除报价：'.($before['quote_no']??('ID '.($d['id']??''))),'detail'=>$d,'before'=>$before]); ok(); }
 fail('unknown action');
}catch(Throwable $e){ try{ quote_log_event($pdo,['level'=>'ERROR','action'=>$action,'event'=>'接口错误','summary'=>$e->getMessage(),'detail'=>input_json()]); }catch(Throwable $ignore){} fail($e->getMessage()); }
