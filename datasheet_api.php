<?php
/** Artdon Datasheet Center V2.1.8 API */
declare(strict_types=1);
require_once __DIR__.'/datasheet_lib.php';

try {
    ds_init();
    $action = ds_s($_GET['action'] ?? $_POST['action'] ?? 'home');

    if($action==='bootstrap'){
        ds_json(array('version'=>DS_VERSION,'perms'=>ds_perm_runtime(ds_current_user(false)),'stats'=>ds_stats(),'online'=>ds_online(),'features'=>ds_perm_features(),'settings'=>ds_settings_all(),'user'=>ds_current_user(false)),true,'ok');
    }

    if($action==='home'){
        $cats=ds_home_categories();
        foreach($cats as &$c){ $c['samples']=ds_home_samples($c['category'],8); }
        ds_json(array('categories'=>$cats,'stats'=>ds_stats(),'perms'=>ds_perm_runtime(ds_current_user(false)),'online'=>ds_online()),true,'首页分类');
    }

    if($action==='products'){
        $res=ds_products($_GET);
        $kw=ds_s($_GET['kw']??$_GET['q']??'');
        if($kw!=='' || ds_s($_GET['category']??'')!=='' || ds_s($_GET['source']??'')!==''){
            ds_log('product.search','搜索产品','success','',0,'product_list','',$kw,array('section'=>'产品列表','keyword'=>$kw,'category'=>ds_s($_GET['category']??''),'source'=>ds_s($_GET['source']??''),'quantity'=>(int)($res['total']??count($res['rows']??array()))));
        }
        ds_json($res,true,'产品列表');
    }

    if($action==='product'){
        $id=(int)($_GET['id']??0); $model=ds_s($_GET['model_no']??'');
        $p=ds_product_detail_fast($id,$model,false);
        ds_log_product_use('产品详情',$p,array('action_from'=>'select_product'));
        ds_json($p,true,'产品详情');
    }

    if($action==='product_related'){
        $id=(int)($_GET['id']??0); $model=ds_s($_GET['model_no']??'');
        $p=ds_product_detail_fast($id,$model,false);
        ds_log_product_use('资料/配置版块',$p,array('action_from'=>'open_related','loaded'=>'files_curves_accessories_configs'));
        ds_json(ds_product_related_fast($id,$model),true,'产品资料/配置');
    }
if($action==='sync_website_cache'){
        ds_require_perm('sync_website','刷新官网资料缓存');
        $id=(int)($_GET['id']??0); $model=ds_s($_GET['model_no']??'');
        $row=ds_naming_row($id,$model); if(!$row) throw new RuntimeException('未找到命名系统型号。');
        $data=ds_refresh_website_cache_for_product($row);
        ds_log('website.cache','从官网下载当前产品资料','success',ds_s($row['model_no']??''),(int)($row['id']??0),'product',(string)($row['id']??''),ds_s($row['model_no']??''),array('section'=>'官网同步','source_type'=>'official_website_pdf','source_url'=>ds_website_pdf_url_for_row($row),'params'=>count($data['params']??array()),'curves'=>count($data['photometric_images']??array()),'accessories'=>count($data['accessory_items']??array()),'quantity'=>count($data['params']??array())+count($data['photometric_images']??array())+count($data['accessory_items']??array())));
        ds_json(ds_product_detail_fast((int)($row['id']??0),ds_s($row['model_no']??''),false),true,'官网资料已缓存');
    }

    if($action==='save_override'){
        ds_require_perm('edit_params','保存产品补充资料');
        $raw=json_decode(file_get_contents('php://input'),true); if(!is_array($raw)) $raw=$_POST;
        $id=(int)($raw['naming_model_id']??0); $model=ds_s($raw['model_no']??'',120);
        if($model==='') throw new RuntimeException('缺少型号。');
        $fields=array('title','intro','product_family','model_display','dimensions','cutout','power','luminous_flux','efficacy','voltage','cct','cri','beam_angle','ip_rating','finish','mounting','dimming','material','remark');
        $data=array('naming_model_id'=>$id,'model_no'=>$model,'updated_by'=>ds_username());
        foreach($fields as $f){ $data[$f]=ds_s($raw[$f]??'', $f==='remark'||$f==='intro'?5000:255); }
        $oldOverride=array();
        try{ $stOld=ds_db()->prepare('SELECT * FROM datasheet_overrides WHERE model_no=? LIMIT 1'); $stOld->execute(array($model)); $oldOverride=$stOld->fetch()?:array(); }catch(Throwable $e){}
        $cols=array_keys($data); $vals=array_values($data);
        $updates=array(); foreach($cols as $c){ if(!in_array($c,array('model_no'),true)) $updates[]="`$c`=VALUES(`$c`)"; }
        ds_db()->prepare('INSERT INTO datasheet_overrides(`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',$updates))->execute($vals);
        ds_log('override.save','保存产品详细参数','success',$model,$id,'product',(string)$id,$model,array('section'=>'详细参数','before'=>$oldOverride,'after'=>$data));
        ds_json(ds_product_detail_fast($id,$model,true),true,'补充资料已保存');
    }

    if($action==='upload_files'){
        $model=ds_s($_POST['model_no']??'',120); $modelId=(int)($_POST['naming_model_id']??0);
        $isLibrary=!empty($_POST['library_upload']);
        $type=ds_s($_POST['file_type']??'其它',80);
        if($isLibrary){
            if(strpos($type,'高清')!==false) ds_require_perm('upload_library_hd','上传公共高清图库');
            elseif(strpos($type,'配件')!==false) ds_require_perm('upload_library_accessory','上传公共配件图库');
            elseif(strpos($type,'配光')!==false || strpos($type,'曲线')!==false) ds_require_perm('upload_library_curve','上传公共配光曲线库');
            else ds_require_perm('upload_file','上传公共资料');
        } else {
            ds_require_perm('upload_file','上传当前产品资料');
        }
        if($model==='' && !$isLibrary) throw new RuntimeException('缺少型号。'); $visibility=ds_s($_POST['visibility']??'customer',40); $title=ds_s($_POST['file_title']??'',255); $note=ds_s($_POST['note']??'',2000); if(!empty($_POST['library_model_hint'])){ $note=trim($note.' 关键词: '.ds_s($_POST['library_model_hint']??'',200)); }
        $isCurveType=(strpos($type,'配光')!==false || strpos($type,'曲线')!==false || stripos($type,'photometric')!==false || stripos($type,'curve')!==false);
        if($isLibrary && $isCurveType && $model==='') throw new RuntimeException('上传配光曲线需填写产品型号，用于生成“产品系列 + 产品型号 + 角度”的文件名。');
        $curveAnglesRaw=ds_s($_POST['curve_angles']??'',1000);
        $curveAngles=array_values(array_filter(array_map(function($x){ return ds_s($x,80); }, preg_split('/[,，;；\r\n\/]+/u',$curveAnglesRaw)?:array())));
        $curveSeries='';
        if($isCurveType && $model!==''){
            $curveRow=ds_naming_row($modelId,$model);
            if($curveRow) $curveSeries=ds_series($curveRow);
            if($curveSeries==='未分系列') $curveSeries='';
        }
        if(empty($_FILES['files'])) throw new RuntimeException('没有选择文件。');
        $files=$_FILES['files']; $count=is_array($files['name'])?count($files['name']):1; $saved=array();
        for($i=0;$i<$count;$i++){
            $f=array('name'=>is_array($files['name'])?$files['name'][$i]:$files['name'],'type'=>is_array($files['type'])?$files['type'][$i]:$files['type'],'tmp_name'=>is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name'],'error'=>is_array($files['error'])?$files['error'][$i]:$files['error'],'size'=>is_array($files['size'])?$files['size'][$i]:$files['size']);
            if(($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
            $up=ds_upload_one_file($f,'files/'.$type);
            $angle=$isCurveType ? ds_s($curveAngles[$i]??'',80) : '';
            $fileTitle=$title!==''?$title:$up['name'];
            $originalName=$up['name'];
            if($isCurveType && $angle!==''){
                $nameParts=array_values(array_filter(array($curveSeries,$model,$angle),function($x){ return ds_s($x)!==''; }));
                $fileTitle=ds_s(implode(' ', $nameParts),255);
                $ext=strtolower(pathinfo($up['name'],PATHINFO_EXTENSION));
                $originalName=$fileTitle.($ext!==''?'.'.$ext:'');
            }
            $fileNote=$note;
            if($isCurveType && $angle!=='') $fileNote=trim($fileNote.' 角度: '.$angle);
            ds_db()->prepare('INSERT INTO datasheet_files(naming_model_id,model_no,file_type,file_title,original_name,file_path,mime_type,size_bytes,visibility,note,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($modelId,$model,$type,$fileTitle,$originalName,$up['path'],$up['mime'],$up['size'],$visibility,$fileNote,ds_username()));
            $saved[]=$up;
            if(preg_match('/\.zip$/i',$up['name']??'')){
                $ex=ds_extract_archive_images_to_library($up,$type,$title,$note,$visibility,$model,$modelId);
                if(!empty($ex['saved'])){ foreach($ex['saved'] as $one){ $saved[]=$one; } }
            }
        }
        ds_log('file.upload',$isLibrary?'上传公共资料库':'上传产品资料','success',$model,$modelId,'file','',$type,array('section'=>$isLibrary?'公共资料库':'产品资料','source_type'=>$isLibrary?'manual_public_library':'manual_product_upload','file_type'=>$type,'count'=>count($saved),'quantity'=>count($saved),'visibility'=>$visibility,'files'=>array_map(function($x){return $x['name']??'';},$saved)));
        ds_json(ds_product_detail_fast($modelId,$model,true),true,'已上传 '.count($saved).' 个文件');
    }

    if($action==='delete_file'){
        ds_require_perm('delete_file','删除资料');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $id=(int)($raw['id']??0);
        $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? LIMIT 1'); $st->execute(array($id)); $f=$st->fetch(); if(!$f) throw new RuntimeException('文件不存在。');
        ds_db()->prepare('UPDATE datasheet_files SET is_deleted=1 WHERE id=?')->execute(array($id));
        $path=ds_local_path((string)$f['file_path']);
        $ref=ds_db()->prepare('SELECT COUNT(*) FROM datasheet_files WHERE id<>? AND is_deleted=0 AND file_path=?');
        $ref->execute(array($id,(string)$f['file_path']));
        if($path!=='' && is_file($path) && (int)$ref->fetchColumn()<=0) @unlink($path);
        ds_log('file.delete','删除资料','success',(string)$f['model_no'],(int)$f['naming_model_id'],'file',(string)$id,(string)$f['original_name'],$f);
        if(ds_s($f['model_no']??'')==='') ds_json(array('deleted_id'=>$id),true,'资料已删除');
        ds_json(ds_product_detail_fast((int)$f['naming_model_id'],(string)$f['model_no'],true),true,'资料已删除');
    }

    if($action==='update_file'){
        ds_require_perm('upload_file','修改资料');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $id=(int)($raw['id']??0);
        $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? AND is_deleted=0 LIMIT 1'); $st->execute(array($id)); $f=$st->fetch(); if(!$f) throw new RuntimeException('文件不存在或已删除。');
        $title=ds_s($raw['file_title']??($f['file_title']??''),255);
        $type=ds_s($raw['file_type']??($f['file_type']??''),80);
        $visibility=ds_s($raw['visibility']??($f['visibility']??'customer'),40);
        $note=ds_s($raw['note']??($f['note']??''),2000);
        ds_db()->prepare('UPDATE datasheet_files SET file_title=?, file_type=?, visibility=?, note=? WHERE id=?')->execute(array($title,$type,$visibility,$note,$id));
        ds_log('file.update','修改资料信息','success',(string)$f['model_no'],(int)$f['naming_model_id'],'file',(string)$id,$title,array('before'=>$f,'after'=>array('file_title'=>$title,'file_type'=>$type,'visibility'=>$visibility,'note'=>$note)));
        if(ds_s($f['model_no']??'')==='') ds_json(array('updated_id'=>$id,'file_title'=>$title,'file_type'=>$type,'visibility'=>$visibility,'note'=>$note),true,'资料信息已修改');
        ds_json(ds_product_detail_fast((int)$f['naming_model_id'],(string)$f['model_no'],true),true,'资料信息已修改');
    }

    if($action==='download_file'){
        ds_require_perm('download','下载资料');
        $id=(int)($_GET['id']??0);
        $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? AND is_deleted=0 LIMIT 1'); $st->execute(array($id)); $f=$st->fetch(); if(!$f) throw new RuntimeException('文件不存在或已删除。');
        $path=ds_local_path((string)$f['file_path']); if($path==='' || !is_file($path)) throw new RuntimeException('文件不存在，请检查资料文件。');
        ds_log('file.download','下载资料','success',(string)$f['model_no'],(int)$f['naming_model_id'],'file',(string)$id,(string)($f['original_name']?:$f['file_title']),array('section'=>'资料下载','file_type'=>ds_s($f['file_type']??''),'size'=>(int)($f['size_bytes']??0)));
        while(ob_get_level()>0) @ob_end_clean();
        $name=(string)($f['original_name']?:basename($path));
        header('Content-Type: '.((string)($f['mime_type']??'') ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($name));
        readfile($path);
        exit;
    }

    if($action==='materials'){
        ds_require_perm('select_material','读取 BOM 物料库');
        ds_json(ds_bom_materials($_GET),true,'BOM物料库');
    }

    if($action==='curve_library'){
        ds_require_perm('view_datasheet','搜索配光曲线库');
        ds_json(ds_curve_library(array('kw'=>ds_s($_GET['kw']??''))),true,'配光曲线库');
    }

    if($action==='image_asset_library'){
        ds_require_perm('view_datasheet','搜索图片资料库');
        ds_json(ds_image_file_library(array('kw'=>ds_s($_GET['kw']??'')), ds_s($_GET['kind']??'')),true,'图片资料库');
    }

    if($action==='copy_file_to_product'){
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST;
        $fileId=(int)($raw['file_id']??0); $model=ds_s($raw['model_no']??'',120); $modelId=(int)($raw['naming_model_id']??0); $type=ds_s($raw['file_type']??'配光曲线',80);
        if(strpos($type,'高清')!==false) ds_require_perm('pair_hd','高清图配对到产品');
        elseif(strpos($type,'配件')!==false) ds_require_perm('pair_accessory','配件图配对到产品');
        elseif(strpos($type,'配光')!==false || strpos($type,'曲线')!==false) ds_require_perm('pair_curve','配光曲线配对到产品');
        else ds_require_perm('upload_file','复制资料到当前产品');
        if($fileId<=0 || $model==='') throw new RuntimeException('缺少文件或型号。');
        $st=ds_db()->prepare('SELECT * FROM datasheet_files WHERE id=? AND is_deleted=0 LIMIT 1'); $st->execute(array($fileId)); $f=$st->fetch(); if(!$f) throw new RuntimeException('原文件不存在。');
        ds_db()->prepare('INSERT INTO datasheet_files(naming_model_id,model_no,file_type,file_title,original_name,file_path,mime_type,size_bytes,visibility,note,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($modelId,$model,$type,ds_s($f['file_title']??$f['original_name']??''),ds_s($f['original_name']??''),ds_s($f['file_path']??''),ds_s($f['mime_type']??''),(int)($f['size_bytes']??0),ds_s($f['visibility']??'customer'),'从公共资料库配对：'.ds_s($f['model_no']??'公共库').' '.ds_s($f['note']??''),ds_username()));
        ds_log('file.copy','公共资料配对到产品','success',$model,$modelId,'file',(string)$fileId,$type,array('section'=>'资料配对','source_type'=>'public_library','file_type'=>$type,'source_model'=>$f['model_no']??'','source_file'=>$f['original_name']??'','quantity'=>1));
        ds_json(ds_product_detail_fast($modelId,$model,true),true,'已复制到当前产品');
    }

    if($action==='accessories'){
        ds_json(ds_accessory_list($_GET),true,'配件库');
    }

    if($action==='save_accessory'){
        ds_require_perm('manage_accessory','管理配件库');
        $id=(int)($_POST['id']??0);
        $image=ds_s($_POST['image_path']??'',800);
        if(!empty($_FILES['image_file']) && ($_FILES['image_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){ $up=ds_upload_one_file($_FILES['image_file'],'accessories'); $image=$up['path']; }
        $data=array(
            'accessory_type'=>ds_s($_POST['accessory_type']??'配件',80),'category'=>ds_s($_POST['category']??'',120),'brand'=>ds_s($_POST['brand']??'',120),'name_cn'=>ds_s($_POST['name_cn']??'',255),'name_en'=>ds_s($_POST['name_en']??'',255),'model_no'=>ds_s($_POST['model_no']??'',160),'spec'=>ds_s($_POST['spec']??'',5000),'description'=>ds_s($_POST['description']??'',5000),'image_path'=>$image,'source'=>ds_s($_POST['source']??'manual',40),'bom_material_id'=>(int)($_POST['bom_material_id']??0),'visibility'=>ds_s($_POST['visibility']??'customer',40),'updated_by'=>ds_username()
        );
        if($data['name_cn']==='' && $data['name_en']==='') throw new RuntimeException('配件名称不能为空。');
        if($id>0){ $sets=array(); $vals=array(); foreach($data as $c=>$v){ $sets[]="`$c`=?"; $vals[]=$v; } $vals[]=$id; ds_db()->prepare('UPDATE datasheet_accessories SET '.implode(',',$sets).' WHERE id=?')->execute($vals); }
        else { $data['created_by']=ds_username(); $cols=array_keys($data); ds_db()->prepare('INSERT INTO datasheet_accessories(`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($data)); $id=(int)ds_db()->lastInsertId(); }
        ds_log('accessory.save','保存配件/配置库','success','',0,'accessory',(string)$id,$data['name_cn'] ?: $data['name_en'],$data);
        ds_json(array('id'=>$id,'rows'=>ds_accessory_list(array())),true,'配件已保存');
    }

    if($action==='delete_accessory'){
        ds_require_perm('manage_accessory','删除配件');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $id=(int)($raw['id']??0);
        ds_db()->prepare('UPDATE datasheet_accessories SET is_active=0,updated_by=?,updated_at=NOW() WHERE id=?')->execute(array(ds_username(),$id));
        ds_log('accessory.delete','删除/停用配件','success','',0,'accessory',(string)$id,'',array('id'=>$id));
        ds_json(ds_accessory_list(array()),true,'配件已停用');
    }

    if($action==='import_material_accessory'){
        ds_require_perm('manage_accessory','从 BOM 物料建立配件/配置');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $m=$raw['material']??array(); if(!is_array($m)) throw new RuntimeException('物料数据无效。');
        $type=ds_s($raw['accessory_type']??($m['group']??'配件'),80);
        $data=array('accessory_type'=>$type,'category'=>ds_s($m['category']??'',120),'brand'=>ds_s($m['brand']??'',120),'name_cn'=>ds_s($m['name']??'',255),'name_en'=>ds_s($m['name']??'',255),'model_no'=>ds_s($m['model']??'',160),'spec'=>ds_s($m['spec']??'',5000),'description'=>ds_s($m['supplier']??'',5000),'image_path'=>ds_s($m['image']??'',800),'source'=>'bom','bom_material_id'=>(int)($m['id']??0),'visibility'=>'customer','created_by'=>ds_username(),'updated_by'=>ds_username());
        $cols=array_keys($data); ds_db()->prepare('INSERT INTO datasheet_accessories(`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($data)); $id=(int)ds_db()->lastInsertId();
        ds_log('accessory.import_material','从BOM物料建立配件','success','',0,'accessory',(string)$id,$data['name_cn'],$data);
        ds_json(array('id'=>$id,'rows'=>ds_accessory_list(array('type'=>$type))),true,'已加入配件/配置库');
    }

    if($action==='save_product_accessories'){
        ds_require_perm('manage_accessory','保存产品配件选择');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $model=ds_s($raw['model_no']??'',120); $modelId=(int)($raw['naming_model_id']??0); $ids=$raw['accessory_ids']??array(); if(!is_array($ids)) $ids=array();
        ds_db()->prepare('DELETE FROM datasheet_product_accessories WHERE model_no=?')->execute(array($model));
        $ins=ds_db()->prepare('INSERT INTO datasheet_product_accessories(naming_model_id,model_no,accessory_id,enabled,sort_order,created_by) VALUES(?,?,?,?,?,?)'); $i=0;
        foreach($ids as $aid){ $aid=(int)$aid; if($aid>0) $ins->execute(array($modelId,$model,$aid,1,$i+=10,ds_username())); }
        ds_log('product_accessories.save','保存产品 PDF 配件选择','success',$model,$modelId,'product',(string)$modelId,$model,array('section'=>'配件','accessory_ids'=>$ids,'quantity'=>count($ids)));
        ds_json(ds_product_detail_fast($modelId,$model,true),true,'产品配件已保存');
    }

    if($action==='save_configs'){
        ds_require_perm('select_material','保存芯片/光学/电源配置');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $model=ds_s($raw['model_no']??'',120); $modelId=(int)($raw['naming_model_id']??0); $type=ds_s($raw['config_type']??'',80); $items=$raw['items']??array(); if(!is_array($items)) $items=array();
        if($model===''||$type==='') throw new RuntimeException('缺少型号或配置类型。');
        ds_db()->prepare('DELETE FROM datasheet_product_configs WHERE model_no=? AND config_type=?')->execute(array($model,$type));
        $ins=ds_db()->prepare('INSERT INTO datasheet_product_configs(naming_model_id,model_no,config_type,material_id,material_json,enabled,sort_order,note,updated_by) VALUES(?,?,?,?,?,?,?,?,?)'); $i=0;
        foreach($items as $it){ if(!is_array($it)) continue; $mid=(int)($it['id']??$it['material_id']??0); $ins->execute(array($modelId,$model,$type,$mid,json_encode($it,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,$i+=10,ds_s($it['note']??'',500),ds_username())); }
        ds_log('config.save','保存产品配置：'.$type,'success',$model,$modelId,'product',(string)$modelId,$model,array('section'=>'配置','file_type'=>$type,'type'=>$type,'count'=>count($items),'quantity'=>count($items)));
        ds_json(ds_product_detail_fast($modelId,$model,true),true,'配置已保存');
    }


    if($action==='create_snapshot'){
        ds_require_perm('record_send','生成资料快照');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST;
        $p=ds_product_detail_fast((int)($raw['naming_model_id']??0),ds_s($raw['model_no']??''),true);
        $sid=ds_create_snapshot($p['model_no'],(int)$p['id'],ds_s($raw['customer_name']??''),ds_s($raw['customer_email']??''),ds_s($raw['note']??''),'manual');
        ds_log('snapshot.create','保存资料快照','success',$p['model_no'],(int)$p['id'],'snapshot',(string)$sid,ds_s($raw['customer_name']??''),array_merge($raw,array('section'=>'快照','snapshot_id'=>$sid,'customer_name'=>ds_s($raw['customer_name']??''),'customer_email'=>ds_s($raw['customer_email']??''))));
        ds_json(ds_product_detail_fast((int)$p['id'],$p['model_no'],true),true,'快照已保存');
    }

    if($action==='logs'){
        ds_require_perm('view_logs','查看资料日志');
        $per=max(10,min(300,(int)($_GET['per_page']??80))); $where='1=1'; $args=array();
        $kw=ds_s($_GET['kw']??'');
        if($kw!==''){ $where.=' AND (action LIKE ? OR action_label LIKE ? OR model_no LIKE ? OR display_name LIKE ? OR username LIKE ? OR target_title LIKE ? OR detail_json LIKE ?)'; for($i=0;$i<7;$i++) $args[]='%'.$kw.'%'; }
        $user=ds_s($_GET['user']??''); if($user!==''){ $where.=' AND (username LIKE ? OR display_name LIKE ?)'; $args[]='%'.$user.'%'; $args[]='%'.$user.'%'; }
        $act=ds_s($_GET['action_type']??''); if($act!==''){ $where.=' AND action LIKE ?'; $args[]='%'.$act.'%'; }
        $model=ds_s($_GET['model_no']??''); if($model!==''){ $where.=' AND model_no LIKE ?'; $args[]='%'.$model.'%'; }
        $result=ds_s($_GET['result']??''); if($result!==''){ $where.=' AND result=?'; $args[]=$result; }
        $section=ds_s($_GET['section']??''); if($section!==''){ $where.=' AND section_name LIKE ?'; $args[]='%'.$section.'%'; }
        $sourceType=ds_s($_GET['source_type']??''); if($sourceType!==''){ $where.=' AND source_type=?'; $args[]=$sourceType; }
        $fileType=ds_s($_GET['file_type']??''); if($fileType!==''){ $where.=' AND file_type LIKE ?'; $args[]='%'.$fileType.'%'; }
        $from=ds_s($_GET['date_from']??''); if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){ $where.=' AND created_at>=?'; $args[]=$from.' 00:00:00'; }
        $to=ds_s($_GET['date_to']??''); if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){ $where.=' AND created_at<=?'; $args[]=$to.' 23:59:59'; }
        $st=ds_db()->prepare('SELECT * FROM datasheet_logs WHERE '.$where.' ORDER BY id DESC LIMIT '.$per); $st->execute($args); ds_json(array('rows'=>$st->fetchAll()?:array()),true,'日志');
    }

    if($action==='record_send'){
        ds_require_perm('record_send','记录客户资料发送');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $p=ds_product_detail_fast((int)($raw['naming_model_id']??0),ds_s($raw['model_no']??''),true);
        $snapshotId=ds_create_snapshot($p['model_no'],(int)$p['id'],ds_s($raw['customer_name']??'',255),ds_s($raw['customer_email']??'',255),ds_s($raw['note']??'',5000),'send_record');
        ds_db()->prepare('INSERT INTO datasheet_records(naming_model_id,model_no,record_type,file_title,file_format,customer_name,customer_email,note,created_by,snapshot_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($p['id'],$p['model_no'],'发送记录','客户资料发送','log',ds_s($raw['customer_name']??'',255),ds_s($raw['customer_email']??'',255),ds_s($raw['note']??'',5000),ds_username(),$snapshotId));
        ds_log('send.record','记录客户资料发送并保存快照','success',$p['model_no'],$p['id'],'snapshot',(string)$snapshotId,ds_s($raw['customer_name']??''),array_merge($raw,array('section'=>'客户发送记录','snapshot_id'=>$snapshotId,'customer_name'=>ds_s($raw['customer_name']??''),'customer_email'=>ds_s($raw['customer_email']??''))));
        ds_json(ds_product_detail_fast((int)$p['id'],$p['model_no'],true),true,'已记录发送并保存快照');
    }

    if($action==='generate_record'){
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $type=ds_s($raw['type']??'PDF',80); $p=ds_product_detail((int)($raw['naming_model_id']??0),ds_s($raw['model_no']??''));
        if($type==='PDF') ds_require_perm('generate_pdf','生成 PDF'); elseif($type==='Excel') ds_require_perm('generate_excel','生成 Excel'); else ds_require_perm('generate_zip','生成资料包');
        ds_db()->prepare('INSERT INTO datasheet_records(naming_model_id,model_no,record_type,file_title,file_path,file_format,note,created_by) VALUES(?,?,?,?,?,?,?,?)')
            ->execute(array($p['id'],$p['model_no'],$type,$p['model_no'].' '.$type,'',$type,ds_s($raw['note']??'',1000),ds_username()));
        ds_log('generate.record','生成资料：'.$type,'success',$p['model_no'],$p['id'],'product',(string)$p['id'],$p['model_no'],array_merge($raw,array('section'=>'资料输出','file_type'=>$type,'source_type'=>'local_generated','quantity'=>1)));
        ds_json(ds_product_detail((int)$p['id'],$p['model_no']),true,'生成记录已写入');
    }



    if($action==='storage_overview'){
        ds_require_perm('view_logs','查看空间使用一览');
        ds_log('storage.view','查看空间使用一览','success','',0,'storage','datasheet','空间一览',array('section'=>'空间一览','source_type'=>'local_stats'));
        ds_json(ds_storage_overview(),true,'空间使用一览');
    }

    if($action==='settings_get'){
        ds_require_perm('view_datasheet','查看资料系统设置');
        ds_json(array('settings'=>ds_settings_all(),'online'=>ds_online(),'user'=>ds_current_user(false),'perms'=>ds_perm_runtime(ds_current_user(false))),true,'系统设置');
    }

    if($action==='settings_save'){
        ds_require_perm('manage_settings','保存资料系统设置');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST;
        $oldSettings=ds_settings_all();
        $newSettings=ds_save_settings($raw);
        ds_log('settings.save','保存系统设置','success','',0,'settings','datasheet','资料系统设置',array('section'=>'设置中心','before'=>$oldSettings,'after'=>$newSettings));
        ds_json(array('settings'=>$newSettings),true,'设置已保存');
    }

    if($action==='snapshots_all'){
        ds_require_perm('view_all_snapshots','查看全部资料快照');
        ds_json(array('rows'=>ds_snapshots_all($_GET)),true,'全部快照');
    }

    if($action==='snapshot_detail'){
        ds_require_perm('view_all_snapshots','查看资料快照详情');
        $id=(int)($_GET['id']??0); if($id<=0) throw new RuntimeException('缺少快照ID。');
        ds_json(ds_snapshot_detail($id),true,'快照详情');
    }

    if($action==='online_list'){
        ds_require_perm('view_online','查看在线人员');
        ds_json(array('online'=>ds_online()),true,'在线人员');
    }

    if($action==='permissions_list'){
        ds_require_perm('manage_permissions','管理资料系统权限');
        ds_perm_bootstrap(); $users=array(); $defs=ds_perm_features();
        if(ds_table_exists('crm_users')){
            $rs=ds_db()->query("SELECT u.*, r.role_key, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id=u.role_id LEFT JOIN crm_departments d ON d.id=u.department_id ORDER BY u.id ASC LIMIT 1000")->fetchAll()?:array();
            foreach($rs as $u){
                $uid=(int)($u['id']??0); $features=array();
                $u['display_name']=ds_s($u['real_name']??$u['display_name']??$u['username']??'',120);
                $u['department']=ds_s($u['department_name']??$u['department']??'',120);
                foreach(array_keys($defs) as $k){ $features[$k]=function_exists('artdon_sso_can_feature') && artdon_sso_can_feature(DS_MODULE,$k,$u) ? 1 : 0; }
                $users[]=array('id'=>$uid,'username'=>ds_s($u['username']??'',120),'display_name'=>$u['display_name'],'department'=>$u['department'],'features'=>$features);
            }
        }
        ds_json(array('users'=>$users,'features'=>ds_perm_features()),true,'权限列表');
    }

    if($action==='permissions_save'){
        ds_require_perm('manage_permissions','保存资料系统权限');
        $raw=json_decode(file_get_contents('php://input'),true)?:$_POST; $uid=(int)($raw['user_id']??0); $features=$raw['features']??array(); if($uid<=0||!is_array($features)) throw new RuntimeException('参数错误。');
        $defs=ds_perm_features();
        $map=array(
            'view_datasheet'=>'datasheet.view','view_products'=>'datasheet.view','view_snapshots'=>'datasheet.view','view_all_snapshots'=>'datasheet.admin',
            'edit_params'=>'datasheet.edit','sync_website'=>'datasheet.sync_source',
            'upload_file'=>'datasheet.create','upload_library_hd'=>'datasheet.create','upload_library_accessory'=>'datasheet.create','upload_library_curve'=>'datasheet.create',
            'pair_hd'=>'datasheet.edit','pair_accessory'=>'datasheet.edit','pair_curve'=>'datasheet.edit','delete_file'=>'datasheet.delete',
            'generate_pdf'=>'datasheet.generate_pdf','generate_excel'=>'datasheet.generate_excel','generate_zip'=>'datasheet.package','batch_generate'=>'datasheet.package',
            'record_send'=>'datasheet.create','manage_accessory'=>'datasheet.edit','select_material'=>'datasheet.edit',
            'view_online'=>'datasheet.admin','view_logs'=>'datasheet.admin','manage_settings'=>'datasheet.admin','manage_permissions'=>'datasheet.admin'
        );
        $states=array();
        foreach($defs as $k=>$d){
            $perm=$map[$k]??('datasheet.'.($d['cap']??'view'));
            if(!isset($states[$perm])) $states[$perm]=0;
            if(!empty($features[$k])) $states[$perm]=1;
        }
        ds_db()->prepare("DELETE FROM crm_user_permissions WHERE user_id=? AND permission_key LIKE 'datasheet.%'")->execute(array($uid));
        $ins=ds_db()->prepare('INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE effect=VALUES(effect)');
        foreach($states as $perm=>$allowed){ $ins->execute(array($uid,$perm,$allowed?'allow':'deny')); }
        ds_log('permission.save','保存资料系统权限','success','',0,'user',(string)$uid,'',array('section'=>'权限中心','user_id'=>$uid,'features'=>$features));
        // return fresh
        $_GET['action']='permissions_list';
        ds_json(array('saved'=>1),true,'权限已保存');
    }

    ds_fail('未知 action：'.$action);
} catch(Throwable $e) {
    ds_log('api.error','接口错误','error','',0,'api','',ds_s($_GET['action']??$_POST['action']??''),array('error'=>$e->getMessage()));
    ds_fail($e->getMessage());
}
