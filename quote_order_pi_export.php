<?php
/* ARTDON_SSO_GATE_V2_START */
$__sso = __DIR__.'/includes/artdon_sso_core.php';
if (is_file($__sso)) {
    require_once $__sso;
    if (function_exists('artdon_sso_require_page')) artdon_sso_require_page('quote');
}
/* ARTDON_SSO_GATE_V2_END */

function qopi_h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function qopi_money($v){ return number_format((float)($v ?? 0), 2, '.', ''); }
function qopi_num($v){ $n=(float)($v ?? 0); return abs($n-round($n))<0.000001 ? (string)(int)round($n) : rtrim(rtrim(number_format($n,3,'.',''),'0'),'.'); }
function qopi_arr($v){ return is_array($v) ? $v : []; }
function qopi_pick($arr, $keys, $def=''){
    $arr=qopi_arr($arr);
    foreach($keys as $k){ if(isset($arr[$k]) && trim((string)$arr[$k])!=='') return $arr[$k]; }
    return $def;
}
function qopi_post_payload(){
    $raw = $_POST['payload'] ?? '';
    if ($raw === '') {
        $body = file_get_contents('php://input');
        $j = json_decode($body, true);
        if (is_array($j) && isset($j['payload'])) $raw = is_string($j['payload']) ? $j['payload'] : json_encode($j['payload'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        elseif (is_array($j)) return $j;
    }
    $p = json_decode((string)$raw, true);
    return is_array($p) ? $p : [];
}
function qopi_error($msg){
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,Arial;padding:32px"><div style="border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:16px;padding:20px"><h2>订单导出失败</h2><p>'.qopi_h($msg).'</p></div></div>';
    exit;
}
function qopi_lines($text){
    $text=str_replace(["\r\n","\r"],"\n",(string)$text);
    $arr=array_filter(array_map('trim', explode("\n", $text)), function($x){ return $x!==''; });
    return array_values($arr);
}
function qopi_spec($it){
    $it=qopi_arr($it);
    $s=trim((string)qopi_pick($it, ['specification','spec','extra_spec','description'], ''));
    if($s!=='') return $s;
    $p=qopi_arr($it['product'] ?? []);
    $parts=[];
    $name=trim((string)qopi_pick($p,['name','product_name'],''));
    if($name!=='') $parts[]='1. '.qopi_h($name);
    $size=trim((string)qopi_pick($p,['size','quote_display_size'],''));
    if($size!=='') $parts[]=(count($parts)+1).'. Size: '.qopi_h($size);
    $cut=trim((string)qopi_pick($p,['cutout','quote_display_cutout'],''));
    if($cut!=='') $parts[]=(count($parts)+1).'. Cut out: '.qopi_h($cut);
    foreach(['beam_angle'=>'Beam Angle','power'=>'Power','cct'=>'CCT','cri'=>'CRI','ip'=>'IP'] as $k=>$lab){
        $v=trim((string)($it[$k] ?? ''));
        if($v!=='') $parts[]=(count($parts)+1).'. '.$lab.': '.qopi_h($v);
    }
    return implode("\n", array_map(function($x){ return html_entity_decode($x, ENT_QUOTES, 'UTF-8'); }, $parts));
}
function qopi_title($p){
    $currency=strtoupper((string)($p['currency'] ?? ''));
    return $currency==='RMB' ? '订单' : 'Proforma Invoice';
}
function qopi_items($p){
    $items=$p['items'] ?? [];
    return is_array($items) ? $items : [];
}

$p=qopi_post_payload();
if(!$p) qopi_error('没有收到订单快照 payload。');
$items=qopi_items($p);
if(!$items) qopi_error('订单没有产品明细。');
$type=strtolower((string)($_GET['type'] ?? $_POST['type'] ?? 'pdf'));
$title=qopi_title($p);
$currency=(string)($p['currency'] ?? 'USD');
$orderNo=(string)($p['order_no'] ?? ($p['quote_no'] ?? ''));
$sourceQuote=(string)($p['source_quote_no'] ?? '');
$date=(string)($p['quote_date'] ?? date('Y-m-d'));
$customer=qopi_arr($p['customer'] ?? []);
$header=qopi_arr($p['header'] ?? []);
$bank=qopi_arr($p['bank'] ?? []);
$template=qopi_arr($p['template'] ?? []);
$total=qopi_arr($p['total'] ?? []);
$company=qopi_pick($header,['company','name'],'Artdon Lighting Limited');
$from=qopi_pick($header,['from_text','from','text'],'');
$bankText=qopi_pick($bank,['text','bank_text'],'');
$extraTerms=qopi_pick($bank,['extra_terms','terms_text'],'');
$termsJson=$template['terms_json'] ?? '';
$terms=[];
$j=json_decode((string)$termsJson,true);
if(is_array($j)) $terms=$j;

if($type==='excel' || $type==='xls'){
    $fn = preg_replace('/[^A-Za-z0-9_\-.]+/','_', $orderNo ?: 'order').'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fn.'"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"><style>td,th{border:1px solid #999;padding:6px;mso-number-format:\@;} .num{mso-number-format:0.00;}</style></head><body>';
    echo '<h2>'.qopi_h($title).'</h2>';
    echo '<table><tr><td>Order No.</td><td>'.qopi_h($orderNo).'</td><td>Date</td><td>'.qopi_h($date).'</td></tr>';
    echo '<tr><td>Customer</td><td>'.qopi_h(qopi_pick($customer,['company','name','customer_name'],'')).'</td><td>Source Quote</td><td>'.qopi_h($sourceQuote).'</td></tr></table>';
    echo '<table><tr><th>#</th><th>Customer Code</th><th>Model</th><th>Product</th><th>Specification</th><th>Color</th><th>QTY</th><th>Unit Price</th><th>Amount</th></tr>';
    $sumQty=0;$sumAmt=0;
    foreach($items as $i=>$it){
        $it=qopi_arr($it); $prod=qopi_arr($it['product'] ?? []);
        $qty=(float)($it['qty'] ?? 0); $price=(float)($it['price'] ?? ($it['unit_price'] ?? 0)); $amt=(float)($it['amount'] ?? ($qty*$price));
        $sumQty+=$qty; $sumAmt+=$amt;
        echo '<tr><td>'.($i+1).'</td><td>'.qopi_h($it['customer_code'] ?? '').'</td><td>'.qopi_h(qopi_pick($prod,['code','model','product_model'],'')).'</td><td>'.qopi_h(qopi_pick($prod,['name','product_name'],'')).'</td><td>'.nl2br(qopi_h(qopi_spec($it))).'</td><td>'.qopi_h($it['color'] ?? '').'</td><td class="num">'.qopi_num($qty).'</td><td class="num">'.qopi_money($price).'</td><td class="num">'.qopi_money($amt).'</td></tr>';
    }
    echo '<tr><th colspan="6">Total</th><th>'.qopi_num($sumQty).'</th><th>'.qopi_h($currency).'</th><th>'.qopi_money($sumAmt).'</th></tr></table>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html><head><meta charset="utf-8"><title><?php echo qopi_h($title.' '.$orderNo); ?></title>
<style>
@page{size:A4 portrait;margin:0}*{box-sizing:border-box}body{margin:0;background:#e5e7eb;color:#111827;font-family:"Times New Roman",Arial,"Microsoft YaHei",sans-serif}.paper{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:18mm 14mm 16mm}.top{display:grid;grid-template-columns:1fr 72mm;gap:10mm;align-items:start}.company h1{font-size:16pt;margin:0 0 5mm;font-weight:400}.from{font-size:9pt;line-height:1.35;white-space:pre-line}.stamp{text-align:center;color:#0000a0;font-weight:700;font-size:8.5pt;white-space:pre-line}.doc-title{text-align:center;font-size:17pt;font-weight:700;margin:8mm 0 5mm}.meta{width:72mm;border-collapse:collapse;font-size:8.5pt}.meta td{border:1.1px solid #000;padding:1.3mm 2mm}.meta td:first-child{font-weight:700;text-align:right;width:34mm}.to{font-size:9.2pt;line-height:1.35;white-space:pre-line;margin-top:5mm}.items{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:8mm;font-size:7.2pt;line-height:1.18}.items th,.items td{border:1.1px solid #000;padding:1.1mm;text-align:center;vertical-align:middle}.items th{font-weight:700}.items .spec{text-align:left;white-space:pre-line;line-height:1.2}.items .img{width:18mm;height:18mm;object-fit:contain}.summary{margin-top:5mm;display:grid;grid-template-columns:1fr 72mm;gap:8mm;align-items:start}.bank{background:#f3f4f6;padding:2.5mm;font-size:7.8pt;line-height:1.3;white-space:pre-line}.total{border:1.1px solid #000;padding:2.5mm;font-size:10pt;line-height:1.6}.terms{margin-top:4mm;border:1px solid #999;padding:2.5mm;font-size:7.8pt;line-height:1.35;white-space:pre-line}.sign{margin-top:14mm;text-align:center;font-size:9pt}.no-print{position:sticky;top:0;background:#111827;color:#fff;padding:10px 14px;text-align:right}.no-print button{border:0;background:#2563eb;color:#fff;border-radius:8px;padding:8px 13px;font-weight:700;cursor:pointer}@media print{body{background:#fff}.paper{margin:0;width:210mm;min-height:297mm}.no-print{display:none}.items tr{break-inside:avoid;page-break-inside:avoid}}
</style></head><body>
<div class="no-print"><button onclick="window.print()">打印 / 另存为 PDF</button></div>
<div class="paper">
  <div class="top"><div class="company"><h1><?php echo qopi_h($company); ?></h1><div class="from"><?php echo qopi_h($from); ?></div></div><div><div class="stamp"><?php echo qopi_h(qopi_pick($header,['stamp'],'')); ?></div><table class="meta"><tr><td><?php echo $currency==='RMB'?'订单号':'PI Number'; ?></td><td><?php echo qopi_h($orderNo); ?></td></tr><tr><td><?php echo $currency==='RMB'?'订单日期':'PI Date'; ?></td><td><?php echo qopi_h($date); ?></td></tr><tr><td>Currency</td><td><?php echo qopi_h($currency); ?></td></tr><?php if($sourceQuote!==''){ ?><tr><td>Source Quote</td><td><?php echo qopi_h($sourceQuote); ?></td></tr><?php } ?></table></div></div>
  <div class="doc-title"><?php echo qopi_h($title); ?></div>
  <div class="to"><b><?php echo $currency==='RMB'?'客户':'To'; ?>:</b><br><?php echo qopi_h(qopi_pick($customer,['company','name','customer_name'],'')); ?><?php if(qopi_pick($customer,['contact','primary_contact'],'')!=='') echo "\nContact: ".qopi_h(qopi_pick($customer,['contact','primary_contact'],'')); ?><?php if(qopi_pick($customer,['country'],'')!=='') echo "\nCountry: ".qopi_h(qopi_pick($customer,['country'],'')); ?><?php if(qopi_pick($customer,['address1','address'],'')!=='') echo "\n".qopi_h(qopi_pick($customer,['address1','address'],'')); ?></div>
  <table class="items"><thead><tr><th style="width:7mm">#</th><th style="width:22mm">Picture</th><th style="width:24mm">Customer Code</th><th style="width:24mm">Model</th><th>Specification</th><th style="width:16mm">Color</th><th style="width:16mm">QTY</th><th style="width:18mm">Unit Price</th><th style="width:20mm">Amount</th></tr></thead><tbody>
<?php $sumQty=0;$sumAmt=0; foreach($items as $i=>$it){ $it=qopi_arr($it); $prod=qopi_arr($it['product'] ?? []); $qty=(float)($it['qty'] ?? 0); $price=(float)($it['price'] ?? ($it['unit_price'] ?? 0)); $amt=(float)($it['amount'] ?? ($qty*$price)); $sumQty+=$qty; $sumAmt+=$amt; $img=qopi_pick($prod,['image','product_image'],''); ?>
<tr><td><?php echo $i+1; ?></td><td><?php if($img!==''){ ?><img class="img" src="<?php echo qopi_h($img); ?>"><?php } ?></td><td><?php echo qopi_h($it['customer_code'] ?? ''); ?></td><td><?php echo qopi_h(qopi_pick($prod,['code','model','product_model'],'')); ?></td><td class="spec"><?php echo qopi_h(qopi_spec($it)); ?></td><td><?php echo qopi_h($it['color'] ?? ''); ?></td><td><?php echo qopi_num($qty); ?></td><td><?php echo qopi_money($price); ?></td><td><?php echo qopi_money($amt); ?></td></tr>
<?php } ?>
<tr><td colspan="6" style="text-align:right;font-weight:700">Total</td><td><?php echo qopi_num($sumQty); ?></td><td><?php echo qopi_h($currency); ?></td><td><?php echo qopi_money($sumAmt); ?></td></tr>
  </tbody></table>
  <div class="summary"><div><?php if($bankText!==''){ ?><div class="bank"><?php echo qopi_h($bankText); ?></div><?php } if($extraTerms!==''){ ?><div class="terms"><?php echo qopi_h($extraTerms); ?></div><?php } ?></div><div class="total"><b>Total Amount</b><br><?php echo qopi_h($currency).' '.qopi_money($sumAmt); ?><br><small>Quantity: <?php echo qopi_num($sumQty); ?> PCS</small></div></div>
  <div class="sign">For and on behalf of<br><br><b><?php echo qopi_h($company); ?></b></div>
</div>
</body></html>
