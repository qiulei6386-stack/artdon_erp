<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_api('bom');
/* ARTDON_SSO_GATE_V2_END */

/**
 * Artdon BOM Naming Bridge API V1.0
 * Safe add-on: does not replace bom.php / bom_api.php.
 */
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function require_bom_bridge_perm($action){
    $map = [
        'ping' => 'view',
        'init' => 'naming_link',
        'list_naming' => 'view',
        'create_bom_from_naming' => 'naming_link',
        'bind_existing' => 'naming_link',
        'schema_debug' => 'admin',
    ];
    $cap = $map[$action] ?? 'view';
    if(!artdon_sso_can('bom', $cap)){
        artdon_sso_json_error('当前账号没有 BOM 命名联动权限', 403, ['error_code'=>'PERMISSION_DENIED','action'=>$action]);
    }
    if(in_array($action, ['create_bom_from_naming','bind_existing'], true) && !artdon_sso_can('bom','edit')){
        artdon_sso_json_error('当前账号没有 BOM 编辑权限', 403, ['error_code'=>'PERMISSION_DENIED','action'=>$action]);
    }
}
require_bom_bridge_perm((string)$action);

function out_json($arr){ while(ob_get_level()>0){ @ob_end_clean(); } echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body_json(){ $raw=file_get_contents('php://input'); $d=json_decode($raw,true); return is_array($d)?$d:$_POST; }
function pdo_bridge(){
    if(function_exists('db')){ $pdo=db(); if($pdo instanceof PDO){ $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); return $pdo; } }
    $host=defined('DB_HOST')?DB_HOST:'localhost'; $name=defined('DB_NAME')?DB_NAME:'artdon_erp'; $user=defined('DB_USER')?DB_USER:'artdon_erp'; $pass=defined('DB_PASS')?DB_PASS:'';
    return new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function qid($x){ return '`'.str_replace('`','``',$x).'`'; }
function table_exists($pdo,$t){ $st=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'); $st->execute([$t]); return (bool)$st->fetchColumn(); }
function cols($pdo,$t){ if(!table_exists($pdo,$t)) return []; $st=$pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'); $st->execute([$t]); return $st->fetchAll(PDO::FETCH_COLUMN); }
function hascol($pdo,$t,$c){ return in_array($c, cols($pdo,$t), true); }
function addcol($pdo,$t,$c,$ddl){ if(table_exists($pdo,$t) && !hascol($pdo,$t,$c)) $pdo->exec('ALTER TABLE '.qid($t).' ADD COLUMN '.$ddl); }
function now_text(){ return date('Y-m-d H:i:s'); }
function uid(){ return 'BOM-'.date('YmdHis').'-'.substr(md5(uniqid('', true)),0,6); }
function current_user_name(){
    if(function_exists('artdon_sso_current_user')){
        $u = artdon_sso_current_user(false);
        if($u) return (string)($u['display_name'] ?? $u['real_name'] ?? $u['username'] ?? 'system');
    }
    foreach(['plm_user','crm_user','artdon_user','bom_user','user','current_user'] as $k){
        if(!isset($_SESSION[$k])) continue; $u=$_SESSION[$k];
        if(is_string($u) && trim($u)!=='') return trim($u);
        if(is_array($u)){ foreach(['display_name','real_name','name','username','account','email'] as $kk){ if(!empty($u[$kk])) return trim((string)$u[$kk]); } }
    }
    foreach(['username','user_name','display_name','account'] as $k){ if(!empty($_SESSION[$k])) return trim((string)$_SESSION[$k]); }
    return 'system';
}
function norm($s){ $s=mb_strtolower(trim((string)$s),'UTF-8'); $s=str_replace(['．','。','-','_',' '],['.','.','','',''],$s); return $s; }
function pick($row,$keys,$default=''){ foreach($keys as $k){ if(isset($row[$k]) && trim((string)$row[$k])!=='') return trim((string)$row[$k]); } return $default; }
function naming_select_fields($pdo){
    $base=[]; $c=cols($pdo,'naming_models');
    foreach(['id','model_no','category','item_name','product_name','customer','status','remark','image_path','drawing_path','dimension_type','dim_opening','dim_outer_d','dim_length','dim_width','dim_height','size_code','created_at','updated_at'] as $f){
        $base[] = in_array($f,$c,true) ? qid($f) : "'' AS ".qid($f);
    }
    return implode(',', $base);
}
function dim_text($r){
    $parts=[];
    if(!empty($r['dim_opening'])) $parts[]='开孔 '.$r['dim_opening'];
    if(!empty($r['dim_outer_d'])) $parts[]='直径 '.$r['dim_outer_d'];
    if(!empty($r['dim_length'])) $parts[]='长 '.$r['dim_length'];
    if(!empty($r['dim_width'])) $parts[]='宽 '.$r['dim_width'];
    if(!empty($r['dim_height'])) $parts[]='高 '.$r['dim_height'];
    if(!$parts && !empty($r['size_code'])) $parts[]='尺寸 '.$r['size_code'];
    return implode(' × ', $parts);
}
function ensure_schema($pdo){
    if(!table_exists($pdo,'bom_projects')) return;
    addcol($pdo,'bom_projects','naming_id','`naming_id` INT NULL DEFAULT NULL');
    addcol($pdo,'bom_projects','naming_model_no','`naming_model_no` VARCHAR(80) NOT NULL DEFAULT \"\"');
    addcol($pdo,'bom_projects','naming_snapshot_json','`naming_snapshot_json` MEDIUMTEXT NULL');
    try{ $pdo->exec('CREATE INDEX idx_bom_naming_id ON `bom_projects` (`naming_id`)'); }catch(Throwable $e){}
    try{ $pdo->exec('CREATE INDEX idx_bom_naming_model ON `bom_projects` (`naming_model_no`)'); }catch(Throwable $e){}
}
function project_to_public($p){
    return [
        'project_uid'=>(string)($p['project_uid'] ?? $p['id'] ?? ''),
        'name'=>(string)($p['name'] ?? ''),
        'model'=>(string)($p['model'] ?? ''),
        'naming_id'=>(int)($p['naming_id'] ?? 0),
        'naming_model_no'=>(string)($p['naming_model_no'] ?? ''),
        'updated_at'=>(string)($p['updated_at'] ?? $p['updatedAt'] ?? ''),
        'total_cost'=>artdon_sso_can('bom','cost_view') ? bom_total_cost($p) : '',
    ];
}
function bom_total_cost($p){
    $rows=json_decode((string)($p['rows_json'] ?? '[]'),true); if(!is_array($rows)) $rows=[];
    $mat=0.0; foreach($rows as $r){ $qty=(float)($r['qty']??0); $price=(float)($r['price']??0); $process=(float)($r['process']??0); $finish=(float)($r['finishCost']??($r['finish_cost']??0)); $mat += $qty*($price+$process+$finish); }
    return round($mat + (float)($p['labor']??0) + (float)($p['other']??0), 4);
}
function load_bom_projects($pdo){
    if(!table_exists($pdo,'bom_projects')) return [];
    $where = hascol($pdo,'bom_projects','is_active') ? ' WHERE (`is_active`=1 OR `is_active` IS NULL)' : '';
    return $pdo->query('SELECT * FROM `bom_projects`'.$where.' ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function find_existing_bom($pdo,$naming){
    $projects=load_bom_projects($pdo); $id=(int)($naming['id']??0); $model=(string)($naming['model_no']??''); $nmodel=norm($model);
    $best=null; $score=-1;
    foreach($projects as $p){
        $s=0;
        if($id>0 && (int)($p['naming_id']??0)===$id) $s=100;
        $pm=(string)($p['naming_model_no'] ?? ''); if($nmodel!=='' && norm($pm)===$nmodel) $s=max($s,90);
        $modelCol=(string)($p['model'] ?? ''); if($nmodel!=='' && norm($modelCol)===$nmodel) $s=max($s,80);
        $name=(string)($p['name'] ?? ''); if($nmodel!=='' && strpos(norm($name),$nmodel)!==false) $s=max($s,70);
        if($s>$score){ $score=$s; $best=$p; }
    }
    return $score>0 ? project_to_public($best) : null;
}
function naming_row($pdo,$id){
    if(!table_exists($pdo,'naming_models')) return null;
    $st=$pdo->prepare('SELECT '.naming_select_fields($pdo).' FROM `naming_models` WHERE id=? LIMIT 1'); $st->execute([(int)$id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function create_bom_from_naming($pdo,$naming,$copyExisting=false){
    ensure_schema($pdo);
    if(!table_exists($pdo,'bom_projects')) throw new RuntimeException('未找到 bom_projects 表');
    $model=(string)$naming['model_no']; $title=trim($model.'-'.($naming['product_name'] ?: $naming['item_name']));
    $who=current_user_name(); $uid=uid(); $snapshot=json_encode($naming, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $data=[
        'project_uid'=>$uid,
        'name'=>$title ?: ('命名产品BOM-'.$model),
        'customer'=>(string)($naming['customer'] ?? ''),
        'model'=>$model,
        'product_type'=>trim(($naming['category']??'').' / '.($naming['item_name']??''),' /'),
        'currency'=>'RMB',
        'product_image'=>(string)($naming['image_path'] ?? ''),
        'labor'=>0,
        'other'=>0,
        'profit_rate'=>30,
        'quote_mode'=>'markup',
        'exchange_rate'=>1,
        'note'=>"来自命名系统：{$model}\n".dim_text($naming),
        'rows_json'=>'[]',
        'created_by'=>$who,
        'updated_by'=>$who,
        'naming_id'=>(int)$naming['id'],
        'naming_model_no'=>$model,
        'naming_snapshot_json'=>$snapshot,
        'is_active'=>1,
    ];
    if($copyExisting){
        $existing=find_existing_bom($pdo,$naming);
        if($existing && !empty($existing['project_uid'])){
            $st=$pdo->prepare('SELECT * FROM `bom_projects` WHERE `project_uid`=? LIMIT 1'); $st->execute([$existing['project_uid']]); $src=$st->fetch(PDO::FETCH_ASSOC);
            if($src){
                foreach(['labor','other','profit_rate','quote_mode','exchange_rate','rows_json','currency'] as $k){ if(array_key_exists($k,$src)) $data[$k]=$src[$k]; }
                $data['name']=$title.' - 复制';
                $data['note']=trim(($src['note']??'')."\n复制自 BOM：".$existing['project_uid']);
            }
        }
    }
    $cols=[]; $vals=[]; $ph=[]; $tableCols=cols($pdo,'bom_projects');
    foreach($data as $k=>$v){ if(in_array($k,$tableCols,true)){ $cols[]=qid($k); $vals[]=$v; $ph[]='?'; } }
    if(!$cols) throw new RuntimeException('bom_projects 字段异常，无法写入');
    $pdo->prepare('INSERT INTO `bom_projects` ('.implode(',',$cols).') VALUES ('.implode(',',$ph).')')->execute($vals);
    return ['project_uid'=>$uid,'name'=>$data['name'],'model'=>$model];
}

try{
    $pdo=pdo_bridge();
    if($action==='ping') out_json(['ok'=>true,'version'=>'BOM Naming Bridge API V1.0']);
    if($action==='init'){
        ensure_schema($pdo);
        out_json(['ok'=>true,'message'=>'BOM 命名联动初始化完成','has_naming'=>table_exists($pdo,'naming_models'),'has_bom'=>table_exists($pdo,'bom_projects'),'bom_project_cols'=>cols($pdo,'bom_projects')]);
    }
    if($action==='list_naming'){
        ensure_schema($pdo);
        if(!table_exists($pdo,'naming_models')) out_json(['ok'=>false,'error'=>'未找到 naming_models 表，请先确认命名系统已安装。']);
        $d=body_json(); $q=trim((string)($d['q'] ?? $_GET['q'] ?? '')); $limit=(int)($d['limit'] ?? $_GET['limit'] ?? 80); if($limit<10)$limit=10; if($limit>300)$limit=300;
        $where=[]; $args=[];
        if($q!==''){
            $where[]='(`model_no` LIKE ? OR `product_name` LIKE ? OR `item_name` LIKE ? OR `category` LIKE ? OR `customer` LIKE ? OR `remark` LIKE ?)';
            for($i=0;$i<6;$i++) $args[]='%'.$q.'%';
        }
        if(hascol($pdo,'naming_models','bom_allowed')) $where[]='(`bom_allowed`=1 OR `bom_allowed` IS NULL)';
        $sql='SELECT '.naming_select_fields($pdo).' FROM `naming_models` '.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY updated_at DESC, id DESC LIMIT '.$limit;
        $st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){
            $r['dim_summary']=dim_text($r);
            $ex=find_existing_bom($pdo,$r);
            $r['bom_existing']=$ex;
            $r['has_bom']=$ex?1:0;
        }
        out_json(['ok'=>true,'rows'=>$rows,'count'=>count($rows)]);
    }
    if($action==='create_bom_from_naming'){
        $d=body_json(); $id=(int)($d['naming_id'] ?? 0); $mode=(string)($d['mode'] ?? 'open_or_create');
        if($id<=0) out_json(['ok'=>false,'error'=>'缺少 naming_id']);
        $n=naming_row($pdo,$id); if(!$n) out_json(['ok'=>false,'error'=>'命名产品不存在']);
        ensure_schema($pdo);
        $existing=find_existing_bom($pdo,$n);
        if($existing && $mode==='open_or_create') out_json(['ok'=>true,'mode'=>'existing','project'=>$existing,'message'=>'该命名产品已有 BOM，已返回现有成本单']);
        $created=create_bom_from_naming($pdo,$n,$mode==='copy');
        out_json(['ok'=>true,'mode'=>'created','project'=>$created,'message'=>'已按命名系统产品新建 BOM 成本单']);
    }
    if($action==='bind_existing'){
        $d=body_json(); $uid=trim((string)($d['project_uid']??'')); $id=(int)($d['naming_id']??0);
        if($uid===''||$id<=0) out_json(['ok'=>false,'error'=>'缺少 project_uid 或 naming_id']);
        $n=naming_row($pdo,$id); if(!$n) out_json(['ok'=>false,'error'=>'命名产品不存在']);
        ensure_schema($pdo); $cols=cols($pdo,'bom_projects');
        $data=['naming_id'=>(int)$n['id'],'naming_model_no'=>(string)$n['model_no'],'naming_snapshot_json'=>json_encode($n,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'model'=>(string)$n['model_no'],'product_type'=>trim(($n['category']??'').' / '.($n['item_name']??''),' /'),'product_image'=>(string)($n['image_path']??''),'updated_by'=>current_user_name()];
        $sets=[];$args=[]; foreach($data as $k=>$v){ if(in_array($k,$cols,true)){ $sets[]=qid($k).'=?'; $args[]=$v; } }
        if(in_array('updated_at',$cols,true)) $sets[]='`updated_at`=NOW()';
        $args[]=$uid; $pdo->prepare('UPDATE `bom_projects` SET '.implode(',',$sets).' WHERE `project_uid`=?')->execute($args);
        out_json(['ok'=>true,'message'=>'已绑定命名产品','project_uid'=>$uid,'model_no'=>$n['model_no']]);
    }
    if($action==='schema_debug'){
        out_json(['ok'=>true,'tables'=>['naming_models'=>table_exists($pdo,'naming_models'),'bom_projects'=>table_exists($pdo,'bom_projects')],'naming_cols'=>cols($pdo,'naming_models'),'bom_cols'=>cols($pdo,'bom_projects')]);
    }
    out_json(['ok'=>false,'error'=>'unknown action']);
}catch(Throwable $e){ out_json(['ok'=>false,'error'=>$e->getMessage()]); }
