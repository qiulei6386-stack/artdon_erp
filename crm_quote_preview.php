<?php
/* ARTDON_SSO_GATE_V2_START */
require_once __DIR__.'/includes/artdon_sso_core.php';
artdon_sso_require_page('quote');
/* ARTDON_SSO_GATE_V2_END */

@ini_set('display_errors','0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
require_once __DIR__ . '/includes/bootstrap.php';
try{ $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); }catch(Exception $e){ die('DB ERROR'); }
$id = isset($_GET['quote_id']) ? intval($_GET['quote_id']) : 0;
$quote_no = isset($_GET['quote_no']) ? trim($_GET['quote_no']) : '';
if($id>0){ $st=$pdo->prepare('SELECT * FROM quote_orders WHERE id=? LIMIT 1'); $st->execute([$id]); }
else { $st=$pdo->prepare('SELECT * FROM quote_orders WHERE quote_no=? LIMIT 1'); $st->execute([$quote_no]); }
$q=$st->fetch(PDO::FETCH_ASSOC);
if(!$q){ die('<!doctype html><meta charset="utf-8"><div style="padding:30px;font-family:Arial">报价不存在</div>'); }
function jarr($s){ $a=json_decode((string)$s,true); return is_array($a)?$a:[]; }
function money($n){ return number_format((float)$n,2); }
function firstv($arr,$keys,$def=''){ foreach($keys as $k){ if(isset($arr[$k]) && $arr[$k]!=='' && $arr[$k]!==null) return $arr[$k]; } return $def; }
$c=jarr($q['customer_json']??'');
$items=jarr($q['items_json']??'');
if(!$items){
  $p=jarr($q['product_json']??'');
  if($p){ $items=[['product'=>$p,'qty'=>$q['qty']??1,'price'=>$q['price']??0,'amount'=>$q['amount']??0,'moq'=>$q['moq']??'','color'=>$q['color']??'','extra_spec'=>$q['extra_spec']??'']]; }
}
$currency=$q['currency'] ?: 'USD';
$quoteNo=$q['quote_no'] ?: '';
$quoteDate=$q['quote_date'] ?: date('Y-m-d');
$customerName=firstv($c,['company','name','customer_name'],'Please select customer');
$customerCode=firstv($c,['code','customer_code'],'');
$totalQty=0; $totalAmt=0;
function spec_lines($it){
  $p = (isset($it['product']) && is_array($it['product'])) ? $it['product'] : [];
  $parts = (isset($it['parts']) && is_array($it['parts'])) ? $it['parts'] : [];
  $lines=[];
  $name=firstv($p,['name','product_name','title','code','model'],'');
  if($name) $lines[]=$name;
  foreach(['power'=>'Power','cct'=>'CCT','cri'=>'CRI','size'=>'Size','cutout'=>'Cutout','ip'=>'IP'] as $k=>$label){
    $v = $it[$k] ?? ($p[$k] ?? '');
    if($v!=='') $lines[]=$label.': '.$v;
  }
  if(!empty($parts)){
    foreach($parts as $key=>$part){
      if(!is_array($part)) continue;
      $pn=firstv($part,['name','code','model','title'],'');
      if($pn) $lines[]=ucfirst((string)$key).': '.$pn;
    }
  }
  $extra=$it['extra_spec'] ?? '';
  if($extra) $lines[]=$extra;
  return $lines;
}
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($quoteNo)?> - Quotation</title>
<style>
body{margin:0;background:#eef4fb;font-family:Arial,"Times New Roman","Microsoft YaHei",sans-serif;color:#111}.toolbar{position:sticky;top:0;background:#0f172a;color:#fff;padding:10px 18px;display:flex;gap:10px;align-items:center;z-index:9}.toolbar button{border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer}.paper{width:210mm;min-height:297mm;background:#fff;margin:18px auto;padding:25mm 17mm 18mm;box-shadow:0 6px 22px #0002;box-sizing:border-box}.head{display:grid;grid-template-columns:1.15fr .9fr;gap:25mm;margin-bottom:18mm}.company h1{font-family:"Times New Roman",serif;font-size:26px;margin:0 0 14px;font-weight:500}.company div{font-family:"Times New Roman",serif;font-size:14px;line-height:1.25}.right{text-align:center}.right .cn{color:#0000aa;font-weight:700;font-size:14px}.right h2{font-family:"Times New Roman",serif;font-size:26px;margin:12px 0}.terms{width:100%;border-collapse:collapse;font-family:"Times New Roman",serif;font-size:12px;text-align:left}.terms td{border:1px solid #111;padding:6px 8px}.terms td:first-child{font-weight:bold;text-align:right;width:45%}.to{margin-top:20mm;font-family:"Times New Roman",serif;font-size:14px;line-height:1.35}.quote-table{width:100%;border-collapse:collapse;font-family:"Times New Roman",serif;font-size:12px}.quote-table th,.quote-table td{border:1px solid #111;padding:6px 7px;vertical-align:middle}.quote-table th{text-align:center;font-weight:bold}.quote-table td{text-align:center}.quote-table td.spec{text-align:left;line-height:1.32}.prod-img{max-width:50px;max-height:50px;object-fit:contain}.total-row td{font-weight:bold}.footer{margin-top:18px;font-family:"Times New Roman",serif;font-size:12px}.bank{margin-top:24px;background:#f2f2f2;padding:10px 12px;line-height:1.35}.sig{margin-top:28px}.muted{color:#64748b}.empty{text-align:center;color:#64748b;padding:30px!important}@media print{body{background:#fff}.toolbar{display:none}.paper{box-shadow:none;margin:0;width:210mm;min-height:297mm}@page{size:A4 portrait;margin:0}}
</style></head><body>
<div class="toolbar"><b><?=h($quoteNo)?></b><button onclick="window.print()">打印/PDF</button><button onclick="history.back()">返回CRM</button></div>
<div class="paper">
  <div class="head">
    <div class="company"><h1>Gallin Industrial (HK) Limited</h1><div><b>From:</b><br>Winnie +86-13702507880<br>No.15 Zhihe 3rd street,Yumin,Dongsheng town,<br>Zhongshan City,Guangdong,China<br>Tel:+86-760-22211886 Fax:+86-760-22211890</div><div class="to"><b>To:</b><br><?=h($customerName)?></div></div>
    <div class="right"><div class="cn">GALLIN INDUSTRIAL (HK) LIMITED<br>加 林 实 业 香 港 有 限 公 司</div><h2>Quotation Sheet</h2><table class="terms"><tr><td>PI Number:</td><td><?=h($quoteNo)?></td></tr><tr><td>Payment:</td><td>40% Deposit before production</td></tr><tr><td></td><td>60% payment before shipment</td></tr><tr><td>Price Terms:</td><td>EXWORK</td></tr><tr><td>Quoted Date:</td><td><?=h($quoteDate)?></td></tr><tr><td>Delivery Date:</td><td>25-35Days After Confirmed</td></tr><tr><td>Quoted Valid</td><td>Within 10 days</td></tr></table></div>
  </div>
  <table class="quote-table"><thead><tr><th>Picture</th><th>Size or<br>Drawing(mm)</th><th>Customer<br>Code</th><th>Manufacturer<br>Code</th><th>Specification</th><th>Color</th><th>QTY<br>(pcs)</th><th>Unit<br>Price(<?=h($currency)?>)</th><th>Amount<br>(<?=h($currency)?>)</th><th>MOQ<br>(pcs)</th></tr></thead><tbody>
<?php if(!$items): ?><tr><td colspan="10" class="empty">没有产品明细，请回报价系统检查该报价是否保存了 items_json。</td></tr><?php endif; ?>
<?php foreach($items as $it): $p=(isset($it['product'])&&is_array($it['product']))?$it['product']:[]; $qty=(float)($it['qty']??1); $price=(float)($it['price']??($it['unit_price']??0)); $amt=(float)($it['amount']??($qty*$price)); $totalQty+=$qty; $totalAmt+=$amt; $img=firstv($p,['image','img','picture'],''); ?>
<tr>
<td><?php if($img): ?><img class="prod-img" src="<?=h($img)?>"><?php endif; ?></td>
<td><?=h(firstv($p,['size','dimension','drawing'],''))?></td>
<td><?=h($customerCode)?></td>
<td><?=h(firstv($p,['code','model','manufacturer_code'],''))?></td>
<td class="spec"><?php $n=1; foreach(spec_lines($it) as $line){ echo h($n.'. '.$line).'<br>'; $n++; } ?></td>
<td><?=h($it['color']??($p['color']??''))?></td>
<td><?=h($qty)?></td>
<td><?=money($price)?></td>
<td><?=money($amt)?></td>
<td><?=h($it['moq']??($p['moq']??''))?></td>
</tr>
<?php endforeach; ?>
<tr class="total-row"><td colspan="6"></td><td>Total:<br><?=h($totalQty)?></td><td></td><td><?=money($totalAmt)?></td><td></td></tr>
</tbody></table>
<div class="footer">Remark : all products marked product model number,adaptor wire, color temperature, reflector degree, CRI and product color.</div>
<div class="sig">Signature:........................................................................................................</div>
<div class="bank"><b>Bank Details:</b><br>Beneficiary: Gallin Industrial (HK) Limited<br>Account Number: 000649150<br>Swift code: DIBKHKHH<br>Bank Code: 016<br>Beneficiary Bank: DBS Bank (Hong Kong) Limited</div>
</div></body></html>
