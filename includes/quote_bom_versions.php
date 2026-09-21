<?php
declare(strict_types=1);
require_once __DIR__.'/bom_workflow.php';

// Read-only version catalog. Never load images or the complete BOM library.
function qbv_keys(array $p): array {
    return bcp_product_keys($p);
}
function qbv_matches(array $p,array $bom): bool {
    return (bool)array_intersect(qbv_keys($p),bcp_models($bom['model']??''));
}
function qbv_projects(PDO $pdo,array $product): array {
    if(!qbv_keys($product))return array();
    $st=$pdo->query('SELECT c.project_uid,c.snapshot_id,c.source,c.cost,c.updated_at,c.payload_json FROM bom_cost_publications c JOIN bom_projects b ON b.project_uid=c.project_uid WHERE b.is_active=1 ORDER BY c.updated_at DESC,c.project_uid');
    $out=array();
    while($r=$st->fetch(PDO::FETCH_ASSOC)){
        $p=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
        $keys=bcp_models($p['model']??'');
        if(stripos((string)($p['linked_system']??''),'NAMING')!==false||strpos((string)($p['linked_system']??''),'命名')!==false)$keys[]='NID'.($p['linked_id']??'');
        if(!array_intersect(qbv_keys($product),$keys))continue;
        $r['meta']=$p;$out[$r['project_uid']]=$r;
    }
    return $out;
}
function qbv_meta(array $s,array $pub): array {
    $t=json_decode((string)($s['totals_json']??'{}'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($t)||!isset($t['total'])||!is_numeric($t['total'])||!is_finite((float)$t['total'])||(float)$t['total']<0)throw new RuntimeException('BOM快照成本无效，请先核对该版本');
    return array('project_uid'=>(string)$s['project_uid'],'snapshot_id'=>(int)$s['id'],'snapshot_uid'=>(string)$s['snapshot_uid'],
        'name'=>(string)$s['snapshot_name'],'model'=>(string)$s['model'],'customer'=>(string)$s['customer'],
        'version_no'=>(string)$s['version_no'],'variant_label'=>(string)$s['variant_label'],'cost_rmb'=>round((float)$t['total'],4),
        'approved_at'=>(string)($s['approved_at']?:$s['created_at']),
        'published_at'=>(int)$pub['snapshot_id']===(int)$s['id']?(string)$pub['updated_at']:'',
        'current'=>(int)$pub['snapshot_id']===(int)$s['id']&&$pub['source']==='approved_snapshot',
        'publication_snapshot_id'=>(int)$pub['snapshot_id']);
}
function qbv_catalog(PDO $pdo,array $p,int $page=1): array {
    $projects=qbv_projects($pdo,$p);$page=max(1,min(100000,$page));$size=10;
    $result=array('versions'=>array(),'projects'=>count($projects),'total'=>0,'page'=>1,'pages'=>1,'legacy'=>array(),'current_publications'=>array());
    foreach($projects as $pub)if($pub['source']==='approved_snapshot')$result['current_publications'][]=array('project_uid'=>$pub['project_uid'],'snapshot_id'=>(int)$pub['snapshot_id']);
    foreach($projects as $pub)if($pub['source']!=='approved_snapshot')$result['legacy'][]=array('name'=>$pub['meta']['name']??'','project_uid'=>$pub['project_uid'],'cost_rmb'=>(float)$pub['cost'],'updated_at'=>$pub['updated_at'],'source'=>$pub['source'],'status'=>$pub['source']==='voided'?'当前快照已作废，暂停报价取价':'历史未审核成本（冻结）');
    if(!$projects)return $result;
    $marks=implode(',',array_fill(0,count($projects),'?'));$args=array_keys($projects);
    $where="project_uid IN ($marks)";
    $count=$pdo->prepare('SELECT COUNT(*) FROM bom_snapshots WHERE '.$where);$count->execute($args);$result['total']=(int)$count->fetchColumn();
    $result['pages']=max(1,(int)ceil($result['total']/$size));$result['page']=min($page,$result['pages']);$offset=($result['page']-1)*$size;
    $st=$pdo->prepare("SELECT id,project_uid,snapshot_uid,snapshot_name,model,customer,version_no,variant_label,totals_json,approved_at,created_at FROM bom_snapshots WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset");$st->execute($args);
    $voids=array();foreach($projects as $uid=>$pub)$voids[$uid]=bw_snapshot_voids($pdo,(string)$uid);
    while($s=$st->fetch(PDO::FETCH_ASSOC))$result['versions'][]=array_merge(qbv_meta($s,$projects[$s['project_uid']]),$voids[$s['project_uid']][(int)$s['id']]??array('voided'=>false),array('unavailable'=>$projects[$s['project_uid']]['source']==='voided'));
    return $result;
}
function qbv_components(array $rows): array {
    $spec=array();$labels=array('led'=>'LED','driver'=>'LED Driver','optic'=>'Optic','accessories'=>'Accessories','connector'=>'Connector','other'=>'Other');
    foreach($rows as $r){
        $class=qspec_classify_component(qspec_text_of_node($r));
        if(!$class||isset($spec[$class]))continue;
        $value=qspec_quote_name_of_node($r);
        if($value!=='')$spec[$class]=array('label'=>$labels[$class],'value'=>$value);
    }
    return $spec;
}
function qbv_resolve(PDO $pdo,array $product,int $id=0,?int $expectedPublication=null,bool $historyRead=false): array {
    $projects=qbv_projects($pdo,$product);
    if(!$id){
        foreach($projects as $pub)if($pub['source']==='voided')return array('choose'=>true,'message'=>'匹配BOM含作废暂停项，请明确选择其他有效BOM；没有有效版本时不能新增报价');
        $approved=array_filter($projects,fn($p)=>$p['source']==='approved_snapshot'&&!empty($p['snapshot_id']));
        if(count($approved)>1)return array('choose'=>true,'message'=>'存在多份已审核BOM，请选择客户/用途及版本');
        if(!$approved)return array('legacy'=>true,'message'=>'暂无正式审核快照，保留历史成本；未从草稿自动提取关键件');
        $id=(int)reset($approved)['snapshot_id'];
    }
    $st=$pdo->prepare('SELECT id,project_uid,snapshot_uid,snapshot_name,model,customer,version_no,variant_label,totals_json,approved_at,created_at,OCTET_LENGTH(rows_json) AS row_bytes FROM bom_snapshots WHERE id=?');$st->execute(array($id));$s=$st->fetch(PDO::FETCH_ASSOC);
    if(!$s||!isset($projects[$s['project_uid']]))throw new RuntimeException('该快照不属于此产品的有效BOM，请重新选择');
    $pub=$projects[$s['project_uid']];
    $void=bw_snapshot_voids($pdo,(string)$s['project_uid'])[$id]??array('voided'=>false);
    if(!$historyRead&&($void['voided']||$pub['source']==='voided'))throw new RuntimeException('该BOM快照已作废或已暂停取价，不能加入新报价');
    // Historical snapshots may have a former model; do not silently apply another model's content.
    if(!qbv_matches($product,$s))throw new RuntimeException('历史快照型号与当前产品不同，不能直接采用');
    if($expectedPublication!==null&&(int)$pub['snapshot_id']!==$expectedPublication)throw new RuntimeException('BOM已产生新版本，请重新打开版本列表核对');
    if((int)$s['row_bytes']>8*1024*1024)throw new RuntimeException('该快照物料明细异常过大，请管理员检查；未改变报价');
    $meta=qbv_meta($s,$pub);
    $st=$pdo->prepare('SELECT rows_json FROM bom_snapshots WHERE id=? AND project_uid=?');$st->execute(array($id,$s['project_uid']));
    $rows=bw_rows((string)$st->fetchColumn());$spec=qbv_components($rows);
    $reference=$meta;unset($reference['current'],$reference['published_at'],$reference['publication_snapshot_id']);
    $reference['digest']=hash('sha256',bw_json(array($reference,$spec)));
    $cost=$meta['cost_rmb'];
    return array('version'=>array_merge($meta,$void,array('unavailable'=>$pub['source']==='voided')),'patch'=>array('bom_version'=>$reference,'bom_match'=>1,'bom_cost_source'=>'BOM审核快照 #'.$id,
        'cost_rmb'=>$cost,'price_rmb'=>$cost,'cost_usd'=>$cost/7,'price_usd'=>$cost/7,'cost_updated_at'=>$meta['approved_at'],
        'quote_spec'=>$spec,'quote_spec_json'=>bw_json($spec),'quote_spec_source'=>'bom_snapshot','quote_spec_updated_at'=>$meta['approved_at'],
        'quote_spec_auto_generated'=>1,'bom_quote_spec_id'=>''));
}
function qbv_validate_save(PDO $pdo,array $data,?array $before=null): void {
    // Old quotes without a version reference are preserved, never retroactively rewritten.
    $hasVoids=bw_ready($pdo)&&(bool)$pdo->query("SELECT 1 FROM bom_workflow_events WHERE action='void_snapshot' LIMIT 1")->fetchColumn();
    if(!$hasVoids&&strpos((string)($data['items_json']??''),'"bom_version"')===false)return;
    $items=json_decode((string)($data['items_json']??'[]'),true,512,JSON_THROW_ON_ERROR);$cache=array();
    $oldRefs=array();
    if($hasVoids&&!empty($before['id'])){
        // Project only version references; never decode old embedded images a second time.
        $st=$pdo->prepare("SELECT JSON_EXTRACT(items_json,'$[*].product.bom_version') FROM quote_orders WHERE id=?");$st->execute(array($before['id']));
        foreach(json_decode((string)($st->fetchColumn()?:'[]'),true)?:array() as $ref){$key=(int)($ref['snapshot_id']??0).'|'.($ref['digest']??'');$oldRefs[$key]=($oldRefs[$key]??0)+1;}
    }
    foreach($items as $i=>$it){
        $p=$it['product']??array();$v=$p['bom_version']??null;
        if(!$v){
            if($hasVoids)foreach(qbv_projects($pdo,$p) as $pub)if($pub['source']==='voided'){
                // A pre-version legacy row may remain unchanged in its original document only.
                $same=false;if(!empty($before['id'])){$st=$pdo->prepare('SELECT JSON_EXTRACT(items_json,?) = CAST(? AS JSON) FROM quote_orders WHERE id=?');$st->execute(array('$['.(int)$i.']',bw_json($it),$before['id']));$same=(bool)$st->fetchColumn();}
                if(!$same)throw new RuntimeException('匹配BOM已暂停报价取价，请选择有效审核版本');
            }
            continue;
        }
        $id=(int)($v['snapshot_id']??0);if(!$id)throw new RuntimeException('报价BOM版本标识不完整');
        $key=$id.'|'.bw_json(qbv_keys($p));
        $r=$cache[$key]??($cache[$key]=qbv_resolve($pdo,$p,$id,null,true));$expected=$r['patch']??array();
        if(!empty($r['version']['voided'])||!empty($r['version']['unavailable'])){
            $oldKey=$id.'|'.($v['digest']??'');if(empty($oldRefs[$oldKey]))throw new RuntimeException('作废快照只能保留原单已有引用，不能新增、复制或另存到新报价');$oldRefs[$oldKey]--;
        }
        if(!isset($expected['bom_version'])||bw_json($expected['bom_version'])!==bw_json($v))throw new RuntimeException('第'.($i+1).'行BOM版本资料已变化，请重新核对版本');
        foreach(array('cost_rmb','price_rmb','cost_usd','price_usd') as $field)if(!isset($p[$field])||abs((float)$p[$field]-(float)$expected[$field])>0.00001)throw new RuntimeException('BOM快照成本被改动，请重新选择版本；售价请在手工售价填写');
        if(bw_json($p['quote_spec']??array())!==bw_json($expected['quote_spec']))throw new RuntimeException('BOM快照关键件不一致，请重新选择版本；客户说明请使用报价备注');
    }
}
