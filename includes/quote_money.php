<?php
/** One monetary contract for saved quotations, approval and outgoing documents.
 * No catalogue lookup, exchange-rate lookup, I/O or historical-data rewriting.
 */
function qm_currency($v): string {
    $v = strtoupper(trim((string)$v));
    if ($v === 'CNY' || $v === '人民币') $v = 'RMB';
    if (!preg_match('/^[A-Z]{3}$/', $v)) throw new RuntimeException('报价币种无效，请重新选择');
    return $v;
}
function qm_number($v, string $label): float {
    if (!is_numeric($v) || !is_finite((float)$v) || abs((float)$v) > 1000000000) throw new RuntimeException($label.'不是有效数字或超出允许范围');
    return (float)$v;
}
function qm_cents($v): int { return (int)round(qm_number($v, '金额') * 100, 0, PHP_ROUND_HALF_UP); }
function qm_row_cents($qty, $price): int {
    // Quantities: 3 decimals; unit prices: 4 decimals. Multiply integers, round once per row.
    $q = (int)round(qm_number($qty, '数量') * 1000, 0, PHP_ROUND_HALF_UP);
    $p = (int)round(qm_number($price, '单价') * 10000, 0, PHP_ROUND_HALF_UP);
    if ($q < 0) throw new RuntimeException('数量不能小于零');
    if ($q && abs($p) > intdiv(PHP_INT_MAX - 50000, $q)) throw new RuntimeException('单行金额超出允许范围');
    $n = $q * $p;
    $cents = intdiv(abs($n) + 50000, 100000) * ($n < 0 ? -1 : 1);
    if (abs($cents) > 100000000000) throw new RuntimeException('单行金额超出允许范围');
    return $cents;
}
function qm_virtual(array $it): bool {
    return !empty($it['is_virtual_item']) || ($it['item_type']??'') === 'virtual' || ($it['product_type']??'') === 'virtual'
        || (array_key_exists('shippable',$it) && in_array($it['shippable'],[false,0,'0','false'],true));
}
function qm_calculate(array $items, $currency, $adjustment = []): array {
    $currency = qm_currency($currency);
    if (!$items) throw new RuntimeException('报价没有完整明细，已停止保存/审核');
    if (is_string($adjustment)) $adjustment = json_decode($adjustment, true);
    if (!is_array($adjustment)) throw new RuntimeException('整单调整格式无效');
    $type = $adjustment['type']??'none';
    if (!in_array($type, ['none','discount_amount','discount_percent','surcharge_amount'], true)) throw new RuntimeException('整单调整类型无效');
    $value = abs(qm_number($adjustment['value']??0, '整单调整'));
    $subtotal = 0; $qty = 0; $out = [];
    foreach ($items as $i => $it) {
        if (!is_array($it) || !array_key_exists('price', $it) || !array_key_exists('qty', $it)) throw new RuntimeException('第'.($i+1).'行缺少当前单价/数量，请重新打开核对');
        if (qm_currency($it['currency']??$currency) !== $currency) throw new RuntimeException('第'.($i+1).'行币种与报价不一致，禁止自动猜测换算');
        $p = round(qm_number($it['price'], '单价'), 4, PHP_ROUND_HALF_UP);
        $discount = strtolower((string)($it['virtual_type']??''))==='discount' || strtoupper((string)($it['product']['code']??$it['product_code']??''))==='DISCOUNT';
        $q = round(qm_number($it['qty'], '数量'), 3, PHP_ROUND_HALF_UP);
        if ($p < 0 && !(qm_virtual($it) && $discount)) throw new RuntimeException('只有折扣行允许负单价');
        if (qm_virtual($it) && $discount && $p > 0) throw new RuntimeException('折扣行单价应为负数');
        $cents = qm_row_cents($q, $p); $subtotal += $cents;
        if (abs($subtotal) > 100000000000) throw new RuntimeException('报价总额超出允许范围');
        $it['qty'] = $q; $it['price'] = $p; $it['unit_price'] = $p;
        $it['currency'] = $currency; $it['amount'] = $cents / 100;
        // Old approval aliases must never become a second price source.
        if (array_key_exists('approved_price', $it)) $it['approved_price'] = $p;
        if (array_key_exists('approved_qty', $it)) $it['approved_qty'] = $q;
        if (!(qm_virtual($it) && in_array($it['count_in_qty']??false, [false,0,'0','false'], true))) $qty += $q;
        $out[] = $it;
    }
    $delta = 0;
    if ($type === 'discount_amount') $delta = -qm_cents($value);
    if ($type === 'surcharge_amount') $delta = qm_cents($value);
    if ($type === 'discount_percent') $delta = -(int)round($subtotal * $value / 100, 0, PHP_ROUND_HALF_UP);
    if ($delta < 0) $delta = max($delta, -max(0, $subtotal));
    return ['items'=>$out,'currency'=>$currency,'qty'=>round($qty,3),'price'=>count($out)===1?$out[0]['price']:0,
        'subtotal_amount'=>$subtotal/100,'adjustment_amount'=>$delta/100,'amount'=>max(0,$subtotal+$delta)/100,
        'adjustment_json'=>json_encode($adjustment, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
}
function qm_assert_totals(array $submitted, array $calculated, bool $required = true): void {
    foreach (['subtotal_amount','adjustment_amount','amount'] as $key) {
        if (!array_key_exists($key,$submitted)) { if ($required) throw new RuntimeException('页面版本过旧，缺少金额校验信息，请保留输入并刷新'); else continue; }
        if (qm_cents($submitted[$key]) !== qm_cents($calculated[$key])) throw new RuntimeException('金额核对失败（'.$key.'）：页面 '.$submitted[$key].'，明细计算 '.$calculated[$key].'。已停止操作，请重新核对，不会保存错误金额');
    }
}
function qm_prepare_save(array &$d): void {
    $items = json_decode((string)($d['items_json']??''), true);
    if (!is_array($items)) throw new RuntimeException('报价明细格式错误');
    $money = qm_calculate($items, $d['currency']??'', $d['adjustment_json']??'{}');
    qm_assert_totals($d, $money);
    if (qm_number($d['exchange_rate']??0, '汇率') <= 0) throw new RuntimeException('汇率必须大于零');
    foreach (['qty','price','currency','subtotal_amount','adjustment_amount','amount','adjustment_json'] as $key) $d[$key] = $money[$key];
    $d['items_json'] = json_encode($money['items'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function qm_revision(array $q): string {
    $ctx = hash_init('sha256');
    foreach (['id','quote_no','customer_id','customer_json','currency','exchange_rate','items_json','qty','price','subtotal_amount','adjustment_amount','adjustment_json','amount','approval_status','approval_log_json','submitted_at','approved_at','rejected_at','header_json','bank_json','template_json','commission_json'] as $k) {
        $v = (string)($q[$k]??''); hash_update($ctx, $k.':'.strlen($v).':'); hash_update($ctx, $v);
    }
    return hash_final($ctx);
}
function qm_require_revision(array $q, $revision): void {
    if (!is_string($revision) || !hash_equals(qm_revision($q), $revision)) throw new RuntimeException('这张报价已变化或页面版本过旧。已阻止覆盖/审核；请保留当前输入，重新打开最新报价核对后再提交');
}
function qm_validate_snapshot(array $snap): array {
    $items = json_decode((string)($snap['items_json']??''),true);
    if (!is_array($items)) throw new RuntimeException('审核快照明细无效');
    $m = qm_calculate($items,$snap['currency']??'', ($snap['adjustment_json']??'')?:'{}');
    qm_assert_totals($snap,$m,false);
    foreach ($items as $i=>$it) {
        if (isset($it['amount']) && qm_cents($it['amount']) !== qm_cents($m['items'][$i]['amount'])) throw new RuntimeException('历史审核快照行金额不一致，已停止输出；须人工核对并另存版本，未改历史数据');
    }
    return $m;
}
function qm_audit_summary(array $q): array {
    $out = array_intersect_key($q,array_flip(['id','quote_no','currency','exchange_rate','qty','price','subtotal_amount','adjustment_amount','adjustment_json','amount','approval_status']));
    $items = $q['items']??json_decode((string)($q['items_json']??'[]'),true);
    $out['items'] = [];
    foreach (is_array($items)?$items:[] as $i=>$it) {
        if (!is_array($it)) continue;
        $out['items'][] = ['index'=>$i,'product_id'=>$it['product']['id']??$it['product_id']??null] + array_intersect_key($it,array_flip(['currency','qty','price','unit_price','approved_price','amount','manual_price','price_multiplier']));
    }
    return $out;
}

function qm_order_totals(array $items, array $d): array {
    $currency=qm_currency($d['currency']??''); $sum=0; $qty=0;
    foreach($items as $i=>$it){
        $base=json_decode((string)($it['item_json']??'{}'),true)?:[];
        $price=$it['price']??$it['unit_price']??null;
        $q=$it['qty']??null;
        $cents=qm_row_cents($q,$price);
        if(isset($it['currency']) && qm_currency($it['currency'])!==$currency) throw new RuntimeException('订单第'.($i+1).'行币种不一致');
        if(isset($it['amount']) && qm_cents($it['amount'])!==$cents) throw new RuntimeException('订单第'.($i+1).'行金额不一致');
        foreach(['qty'=>$q,'price'=>$price] as $key=>$value){
            if(isset($base[$key]) && abs((float)$base[$key]-(float)$value)>0.000001) throw new RuntimeException('订单明细与行快照不一致，请重新生成预览');
        }
        $sum+=$cents;
        if(!(qm_virtual($it+$base) && in_array($it['count_in_qty']??$base['count_in_qty']??false,[false,0,'0','false'],true))) $qty+=(float)$q;
    }
    if(qm_cents($d['amount']??null)!==$sum) throw new RuntimeException('订单总额与明细不一致，已停止转单');
    if(abs(qm_number($d['qty']??null,'总数量')-$qty)>0.000001) throw new RuntimeException('订单数量与明细不一致，已停止转单');
    return ['qty'=>$qty,'amount'=>$sum/100];
}
