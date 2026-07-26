<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Services;
final class LegacyDocumentTemplateAdapter{
 public function orderSnapshot(array $q,string $orderNo):array{$customer=$q['customer_snapshot']??[];$items=[];$qty=0;
  foreach($q['items']??[] as $i=>$it){$custom=$it['custom_fields']??[];$n=(float)($it['quantity']??0);$price=(float)($it['unit_price']??0);$qty+=$n;
   $items[]=['customer_code'=>$custom['customer_model']??'','product_code'=>$custom['manufacturer_code']??($it['sku_code']??$it['model_no']??''),
    'product_name'=>$it['product_name']??$it['description']??'','specification'=>$this->config($it['configuration_snapshot']??[]),
    'color'=>$custom['color']??'','qty'=>$n,'price'=>$price,'unit_price'=>$price,'amount'=>(float)($it['line_amount']??$n*$price),
    'image'=>$it['image_path']??'','product'=>['code'=>$it['model_no']??'','name'=>$it['product_name']??$it['description']??'','image'=>$it['image_path']??''],
    'lead_time'=>$it['lead_time']??'','note'=>$it['customer_note']??'','custom_fields'=>$custom];}
  return ['order_no'=>$orderNo,'quote_no'=>$q['quote_no'],'source_quote_no'=>$q['quote_no'],'quote_date'=>$q['quote_date'],'currency'=>$q['currency'],
   'exchange_rate'=>$q['exchange_rate_snapshot']??1,'customer'=>['company'=>$customer['customer_name']??$customer['customer_name_en']??'',
   'name'=>$customer['customer_name']??$customer['customer_name_en']??'','contact'=>$q['contact_name']??'','country'=>$q['country']??'',
   'email'=>$q['contact_email']??'','phone'=>$q['contact_phone']??''],'header'=>[],'bank'=>[],'template'=>[],'items'=>$items,
   'total'=>['qty'=>$qty,'amount'=>(float)$q['total_amount']],'payment_terms'=>$q['payment_terms']??'','trade_terms'=>$q['trade_terms']??'',
   'customer_note'=>$q['customer_note']??'','internal_note'=>$q['internal_note']??'','commission_amount'=>$q['commission_amount']??0];}
 private function config(mixed $v):string{if(!is_array($v))return trim((string)$v);$out=[];array_walk_recursive($v,static function($x,$k)use(&$out){if(is_scalar($x)&&$x!=='')$out[]=$k.': '.$x;});return implode("\n",$out);}
}
