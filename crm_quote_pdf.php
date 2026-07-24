<?php
/**
 * Artdon Quotation PDF Export V6.8.5.21 Quote/Order Unified Spec
 * Syncs quotation preview format to PDF/print export:
 * - No fixed Remark line.
 * - TO area shows contact+phone, company, addresses, email.
 * - Beam angle stores digits but prints with degree symbol.
 * - Accessories and simplified optic specification are included.
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE) {
    @session_name('ARTDON_SYS');
    @session_start();
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function s_trim($v): string { return trim((string)($v ?? '')); }
function first_non_empty(array $arr): string { foreach ($arr as $v) { $s = s_trim($v); if ($s !== '') return $s; } return ''; }
function num($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function money_fmt($v): string { return number_format((float)$v, 2, '.', ''); }
function clean_param($v): string {
    $s = s_trim($v);
    if ($s === '') return '';
    $low = mb_strtolower($s, 'UTF-8');
    if (in_array($low, ['undefined','null','none','未选','未选择','选无','请选择','-'], true)) return '';
    return $s;
}
function maybe_json($v, $def = []) {
    if (is_array($v) || is_object($v)) return $v;
    $s = s_trim($v);
    if ($s === '') return $def;
    $j = json_decode($s, true);
    return is_array($j) ? $j : $def;
}
function arr_get($arr, $key, $def = '') {
    if (is_array($arr) && array_key_exists($key, $arr)) return $arr[$key];
    if (is_object($arr) && isset($arr->$key)) return $arr->$key;
    return $def;
}
function arr_first($arr, array $keys): string {
    foreach ($keys as $k) {
        $v = arr_get($arr, $k, '');
        if (is_array($v) || is_object($v)) continue;
        $s = clean_param($v);
        if ($s !== '') return $s;
    }
    return '';
}

$payloadRaw = $_POST['payload'] ?? file_get_contents('php://input');
$payload = json_decode((string)$payloadRaw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><h2>PDF 导出失败</h2><p>缺少或无法解析 payload。</p>';
    exit;
}

function quote_pdf_block($msg){ http_response_code(403); echo '<!doctype html><meta charset="utf-8"><style>
@font-face{font-family:"ARS MaquetteTr";src:url("assets/fonts/ARSMaqLigTr.otf") format("opentype");font-weight:300 900;font-style:normal;font-display:swap}
html,body,.paper,.paper *,.quote-table,.quote-table *,.terms,.terms *,.bank,.bank *,.bank-terms,.bank-terms *,.final-summary,.final-summary *,.final-sign,.final-sign *,.doc-table,.doc-table *,.box,.box *{font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif!important;}body{font-family:Arial,"Microsoft YaHei",sans-serif;padding:40px;color:#111827} .box{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:14px;padding:18px;max-width:720px}</style><div class="box"><h2>报价未审核，不能导出</h2><p>'.h($msg).'</p><p>请回报价系统 → 未审核列表 → 审核通过后再导出 PDF。</p></div>'; exit; }
function quote_pdf_table_exists(PDO $pdo,string $t): bool { try{$s=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');$s->execute([$t]);return (bool)$s->fetchColumn();}catch(Throwable $e){return false;} }
function quote_pdf_col_exists(PDO $pdo,string $t,string $c): bool { try{$s=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');$s->execute([$t,$c]);return (bool)$s->fetchColumn();}catch(Throwable $e){return false;} }
function quote_pdf_apply_approved_snapshot(array $payload): array {
    if (!empty($payload['order_export']) || !empty($payload['is_order']) || trim((string)($payload['order_no'] ?? '')) !== '') return $payload;
    $id=(int)($payload['quote_id']??0); if($id<=0) quote_pdf_block('缺少报价ID，不能读取审核快照。');
    require_once __DIR__.'/includes/db.php'; $pdo=db();
    $st=$pdo->prepare('SELECT id,quote_no,approval_status,approved_snapshot_json FROM quote_orders WHERE id=? LIMIT 1');$st->execute([$id]);$q=$st->fetch(PDO::FETCH_ASSOC);
    if(!$q || strtolower((string)($q['approval_status']??''))!=='approved') quote_pdf_block('报价不存在或尚未审核通过。');
    $snap=json_decode((string)($q['approved_snapshot_json']??''),true);
    if(!is_array($snap)||(int)($snap['id']??0)!==$id||(string)($snap['quote_no']??'')!==(string)$q['quote_no']) quote_pdf_block('审核快照不存在或与报价不一致。');
    $items=json_decode((string)($snap['items_json']??''),true); if(!is_array($items)||!$items) quote_pdf_block('审核快照没有产品明细。');
    return ['quote_id'=>$id,'quote_no'=>$snap['quote_no'],'quote_date'=>$snap['quote_date']??date('Y-m-d'),'quote_status'=>$snap['quote_status']??($snap['status']??'Quotation sheet'),'currency'=>$snap['currency']??'USD','exchange_rate'=>$snap['exchange_rate']??1,'customer'=>maybe_json($snap['customer_json']??'',[]),'header'=>maybe_json($snap['header_json']??'',[]),'bank'=>maybe_json($snap['bank_json']??'',[]),'template'=>maybe_json($snap['template_json']??'',[]),'items'=>$items,'total'=>['qty'=>$snap['qty']??0,'amount'=>$snap['amount']??0],'approval_status'=>'approved','approved_snapshot_export'=>1];
}
function quote_pdf_approval_guard(array $payload): void {
    if (!empty($payload['order_export']) || !empty($payload['is_order']) || trim((string)($payload['order_no'] ?? '')) !== '') return;
    $no = trim((string)($payload['quote_no'] ?? ''));
    if ($no === '') quote_pdf_block('缺少报价编号。');
    try {
        require_once __DIR__ . '/includes/db.php';
        if (!function_exists('db')) return;
        $pdo = db(); if (!$pdo instanceof PDO) return;
        if (!quote_pdf_table_exists($pdo,'quote_orders') || !quote_pdf_col_exists($pdo,'quote_orders','approval_status')) quote_pdf_block('报价系统尚未启用审核字段，请先覆盖 quote_api.php 并刷新报价系统。');
        $st=$pdo->prepare("SELECT approval_status,approved_at FROM quote_orders WHERE quote_no=? ORDER BY id DESC LIMIT 1");
        $st->execute([$no]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r) quote_pdf_block('这张报价尚未保存提交审核：'.$no);
        if (strtolower((string)($r['approval_status'] ?? '')) !== 'approved') quote_pdf_block('当前报价状态为：'.($r['approval_status'] ?: 'pending').'。');
    } catch (Throwable $e) { quote_pdf_block('审核状态检查失败：'.$e->getMessage()); }
}
$payload=quote_pdf_apply_approved_snapshot($payload);
quote_pdf_approval_guard($payload);

function quote_pdf_at_doc_no($v): string {
    $s = s_trim($v);
    if (preg_match('/^SO-(.+)$/i', $s, $m)) return 'AT-' . $m[1];
    return $s;
}
function quote_pdf_normalize_order_no(array &$payload): void {
    $isOrder = !empty($payload['order_export']) || !empty($payload['is_order']) || s_trim($payload['order_no'] ?? '') !== '' || stripos((string)($payload['quote_no'] ?? ''), 'SO-') === 0;
    if (!$isOrder) return;
    $no = quote_pdf_at_doc_no($payload['quote_no'] ?? '');
    if ($no === '') $no = quote_pdf_at_doc_no($payload['order_no'] ?? '');
    if ($no !== '') { $payload['quote_no'] = $no; $payload['order_no'] = $no; }
}
function quote_pdf_doc_no_label(string $docTitle): string {
    if ($docTitle === '订购合同') return '订单号';
    if ($docTitle === 'PROFORMA INVOICE') return 'PI Number';
    return 'Quotation No';
}
function quote_pdf_file_doc_label(string $docTitle): string {
    if ($docTitle === 'PROFORMA INVOICE') return 'Proforma Invoice';
    if ($docTitle === '订购合同') return 'Purchase Contract';
    if (preg_match('/quotation/i', $docTitle)) return 'Quotation';
    return trim(ucwords(strtolower($docTitle))) ?: 'Document';
}
function quote_pdf_safe_filename(string $s, string $ext): string {
    $s = trim($s);
    $s = preg_replace('#[\\/:*?"<>|]+#', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = trim($s, " ._-	
");
    return ($s !== '' ? $s : 'quotation') . $ext;
}
function quote_pdf_file_title(array $payload, string $quoteNo, string $docTitle): string {
    $isOrder = !empty($payload['order_export']) || !empty($payload['is_order']) || s_trim($payload['order_no'] ?? '') !== '' || stripos((string)($payload['quote_no'] ?? ''), 'SO-') === 0;
    $no = quote_pdf_at_doc_no(s_trim($payload['order_no'] ?? '') ?: $quoteNo);
    if ($isOrder && $no !== '') return trim($no . ' ' . quote_pdf_file_doc_label($docTitle));
    return $quoteNo !== '' ? $quoteNo : 'quotation';
}
quote_pdf_normalize_order_no($payload);

function quote_doc_title(array $payload): string {
    $v = s_trim($payload['quote_status'] ?? ($payload['order_doc_title'] ?? ($payload['contract_title'] ?? 'Quotation sheet')));
    $currency = strtoupper(s_trim($payload['currency'] ?? ''));
    $isOrder = s_trim($payload['order_no'] ?? '') !== '' || stripos((string)($payload['quote_no'] ?? ''), 'SO-') === 0 || s_trim($payload['order_doc_title'] ?? '') !== '' || s_trim($payload['contract_title'] ?? '') !== '';
    if (preg_match('/订购合同|purchase|contract/iu', $v)) return '订购合同';
    if ($isOrder && in_array($currency, ['RMB','CNY','人民币'], true)) return '订购合同';
    if ($isOrder) return 'PROFORMA INVOICE';
    return preg_match('/proforma|invoice/i', $v) ? 'PROFORMA INVOICE' : 'Quotation sheet';
}
function default_terms(array $payload): array {
    return [
        ['PI Number:', $payload['quote_no'] ?? ''],
        ['Payment:', '40% Deposit before production'],
        ['', '60% payment before shipment'],
        ['Price Terms:', 'EXWORK'],
        ['Quoted Date:', $payload['quote_date'] ?? date('Y-m-d')],
        ['Delivery Date:', '25-35Days After Confirmed'],
        ['Quoted Valid', 'Within 10 days'],
    ];
}
function terms_from_payload(array $payload): array {
    $tpl = is_array($payload['template'] ?? null) ? $payload['template'] : [];
    $terms = maybe_json($tpl['terms_json'] ?? '', []);
    if (!$terms) $terms = default_terms($payload);
    $out = [];
    foreach ($terms as $r) {
        if (!is_array($r)) continue;
        $a = str_replace(['QTNO','DATE'], [$payload['quote_no'] ?? '', $payload['quote_date'] ?? date('Y-m-d')], (string)($r[0] ?? ''));
        $b = str_replace(['QTNO','DATE'], [$payload['quote_no'] ?? '', $payload['quote_date'] ?? date('Y-m-d')], (string)($r[1] ?? ''));
        $out[] = [$a, $b];
    }
    if (!$out) $out = default_terms($payload);
    $isOrder = !empty($payload['order_export']) || !empty($payload['is_order']) || s_trim($payload['order_no'] ?? '') !== '';
    $docTitle = quote_doc_title($payload);
    foreach ($out as &$r) {
        if (preg_match('/PI\s*Number|订单号|訂單號|报价编号|報價編號/iu', $r[0])) {
            $r[0] = $isOrder ? ($docTitle === '订购合同' ? '订单号:' : 'PI Number:') : $r[0];
            $r[1] = (string)($payload['quote_no'] ?? '');
        }
        if (preg_match('/Quoted\s*Date/i', $r[0])) $r[1] = (string)($payload['quote_date'] ?? date('Y-m-d'));
    }
    unset($r);
    return $out;
}

function quote_customer_address_lines($c): array {
    $seen = [];
    $out = [];
    $push = function($v) use (&$seen, &$out) {
        $s = trim(preg_replace('/\s+/u', ' ', (string)($v ?? '')));
        if ($s === '') return;
        $k = mb_strtolower($s, 'UTF-8');
        if (isset($seen[$k])) return;
        $seen[$k] = true;
        $out[] = $s;
    };
    $push(arr_first($c, ['address1','office_address','address','company_address']));
    $push(arr_first($c, ['address2','factory_address','delivery_address']));
    $raw = arr_get($c, 'addresses_json', arr_get($c, 'addresses', ''));
    $arr = maybe_json($raw, []);
    if (is_array($arr)) {
        foreach ($arr as $a) {
            if (is_string($a)) { $push($a); continue; }
            if (!is_array($a)) continue;
            $label = s_trim($a['label'] ?? $a['name'] ?? $a['type'] ?? '');
            $addr = s_trim($a['address'] ?? $a['addr'] ?? $a['value'] ?? $a['text'] ?? '');
            $note = s_trim($a['note'] ?? '');
            if ($addr !== '') $push(($label !== '' ? $label . ': ' : '') . $addr . ($note !== '' ? ' ' . $note : ''));
        }
    }
    return array_slice($out, 0, 4);
}
function quote_customer_to_text($c): string {
    if (!is_array($c) || count($c) === 0) return 'Please select customer';
    $contact = arr_first($c, ['primary_contact','main_contact','contact_name','contact','person','linkman']);
    $phone = arr_first($c, ['primary_contact_phone','contact_phone','contact_mobile','contact_whatsapp','whatsapp','mobile','phone','tel']);
    $email = arr_first($c, ['primary_contact_email','contact_email','email','customer_email','mail']);
    $company = arr_first($c, ['company','name','customer_name','client_name']);
    $lines = [];
    if ($contact !== '' || $phone !== '') $lines[] = 'Contact: ' . trim($contact . ($contact !== '' && $phone !== '' ? '  ' : '') . $phone);
    if ($company !== '') $lines[] = $company;
    foreach (quote_customer_address_lines($c) as $i => $addr) $lines[] = ($i === 0 ? 'Address: ' : '') . $addr;
    if ($email !== '') $lines[] = 'Email: ' . $email;
    return $lines ? implode("\n", $lines) : 'Please select customer';
}

function quote_text_blob($p): string { return implode(' ', array_filter([arr_get($p,'dimension_type'),arr_get($p,'category'),arr_get($p,'item_name'),arr_get($p,'name'),arr_get($p,'product_name'),arr_get($p,'series'),arr_get($p,'type'),arr_get($p,'code'),arr_get($p,'model')], fn($x)=>s_trim($x)!=='')); }
function quote_is_not_round_text($txt): bool { return (bool)preg_match('/方形|方圆|长方|线性|线条|长条|条形|K条|LUMI|linear|square|rect/i', (string)$txt); }
function quote_is_round_product($p): bool {
    $t = strtolower(s_trim(arr_get($p, 'dimension_type')));
    $txt = quote_text_blob($p);
    if (quote_is_not_round_text($txt)) return false;
    if ((int)arr_get($p, 'is_round_dimension', 0) === 1) return true;
    if (in_array($t, ['embedded_round','diameter','round','circle','circular'], true)) return true;
    if (in_array($t, ['embedded_square','box','square','rectangle'], true)) return false;
    return (bool)preg_match('/圆形|圆筒|筒灯|downlight|cylinder|round/i', $txt);
}
function quote_is_embedded_product($p): bool {
    $t = strtolower(s_trim(arr_get($p, 'dimension_type')));
    if (in_array($t, ['embedded_round','embedded_square','opening','recessed'], true)) return true;
    if ((int)arr_get($p, 'is_embedded', 0) === 1) return true;
    return (bool)preg_match('/嵌入|无边|有边|开孔|recessed/i', quote_text_blob($p));
}
function quote_display_size($p): string {
    $L=clean_param(arr_get($p,'dim_length')); $W=clean_param(arr_get($p,'dim_width')); $H=clean_param(arr_get($p,'dim_height')); $D=clean_param(arr_get($p,'dim_outer_d'));
    $round = quote_is_round_product($p);
    if ($L!=='' && $W!=='' && $H!=='') return $L.'*'.$W.'*'.$H;
    if ($L!=='' && $W!=='') return $L.'*'.$W.($H!==''?'*'.$H:'');
    if ($D!=='' && $H!=='') return ($round?'Φ':'').$D.'*'.$H;
    if ($D!=='') return ($round?'Φ':'').$D.($H!==''?'*'.$H:'');
    $v = clean_param(arr_first($p, ['quote_display_size','size','dimension','dimensions']));
    if ($v!=='' && !$round) $v = preg_replace('/^\s*[ΦφØø]\s*/u', '', $v);
    return $v;
}
function quote_format_cutout($v): string {
    $v = clean_param($v);
    if ($v === '') return '';
    $v = preg_replace('/^\s*(cut\s*out|cutout|开孔)\s*[:：]?\s*/iu', '', $v);
    $v = preg_replace('/^\s*[ΦφØø]\s*/u', '', $v);
    $v = preg_replace('/\s*mm\s*$/i', '', $v);
    $v = trim($v);
    return $v !== '' ? 'Φ'.$v.'mm' : '';
}
function quote_display_cutout($p): string { return quote_is_embedded_product($p) ? quote_format_cutout(arr_first($p, ['quote_display_cutout','cutout','dim_opening','hole','opening'])) : ''; }
function clean_beam_angle_value($v): string { return trim(preg_replace('/\s*[°º]\s*$/u', '', (string)($v ?? ''))); }
function format_beam_angle($v): string { $v=clean_beam_angle_value($v); if($v==='') return ''; return preg_match('/^\d+(?:\.\d+)?(?:[X\/\-]\d+(?:\.\d+)?)*$/', $v) ? $v.'°' : $v; }
function format_power_spec($v): string { $raw=trim((string)($v ?? '')); if($raw==='') return ''; $core=str_replace(['瓦','ｗ','Ｗ'],'W',$raw); $core=preg_replace('/\s+/','',$core); $core=preg_replace('/w$/i','',$core); $core=preg_replace('/[^0-9.\-+xX\/]/','',$core); if($core!=='' && preg_match('/^\d+(?:\.\d+)?(?:[Xx\/\-]\d+(?:\.\d+)?)*$/',$core)) return strtoupper($core).'W'; if(preg_match('/w$/i',$raw)) return preg_replace('/w$/i','W',$raw); return $raw; }
function norm_cct($v): string { $v=clean_param($v); if($v!=='' && preg_match('/^\d+(?:\.\d+)?$/', $v)) return $v.'K'; return $v; }
function norm_cri($v): string { $v=clean_param($v); if($v!=='' && preg_match('/^\d+(?:\.\d+)?$/', $v)) return 'CRI'.$v; return $v; }
function norm_ip($v): string { $v=clean_param($v); if($v!=='' && preg_match('/^\d+$/', $v)) return 'IP'.$v; return $v; }
function product_series_line($p): string { return arr_first($p, ['series','series_name','family','name','product_name','title']); }
function spec_label($label): string {
    $k = mb_strtolower(s_trim($label), 'UTF-8');
    if (in_array($k, ['connector','接头','连接头','adapter'], true)) return 'Adapter';
    if (in_array($k, ['driver','led driver','电源','驱动'], true)) return 'LED Driver';
    if (in_array($k, ['optic','光学','透镜','反光杯'], true)) return 'Optic';
    if (in_array($k, ['accessories','accessory','extra','附件','配件'], true)) return 'Accessories';
    if (in_array($k, ['led','芯片','cob'], true)) return 'LED';
    return s_trim($label);
}

