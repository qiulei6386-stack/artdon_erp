<?php
/* ARTDON_QUOTE_ORDER_EXCEL_V6_8_5_54 PL uppercase title */
if (!function_exists('qoe_xml')) {
function qoe_xml($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'); }
function qoe_col($n){ $s=''; while($n>0){ $m=($n-1)%26; $s=chr(65+$m).$s; $n=intdiv($n-1,26); } return $s; }
function qoe_ref($c,$r){ return qoe_col($c).$r; }
function qoe_cell($c,$r,$v,$style=0,$num=false){
  $ref=qoe_ref($c,$r); $s=$style?' s="'.$style.'"':'';
  if($num && is_numeric($v)) return '<c r="'.$ref.'"'.$s.'><v>'.(0+$v).'</v></c>';
  return '<c r="'.$ref.'" t="inlineStr"'.$s.'><is><t xml:space="preserve">'.qoe_xml($v).'</t></is></c>';
}
function qoe_formula($c,$r,$formula,$style=0){ $ref=qoe_ref($c,$r); $s=$style?' s="'.$style.'"':''; return '<c r="'.$ref.'"'.$s.'><f>'.qoe_xml($formula).'</f></c>'; }
function qoe_row($r,$cells,$height=null){ $h=$height?' ht="'.$height.'" customHeight="1"':''; return '<row r="'.$r.'"'.$h.'>'.implode('',$cells).'</row>'; }
function qoe_merge(&$merges,$a,$b,$c,$d){ $merges[]=qoe_ref($a,$b).':'.qoe_ref($c,$d); }
function qoe_style_cells($r,$c1,$c2,$style,$firstText=''){
  $cells=[];
  for($c=$c1;$c<=$c2;$c++) $cells[]=qoe_cell($c,$r,$c===$c1?$firstText:'',$style);
  return $cells;
}
function qoe_safe_filename($s,$ext){
  $s=trim((string)$s); $s=preg_replace('#[\\/:*?"<>|]+#',' ', $s); $s=preg_replace('/\s+/',' ', $s); $s=trim($s, " ._-\t\n\r\0\x0B");
  return ($s!==''?$s:'document').$ext;
}
function qoe_content_disposition_filename($filename){ $safe=str_replace(['\\','"'],['_','\\"'],$filename); return 'attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($filename); }
function qoe_num($v){ return is_numeric($v) ? (0+$v) : 0; }
function qoe_qty($v,$d=2){ if(function_exists('qd_qty')) return qd_qty($v,$d); $s=number_format((float)$v,$d,'.',''); $s=preg_replace('/\.0+$/','',$s); $s=preg_replace('/(\.\d*?)0+$/','$1',$s); return $s; }
function qoe_money($v){ if(function_exists('qd_money')) return qd_money($v); return number_format((float)$v,2,'.',''); }
function qoe_len($s){ return function_exists('mb_strlen') ? mb_strlen((string)$s,'UTF-8') : strlen((string)$s); }
function qoe_line_count($s){ $arr=preg_split('/\R/',(string)$s); $n=0; foreach($arr as $x){ $n+=max(1,ceil(qoe_len($x)/58)); } return max(1,$n); }
function qoe_zip_files($files){
  $time=time(); $d=getdate($time); $dosTime=(($d['hours'] & 0x1F) << 11) | (($d['minutes'] & 0x3F) << 5) | (int)(($d['seconds'] & 0x3E) / 2); $dosDate=((($d['year']-1980) & 0x7F) << 9) | (($d['mon'] & 0x0F) << 5) | ($d['mday'] & 0x1F);
  $out=''; $central=''; $offset=0; $count=0;
  foreach($files as $name=>$data){ $name=str_replace('\\','/',$name); $data=(string)$data; $crc=crc32($data); if($crc<0)$crc+=4294967296; $size=strlen($data); $nlen=strlen($name); $local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0).$name.$data; $out.=$local; $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,0x0314,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0,0,0,0,0,$offset).$name; $offset+=strlen($local); $count++; }
  $cdOffset=strlen($out); $cdSize=strlen($central); return $out.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,$cdSize,$cdOffset,0);
}
function qoe_image_source_to_jpeg($src){
  $src=trim((string)$src); if($src==='') return null; $data=null;
  if(preg_match('/^data:image\/[^;]+;base64,(.+)$/i',$src,$m)) $data=base64_decode($m[1]);
  else if(preg_match('/^https?:\/\//i',$src)){ $ctx=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]); $data=@file_get_contents($src,false,$ctx); }
  else{
    $doc=isset($_SERVER['DOCUMENT_ROOT'])?rtrim((string)$_SERVER['DOCUMENT_ROOT'],'/'):''; $candidates=[];
    if(strlen($src)>0 && $src[0]==='/'){ $candidates[]=$src; if($doc)$candidates[]=$doc.$src; $candidates[]=__DIR__.$src; }
    else{ $candidates[]=__DIR__.'/'.$src; $candidates[]=getcwd().'/'.$src; if($doc)$candidates[]=$doc.'/'.$src; }
    foreach($candidates as $f){ if($f && is_file($f)){ $data=@file_get_contents($f); if($data!==false) break; } }
  }
  if(!$data) return null; $size=@getimagesizefromstring($data); if(!$size || empty($size[0]) || empty($size[1])) return null;
  $w=(int)$size[0]; $h=(int)$size[1]; $mime=strtolower((string)($size['mime']??''));
  if($mime==='image/jpeg' || $mime==='image/jpg') return ['data'=>$data,'w'=>$w,'h'=>$h];
  if(function_exists('imagecreatefromstring') && function_exists('imagejpeg')){
    $im=@imagecreatefromstring($data); if(!$im) return null; $canvas=imagecreatetruecolor($w,$h); $white=imagecolorallocate($canvas,255,255,255); imagefilledrectangle($canvas,0,0,$w,$h,$white); imagecopy($canvas,$im,0,0,0,0,$w,$h); ob_start(); imagejpeg($canvas,null,88); $jpg=ob_get_clean(); imagedestroy($im); imagedestroy($canvas); if($jpg) return ['data'=>$jpg,'w'=>$w,'h'=>$h];
  }
  return null;
}
function qoe_drawing_parts($images,$colWidths,$rowHeights){
  if(!$images) return [[], '', '', '']; $files=[]; $anchors=[]; $rels=[]; $idx=1;
  foreach($images as $img){
    $files['xl/media/image'.$idx.'.jpg']=$img['data']; $rels[]='<Relationship Id="rId'.$idx.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$idx.'.jpg"/>';
    $col=max(1,(int)($img['col']??1)); $row=max(1,(int)($img['row']??1)); $cw=(float)($colWidths[$col]??10); $rh=(float)($rowHeights[$row]??60);
    $colPx=max(40,$cw*7.2); $rowPx=max(30,$rh*1.333333); $maxW=$colPx*0.82; $maxH=$rowPx*0.72;
    $iw=max(1,(float)$img['w']); $ih=max(1,(float)$img['h']); $scale=min($maxW/$iw,$maxH/$ih,1.0); $dw=max(1,round($iw*$scale)); $dh=max(1,round($ih*$scale));
    $cx=$dw*9525; $cy=$dh*9525; $colOff=max(0,round(($colPx-$dw)/2))*9525; $rowOff=max(0,round(($rowPx-$dh)/2))*9525;
    $anchors[]='<xdr:oneCellAnchor><xdr:from><xdr:col>'.($col-1).'</xdr:col><xdr:colOff>'.$colOff.'</xdr:colOff><xdr:row>'.($row-1).'</xdr:row><xdr:rowOff>'.$rowOff.'</xdr:rowOff></xdr:from><xdr:ext cx="'.$cx.'" cy="'.$cy.'"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($idx+1).'" name="Picture '.$idx.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId'.$idx.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    $idx++;
  }
  $drawing='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.implode('',$anchors).'</xdr:wsDr>';
  $drawingRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('',$rels).'</Relationships>';
  $sheetRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
  return [$files,$drawing,$drawingRels,$sheetRels];
}
function qoe_cols_xml($widths){ $xml='<cols>'; foreach($widths as $i=>$w){ $xml.='<col min="'.$i.'" max="'.$i.'" width="'.$w.'" customWidth="1"/>'; } return $xml.'</cols>'; }
function qoe_styles_xml(){
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="9"><font><sz val="9"/><name val="ARS MaquetteTr"/></font><font><sz val="16"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="18"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="9"/><name val="ARS MaquetteTr"/></font><font><sz val="8"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="10"/><name val="ARS MaquetteTr"/></font><font><sz val="7"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="8"/><name val="ARS MaquetteTr"/></font><font><sz val="8"/><name val="ARS MaquetteTr"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF9ED"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border><border><left style="medium"><color auto="1"/></left><right style="medium"><color auto="1"/></right><top style="medium"><color auto="1"/></top><bottom style="medium"><color auto="1"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="16"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="3" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="TableStyleLight16"/></styleSheet>';
}
function qoe_build_ci_sheet($order,$ship,$items,$settings,$customer,$sellerName,$sellerText,$docTitle,&$images,&$rowHeights,&$colWidths,&$merges){
  $currency=(string)($order['currency']??'USD'); $rows=[]; $colWidths=[1=>10,2=>12,3=>13,4=>13,5=>42,6=>8.5,7=>7,8=>10,9=>10,10=>10]; $r=1;
  $rows[]=qoe_row($r,qoe_style_cells($r,1,5,1,$sellerName),26); qoe_merge($merges,1,$r,5,$r); $rowHeights[$r]=26; $r+=2;
  $txt="From:\n".trim((string)$sellerText); $rows[]=qoe_row($r,qoe_style_cells($r,1,5,3,$txt),70); qoe_merge($merges,1,$r,5,$r+2); $rowHeights[$r]=70; $r+=5;
  $buyerLabel=(string)($settings['buyer_label']??'Buyer / Consignee'); $buyer=function_exists('qd_customer_text') ? qd_customer_text($customer,$buyerLabel) : ''; $rows[]=qoe_row($r,qoe_style_cells($r,1,5,3,$buyerLabel.':'."\n".$buyer),70); qoe_merge($merges,1,$r,5,$r+2); $rowHeights[$r]=70;
  $titleRow=3; $rows[]=qoe_row($titleRow,qoe_style_cells($titleRow,7,10,2,$docTitle),30); qoe_merge($merges,7,$titleRow,10,$titleRow); $rowHeights[$titleRow]=30;
  $tr=5; $terms=function_exists('qd_doc_terms')?qd_doc_terms('ci',$order,$ship,$settings):[];
  foreach($terms as $row){ $label=(string)($row[0]??''); $val=(string)($row[1]??''); $cells=[]; for($c=7;$c<=8;$c++)$cells[]=qoe_cell($c,$tr,$c===7?$label:'',4); for($c=9;$c<=10;$c++)$cells[]=qoe_cell($c,$tr,$c===9?$val:'',5); $rows[]=qoe_row($tr,$cells,26); qoe_merge($merges,7,$tr,8,$tr); qoe_merge($merges,9,$tr,10,$tr); $rowHeights[$tr]=26; $tr++; }
  $r=16; $headers=['Picture','Size','Customer'."\n".'Model','Manufacturer'."\n".'Code','Description','Color','QTY'."\n".'(pcs)','Unit Price'."\n".'('.$currency.')','Amount'."\n".'('.$currency.')','HS Code']; $cells=[]; foreach($headers as $i=>$h)$cells[]=qoe_cell($i+1,$r,$h,6); $rows[]=qoe_row($r,$cells,36); $rowHeights[$r]=36; $start=$r+1; $r++;
  foreach($items as $i=>$it){ $desc=function_exists('qd_desc')?qd_desc($it):($it['specification']??''); $height=max(88,min(140,36+qoe_line_count($desc)*11)); $rowHeights[$r]=$height; $img=function_exists('qd_img_src')?qd_img_src($it):($it['image']??''); if($img!==''){ $im=qoe_image_source_to_jpeg($img); if($im){ $im['row']=$r; $im['col']=1; $images[]=$im; } }
    $qty=qoe_num($it['qty']??0); $price=qoe_num($it['unit_price']??0); $row=[qoe_cell(1,$r,'',7),qoe_cell(2,$r,$it['size']??'',7),qoe_cell(3,$r,$it['customer_code']??'',7),qoe_cell(4,$r,$it['product_code']??'',7),qoe_cell(5,$r,$desc,9),qoe_cell(6,$r,$it['color']??'',7),qoe_cell(7,$r,$qty,8,true),qoe_cell(8,$r,$price,8,true),qoe_formula(9,$r,'G'.$r.'*H'.$r,8),qoe_cell(10,$r,$it['hs_code']??'',15)]; $rows[]=qoe_row($r,$row,$height); $r++; }
  $end=max($start,$r-1); $rowHeights[$r]=24; $rows[]=qoe_row($r,array_merge(qoe_style_cells($r,1,6,7,''),[qoe_formula(7,$r,'SUM(G'.$start.':G'.$end.')',10),qoe_cell(8,$r,'Total:',12),qoe_formula(9,$r,'SUM(I'.$start.':I'.$end.')',10),qoe_cell(10,$r,'',7)]),24); $r+=3;
  $left='Total Qty: '.qoe_qty(function_exists('qd_total')?qd_total($items,'qty'):0).' PCS' . "\n" . 'Total Amount: '.$currency.' '.qoe_money(function_exists('qd_total')?qd_total($items,'amount'):0) . "\n" . 'Currency: '.$currency;
  $right='Invoice No: '.($ship['commercial_invoice_no']??'') . "\n" . 'PI No.: '.(function_exists('qd_order_no_at')?qd_order_no_at($order['order_no']??'',$order['quote_no']??''):($order['order_no']??'')) . "\n" . 'Date: '.($ship['ship_date']??date('Y-m-d'));
  $rows[]=qoe_row($r,array_merge(qoe_style_cells($r,1,5,11,$left),qoe_style_cells($r,7,10,11,$right)),72); qoe_merge($merges,1,$r,5,$r); qoe_merge($merges,7,$r,10,$r); $rowHeights[$r]=72; $r+=3;
  /* V6.8.5.53: CI Excel 不显示银行信息区 */
  return [$rows,$r];
}
function qoe_build_pl_sheet($order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,&$images,&$rowHeights,&$colWidths,&$merges){
  $rows=[]; $colWidths=[1=>9,2=>12,3=>13,4=>34,5=>8,6=>7,7=>7,8=>5.5,9=>5.5,10=>5.5,11=>7,12=>7,13=>7,14=>7]; $r=1;
  $rows[]=qoe_row($r,qoe_style_cells($r,1,8,1,$sellerName),26); qoe_merge($merges,1,$r,8,$r); $rowHeights[$r]=26; $r+=2;
  $rows[]=qoe_row($r,qoe_style_cells($r,1,8,3,"From:\n".trim((string)$sellerText)),70); qoe_merge($merges,1,$r,8,$r+2); $rowHeights[$r]=70; $r+=5;
  $buyerLabel=(string)($settings['buyer_label']??'Buyer / Consignee'); $buyer=function_exists('qd_customer_text') ? qd_customer_text($customer,$buyerLabel) : ''; $rows[]=qoe_row($r,qoe_style_cells($r,1,8,3,$buyerLabel.':'."\n".$buyer),70); qoe_merge($merges,1,$r,8,$r+2); $rowHeights[$r]=70;
  $titleRow=3; $rows[]=qoe_row($titleRow,qoe_style_cells($titleRow,10,14,2,$docTitle),30); qoe_merge($merges,10,$titleRow,14,$titleRow); $rowHeights[$titleRow]=30;
  $tr=5; $terms=function_exists('qd_doc_terms')?qd_doc_terms('pl',$order,$ship,$settings):[]; foreach($terms as $row){$label=(string)($row[0]??'');$val=(string)($row[1]??'');$cells=[];for($c=10;$c<=11;$c++)$cells[]=qoe_cell($c,$tr,$c===10?$label:'',4);for($c=12;$c<=14;$c++)$cells[]=qoe_cell($c,$tr,$c===12?$val:'',5);$rows[]=qoe_row($tr,$cells,26);qoe_merge($merges,10,$tr,11,$tr);qoe_merge($merges,12,$tr,14,$tr);$rowHeights[$tr]=26;$tr++;}
  $r=16; $headers=['Picture','Customer'."\n".'Model','Manufacturer'."\n".'Code','Description','Color','QTY'."\n".'(pcs)','PCS/'."\n".'CTN','L'."\n".'(cm)','W'."\n".'(cm)','H'."\n".'(cm)','CTNS','N.W.'."\n".'(KG)','G.W.'."\n".'(KG)','CBM']; $cells=[]; foreach($headers as $i=>$h)$cells[]=qoe_cell($i+1,$r,$h,6); $rows[]=qoe_row($r,$cells,36); $rowHeights[$r]=36; $start=$r+1; $r++;
  foreach($items as $i=>$it){ $desc=function_exists('qd_desc')?qd_desc($it):($it['specification']??''); $height=max(88,min(140,36+qoe_line_count($desc)*11)); $rowHeights[$r]=$height; $img=function_exists('qd_img_src')?qd_img_src($it):($it['image']??''); if($img!==''){ $im=qoe_image_source_to_jpeg($img); if($im){ $im['row']=$r; $im['col']=1; $images[]=$im; } } $dims=function_exists('qd_carton_dims')?qd_carton_dims($it['carton_size']??''):['','',''];
    $row=[qoe_cell(1,$r,'',7),qoe_cell(2,$r,$it['customer_code']??'',7),qoe_cell(3,$r,$it['product_code']??'',7),qoe_cell(4,$r,$desc,9),qoe_cell(5,$r,$it['color']??'',7),qoe_cell(6,$r,qoe_num($it['qty']??0),8,true),qoe_cell(7,$r,qoe_num($it['pcs_per_ctn']??0),8,true),qoe_cell(8,$r,$dims[0]??'',7),qoe_cell(9,$r,$dims[1]??'',7),qoe_cell(10,$r,$dims[2]??'',7),qoe_cell(11,$r,qoe_num($it['cartons']??0),8,true),qoe_cell(12,$r,qoe_num($it['nw']??0),8,true),qoe_cell(13,$r,qoe_num($it['gw']??0),8,true),qoe_cell(14,$r,qoe_num($it['cbm']??0),8,true)]; $rows[]=qoe_row($r,$row,$height); $r++; }
  $end=max($start,$r-1); $rowHeights[$r]=24; $rows[]=qoe_row($r,array_merge(qoe_style_cells($r,1,5,7,''),[qoe_formula(6,$r,'SUM(F'.$start.':F'.$end.')',10),qoe_cell(7,$r,'',7),qoe_cell(8,$r,'Total:',12),qoe_cell(9,$r,'',12),qoe_cell(10,$r,'',12),qoe_formula(11,$r,'SUM(K'.$start.':K'.$end.')',10),qoe_formula(12,$r,'SUM(L'.$start.':L'.$end.')',10),qoe_formula(13,$r,'SUM(M'.$start.':M'.$end.')',10),qoe_formula(14,$r,'SUM(N'.$start.':N'.$end.')',10)]),24); qoe_merge($merges,8,$r,10,$r); $r+=3;
  $left='Total Qty: '.qoe_qty(function_exists('qd_total')?qd_total($items,'qty'):0).' PCS' . "\n" . 'Total Cartons: '.qoe_qty(function_exists('qd_total')?qd_total($items,'cartons'):0).' CTNS' . "\n" . 'Total N.W.: '.qoe_qty(function_exists('qd_total')?qd_total($items,'nw'):0,3).' KG' . "\n" . 'Total G.W.: '.qoe_qty(function_exists('qd_total')?qd_total($items,'gw'):0,3).' KG' . "\n" . 'Total CBM: '.qoe_qty(function_exists('qd_total')?qd_total($items,'cbm'):0,4);
  $right='Packing List No: '.($ship['packing_list_no']??'') . "\n" . 'PI No.: '.(function_exists('qd_order_no_at')?qd_order_no_at($order['order_no']??'',$order['quote_no']??''):($order['order_no']??'')) . "\n" . 'Date: '.($ship['ship_date']??date('Y-m-d'));
  $rows[]=qoe_row($r,array_merge(qoe_style_cells($r,1,7,11,$left),qoe_style_cells($r,9,14,11,$right)),76); qoe_merge($merges,1,$r,7,$r); qoe_merge($merges,9,$r,14,$r); $rowHeights[$r]=76; $r++;
  if($cartons){ $r+=2; $lines=['Carton Detail']; foreach($cartons as $c){ $lines[]=trim(($c['carton_no']?:($c['carton_range']??'')).'  '.($c['items_text']??'').'  QTY '.qoe_qty($c['qty']??0).'  '.($c['carton_size']??'').'  N.W. '.qoe_qty($c['nw']??0,3).'  G.W. '.qoe_qty($c['gw']??0,3).'  CBM '.qoe_qty($c['cbm']??0,4)); } $txt=implode("\n",$lines); $rows[]=qoe_row($r,qoe_style_cells($r,1,14,11,$txt),max(44,count($lines)*18)); qoe_merge($merges,1,$r,14,$r); $rowHeights[$r]=max(44,count($lines)*18); $r++; }
  return [$rows,$r];
}
function qoe_build_xlsx($type,$order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle){
  $images=[]; $rowHeights=[]; $colWidths=[]; $merges=[];
  if($type==='ci') list($rows,$last)=qoe_build_ci_sheet($order,$ship,$items,$settings,$customer,$sellerName,$sellerText,$docTitle,$images,$rowHeights,$colWidths,$merges);
  else list($rows,$last)=qoe_build_pl_sheet($order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$images,$rowHeights,$colWidths,$merges);
  usort($rows,function($a,$b){preg_match('/<row r="(\d+)"/',$a,$ma);preg_match('/<row r="(\d+)"/',$b,$mb);return intval($ma[1]??0)<=>intval($mb[1]??0);});
  $cols=qoe_cols_xml($colWidths); $mergeXml=$merges?'<mergeCells count="'.count($merges).'">'.implode('',array_map(function($m){return '<mergeCell ref="'.$m.'"/>';},$merges)).'</mergeCells>':''; $drawingTag=$images?'<drawing r:id="rId1"/>':'';
  $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.qoe_col(count($colWidths)).$last.'"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="18"/>'.$cols.'<sheetData>'.implode('',$rows).'</sheetData>'.$mergeXml.'<printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'.$drawingTag.'</worksheet>';
  list($mediaFiles,$drawingXml,$drawingRels,$sheetRels)=qoe_drawing_parts($images,$colWidths,$rowHeights);
  $contentTypes='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.($images?'<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>':'').'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
  $files=['[Content_Types].xml'=>$contentTypes,'_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>','xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr/><sheets><sheet name="'.qoe_xml($docTitle).'" sheetId="1" r:id="rId1"/></sheets><calcPr calcMode="auto"/></workbook>','xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>','xl/worksheets/sheet1.xml'=>$sheet,'xl/styles.xml'=>qoe_styles_xml(),'docProps/app.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Artdon Quotation ERP</Application></Properties>','docProps/core.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.qoe_xml($docFileTitle).'</dc:title><dc:creator>Artdon Lighting Limited</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created></cp:coreProperties>'];
  if($images){$files['xl/worksheets/_rels/sheet1.xml.rels']=$sheetRels;$files['xl/drawings/drawing1.xml']=$drawingXml;$files['xl/drawings/_rels/drawing1.xml.rels']=$drawingRels;foreach($mediaFiles as $k=>$v)$files[$k]=$v;}
  return qoe_zip_files($files);
}
function qoe_export_document_xlsx($type,$order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle){
  $bin=qoe_build_xlsx($type,$order,$ship,$items,$cartons,$settings,$customer,$sellerName,$sellerText,$docTitle,$docFileTitle);
  $filename=qoe_safe_filename($docFileTitle,'.xlsx');
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: '.qoe_content_disposition_filename($filename));
  header('Content-Length: '.strlen($bin));
  echo $bin;
}
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
  $_GET['format']='xlsx';
  require __DIR__.'/quote_order_doc.php';
  exit;
}
