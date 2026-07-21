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
if ($action !== 'download_backup') {
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
    'save_quote'=>'quote_edit','push_order_crm_notice'=>'order_convert','list_pending_quotes'=>'quote_review_view','approve_quote'=>'quote_approve','reject_quote'=>'quote_approve','unapprove_quote'=>'quote_approve','delete_quote'=>'quote_delete','list_logs'=>'log_view','log_health'=>'log_view','delete_logs'=>'log_manage','log_event'=>'can_access','list_permission_users'=>'permission_manage','save_user_permission'=>'permission_manage','reset_user_permission'=>'permission_manage','delete_permission_user'=>'permission_manage','void_sales_order'=>'settings_manage','delete_test_order'=>'settings_manage'
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
    'quotes'=>rows($pdo,"SELECT ".quote_select_columns_except($pdo,'quote_orders',['approved_snapshot_json'])." FROM quote_orders ORDER BY id DESC LIMIT 1000"),
    'materials'=>get_materials($pdo),
    'price_levels'=>get_price_levels($pdo),
    'options'=>get_options($pdo),
    'system_settings'=>get_quote_system_settings($pdo),
    'owner_repair_count'=>$ownerRepairCount
   ]);
 }

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
     $items=quote_apply_review_items($items);
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
   $id=save_row($pdo,'quote_orders',$d,['quote_no','quote_date','user_name','customer_id','customer_name','customer_json','header_id','bank_id','template_id','header_json','bank_json','template_json','product_type','product_id','product_json','parts_json','items_json','qty','price','amount','currency','exchange_rate','moq','color','cct','cri','ip','extra_spec','status','quote_status','version_no','price_level_id','price_level_name','price_multiplier','approval_status','submitted_by','submitted_at','approved_by','approved_at','rejected_by','rejected_at','approval_note']);
   $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($id)]);
   $items=json_decode((string)($d['items_json']??'[]'),true); if(!is_array($items)) $items=[];
   quote_append_approval_log($pdo,(int)$id,[
     'action'=>$before?'resubmit':'submit','time'=>date('Y-m-d H:i:s'),'user'=>(string)($__quote_user['username']??''),'user_name'=>(string)($__quote_user['display_name']??$__quote_user['username']??''),
     'note'=>$before?'修改报价后重新提交审核':'提交报价审核','changes'=>[],'amount'=>quote_money_log((float)($d['amount']??0))
  ]);
  $after=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($id)]);
  quote_push_crm_review_reminder($pdo,$after?:$d,$__quote_user,$before?'resubmitted':'submitted',$before?'修改报价后重新提交审核':'提交报价审核');
  quote_log_event($pdo,['action'=>'save_quote_done','event'=>'报价保存完成','quote_id'=>$id,'quote_no'=>$d['quote_no']??'','customer_name'=>qlog_customer_name($d),'summary'=>'报价保存完成：'.($d['quote_no']??'').'，产品 '.count($items).' 个，数量 '.($d['qty']??'').'，金额 '.($d['currency']??'').' '.($d['amount']??''),'detail'=>$d,'before'=>$before,'after'=>$after]);
  ok(['id'=>$id,'approval_status'=>'pending']);
}
 if($action==='delete_quote') { $d=input_json(); $before=null; if(!empty($d['id'])) $before=row($pdo,'SELECT * FROM quote_orders WHERE id=? LIMIT 1',[intval($d['id'])]); $pdo->prepare("DELETE FROM quote_orders WHERE id=?")->execute([intval($d['id'])]); quote_log_event($pdo,['action'=>'delete_quote_done','event'=>'报价删除完成','quote_id'=>intval($d['id']??0),'quote_no'=>$before['quote_no']??'','customer_name'=>qlog_customer_name($before?:[]),'summary'=>'删除报价：'.($before['quote_no']??('ID '.($d['id']??''))),'detail'=>$d,'before'=>$before]); ok(); }
 fail('unknown action');
}catch(Throwable $e){ try{ quote_log_event($pdo,['level'=>'ERROR','action'=>$action,'event'=>'接口错误','summary'=>$e->getMessage(),'detail'=>input_json()]); }catch(Throwable $ignore){} fail($e->getMessage()); }