function quote_part_stopword($t): bool { return preg_match('/^(led|cob|driver|optic|optics|lens|reflector|adapter|connector|accessory|accessories|extra|chip|power|light|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接器|附件|配件|物料|材料)$/iu', trim((string)$t))===1; }
function quote_drop_material_name_tokens($raw): array {
    $tokens=preg_split('/\s+/u', trim((string)$raw)); $out=[];
    foreach((array)$tokens as $t){ $t=trim($t); if($t==='' || preg_match('/[\x{4e00}-\x{9fff}]/u',$t) || quote_part_stopword($t)) continue; $out[]=$t; }
    return $out;
}
function quote_model_from_ascii_text($raw,$hint=''): string {
    $hint=clean_param($hint); if($hint!=='') return $hint;
    $toks=quote_drop_material_name_tokens($raw); if(!$toks) return '';
    $s=trim(implode(' ',$toks));
    if(preg_match('/([A-Za-z0-9._-]+\s+Series\s*[A-Za-z0-9@._-]+(?:\s*[A-Za-z0-9@._-]+){0,2})/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1]));
    if(preg_match('/([A-Z]{1,8}[-_]?\d[A-Z0-9._-]*(?:\s+[A-Z0-9]{1,6}){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1]));
    if(preg_match('/(\d+(?:\.\d+)?\s*MM(?:\s+[A-Z0-9._-]+){0,3})$/iu',$s,$m)) return trim(preg_replace('/\s+/u',' ',$m[1]));
    if(count($toks)>4) return trim(implode(' ',array_slice($toks,-3)));
    if(count($toks)>1) return trim(implode(' ',array_slice($toks,1)));
    return $toks[0]??'';
}
function quote_brand_from_ascii_text($raw,$hint=''): string {
    $hint=clean_param($hint); if($hint!=='') return $hint;
    $toks=quote_drop_material_name_tokens($raw); return $toks[0]??'';
}
function quote_brand_model_only($input,$label=''): string {
    if(is_array($input)){
        $brand=clean_param($input['brand'] ?? ($input['manufacturer'] ?? ($input['factory'] ?? '')));
        $model=clean_param($input['model'] ?? ($input['model_no'] ?? ($input['product_model'] ?? ($input['code'] ?? ($input['sku'] ?? ($input['series'] ?? ($input['series_name'] ?? '')))))));
        if($model==='') $model=quote_model_from_ascii_text(trim(implode(' ',array_filter([$input['name']??'',$input['spec']??'',$input['description']??'']))));
        if($brand==='') $brand=quote_brand_from_ascii_text(trim(implode(' ',array_filter([$input['brand']??'',$input['name']??'',$input['spec']??'',$input['description']??'']))));
        $out=trim(implode(' ',array_filter([$brand,$model])));
        return $out!=='' ? $out : (clean_param($input['spec']??($input['name']??'')));
    }
    $raw=clean_param($input); if($raw==='') return '';
    $brand=quote_brand_from_ascii_text($raw); $model=quote_model_from_ascii_text($raw);
    if($brand!=='' && $model!=='' && stripos($model,$brand)===0) return $model;
    $out=trim(implode(' ',array_filter([$brand,$model])));
    if($out!=='') return $out;
    $tokens=quote_drop_material_name_tokens($raw); return $tokens?trim(implode(' ',$tokens)):$raw;
}
function quote_is_component_label($label): bool { return in_array(spec_label($label), ['LED','LED Driver','Optic','Adapter','Accessories'], true); }
function quote_sanitize_component_value($label,$value): string { return quote_is_component_label($label) ? quote_brand_model_only($value,$label) : clean_param($value); }
function quote_sanitize_saved_spec($spec,$item=[]): string {
    $spec=(string)$spec; if(trim($spec)==='') return '';
    $lines=preg_split('/\R/u',$spec); $out=[];
    foreach((array)$lines as $line){
        if(preg_match('/^(\s*(?:\d+\.\s*)?)(LED\s*Driver|LED|Optic|Adapter|Accessories|Connector|Driver|光学|透镜|反光杯|电源|驱动|芯片|灯珠|接头|连接头|附件|配件)\s*[:：]\s*(.*?)\s*$/iu',$line,$m)){
            $label=spec_label($m[2]); $val=quote_sanitize_component_value($label,$m[3]); $out[]=$m[1].$label.': '.$val;
        } else { $out[]=$line; }
    }
    return implode("\n",$out);
}
function quote_part_name($m): string { return quote_brand_model_only($m); }
function quote_text_ascii_series($v): string {
    $v = clean_param($v); if($v==='') return '';
    if (preg_match('/([A-Za-z][A-Za-z0-9\-]*(?:\s+[A-Za-z][A-Za-z0-9\-]*)*\s+(?:Series|SERIES|series)\s*\d*[A-Za-z0-9\-]*)/', $v, $m)) return trim(preg_replace('/\s+/', ' ', $m[1]));
    $ascii = preg_replace('/[\x{4e00}-\x{9fff}]/u', ' ', $v);
    $ascii = preg_replace('/[|\/，,、]+/u', ' ', $ascii);
    $ascii = trim(preg_replace('/\s+/', ' ', $ascii));
    $ascii = trim(preg_replace('/^(optic|optics|光学|透镜|反光杯)\s*/iu', '', $ascii));
    return $ascii;
}
function quote_optic_spec_text($input): string { return quote_brand_model_only($input, 'Optic'); }
function led_spec_text($m, $item): string { return trim(implode(' ', array_filter([quote_part_name($m), norm_cct(arr_get($item,'cct')), norm_cri(arr_get($item,'cri'))], fn($x)=>$x!==''))); }
function quote_part_spec_name($k, $m, $item): string { if($k==='led') return led_spec_text($m, $item); return quote_brand_model_only($m); }
function quote_remarks_of_item($it): array {
    $arr = arr_get($it, 'quote_remarks', []);
    if (!is_array($arr) || !count($arr)) {
        $extra = arr_get($it, 'extra_spec', '');
        $arr = preg_split('/\n+/u', (string)$extra);
    }
    return array_slice(array_values(array_filter(array_map(fn($x)=>clean_param($x), $arr), fn($x)=>$x!=='')), 0, 4);
}
function product_quote_spec($p): array {
    $raw = arr_get($p, 'quote_spec', null);
    if (!$raw && arr_get($p, 'quote_spec_json', '') !== '') $raw = maybe_json(arr_get($p, 'quote_spec_json'), []);
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $k=>$v) {
            $label=$k; $val='';
            if (is_array($v)) { $label=$v['label'] ?? $k; $val=$v['value'] ?? $v['text'] ?? ''; }
            else $val=$v;
            $label=spec_label($label);
            $val=quote_sanitize_component_value($label,$val);
            $val=clean_param($val);
            if ($val!=='') $out[]=[$label,$val];
        }
    }
    return $out;
}
function build_spec($item): string {
    // V6.8.5.21：已保存报价/已转订单如果带有完整 Specification，导出时优先原样使用。
    // 避免订单导出只剩产品、功率、尺寸、开孔，丢失 LED / Driver / Optic / IP 等原报价参数。
    $savedSpec = clean_param(arr_get($item, 'specification', ''));
    if ($savedSpec !== '') return quote_sanitize_saved_spec($savedSpec,$item);
    $p = is_array(arr_get($item, 'product', [])) ? arr_get($item, 'product', []) : [];
    $parts = is_array(arr_get($item, 'parts', [])) ? arr_get($item, 'parts', []) : [];
    $lines=[]; $n=1; $used=[];
    $add=function($label,$val) use (&$lines,&$n,&$used) { $label=spec_label($label); $val=clean_param($val); if($val!==''){ $lines[] = ($n++).'. '.$label.': '.$val; $used[$label]=true; } };
    $series=product_series_line($p); if($series!=='') $lines[]=($n++).'. '.$series;
    $add('Power', format_power_spec(arr_first($item, ['power']) ?: arr_first($p, ['power'])));
    $add('Size', quote_display_size($p));
    $add('Cut out', quote_display_cutout($p));
    $add('Beam Angle', format_beam_angle(arr_first($item, ['beam_angle','beamAngle'])));
    $qspec=product_quote_spec($p);
    foreach ($qspec as [$label,$val]) { if($label==='LED') $add('LED', trim($val.' '.norm_cct(arr_get($item,'cct')).' '.norm_cri(arr_get($item,'cri')))); else $add($label,$val); }
    $order=['led'=>'LED','connector'=>'Adapter','driver'=>'LED Driver','optic'=>'Optic','extra'=>'Accessories'];
    $none=['led'=>'LED: Without LED chip','connector'=>'Adapter: Without adapter','driver'=>'LED Driver: Without LED driver','optic'=>'Optic: Without optic','extra'=>'Accessories: Without accessories'];
    foreach ($order as $k=>$label) {
        if (!array_key_exists($k, $parts)) continue;
        $m=$parts[$k];
        if (is_array($m) && !empty($m['none'])) { if(!$qspec) $lines[]=($n++).'. '.$none[$k]; continue; }
        $val=quote_part_spec_name($k, $m, $item);
        if ($val==='') continue;
        if ($k==='extra' || $k==='connector') { $add($label,$val); continue; }
        if (empty($used[$label])) $add($label,$val);
    }
    $ip = norm_ip(arr_get($item,'ip'));
    if ($ip!=='') $lines[]=($n++).'. '.$ip;
    foreach (quote_remarks_of_item($item) as $x) $lines[]=($n++).'. '.$x;
    if (!$lines && arr_get($item, 'specification', '') !== '') return (string)arr_get($item, 'specification');
    return $lines ? implode("\n", $lines) : 'Please select product and accessories';
}
function item_price($it): float { return num($it['price'] ?? $it['unit_price'] ?? 0); }
function item_amount($it): float { $qty=num($it['qty'] ?? 0); $p=item_price($it); return num($it['amount'] ?? 0) ?: $qty*$p; }

$quoteNo = s_trim($payload['quote_no'] ?? 'AT-' . date('ymd'));
$quoteDate = s_trim($payload['quote_date'] ?? date('Y-m-d'));
$currency = s_trim($payload['currency'] ?? 'USD');
$header = is_array($payload['header'] ?? null) ? $payload['header'] : [];
$bank = is_array($payload['bank'] ?? null) ? $payload['bank'] : [];
$customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
$terms = terms_from_payload($payload);
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$totalQty = 0; $totalAmount = 0;
foreach ($items as $it) { $totalQty += num($it['qty'] ?? 0); $totalAmount += item_amount($it); }
if (isset($payload['total']) && is_array($payload['total'])) {
    $totalQty = num($payload['total']['qty'] ?? $totalQty) ?: $totalQty;
    $totalAmount = num($payload['total']['amount'] ?? $totalAmount) ?: $totalAmount;
}
$company = s_trim($header['company'] ?? 'Gallin Industrial (HK) Limited');
$fromText = s_trim($header['from_text'] ?? '');
$stamp = !empty($header['show_stamp']) ? s_trim($header['stamp'] ?? '') : '';
$docTitle = quote_doc_title($payload);
$symbol = strtoupper($currency) === 'RMB' ? '¥' : '$';
$fileTitle = quote_pdf_file_title($payload, $quoteNo, $docTitle);
$filename = quote_pdf_safe_filename($fileTitle, '.pdf');

function payment_amount_lines($total, $terms): array {
    $arr=[];
    foreach ($terms as $r) {
        $txt=(string)($r[1] ?? '');
        if (!preg_match('/(\d+(?:\.\d+)?)\s*%/', $txt, $m)) continue;
        $pct=(float)$m[1]; if($pct<=0 || $pct>100) continue;
        $label=preg_replace('/\s*before\s+.*/i','',$txt); $label=preg_replace('/\s*after\s+.*/i','',$label); $label=trim($label) ?: ($pct.'% Payment');
        $arr[]=['label'=>$label,'amount'=>round($total*$pct/100,2),'pct'=>$pct];
    }
    if (count($arr)>=2) {
        $sum=array_sum(array_map(fn($x)=>$x['pct'], $arr));
        if (abs($sum-100)<0.01) { $prev=0; for($i=0;$i<count($arr);$i++){ if($i===count($arr)-1) $arr[$i]['amount']=round(round($total,2)-$prev,2); else { $arr[$i]['amount']=round($total*$arr[$i]['pct']/100,2); $prev=round($prev+$arr[$i]['amount'],2);} } }
    }
    return $arr;
}
$payLines = payment_amount_lines($totalAmount, $terms);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=h($fileTitle)?></title>
<style>
@font-face{font-family:"ARS MaquetteTr";src:url("assets/fonts/ARSMaqLigTr.otf") format("opentype");font-weight:300 900;font-style:normal;font-display:swap}
html,body,.paper,.paper *,.quote-table,.quote-table *,.terms,.terms *,.bank,.bank *,.bank-terms,.bank-terms *,.final-summary,.final-summary *,.final-sign,.final-sign *,.doc-table,.doc-table *,.box,.box *{font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif!important;}
@page{size:A4 portrait;margin:0}
*{box-sizing:border-box}
html,body{margin:0;background:#f4f6fa;color:#000;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif}
.no-print{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",Arial,sans-serif}
.toolbar{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid #d8dee9;padding:10px 14px;display:flex;gap:10px;align-items:center;justify-content:space-between}
.toolbar b{font-size:15px}.toolbar button{border:1px solid #cfd6e3;background:#111827;color:#fff;border-radius:9px;padding:8px 14px;font-weight:800;cursor:pointer}.toolbar .gray{background:#eef2f7;color:#111827}
.paper{width:210mm;min-height:297mm;background:#fff;margin:12px auto;border:1px solid #cfd6e3;padding:20mm 15mm 17mm;overflow:hidden;font-family:"ARS MaquetteTr","Microsoft YaHei",Arial,sans-serif;color:#000}
.paper-top{display:grid;grid-template-columns:96mm 72mm;gap:12mm}.from h1{font-size:16pt;margin:0 0 5mm;font-weight:400}.block,.to{font-size:9.2pt;line-height:1.25;white-space:pre-line}.to{margin-top:7mm}.brandstamp{text-align:center;color:#0000a0;font-weight:700;font-size:8.6pt;white-space:pre-line}.qt-title{text-align:center;font-size:15pt;font-weight:700;margin:2mm 0 4mm}.terms{width:72mm;border-collapse:collapse;font-size:8.2pt}.terms td{border:1.25px solid #000;padding:1.1mm 2mm;height:4.9mm}.terms td:first-child{font-weight:700;text-align:right;width:34mm}.quote-table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:10mm;font-size:7.0pt;line-height:1.18}.quote-table th,.quote-table td{border:1.25px solid #000;padding:1mm;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.quote-table th{height:10mm}.quote-table .spec{text-align:left;white-space:pre-line;line-height:1.18;padding:1.4mm;vertical-align:middle!important}.prod-img{max-width:100%;max-height:18mm;object-fit:contain}.final-summary{display:grid;grid-template-columns:1fr 1fr;gap:12mm;margin-top:8mm}.final-summary .box{border:1.25px solid #000;padding:2.3mm;font-size:8.6pt;line-height:1.4}.final-summary .box b{font-size:10pt}.final-sign{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12mm;text-align:center;font-size:9pt;margin-top:18mm}.final-sign div{border-top:1.25px solid #000;padding-top:3mm}.bank{background:#f2f2f2;padding:2.2mm;font-size:7.8pt;line-height:1.23;margin-top:8mm;white-space:pre-line}.bank-terms{padding:2.5mm;font-size:7.9pt;line-height:1.28;margin-top:4mm;white-space:pre-line;background:#fff}.empty{height:35mm;color:#667085}
/* V6.8.5.38: PI/Order one-product balanced full-page mode. Keeps normal header top spacing, compresses product row whitespace, and leaves a small bottom margin. */
.paper.quote-one-item{padding:20mm 13mm 10mm!important;height:297mm!important;min-height:297mm!important;max-height:297mm!important;overflow:hidden!important}
.paper.quote-one-item .paper-top{grid-template-columns:96mm 72mm!important;gap:12mm!important;min-height:58mm!important}
.paper.quote-one-item .from h1{font-size:16pt!important;margin:0 0 5mm!important}
.paper.quote-one-item .block,.paper.quote-one-item .to{font-size:9.2pt!important;line-height:1.25!important}
.paper.quote-one-item .to{margin-top:7mm!important}
.paper.quote-one-item .brandstamp{font-size:8.2pt!important;line-height:1.22!important}
.paper.quote-one-item .qt-title{font-size:15pt!important;margin:2mm 0 4mm!important}
.paper.quote-one-item .terms{font-size:8.2pt!important}
.paper.quote-one-item .terms td{height:4.9mm!important;padding:1.0mm 1.8mm!important}
.paper.quote-one-item .quote-table{margin-top:10mm!important;font-size:7.25pt!important;line-height:1.18!important}
.paper.quote-one-item .quote-table th,.paper.quote-one-item .quote-table td{padding:.75mm .8mm!important}
.paper.quote-one-item .quote-table th{height:9.8mm!important}
.paper.quote-one-item .quote-product-row td{height:36mm!important}
.paper.quote-one-item .quote-total-row td{height:6mm!important}
.paper.quote-one-item .quote-table .spec{padding:.8mm 1mm!important;line-height:1.18!important}
.paper.quote-one-item .prod-img{max-height:18mm!important}
.paper.quote-one-item .final-summary{margin-top:5mm!important;gap:10mm!important}
.paper.quote-one-item .final-summary .box{font-size:8.7pt!important;line-height:1.38!important;padding:2.3mm!important;min-height:22mm!important}
.paper.quote-one-item .final-summary .box b{font-size:10pt!important}
.paper.quote-one-item .final-sign{margin-top:8mm!important;font-size:8.8pt!important;gap:10mm!important}
.paper.quote-one-item .final-sign div{padding-top:3mm!important}
.paper.quote-one-item .bank{margin-top:5mm!important;padding:2.3mm!important;font-size:7.8pt!important;line-height:1.23!important;min-height:20mm!important}
.paper.quote-one-item .bank-terms{margin-top:2mm!important;padding:1.4mm 1.6mm!important;font-size:7.25pt!important;line-height:1.28!important;min-height:0!important}
.bank-note-block{page-break-inside:avoid;break-inside:avoid}.continuation-head{display:none}
@media print{.no-print{display:none!important}body{background:#fff}.paper{margin:0;border:0;box-shadow:none;width:210mm;min-height:297mm;overflow:visible}.paper:last-child{page-break-after:auto}.quote-table thead{display:table-header-group}.quote-table tr{page-break-inside:avoid;break-inside:avoid}.paper.quote-one-item{height:auto!important;min-height:297mm!important;max-height:none!important;overflow:visible!important;padding:20mm 13mm 10mm!important;page-break-after:auto!important;break-after:auto!important}.bank-note-block.starts-next-page{page-break-before:always;break-before:page;padding-top:10mm}.bank-note-block.starts-next-page .continuation-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10mm;align-items:end;border-bottom:1.25px solid #000;padding-bottom:3mm;margin-bottom:7mm;font-size:8.5pt;line-height:1.25}.continuation-company{font-size:12pt;font-weight:700}.continuation-doc{text-align:right}.continuation-doc b{display:block;font-size:10pt}.bank-note-block.starts-next-page .bank{margin-top:0!important}}
</style>
</head>
<body>
<div class="toolbar no-print"><b>Quotation PDF Export V6.8.5.38 - PI balanced full page</b><div><button class="gray" onclick="history.back()">返回</button><button onclick="window.print()">打印 / 保存 PDF</button></div></div>
<div class="paper<?=count($items)<=1?' quote-one-item':''?>">
  <div class="paper-top">
    <div class="from">
      <h1><?=h($company)?></h1>
      <div class="block"><?=h($fromText)?></div>
      <div class="to"><b>To:</b>
<?=h(quote_customer_to_text($customer))?></div>
    </div>
    <div>
      <div class="brandstamp"><?=h($stamp)?></div>
      <div class="qt-title"><?=h($docTitle)?></div>
      <table class="terms"><?php foreach($terms as $r): ?><tr><td><?=h($r[0] ?? '')?></td><td><?=h($r[1] ?? '')?></td></tr><?php endforeach; ?></table>
    </div>
  </div>
  <table class="quote-table">
    <colgroup><col style="width:10%"><col style="width:11%"><col style="width:8%"><col style="width:9%"><col style="width:29%"><col style="width:7%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:6%"></colgroup>
    <thead><tr><th>Picture</th><th>Size or<br>Drawing(mm)</th><th>Customer<br>Code</th><th>Manufacturer<br>Code</th><th>Specification</th><th>Color</th><th>QTY<br>(pcs)</th><th>Unit<br>Price(<?=h($currency)?>)</th><th>Amount<br>(<?=h($currency)?>)</th><th>MOQ<br>(pcs)</th></tr></thead>
    <tbody>
    <?php if($items): foreach($items as $it): $p=is_array($it['product']??null)?$it['product']:[]; $qty=num($it['qty']??0); $price=item_price($it); $amt=item_amount($it); ?>
      <tr class="quote-product-row">
        <td><?php if(clean_param($p['image'] ?? '') !== ''): ?><img class="prod-img" src="<?=h($p['image'])?>"><?php endif; ?></td>
        <td><?=h(quote_display_size($p))?></td>
        <td><?=h($it['customer_code'] ?? '')?></td>
        <td><?=h($p['code'] ?? $p['model'] ?? '')?></td>
        <td class="spec"><?=h(build_spec($it))?></td>
        <td><?=h($it['color'] ?? '')?></td>
        <td><?=h($qty)?></td>
        <td><?=h(money_fmt($price))?></td>
        <td><?=h(money_fmt($amt))?></td>
        <td><?=h($it['moq'] ?? $p['moq'] ?? '')?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="10" class="empty">Please select product and accessories.</td></tr>
    <?php endif; ?>
      <tr class="quote-total-row"><td colspan="5"></td><td><b>Total:</b></td><td><b><?=h($totalQty)?></b></td><td><b><?=h($symbol)?></b></td><td><b><?=h(money_fmt($totalAmount))?></b></td><td></td></tr>
    </tbody>
  </table>
  <div class="final-summary">
    <div class="box"><b>Total Qty:</b> <?=h($totalQty)?> PCS<?php foreach($payLines as $pl): ?><br><b><?=h($pl['label'])?>:</b> <?=h($currency)?> <?=h(money_fmt($pl['amount']))?><?php endforeach; ?><br><b>Total Amount:</b> <?=h($currency)?> <?=h(money_fmt($totalAmount))?><br><b>Currency:</b> <?=h($currency)?></div>
    <div class="box"><b><?=h(quote_pdf_doc_no_label($docTitle))?>:</b> <?=h($quoteNo)?><br><b>Status:</b> <?=h($docTitle)?><br><b>Date:</b> <?=h($quoteDate)?></div>
  </div>
  <div class="final-sign"><div>Prepared By</div><div>Approved By</div><div>Customer Signature</div></div>
  <?php $bankText=s_trim($bank['text'] ?? ''); $extraTerms=s_trim($bank['extra_terms'] ?? ($bank['terms_text'] ?? '')); if($bankText !== '' || $extraTerms !== ''): ?>
  <section class="bank-note-block" id="bankNoteBlock">
    <div class="continuation-head"><div class="continuation-company"><?=h($company)?></div><div class="continuation-doc"><b><?=h($docTitle)?></b><?=h(quote_pdf_doc_no_label($docTitle))?>: <?=h($quoteNo)?></div></div>
    <?php if($bankText !== ''): ?><div class="bank"><?=h($bankText)?></div><?php endif; ?>
    <?php if($extraTerms !== ''): ?><div class="bank-terms"><?=h($extraTerms)?></div><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<script>
function prepareBankNotePagination(){
  const paper=document.querySelector('.paper'),block=document.getElementById('bankNoteBlock');
  if(!paper||!block)return;
  block.classList.remove('starts-next-page');
  const pageHeight=paper.getBoundingClientRect().width*(297/210),top=block.offsetTop,height=block.getBoundingClientRect().height;
  const pageStart=Math.floor(Math.max(0,top-1)/pageHeight)*pageHeight,guard=paper.getBoundingClientRect().width*(10/210);
  if(top+height>pageStart+pageHeight-guard)block.classList.add('starts-next-page');
}
window.addEventListener('load',function(){setTimeout(function(){prepareBankNotePagination();try{window.focus();window.print();}catch(e){}},450);});
</script>
</body>
</html>
