<?php
/** Artdon Datasheet Center V2.1.7 PDF/Print preview template */
declare(strict_types=1);
require_once __DIR__.'/datasheet_lib.php';

if(!function_exists('ds_pdf_curve_angle_label')){
function ds_pdf_curve_angle_label(array $curve): string {
    foreach(array('beam_angle','angle','file_title','label','original_name','note') as $key){
        $value=ds_s($curve[$key]??'',255);
        if($value==='') continue;
        if(preg_match('/(?:^|[^A-Za-z0-9])(\d{1,3}(?:\.\d+)?\s*(?:°|D|DEG|DEGREE|度))(?:$|[^A-Za-z0-9])/iu',$value,$m)){
            return strtoupper(str_replace(' ','',$m[1]));
        }
    }
    return '';
}
}

try{
    $embedded = defined('DS_PDF_EMBED') && DS_PDF_EMBED;
    if ($embedded) {
        ds_ensure_tables();
    } else {
        ds_init();
        ds_require_perm('generate_pdf','预览/生成 PDF');
    }
    $snapshotId=(int)($_GET['snapshot_id']??0);
    if($snapshotId>0){
        $snap=ds_snapshot_detail($snapshotId);
        $payload=is_array($snap['snapshot']??null)?$snap['snapshot']:array();
        $p=is_array($payload['product']??null)?$payload['product']:array();
        foreach(array('params','files','photometric_images','manual_accessories','highres_images','accessories','website_accessories','website_photometric_images','configs') as $k){
            if(array_key_exists($k,$payload)) $p[$k]=$payload[$k];
        }
        $p['id']=(int)($p['id']??($snap['naming_model_id']??0));
        $p['model_no']=ds_s($p['model_no']??($snap['model_no']??''));
        $p['snapshot_id']=$snapshotId;
    } else {
        $id=(int)($_GET['id']??0); $model=ds_s($_GET['model_no']??'');
        $p=ds_product_detail_fast($id,$model,true);
    }
    if(!$embedded) ds_log_product_use('PDF预览',$p,array('action_from'=>'open_pdf','file_type'=>'PDF','source_type'=>'local_generated','snapshot_id'=>$snapshotId));
    $model=$p['model_no']; $ov=$p['override']??array();
    $title=ds_s($ov['title']??'') ?: ($p['title'] ?: $model);
    $intro=ds_s($ov['intro']??'');
    if($intro==='') $intro=ds_s($p['website_intro']??'');
    if($intro==='') $intro="Compact architectural lighting product designed for professional projects.\nHigh quality optical performance\nMultiple configuration options\nSuitable for commercial lighting applications";
    $params=ds_product_params($p);
    $params=array_filter($params, fn($v)=>ds_s($v)!=='');
    $curveFiles=array_values($p['photometric_images']??array());
    if(!$curveFiles){$curveFiles=array_values(array_filter($p['files']??array(), function($f){ $t=ds_s($f['file_type']??''); $path=ds_s($f['file_path']??''); return preg_match('/配光|曲线|photometric|curve/i',$t) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i',$path); }));}
    $curveFiles=array_slice($curveFiles,0,4);
    if(!$curveFiles && !empty($p['website_photometric_images'])){ foreach((array)$p['website_photometric_images'] as $ph){ if(!empty($ph['image'])) $curveFiles[]=array('url'=>$ph['image'],'file_title'=>ds_s($ph['label']??''),'original_name'=>ds_s($ph['alt']??'Photometric curve')); } }
    $accessories=array_values($p['accessories']??array());
    foreach((array)($p['manual_accessories']??array()) as $ma){ $accessories[]=$ma; }
    foreach((array)($p['website_accessories']??array()) as $wa){ $accessories[]=$wa; }
    $accessories=array_slice($accessories,0,4);
    $showLogo=in_array(strtolower(ds_s($_GET['logo']??'0')),array('1','yes','true','on'),true);
    $footerLogo=''; foreach(array('assets/img/logo-artdon-black.png','assets/img/logo-black.png','assets/img/logo-artdon.png') as $lc){ if(is_file(__DIR__.'/'.$lc)){ $footerLogo=$lc; break; } }
    if($footerLogo==='') $showLogo=false;
    $self='datasheet_pdf.php?'.http_build_query(array('id'=>$p['id'],'model_no'=>$model));
    $dsSettings=ds_settings_all();
    $headerSettings=$dsSettings['header']??array();
    $pdfHeaderEnabled=!empty($headerSettings['pdf_header_enabled']);
    $pdfHeaderTitle=ds_s($headerSettings['pdf_header_title']??'',120);
    $pdfHeaderSubtitle=ds_s($headerSettings['pdf_header_subtitle']??'',180);
    $pdfFooterText=ds_s($headerSettings['pdf_footer_text']??'',180);
    $sourceLabelDisplay=ds_s($p['source_label']??'',80);
    if($embedded){
        $sourceClass=ds_s($p['source_class']??'',40);
        $sourceLabelDisplay=$sourceClass==='web'?'Official website':'Naming system';
    }
    $wm=$dsSettings['watermark']??array();
    $wmEnabled=!empty($wm['enabled']);
    $wmText=ds_s($wm['text']??'',255);
    $wmOpacity=ds_s($wm['opacity']??'0.08',20);
}catch(Throwable $e){ if(!empty($embedded)) throw $e; http_response_code(500); echo '<pre>'.ds_h($e->getMessage()).'</pre>'; exit; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><?php if(defined('DS_PDF_BASE_URL')): ?><base href="<?=ds_h(DS_PDF_BASE_URL)?>"><?php endif; ?><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ds_h($title)?> - Datasheet</title>
<style>
@page{size:A4 portrait;margin:0}*{box-sizing:border-box}html,body{margin:0;background:#e9e9e9;color:#101014;font-family:Arial,"Helvetica Neue",Helvetica,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}.toolbar{position:fixed;right:18px;top:14px;z-index:30;display:flex;gap:8px;align-items:center}.toolbar a,.toolbar button{border:1px solid #111;background:#111;color:#fff;padding:10px 15px;font-size:12px;line-height:1;font-weight:800;letter-spacing:.04em;text-transform:uppercase;cursor:pointer;text-decoration:none}.toolbar a.is-active{background:#fff;color:#111}.pdf-page{width:210mm;height:297mm;margin:10px auto;background:#fff;border:1px solid #cfcfcf;position:relative;overflow:hidden;page-break-after:always;padding:12mm 14mm 8mm}.custom-pdf-head{display:grid;grid-template-columns:1fr auto;align-items:end;gap:8mm;margin:0 0 4mm;padding:0 0 2.4mm;border-bottom:.22mm solid #d8d8d8}.custom-pdf-head b{font-size:9pt;letter-spacing:.08em;text-transform:uppercase}.custom-pdf-head span{display:block;margin-top:1mm;color:#666;font-size:6.2pt}.custom-pdf-head em{font-style:normal;color:#888;font-size:6pt;letter-spacing:.08em;text-transform:uppercase}.custom-footer-text{position:absolute;left:14mm;right:14mm;bottom:3.2mm;text-align:center;color:#777;font-size:5.8pt;letter-spacing:.06em}.sheet-title{margin:0 0 3.2mm 0;font-size:22pt;line-height:.92;font-weight:950;letter-spacing:-.025em;color:#0b0c0f}.sheet-intro{width:100%;max-width:182mm;margin:0 auto 5mm;padding:3mm 0 3.1mm;border-top:.22mm solid #d8d8d8;border-bottom:.22mm solid #d8d8d8;color:#202020;font-size:7pt;line-height:1.22;font-weight:400;white-space:pre-line;overflow-wrap:break-word}.main-grid{display:grid;grid-template-columns:54mm minmax(0,1fr);gap:5mm;align-items:start;width:100%;max-width:182mm;margin:0 auto}.media-stack{display:grid;gap:3.1mm}.image-box{margin:0;width:54mm;height:54mm;border:0;background:transparent;display:flex;align-items:center;justify-content:center;overflow:hidden}.image-box img{display:block;width:100%;height:100%;object-fit:contain;object-position:center center}.image-box.dimension-image{border:.18mm solid #d8d8d8!important;background:#fff!important}.placeholder{color:#999;font-size:7pt;letter-spacing:.08em;text-transform:uppercase}.spec-table{width:100%;border-collapse:collapse;table-layout:fixed}.spec-table tr:nth-child(odd){background:#f3f3f3}.spec-table td{height:5.15mm;padding:0 3mm;border:0;font-size:6.35pt;line-height:1.08;vertical-align:middle}.spec-table td:first-child{width:43%;color:#333;font-weight:400}.spec-table td:last-child{width:57%;color:#161616;font-weight:800;overflow-wrap:anywhere}.section-line{height:0;border:0;border-top:.2mm solid #dddddd;margin:6.2mm auto 0;width:100%;max-width:182mm}.info-section{display:grid;grid-template-columns:54mm minmax(0,1fr);gap:5mm;align-items:start;width:100%;max-width:182mm;margin:0 auto;padding-top:5.2mm}.kicker{margin:0 0 1.5mm 0;color:#c92b32;font-size:5.8pt;line-height:1;font-weight:900;letter-spacing:.18em;text-transform:uppercase}.section-title{margin:0;color:#0b0c0f;font-size:19pt;line-height:.9;font-weight:950;letter-spacing:-.02em}.right-grid{min-width:0}.curve-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:3.2mm;align-items:start;direction:rtl}.curve-card{margin:0;min-width:0;text-align:center;break-inside:avoid;direction:ltr}.curve-frame{width:100%;aspect-ratio:1/1;border:0;background:transparent;display:flex;align-items:center;justify-content:center;overflow:hidden}.curve-frame img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:contain;object-position:center center}.curve-card figcaption{margin-top:2mm;color:#858585;font-size:5.7pt;line-height:1.1;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.accessory-section{padding-top:5.2mm}.accessory-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:3.2mm;align-items:start}.accessory-spacer{min-height:1px}.accessory-card{min-width:0;break-inside:avoid}.accessory-card figure{margin:0;width:100%;aspect-ratio:1/1;border:.18mm solid #d8d8d8;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden}.accessory-card img{display:block;width:auto;height:auto;max-width:82%;max-height:82%;object-fit:contain;padding:0}.accessory-code{display:block;box-sizing:border-box;min-height:5pt;height:5pt;line-height:5pt;margin:2mm 0 .7mm 0;color:#df232b;font-size:5pt;font-weight:900;letter-spacing:.09em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.accessory-code.is-placeholder{visibility:hidden;color:transparent;opacity:0}.accessory-name{margin:0;color:#0b0c0f;font-size:6.5pt;line-height:1.05;font-weight:950;letter-spacing:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pdf-page.has-footer-logo{padding-bottom:12mm}.pdf-footer-logo{position:absolute;left:14mm;right:14mm;bottom:3.4mm;height:6mm;display:flex;align-items:center;justify-content:center;pointer-events:none}.pdf-footer-logo img{display:block;max-width:32mm;max-height:6mm;width:auto;height:auto;object-fit:contain}.source-mark{position:absolute;left:14mm;bottom:10mm;font-size:6pt;color:#888;letter-spacing:.05em}.source-badge{position:absolute;left:2mm;top:2mm;background:#111;color:#fff;border-radius:999px;padding:1.4mm 2.3mm;font-size:5.2pt;font-weight:900}.source-badge.web{background:#166534}.image-box.main-image{position:relative}.pdf-watermark{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) rotate(-28deg);font-size:34pt;font-weight:900;color:#111;opacity:var(--wm-opacity,.08);z-index:0;pointer-events:none;white-space:nowrap}.pdf-page>*:not(.pdf-watermark){position:relative;z-index:1}@media print{html,body{background:#fff}.toolbar{display:none}.pdf-page{margin:0;border:0;box-shadow:none}.source-mark{bottom:4mm}}
</style></head><body>
<?php if(empty($embedded)): ?><div class="toolbar"><a class="<?=$showLogo?'':'is-active'?>" href="<?=ds_h($self.'&logo=0')?>">No LOGO</a><a class="<?=$showLogo?'is-active':''?>" href="<?=ds_h($self.'&logo=1')?>">With black LOGO</a><button onclick="window.print()">Print / Save PDF</button></div><?php endif; ?>
<section class="pdf-page product-sheet<?=$showLogo?' has-footer-logo':''?>" style="--wm-opacity:<?=ds_h($wmOpacity)?>">
  <?php if($wmEnabled && $wmText!==''): ?><div class="pdf-watermark"><?=ds_h($wmText)?></div><?php endif; ?>
  <?php if($pdfHeaderEnabled && ($pdfHeaderTitle!=='' || $pdfHeaderSubtitle!=='')): ?><div class="custom-pdf-head"><div><b><?=ds_h($pdfHeaderTitle)?></b><?php if($pdfHeaderSubtitle!==''): ?><span><?=ds_h($pdfHeaderSubtitle)?></span><?php endif; ?></div><em>Datasheet</em></div><?php endif; ?>
  <h1 class="sheet-title"><?=ds_h($title)?></h1>
  <?php if($intro!==''): ?><div class="sheet-intro"><?=ds_h($intro)?></div><?php endif; ?>
  <div class="main-grid">
    <div class="media-stack">
      <figure class="image-box main-image"><?php if($p['image_url']): ?><span class="source-badge <?=$p['source_class']==='web'?'web':'naming'?>"><?=ds_h($sourceLabelDisplay)?></span><img src="<?=ds_h($p['image_url'])?>" alt="<?=ds_h($title)?>"><?php else: ?><span class="placeholder">Product image</span><?php endif; ?></figure>
      <figure class="image-box dimension-image"><?php if($p['drawing_url']): ?><img src="<?=ds_h($p['drawing_url'])?>" alt="Dimension drawing"><?php else: ?><span class="placeholder">Dimension drawing</span><?php endif; ?></figure>
    </div>
    <table class="spec-table"><tbody><?php foreach($params as $label=>$value): ?><tr><td><?=ds_h($label)?></td><td><?=ds_h($value)?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <?php if($curveFiles): ?><hr class="section-line"><section class="info-section photometric-section"><div class="left-copy"><p class="kicker">Product overview</p><h2 class="section-title">Technical<br>product<br>information</h2></div><div class="right-grid curve-grid"><?php foreach(array_slice($curveFiles,0,4) as $cf): ?><?php $angleLabel=ds_pdf_curve_angle_label($cf); ?><figure class="curve-card"><div class="curve-frame"><img src="<?=ds_h($cf['url'] ?? $cf['image'] ?? '')?>" alt="Photometric curve"></div><?php if($angleLabel!==''): ?><figcaption><?=ds_h($angleLabel)?></figcaption><?php endif; ?></figure><?php endforeach; ?></div></section><?php endif; ?>
  <?php if($accessories): ?><hr class="section-line"><section class="info-section accessory-section"><div class="left-copy"><p class="kicker">Accessories</p><h2 class="section-title">Compatible<br>accessories</h2></div><div class="right-grid accessory-grid"><?php for($i=0,$sp=max(0,4-count($accessories));$i<$sp;$i++): ?><div class="accessory-spacer"></div><?php endfor; ?><?php foreach($accessories as $a): ?><article class="accessory-card"><figure><?php if(!empty($a['image_url'])): ?><img src="<?=ds_h($a['image_url'])?>" alt="<?=ds_h(($a['name_en']??'') ?: (($a['name_cn']??'') ?: (($a['file_title']??'') ?: 'Accessory')))?>"><?php endif; ?></figure><?php if(ds_s($a['model_no']??'')!==''): ?><p class="accessory-code"><?=ds_h($a['model_no'])?></p><?php else: ?><p class="accessory-code is-placeholder">&nbsp;</p><?php endif; ?><h3 class="accessory-name"><?=ds_h(($a['name_en']??'') ?: (($a['name_cn']??'') ?: (($a['file_title']??'') ?: 'Accessory')))?></h3></article><?php endforeach; ?></div></section><?php endif; ?>
  <div class="source-mark">Datasheet Center V<?=DS_VERSION?> · Source: <?=ds_h($sourceLabelDisplay)?></div>
  <?php if($pdfFooterText!=='' && !$showLogo): ?><div class="custom-footer-text"><?=ds_h($pdfFooterText)?></div><?php endif; ?>
  <?php if($showLogo): ?><div class="pdf-footer-logo"><img src="<?=ds_h(ds_asset_url($footerLogo))?>" alt="Artdon Lighting"></div><?php endif; ?>
</section>
<?php if(in_array(strtolower(ds_s($_GET['autoprint']??'')),array('1','yes','true','on'),true)): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},450);});</script><?php endif; ?>
</body></html>
