<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
/* ARTDON_SSO_GATE_V2_END */
if (session_status() === PHP_SESSION_NONE) { @session_name('ARTDON_SYS'); @session_start(); }
function qx_export_allowed(){
  $p = $_SESSION['quote_permissions'] ?? [];
  $u = $_SESSION['quote_user'] ?? [];
  $name = strtolower((string)($u['username'] ?? ''));
  if (in_array($name, ['qiulei','qiulei6386','boss','admin','administrator','owner'], true)) return true;
  return !empty($p['export_pdf_excel']);
}
if (!qx_export_allowed()) { http_response_code(403); echo 'No permission to export quotation.'; exit; }
@ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

function qe_json_decode($s,$def=[]){ $a=json_decode((string)$s,true); return is_array($a)?$a:$def; }
function qe_first($arr,$keys,$def=''){ if(!is_array($arr)) return $def; foreach($keys as $k){ if(isset($arr[$k]) && $arr[$k]!=='' && $arr[$k]!==null) return $arr[$k]; } return $def; }
function qe_safe_filename($s,$ext){ $s=trim((string)$s); $s=preg_replace('#[\\/:*?"<>|]+#',' ', $s); $s=preg_replace('/\s+/',' ', $s); $s=trim($s, " ._-	
"); if($s==='')$s='quotation'; return $s.$ext; }
function qe_content_disposition_filename($filename){ $safe=str_replace(['\\','"'],['_','\"'],$filename); return 'attachment; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($filename); }
function qe_file_doc_label($docTitle){ if($docTitle==='PROFORMA INVOICE') return 'Proforma Invoice'; if($docTitle==='订购合同') return 'Purchase Contract'; if(preg_match('/quotation/i',(string)$docTitle)) return 'Quotation'; $x=trim(ucwords(strtolower((string)$docTitle))); return $x!==''?$x:'Document'; }
function qe_file_title($payload){ $isOrder=!empty($payload['order_export']) || !empty($payload['is_order']) || trim((string)($payload['order_no']??''))!=='' || stripos((string)($payload['quote_no']??''),'SO-')===0; $no=qe_at_doc_no(trim((string)($payload['order_no']??'')) ?: trim((string)($payload['quote_no']??''))); if($isOrder && $no!=='') return trim($no.' '.qe_file_doc_label(qe_doc_title($payload))); return trim((string)($payload['quote_no']??'')) ?: 'quotation'; }

function qe_xml($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_XML1,'UTF-8'); }
function qe_num($n){ return is_numeric($n) ? (0+$n) : 0; }
function qe_col($n){ $s=''; while($n>0){ $m=($n-1)%26; $s=chr(65+$m).$s; $n=intdiv($n-1,26); } return $s; }
function qe_strip_from_label($s){ return preg_replace('/^\s*From:\s*/i','',(string)$s); }

function qe_at_doc_no($v){ $s=trim((string)$v); if(preg_match('/^SO-(.+)$/i',$s,$m)) return 'AT-'.$m[1]; return $s; }
function qe_normalize_order_no(&$payload){ $isOrder=!empty($payload['order_export']) || !empty($payload['is_order']) || trim((string)($payload['order_no']??''))!=='' || stripos((string)($payload['quote_no']??''),'SO-')===0; if(!$isOrder)return; $no=qe_at_doc_no($payload['quote_no']??''); if($no==='')$no=qe_at_doc_no($payload['order_no']??''); if($no!==''){ $payload['quote_no']=$no; $payload['order_no']=$no; } }
function qe_doc_no_label($docTitle){ if($docTitle==='订购合同') return '订单号'; if($docTitle==='PROFORMA INVOICE') return 'PI Number'; return 'Quotation No'; }
function qe_terms_font_size($b){ $v=(float)($b['extra_terms_font_size'] ?? $b['terms_font_size'] ?? 7.5); if($v<6)$v=6; if($v>12)$v=12; return rtrim(rtrim(number_format($v,1,'.',''),'0'),'.'); }
function qe_doc_title($payload){ $v=(string)($payload['quote_status']??($payload['order_doc_title']??($payload['contract_title']??'Quotation sheet'))); $cur=strtoupper(trim((string)($payload['currency']??''))); $isOrder=trim((string)($payload['order_no']??''))!=='' || stripos((string)($payload['quote_no']??''),'SO-')===0 || trim((string)($payload['order_doc_title']??''))!=='' || trim((string)($payload['contract_title']??''))!==''; if(preg_match('/订购合同|purchase|contract/iu',$v)) return '订购合同'; if($isOrder && in_array($cur,['RMB','CNY','人民币'],true)) return '订购合同'; if($isOrder) return 'PROFORMA INVOICE'; return preg_match('/proforma|invoice/i',$v)?'PROFORMA INVOICE':'Quotation sheet'; }
function qe_cell($c,$r,$v,$style=0,$num=false){
  $ref=qe_col($c).$r; $s=$style?' s="'.$style.'"':'';
  if($num && is_numeric($v)) return '<c r="'.$ref.'"'.$s.'><v>'.(0+$v).'</v></c>';
  return '<c r="'.$ref.'" t="inlineStr"'.$s.'><is><t xml:space="preserve">'.qe_xml($v).'</t></is></c>';
}
function qe_formula($c,$r,$formula,$style=0){ $ref=qe_col($c).$r; $s=$style?' s="'.$style.'"':''; return '<c r="'.$ref.'"'.$s.'><f>'.qe_xml($formula).'</f></c>'; }
function qe_row($r,$cells,$height=null){ $h=$height?' ht="'.$height.'" customHeight="1"':''; return '<row r="'.$r.'"'.$h.'>'.implode('',$cells).'</row>'; }
function qe_terms($payload){
  $tpl=$payload['template']??[]; $terms=qe_json_decode($tpl['terms_json']??'',[]);
  if(!$terms){ $terms=[['PI Number:','QTNO'],['Payment:','40% Deposit before production'],['','60% payment before shipment'],['Price Terms:','EXWORK'],['Quoted Date:','DATE'],['Delivery Date:','25-35Days After Confirmed'],['Quoted Valid','Within 10 days']]; }
  $out=[]; $no=$payload['quote_no']??''; $date=$payload['quote_date']??date('Y-m-d');
  $isOrder=!empty($payload['order_export']) || !empty($payload['is_order']) || trim((string)($payload['order_no']??''))!=='';
  $docTitle=qe_doc_title($payload);
  foreach($terms as $t){
    $a=(string)($t[0]??''); $b=(string)($t[1]??'');
    $a=str_replace(['QTNO','DATE'],[$no,$date],$a); $b=str_replace(['QTNO','DATE'],[$no,$date],$b);
    if(preg_match('/PI\s*Number|订单号|訂單號|报价编号|報價編號/iu',$a)){ if($isOrder)$a=($docTitle==='订购合同'?'订单号:':'PI Number:'); $b=$no; }
    if(preg_match('/Quoted\s*Date/i',$a)) $b=$date;
    $out[]=[$a,$b];
  }
  return $out;
}
function qe_payment_label($txt,$pct){
  $txt=trim((string)$txt);
  $txt=preg_replace('/\s*before\s+.*/i','',$txt);
  $txt=preg_replace('/\s*after\s+.*/i','',$txt);
  return trim($txt) !== '' ? trim($txt) : ($pct.'% Payment');
}
function qe_payment_amount_text($payload,$totalAmt,$currency){
  $terms=qe_terms($payload); $out=[]; $sumPct=0.0;
  foreach($terms as $tr){
    $txt=(string)($tr[1]??'');
    if(preg_match('/(\d+(?:\.\d+)?)\s*%/',$txt,$m)){
      $pct=(float)$m[1];
      if($pct>0 && $pct<=100){
        $out[]=['pct'=>$pct,'label'=>qe_payment_label($txt,$pct),'amount'=>round(((float)$totalAmt)*$pct/100,2)];
        $sumPct+=$pct;
      }
    }
  }
  if(count($out)>=2 && abs($sumPct-100)<0.01){
    $totalRounded=round((float)$totalAmt,2); $prev=0.0;
    for($i=0;$i<count($out);$i++){
      if($i===count($out)-1) $out[$i]['amount']=round($totalRounded-$prev,2);
      else { $out[$i]['amount']=round(((float)$totalAmt)*$out[$i]['pct']/100,2); $prev=round($prev+$out[$i]['amount'],2); }
    }
  }
  $lines=[];
  foreach($out as $r){ $lines[]=$r['label'].': '.$currency.' '.number_format($r['amount'],2,'.',''); }
  return implode("
",$lines);
}
function qe_quote_part_name($m){ return qe_brand_model_only($m); }
function qe_empty_param($v){ $v=trim((string)$v); if($v==='') return true; return preg_match('/^(0|\-|\/|n\/?a|none|null|无|没有|不适用)$/iu',$v)===1; }
function qe_clean_param($v){ return qe_empty_param($v)?'':trim((string)$v); }
function qe_item_remarks($it){ $arr=[]; if(isset($it['quote_remarks']) && is_array($it['quote_remarks'])) $arr=$it['quote_remarks']; if(!$arr && !empty($it['extra_spec'])) $arr=preg_split('/\R+/',(string)$it['extra_spec']); $out=[]; foreach((array)$arr as $v){ $v=qe_clean_param($v); if($v!=='') $out[]=$v; if(count($out)>=4) break; } return $out; }
function qe_norm_ip($v){ $v=trim((string)$v); if($v==='') return ''; if(preg_match('/^ip/i',$v)) return strtoupper(preg_replace('/\s+/','',$v)); return 'IP'.preg_replace('/[^0-9A-Za-z]/','',$v); }
function qe_spec_label($label){
  $k=function_exists('mb_strtolower')?mb_strtolower(trim((string)$label),'UTF-8'):strtolower(trim((string)$label));
  if(in_array($k,['connector','接头','连接头','adapter'],true)) return 'Adapter';
  if(in_array($k,['driver','led driver','电源','驱动'],true)) return 'LED Driver';
  if(in_array($k,['optic','光学','透镜','反光杯'],true)) return 'Optic';
  if(in_array($k,['accessories','accessory','extra','附件','配件'],true)) return 'Accessories';
  if(in_array($k,['led','芯片','cob'],true)) return 'LED';
  return (string)$label;
}

function qe_part_stopword($t){ return preg_match('/^(led|cob|driver|optic|optics|lens|reflector|adapter|connector|accessory|accessories|extra|chip|power|light|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接器|附件|配件|物料|材料)$/iu', trim((string)$t))===1; }
function qe_drop_material_name_tokens($raw){ $tokens=preg_split('/\s+/u', trim((string)$raw)); $out=[]; foreach((array)$tokens as $t){ $t=trim($t); if($t==='' || preg_match('/[\x{4e00}-\x{9fff}]/u',$t) || qe_part_stopword($t)) continue; $out[]=$t; } return $out; }
function qe_model_from_ascii_text($raw,$hint=''){ $hint=qe_clean_param($hint); if($hint!=='') return $hint; $toks=qe_drop_material_name_tokens($raw); if(!$toks)return ''; $s=trim(implode(' ',$toks)); if(preg_match('/([A-Za-z0-9._-]+\s+Series\s*[A-Za-z0-9@._-]+(?:\s*[A-Za-z0-9@._-]+){0,2})/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(preg_match('/([A-Z]{1,8}[-_]?\d[A-Z0-9._-]*(?:\s+[A-Z0-9]{1,6}){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(preg_match('/(\d+(?:\.\d+)?\s*MM(?:\s+[A-Z0-9._-]+){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1])); if(count($toks)>4)return trim(implode(' ',array_slice($toks,-3))); if(count($toks)>1)return trim(implode(' ',array_slice($toks,1))); return $toks[0]??''; }
function qe_brand_from_ascii_text($raw,$hint=''){ $hint=qe_clean_param($hint); if($hint!=='')return $hint; $toks=qe_drop_material_name_tokens($raw); return $toks[0]??''; }
function qe_brand_model_only($input,$label=''){
  if(is_array($input)){
    $brand=qe_clean_param($input['brand']??($input['manufacturer']??($input['factory']??'')));
    $model=qe_clean_param($input['model']??($input['model_no']??($input['product_model']??($input['code']??($input['sku']??($input['series']??($input['series_name']??'')))))));
    if($model==='') $model=qe_model_from_ascii_text(trim(implode(' ',array_filter([$input['name']??'',$input['spec']??'',$input['description']??'']))));
    if($brand==='') $brand=qe_brand_from_ascii_text(trim(implode(' ',array_filter([$input['brand']??'',$input['name']??'',$input['spec']??'',$input['description']??'']))));
    $out=trim(implode(' ',array_filter([$brand,$model]))); return $out!==''?$out:qe_clean_param($input['spec']??($input['name']??''));
  }
  $raw=qe_clean_param($input); if($raw==='')return ''; $brand=qe_brand_from_ascii_text($raw); $model=qe_model_from_ascii_text($raw); if($brand!==''&&$model!==''&&stripos($model,$brand)===0)return $model; $out=trim(implode(' ',array_filter([$brand,$model]))); if($out!=='')return $out; $tokens=qe_drop_material_name_tokens($raw); return $tokens?trim(implode(' ',$tokens)):$raw;
}
function qe_is_component_label($label){ return in_array(qe_spec_label($label), ['LED','LED Driver','Optic','Adapter','Accessories'], true); }
function qe_sanitize_component_value($label,$value){ return qe_is_component_label($label)?qe_brand_model_only($value,$label):qe_clean_param($value); }
function qe_sanitize_saved_spec($spec,$it=[]){ $spec=(string)$spec; if(trim($spec)==='')return ''; $lines=preg_split('/\R/u',$spec); $out=[]; foreach((array)$lines as $line){ if(preg_match('/^(\s*(?:\d+\.\s*)?)(LED\s*Driver|LED|Optic|Adapter|Accessories|Connector|Driver|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接头|附件|配件)\s*[:：]\s*(.*?)\s*$/iu',$line,$m)){ $label=qe_spec_label($m[2]); $val=qe_sanitize_component_value($label,$m[3]); $out[]=$m[1].$label.': '.$val; } else { $out[]=$line; } } return implode("\n",$out); }
function qe_product_brand_model($p){ $p=is_array($p)?$p:[]; $out=qe_brand_model_only(['brand'=>$p['brand']??'','model'=>$p['model']??($p['code']??($p['product_model']??'')),'name'=>$p['name']??($p['product_name']??''),'spec'=>$p['spec']??($p['size']??'')]); return $out!==''?$out:qe_first($p,['code','model','name'],'Material'); }
function qe_norm_cri($v){ $v=strtoupper(trim((string)$v)); $v=preg_replace('/^CRI\s*[:：-]?\s*/i','',$v); $v=preg_replace('/\s+/','',$v); return $v===''?'':'CRI'.$v; }
function qe_norm_beam($v){ $v=trim((string)$v); $v=str_replace('度','°',$v); $v=preg_replace('/\s+/','',$v); if($v==='') return ''; if(strpos($v,'°')!==false) return $v; if(preg_match('/^\d+(?:\.\d+)?(?:[xX\\\/\-]\d+(?:\.\d+)?)*$/',$v)) return $v.'°'; return $v; }
function qe_led_line($base,$it){ $cct=qe_clean_param($it['cct']??''); $cri=qe_norm_cri($it['cri']??''); return trim(implode(' ',array_filter([qe_clean_param($base),$cct,$cri]))); }
function qe_series_line($p){ $v=qe_clean_param(qe_first($p,['series','series_name','family'],'')); if($v==='') $v=qe_clean_param(qe_first($p,['name','product_name','title'],'')); return $v; }
function qe_text_blob($p){ return trim(implode(' ',array_filter([ $p['dimension_type']??'', $p['category']??'', $p['item_name']??'', $p['name']??'', $p['product_name']??'', $p['series']??'', $p['type']??'', $p['code']??'', $p['model']??'' ]))); }
function qe_is_not_round_text($txt){ return preg_match('/方形|方圆|长方|线性|线条|长条|条形|K条|LUMI|linear|square|rect/i',(string)$txt)===1; }
function qe_is_embedded_product($p){ $t=strtolower(trim((string)($p['dimension_type']??''))); if(in_array($t,['embedded_round','embedded_square','opening','recessed'],true)) return true; if(!empty($p['is_embedded'])) return true; return preg_match('/嵌入|无边|有边|开孔|recessed/i',qe_text_blob($p))===1; }
function qe_is_round_product($p){ $t=strtolower(trim((string)($p['dimension_type']??''))); $txt=qe_text_blob($p); if(qe_is_not_round_text($txt)) return false; if(!empty($p['is_round_dimension'])) return true; if(in_array($t,['embedded_round','diameter','round','circle','circular'],true)) return true; if(in_array($t,['embedded_square','box','square','rectangle'],true)) return false; return preg_match('/圆形|圆筒|筒灯|downlight|cylinder|round/i',$txt)===1; }
function qe_display_size($p){ $l=qe_clean_param($p['dim_length']??''); $w=qe_clean_param($p['dim_width']??''); $h=qe_clean_param($p['dim_height']??''); $d=qe_clean_param($p['dim_outer_d']??''); $round=qe_is_round_product($p); if($l!==''&&$w!==''&&$h!=='') return $l.'*'.$w.'*'.$h; if($l!==''&&$w!=='') return $l.'*'.$w.($h!==''?'*'.$h:''); if($d!==''&&$h!=='') return ($round?'Φ':'').$d.'*'.$h; if($d!=='') return ($round?'Φ':'').$d.($h!==''?'*'.$h:''); $v=qe_clean_param(qe_first($p,['quote_display_size','size','dimension','dimensions'],'')); if($v!==''&&!$round) $v=preg_replace('/^\s*[ΦφØø]\s*/u','',$v); return $v; }
function qe_format_cutout($v){ $v=qe_clean_param($v); if($v==='') return ''; $v=preg_replace('/^\s*(cut\s*out|cutout|开孔)\s*[:：]?\s*/iu','',$v); $v=preg_replace('/^\s*[ΦφØø]\s*/u','',$v); $v=preg_replace('/\s*mm\s*$/iu','',$v); $v=trim($v); return $v===''?'':'Φ'.$v.'mm'; }
function qe_display_cutout($p){ if(!qe_is_embedded_product($p)) return ''; return qe_format_cutout(qe_first($p,['quote_display_cutout','cutout','dim_opening','hole','opening'],'')); }
function qe_quote_spec_pairs($p){ $raw=$p['quote_spec']??null; if(!$raw && !empty($p['quote_spec_json'])) $raw=json_decode((string)$p['quote_spec_json'],true); $out=[]; if(is_array($raw)){ foreach($raw as $k=>$v){ $label=$k; $val=''; if(is_array($v)){ $label=$v['label']??$k; $val=$v['value']??($v['text']??''); } else { $val=$v; } $label=qe_spec_label($label); $val=qe_sanitize_component_value($label,$val); $val=qe_clean_param($val); if($val!=='') $out[]=[$label,$val]; } } return $out; }
function qe_is_material_sale($it){ $p=(isset($it['product'])&&is_array($it['product']))?$it['product']:[]; return !empty($it['is_material_sale']) || (($it['product_type']??'')==='material') || (isset($p['id']) && strpos((string)$p['id'],'mat-')===0); }
function qe_item_spec($it){
  // V6.8.5.21：报价/订单导出 Excel 与 PDF 同步，优先使用订单/报价保存时的完整 Specification。
  $saved=qe_clean_param($it['specification']??'');
  if($saved!=='') return qe_sanitize_saved_spec($saved,$it);
  $p=(isset($it['product'])&&is_array($it['product']))?$it['product']:[];
  if(qe_is_material_sale($it)){
    $ml=[]; $base=trim(qe_product_brand_model($p));
    $first=qe_led_line($base,$it); if($first!=='') $ml[]=$first;
    if(!empty($it['ip'])) $ml[]=qe_norm_ip($it['ip']);
    foreach(qe_item_remarks($it) as $rv){ $ml[]=$rv; }
    return trim(implode("\n",$ml));
  }
  $lines=[]; $n=1; $add=function($label,$val) use (&$lines,&$n){ $label=qe_spec_label($label); $val=qe_clean_param($val); if($val!=='') $lines[]=($n++).'. '.$label.': '.$val; };
  $series=qe_series_line($p); if($series!=='') $lines[]=($n++).'. '.$series;
  $add('Power', qe_first($it,['power'],'') ?: qe_first($p,['power'],''));
  $add('Size', qe_display_size($p));
  $add('Cut out', qe_display_cutout($p));
  $add('Beam Angle', qe_norm_beam(qe_first($it,['beam_angle','beamAngle','angle','beam'],'')));
  $qspec=qe_quote_spec_pairs($p);
  if($qspec){ foreach($qspec as $pair){ $label=qe_spec_label($pair[0]); $add($label, $label==='LED' ? qe_led_line($pair[1],$it) : $pair[1]); } }
  else{
    $parts=(isset($it['parts'])&&is_array($it['parts']))?$it['parts']:[];
    $labels=['led'=>'LED','driver'=>'LED Driver','optic'=>'Optic','connector'=>'Adapter','extra'=>'Accessories'];
    foreach($labels as $k=>$label){ if(!isset($parts[$k]) || !is_array($parts[$k])) continue; $m=$parts[$k]; if(!empty($m['none'])){ $lines[]=($n++).'. '.$label.': Without '.($label==='Adapter'?'adapter':$label); continue; } $mn=($k==='led')?qe_led_line(qe_quote_part_name($m),$it):qe_quote_part_name($m); if($mn)$add($label,$mn); }
  }
  $ip=qe_norm_ip($it['ip']??''); if($ip!=='') $lines[]=($n++).'. '.$ip;
  foreach(qe_item_remarks($it) as $rv){ $lines[]=($n++).'. '.$rv; }
  return implode("\n",$lines);
}
function qe_len($s){ return function_exists('mb_strlen') ? mb_strlen((string)$s,'UTF-8') : strlen((string)$s); }
function qe_item_row_height($it){
  $p=(isset($it['product'])&&is_array($it['product']))?$it['product']:[];
  $spec=qe_item_spec($it);
  $lines=preg_split('/\R/',(string)$spec,-1,PREG_SPLIT_NO_EMPTY);
  $lineCount=max(1,count($lines));
  $maxLine=0; foreach($lines as $ln){ $maxLine=max($maxLine,qe_len($ln)); }
  $wrapExtra=max(0,ceil($maxLine/34)-1);
  if(qe_is_material_sale($it)){
    $textLen=qe_len((string)$spec.' '.($p['code']??''));
    return max(36, min(70, 26 + $lineCount*9 + ceil($textLen/55)*5));
  }
  return max(78, min(116, 40 + $lineCount*8 + $wrapExtra*10));
}
function qe_load_from_db($id,$quote_no=''){
  require_once __DIR__ . '/includes/bootstrap.php';
  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  if($id>0){ $st=$pdo->prepare('SELECT * FROM quote_orders WHERE id=? LIMIT 1'); $st->execute([$id]); }
  else { $st=$pdo->prepare('SELECT * FROM quote_orders WHERE quote_no=? LIMIT 1'); $st->execute([$quote_no]); }
  $q=$st->fetch(PDO::FETCH_ASSOC); if(!$q) die('Quote not found');
  $payload=['quote_no'=>$q['quote_no']??'','quote_date'=>$q['quote_date']??date('Y-m-d'),'quote_status'=>$q['quote_status']??($q['status']??''),'currency'=>$q['currency']??'USD','exchange_rate'=>$q['exchange_rate']??7,'customer'=>qe_json_decode($q['customer_json']??''),'items'=>qe_json_decode($q['items_json']??'')];
  if(!$payload['items']){ $p=qe_json_decode($q['product_json']??''); if($p) $payload['items']=[['product'=>$p,'parts'=>qe_json_decode($q['parts_json']??''),'qty'=>$q['qty']??1,'price'=>$q['price']??0,'amount'=>$q['amount']??0,'moq'=>$q['moq']??'','color'=>$q['color']??'','cct'=>$q['cct']??'','cri'=>$q['cri']??'','ip'=>$q['ip']??'','extra_spec'=>$q['extra_spec']??'','product_type'=>$q['product_type']??'']]; }
  foreach(['header'=>'quote_headers','bank'=>'quote_banks','template'=>'quote_templates'] as $k=>$t){ $fid=intval($q[$k.'_id']??0); $payload[$k]=[]; if($fid){ $s=$pdo->prepare("SELECT * FROM `$t` WHERE id=? LIMIT 1"); $s->execute([$fid]); $payload[$k]=$s->fetch(PDO::FETCH_ASSOC)?:[]; } }
  return $payload;
}
function qe_get_payload(){
  if($_SERVER['REQUEST_METHOD']==='POST'){
    $raw=file_get_contents('php://input'); $payload=$_POST['payload']??''; if(!$payload && $raw) $payload=$raw; $a=qe_json_decode($payload,[]); if($a) return $a;
  }
  return qe_load_from_db(intval($_GET['quote_id']??($_GET['id']??0)), trim((string)($_GET['quote_no']??'')));
}

function qe_image_source_to_jpeg($src){
  $src=trim((string)$src); if($src==='') return null;
  $data=null;
  if(preg_match('/^data:image\/[^;]+;base64,(.+)$/i',$src,$m)){
    $data=base64_decode($m[1]);
  } else {
    if(preg_match('/^https?:\/\//i',$src)){
      $ctx=stream_context_create(['http'=>['timeout'=>4,'ignore_errors'=>true], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
      $data=@file_get_contents($src,false,$ctx);
    } else {
      $doc=isset($_SERVER['DOCUMENT_ROOT'])?rtrim((string)$_SERVER['DOCUMENT_ROOT'],'/'):''; $candidates=[];
      if(strlen($src)>0 && $src[0]==='/'){
        $candidates[]=$src; if($doc)$candidates[]=$doc.$src; $candidates[]=__DIR__.$src;
      } else {
        $candidates[]=__DIR__.'/'.$src; $candidates[]=getcwd().'/'.$src; if($doc)$candidates[]=$doc.'/'.$src;
      }
      foreach($candidates as $f){ if($f && is_file($f)){ $data=@file_get_contents($f); if($data!==false) break; } }
    }
  }
  if(!$data) return null;
  $size=@getimagesizefromstring($data); if(!$size || empty($size[0]) || empty($size[1])) return null;
  $w=(int)$size[0]; $h=(int)$size[1]; $mime=strtolower((string)($size['mime']??''));
  if($mime==='image/jpeg' || $mime==='image/jpg') return ['data'=>$data,'w'=>$w,'h'=>$h];
  if(function_exists('imagecreatefromstring') && function_exists('imagejpeg')){
    $im=@imagecreatefromstring($data); if(!$im) return null;
    $canvas=imagecreatetruecolor($w,$h);
    $white=imagecolorallocate($canvas,255,255,255); imagefilledrectangle($canvas,0,0,$w,$h,$white);
    imagecopy($canvas,$im,0,0,0,0,$w,$h);
    ob_start(); imagejpeg($canvas,null,88); $jpg=ob_get_clean();
    imagedestroy($im); imagedestroy($canvas);
    if($jpg) return ['data'=>$jpg,'w'=>$w,'h'=>$h];
  }
  return null;
}
function qe_drawing_parts($images){
  if(!$images) return [[], '', '', ''];
  $files=[]; $anchors=[]; $rels=[]; $idx=1;
  foreach($images as $img){
    $files['xl/media/image'.$idx.'.jpg']=$img['data'];
    $rels[]='<Relationship Id="rId'.$idx.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image'.$idx.'.jpg"/>';
    $colPx=76; // Excel column A width 10 ≈ 76 px
    $rowPt=max(20,(float)($img['row_height']??104));
    $rowPx=$rowPt*1.3333333;
    $maxW=54; $maxH=54;
    $iw=max(1,(float)$img['w']); $ih=max(1,(float)$img['h']);
    $scale=min($maxW/$iw,$maxH/$ih,1.0);
    $dw=max(1,round($iw*$scale)); $dh=max(1,round($ih*$scale));
    $cx=$dw*9525; $cy=$dh*9525; $row=max(0,(int)$img['row']-1);
    $colOff=max(0,round(($colPx-$dw)/2))*9525;
    $rowOff=max(0,round(($rowPx-$dh)/2))*9525;
    $anchors[]='<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>'.$colOff.'</xdr:colOff><xdr:row>'.$row.'</xdr:row><xdr:rowOff>'.$rowOff.'</xdr:rowOff></xdr:from><xdr:ext cx="'.$cx.'" cy="'.$cy.'"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($idx+1).'" name="Picture '.$idx.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId'.$idx.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
    $idx++;
  }
  $drawing='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.implode('',$anchors).'</xdr:wsDr>';
  $drawingRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('',$rels).'</Relationships>';
  $sheetRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
  return [$files,$drawing,$drawingRels,$sheetRels];
}

function qe_zip_files($files){
  $time=time(); $d=getdate($time); $dosTime=(($d['hours'] & 0x1F) << 11) | (($d['minutes'] & 0x3F) << 5) | (int)(($d['seconds'] & 0x3E) / 2); $dosDate=((($d['year']-1980) & 0x7F) << 9) | (($d['mon'] & 0x0F) << 5) | ($d['mday'] & 0x1F);
  $out=''; $central=''; $offset=0; $count=0;
  foreach($files as $name=>$data){ $name=str_replace('\\','/',$name); $data=(string)$data; $crc=crc32($data); if($crc<0)$crc+=4294967296; $size=strlen($data); $nlen=strlen($name); $local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0).$name.$data; $out.=$local; $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,0x0314,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nlen,0,0,0,0,0,$offset).$name; $offset+=strlen($local); $count++; }
  $cdOffset=strlen($out); $cdSize=strlen($central); return $out.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,$cdSize,$cdOffset,0);
}
function qe_build_xlsx($payload){
  qe_normalize_order_no($payload); $quoteNo=$payload['quote_no']??'quotation'; $date=$payload['quote_date']??date('Y-m-d'); $currency=$payload['currency']??'USD';
  $h=$payload['header']??[]; $b=$payload['bank']??[]; $c=$payload['customer']??[]; $items=array_values(array_filter($payload['items']??[],'is_array'));
  $company=qe_first($h,['company'],'Gallin Industrial (HK) Limited'); $from=trim(qe_strip_from_label(qe_first($h,['from_text'],''))); $bank=qe_first($b,['text'],''); $bankTerms=qe_first($b,['extra_terms','terms_text'],''); $bankTermsFont=qe_terms_font_size($b);
  $to=trim(implode("\n",array_filter([qe_first($c,['contact'],''),qe_first($c,['email'],''),qe_first($c,['company','name','customer_name'],''),qe_first($c,['phone'],''),qe_first($c,['country'],''),qe_first($c,['note'], '')])));
  $rows=[]; $merges=['A1:E1','F1:J1','A3:E7','A9:E11']; $images=[];
  $r=1;
  $rows[]=qe_row($r,[qe_cell(1,$r,$company,1),qe_cell(6,$r,qe_doc_title($payload),2)],28); $r++;
  $rows[]=qe_row($r,[],10); $r++;
  $terms=qe_terms($payload);
  for($tr=3;$tr<=9;$tr++){
    $cells=[];
    if($tr===3) $cells[]=qe_cell(1,$tr,"From:
".$from,12);
    if($tr===9) $cells[]=qe_cell(1,$tr,"To:
".($to?:'Please select customer'),13);
    $idx=$tr-3;
    if(isset($terms[$idx])){
      // 合并区域内每个单元格都写入样式，避免 Excel 中只显示半截边框。
      $cells[]=qe_cell(6,$tr,$terms[$idx][0],3);
      $cells[]=qe_cell(7,$tr,'',3);
      $cells[]=qe_cell(8,$tr,'',3);
      $cells[]=qe_cell(9,$tr,$terms[$idx][1],4);
      $cells[]=qe_cell(10,$tr,'',4);
      $merges[]='F'.$tr.':H'.$tr; $merges[]='I'.$tr.':J'.$tr;
    }
    $rows[]=qe_row($tr,$cells,22);
  }
  $rows[]=qe_row(10,[],20);
  $rows[]=qe_row(11,[],20);
  $rows[]=qe_row(12,[],10);
  $r=13;
  $headers=['Picture','Size or Drawing(mm)','Customer Code','Manufacturer Code','Specification','Color','QTY (pcs)','Unit Price ('.$currency.')','Amount ('.$currency.')','MOQ (pcs)'];
  $cells=[]; for($i=0;$i<count($headers);$i++) $cells[]=qe_cell($i+1,$r,$headers[$i],5); $rows[]=qe_row($r,$cells,36); $r++;
  $startItemRow=$r; $totalQty=0; $totalAmt=0;
  if(!$items){ $rows[]=qe_row($r,[qe_cell(1,$r,'Please add products on the left side first.',7)],70); $merges[]='A'.$r.':J'.$r; $r++; }
  foreach($items as $it){
    $p=(isset($it['product'])&&is_array($it['product']))?$it['product']:[]; $qty=qe_num($it['qty']??1); $price=qe_num($it['price']??($it['unit_price']??0)); $amt=qe_num($it['amount']??($qty*$price)); $totalQty+=$qty; $totalAmt+=$amt; $isMat=qe_is_material_sale($it); $itemRowHeight=qe_item_row_height($it); $img=qe_image_source_to_jpeg(qe_first($p,['image','product_image','image_path','main_image','photo','picture','img','image_url'],'')); if($img){ $img['row']=$r; $img['row_height']=$itemRowHeight; $images[]=$img; }
    $row=[
      qe_cell(1,$r,'',6), qe_cell(2,$r,$isMat?'':qe_display_size($p),6), qe_cell(3,$r,$it['customer_code']??'',6), qe_cell(4,$r,qe_first($p,['code','model','manufacturer_code'],''),6), qe_cell(5,$r,qe_item_spec($it),7), qe_cell(6,$r,$it['color']??($p['color']??''),6), qe_cell(7,$r,$qty,6,true), qe_cell(8,$r,$price,8,true), qe_formula(9,$r,'G'.$r.'*H'.$r,8), qe_cell(10,$r,$it['moq']??($p['moq']??''),6)
    ];
    $rows[]=qe_row($r,$row,$itemRowHeight); $r++;
  }
  $endItemRow=max($startItemRow,$r-1);
  $rows[]=qe_row($r,[qe_cell(6,$r,'Total:',9),qe_formula(7,$r,'SUM(G'.$startItemRow.':G'.$endItemRow.')',9),qe_formula(9,$r,'SUM(I'.$startItemRow.':I'.$endItemRow.')',10)],28); $totalRow=$r; $r++;
  $r++;
  $paymentText=qe_payment_amount_text($payload,$totalAmt,$currency);
  $summaryLeft="Total Qty: $totalQty PCS".($paymentText!==''?"\n".$paymentText:'')."\nTotal Amount: $currency ".number_format($totalAmt,2,'.','')."\nCurrency: $currency";
  $summaryRight=qe_doc_no_label(qe_doc_title($payload)).": $quoteNo\nStatus: ".($payload['quote_status']??'Draft')."\nDate: $date";
  $sumCells=[];
  for($cc=1;$cc<=5;$cc++) $sumCells[]=qe_cell($cc,$r,$cc===1?$summaryLeft:'',15);
  for($cc=6;$cc<=10;$cc++) $sumCells[]=qe_cell($cc,$r,$cc===6?$summaryRight:'',15);
  $rows[]=qe_row($r,$sumCells,86); $merges[]='A'.$r.':E'.$r; $merges[]='F'.$r.':J'.$r; $r++;
  $r++;
  $remarkCells=[]; for($cc=1;$cc<=10;$cc++) $remarkCells[]=qe_cell($cc,$r,$cc===1?'Remark : all products marked product model number,adaptor wire, color temperature, reflector degree, CRI and product color.':'',12);
  $rows[]=qe_row($r,$remarkCells,28); $merges[]='A'.$r.':J'.$r; $r++;
  $r++;
  $lineCells=[]; for($cc=1;$cc<=10;$cc++) $lineCells[]=qe_cell($cc,$r,'',16);
  $rows[]=qe_row($r,$lineCells,16); $merges[]='A'.$r.':C'.$r; $merges[]='D'.$r.':G'.$r; $merges[]='H'.$r.':J'.$r; $r++;
  $labelCells=[]; for($cc=1;$cc<=10;$cc++){ $txt=''; if($cc===1)$txt='Prepared By'; if($cc===4)$txt='Approved By'; if($cc===8)$txt='Customer Signature'; $labelCells[]=qe_cell($cc,$r,$txt,17); }
  $rows[]=qe_row($r,$labelCells,20); $merges[]='A'.$r.':C'.$r; $merges[]='D'.$r.':G'.$r; $merges[]='H'.$r.':J'.$r; $r++;
  if($bank){ $r++; $bankCells=[]; for($cc=1;$cc<=10;$cc++) $bankCells[]=qe_cell($cc,$r,$cc===1?$bank:'',18); $rows[]=qe_row($r,$bankCells,74); $merges[]='A'.$r.':J'.$r; $r++; }
  if($bankTerms){ $r++; $termCells=[]; for($cc=1;$cc<=10;$cc++) $termCells[]=qe_cell($cc,$r,$cc===1?$bankTerms:'',19); $rows[]=qe_row($r,$termCells,140); $merges[]='A'.$r.':J'.$r; $r++; }
  $last=$r;
  usort($rows,function($a,$b){ preg_match('/<row r="(\d+)"/',$a,$ma); preg_match('/<row r="(\d+)"/',$b,$mb); return intval($ma[1]??0)<=>intval($mb[1]??0); });
  $cols='<cols><col min="1" max="1" width="10" customWidth="1"/><col min="2" max="2" width="12" customWidth="1"/><col min="3" max="3" width="10" customWidth="1"/><col min="4" max="4" width="12" customWidth="1"/><col min="5" max="5" width="42" customWidth="1"/><col min="6" max="6" width="9" customWidth="1"/><col min="7" max="7" width="8" customWidth="1"/><col min="8" max="9" width="11" customWidth="1"/><col min="10" max="10" width="8" customWidth="1"/></cols>';
  $mergeXml='<mergeCells count="'.count($merges).'">'.implode('',array_map(fn($m)=>'<mergeCell ref="'.$m.'"/>',$merges)).'</mergeCells>';
  $drawingTag=$images?'<drawing r:id="rId1"/>':'';
  $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:J'.$last.'"/><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="20"/>'.$cols.'<sheetData>'.implode('',$rows).'</sheetData>'.$mergeXml.'<printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'.$drawingTag.'</worksheet>';
  $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="8"><font><sz val="9"/><name val="ARS MaquetteTr"/></font><font><sz val="16"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="16"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="9"/><name val="ARS MaquetteTr"/></font><font><sz val="8"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="9"/><name val="ARS MaquetteTr"/></font><font><b/><sz val="11"/><name val="ARS MaquetteTr"/></font><font><sz val="'.$bankTermsFont.'"/><name val="ARS MaquetteTr"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="4"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border><border><top style="thin"><color auto="1"/></top></border><border><left style="medium"><color auto="1"/></left><right style="medium"><color auto="1"/></right><top style="medium"><color auto="1"/></top><bottom style="medium"><color auto="1"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="20"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="4" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="5" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="0" borderId="2" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="7" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="TableStyleLight16"/></styleSheet>';
  list($mediaFiles,$drawingXml,$drawingRels,$sheetRels)=qe_drawing_parts($images);
  $contentTypes='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="jpeg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.($images?'<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>':'').'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
  $files=[
    '[Content_Types].xml'=>$contentTypes,
    '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
    'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr/><sheets><sheet name="Quotation" sheetId="1" r:id="rId1"/></sheets><calcPr calcMode="auto"/></workbook>',
    'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml'=>$sheet,
    'xl/styles.xml'=>$styles,
    'docProps/app.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Artdon Quotation ERP</Application></Properties>',
    'docProps/core.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.qe_xml($quoteNo).'</dc:title><dc:creator>Artdon Lighting Limited</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created></cp:coreProperties>'
  ];
  if($images){ $files['xl/worksheets/_rels/sheet1.xml.rels']=$sheetRels; $files['xl/drawings/drawing1.xml']=$drawingXml; $files['xl/drawings/_rels/drawing1.xml.rels']=$drawingRels; foreach($mediaFiles as $k=>$v)$files[$k]=$v; }
  return qe_zip_files($files);
}
$payload=qe_get_payload();
qe_normalize_order_no($payload);
$bin=qe_build_xlsx($payload);
$filename=qe_safe_filename(qe_file_title($payload),'.xlsx');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: '.qe_content_disposition_filename($filename));
header('Content-Length: '.strlen($bin));
echo $bin;
