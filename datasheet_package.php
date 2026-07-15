<?php
/** Artdon Datasheet Center V2.0 ZIP package / batch PDF */
declare(strict_types=1);
require_once __DIR__.'/datasheet_lib.php';
function ds_render_pdf_html_for_zip(int $id, string $modelNo=''): string {
    $oldGet=$_GET; $oldReq=$_REQUEST;
    $_GET=array('id'=>$id,'model_no'=>$modelNo,'logo'=>'0');
    $_REQUEST=$_GET;
    ob_start();
    include __DIR__.'/datasheet_pdf.php';
    $html=(string)ob_get_clean();
    $_GET=$oldGet; $_REQUEST=$oldReq;
    return $html;
}
try{
    ds_init();
    $mode=ds_s($_GET['mode']??'package');
    if($mode==='batch_pdf') ds_require_perm('batch_generate','批量生成PDF'); else ds_require_perm('generate_zip','生成资料包');
    if(!class_exists('ZipArchive')) throw new RuntimeException('服务器未开启 ZipArchive，无法生成 ZIP。');
    $ids=[]; foreach(explode(',',(string)($_GET['ids']??'')) as $x){ $n=(int)$x; if($n>0)$ids[]=$n; }
    if(!$ids && isset($_GET['id'])) $ids[]=(int)$_GET['id'];
    if(!$ids) throw new RuntimeException('缺少产品。');
    $pack=ds_s($_GET['pack']??'all');
    $tmpRel=DS_UPLOAD_ROOT.'/packages/'.date('Ym'); $tmp=__DIR__.'/'.$tmpRel; if(!is_dir($tmp)) @mkdir($tmp,0775,true);
    $work=$tmp.'/work_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)); @mkdir($work,0775,true);
    $zipName=($mode==='batch_pdf'?'Artdon_Batch_PDF_':'Artdon_Datasheet_Package_').date('Ymd_His').'.zip'; $zipPath=$tmp.'/'.$zipName; $rel=$tmpRel.'/'.$zipName;
    $zip=new ZipArchive(); if($zip->open($zipPath,ZipArchive::CREATE)!==true) throw new RuntimeException('无法创建ZIP文件。');
    foreach($ids as $id){
        $p=ds_product_detail($id,''); $base=ds_safe_name($p['model_no']);
        $html=ds_render_pdf_html_for_zip((int)$p['id'],$p['model_no']);
        $htmlFile=$work.'/'.$base.'_Datasheet.html'; file_put_contents($htmlFile,$html);
        $pdfFile=$work.'/'.$base.'_Datasheet.pdf';
        if(ds_html_to_pdf($htmlFile,$pdfFile)) $zip->addFile($pdfFile,$base.'/01_Datasheet/'.$base.'_Datasheet.pdf');
        else $zip->addFile($htmlFile,$base.'/01_Datasheet/'.$base.'_Datasheet_Print.html');
        if($mode==='batch_pdf') { ds_log('batch_pdf.generate','批量生成PDF','success',$p['model_no'],(int)$p['id'],'zip','',$zipName,array('fallback'=>!is_file($pdfFile))); ds_record_generated($p['model_no'],(int)$p['id'],'BatchPDF',$zipName,$rel,'zip','Batch PDF generated'); continue; }
        $files=$p['files']??array();
        foreach($files as $f){
            $type=ds_s($f['file_type']??'其它'); $ok=false;
            if($pack==='all') $ok=true;
            elseif($pack==='images' && preg_match('/高清|图片|image|photo/i',$type)) $ok=true;
            elseif($pack==='ies' && preg_match('/IES|LDT/i',$type)) $ok=true;
            elseif($pack==='test' && preg_match('/测试|报告|report|test/i',$type)) $ok=true;
            elseif($pack==='cert' && preg_match('/证书|CE|ROHS|EMC|cert/i',$type)) $ok=true;
            if(!$ok) continue;
            $lp=ds_local_path((string)$f['file_path']); if($lp==='' || !is_file($lp)) continue;
            $folder='07_Other'; if(preg_match('/高清|图片|image|photo/i',$type)) $folder='02_HD_Images'; elseif(preg_match('/IES|LDT/i',$type)) $folder='03_IES_LDT'; elseif(preg_match('/测试|报告|report|test/i',$type)) $folder='04_Test_Report'; elseif(preg_match('/证书|CE|ROHS|EMC|cert/i',$type)) $folder='05_Certificate'; elseif(preg_match('/安装|manual|CAD|DWG|drawing/i',$type)) $folder='06_Drawing_Manual';
            $zip->addFile($lp,$base.'/'.$folder.'/'.ds_safe_name((string)$f['original_name']));
        }
        if(!empty($p['image_url'])) $zip->addFromString($base.'/00_Links/product_image_url.txt',$p['image_url']);
        if(!empty($p['drawing_url'])) $zip->addFromString($base.'/00_Links/dimension_drawing_url.txt',$p['drawing_url']);
        ds_log('package.generate','生成资料包','success',$p['model_no'],(int)$p['id'],'zip','',$zipName,array('pack'=>$pack,'mode'=>$mode));
        ds_record_generated($p['model_no'],(int)$p['id'],'ZIP',$zipName,$rel,'zip','Package generated');
    }
    $zip->close();
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$zipName.'"'); header('Content-Length: '.filesize($zipPath)); readfile($zipPath); exit;
}catch(Throwable $e){ http_response_code(500); echo ds_h($e->getMessage()); exit; }
