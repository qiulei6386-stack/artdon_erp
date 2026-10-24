<?php
declare(strict_types=1);

/** Pure calculation helpers. Caller loads the existing quote_money.php contract. */
function mq_text(array $row): string {
    $parts = [];
    foreach (['category','type','kind','brand','name','material_name','materialName','model','code','spec','description','remark','supplier','分类','类型','品牌','名称','物料名称','型号','编码','规格','备注','供应商'] as $key) {
        if (isset($row[$key]) && is_scalar($row[$key])) $parts[] = (string)$row[$key];
    }
    return trim(implode(' ', $parts));
}

function mq_identity_text(array $row): string {
    $parts = [];
    foreach (['category','type','kind','name','material_name','materialName','分类','类型','名称','物料名称'] as $key) {
        if (isset($row[$key]) && is_scalar($row[$key])) $parts[] = (string)$row[$key];
    }
    return trim(implode(' ', $parts));
}

function mq_structural_component(string $text): bool {
    // The presence of “LED” or “driver” on a housing does not make it a light source or supply.
    return preg_match('/光源面(?:盖)?|光源盖|散热器后盖|(?:芯片|灯珠|COB)[\s_-]*(?:支架|固定架|固定座|压环)|(?:电源|驱动(?:器)?)[\s_-]*(?:外壳|外盒|盒|盖|壳|支架|固定架)|(?:led|cob|chip)[\s_-]*(?:holder|housing|cover|bracket)|(?:driver|power[\s_-]*supply)[\s_-]*(?:holder|housing|cover|box|bracket|case|shell)/iu', $text) === 1;
}

function mq_packaging_or_fastener(string $text): bool {
    return preg_match('/纸卡|纸箱|纸盒|内盒|外盒|外箱|彩盒|包装|包材|吸塑|珍珠棉|泡棉|护角|标签|贴纸|说明书|吊牌|卡纸|opp\s*袋|pe\s*袋|poly\s*bag|carton|inner\s*box|outer\s*box|gift\s*box|color\s*box|packing|packaging|label|(?:instruction|user)\s*manual|^manual$|螺丝|螺钉|螺栓|螺母|机牙|介机牙|自攻|牙条|紧固件|平垫|弹垫|垫圈|\bscrews?\b|\bbolts?\b|\bnuts?\b|\bwashers?\b|\bfasteners?\b/iu', $text) === 1;
}

function mq_connection_or_optic(string $text): bool {
    return preg_match('/(?:电源|驱动(?:器)?)[\s_-]*(?:(?:输入|输出|连接|延长)[\s_-]*)?(?:线|接线|接头|端子|插头|连接头)|线材|导线|电线|接线端子|\b(?:driver|power\s*supply)(?:\s+(?:input|output|connection|extension))?[\s_-]+(?:cables?|wires?|leads?|terminals?|plugs?)\b|(?:^|\s)(?:cables?|wires?|leads?)(?:\s|$)|connector|adapter|track\s*head|接头|转接|导轨头|连接器|optic|optics|lens|reflector|dark\s*series|herculux|透镜|反光杯|反光|光学|恒坤|honeycomb|蜂窝|格栅|防眩/iu', $text) === 1;
}

function mq_driver_text(string $text): bool {
    return preg_match('/\bled\s*driver\b|\bdriver\b|power\s*supply|constant\s*current|eaglerise|lifud|tridonic|mean\s*well|电源|驱动|伊戈尔|恒流/iu', $text) === 1;
}

function mq_led_text(string $text): bool {
    return preg_match('/\b(cob|cree|osram|bridgelux|citizen|xpg|xhp|cxb|cxa|vhd|gen8)\b|\bled\s*(chip|module|cob)\b|\b(chip|cob)\s*led\b|芯片|灯珠|普瑞/iu', $text) === 1;
}

function mq_classify(array $row): string {
    $text = mq_text($row);
    // Exclusions use the item's identity, not compatibility details in its spec or remarks.
    // An actual driver may mention an output connector, housing, optics or packaging there.
    $identity = mq_identity_text($row);
    $identity = $identity !== '' ? $identity : $text;
    if ($text === '' || mq_packaging_or_fastener($identity) || mq_structural_component($identity) || mq_connection_or_optic($identity)) return '';
    // Keep the website's ordering: LED Driver is a supply, never an LED chip.
    if (mq_driver_text($identity)) return 'driver';
    if (mq_led_text($identity)) return 'led';
    if (mq_driver_text($text)) return 'driver';
    return mq_led_text($text) ? 'led' : '';
}

function mq_number($value, string $label): float {
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1000000000) {
        throw new RuntimeException($label.'必须是有效非负数字，且不能超出允许范围');
    }
    return (float)$value;
}

function mq_commission_enabled(?array $commission): bool {
    if (!is_array($commission)) return false;
    $enabled = $commission['enabled'] ?? false;
    return $enabled === true || $enabled === 1 || $enabled === '1';
}

/** Split list / commission / net from the external sale price without changing unit_price or amount. */
function mq_commission_money(array $sale, ?array $commission): array {
    if (!function_exists('qm_calculate')) throw new RuntimeException('缺少网站统一金额计算库');
    $qty = mq_number($sale['qty'] ?? 0, '报价数量');
    if ($qty <= 0) throw new RuntimeException('报价数量必须大于零');
    $currency = strtoupper(trim((string)($sale['currency'] ?? '')));
    if ($currency === 'CNY' || $currency === '人民币') $currency = 'RMB';
    if (!in_array($currency, ['RMB', 'USD'], true)) throw new RuntimeException('试算币种无效');
    $unitPrice = mq_number($sale['unit_price'] ?? 0, '对外单价');
    $amount = mq_number($sale['amount'] ?? 0, '对外金额');
    $listAmountInput = null;
    if (is_array($commission) && array_key_exists('order_list_amount', $commission) && $commission['order_list_amount'] !== null && $commission['order_list_amount'] !== '') {
        $listAmountInput = mq_number($commission['order_list_amount'], '订单清单金额');
    }
    if ($listAmountInput !== null && $listAmountInput > 0) {
        $listUnit = round($listAmountInput / $qty, 4, PHP_ROUND_HALF_UP);
        $listMoney = qm_calculate([['qty' => $qty, 'price' => $listUnit, 'currency' => $currency]], $currency);
    } else {
        $listMoney = qm_calculate([['qty' => $qty, 'price' => $unitPrice, 'currency' => $currency]], $currency);
    }
    $listUnitPrice = (float)$listMoney['items'][0]['price'];
    $listAmount = (float)$listMoney['amount'];
    $commissionUnitPrice = 0.0;
    $commissionAmount = 0.0;
    if (mq_commission_enabled($commission)) {
        $mode = strtolower(trim((string)($commission['mode'] ?? '')));
        $value = mq_number($commission['value'] ?? 0, '佣金数值');
        if (!in_array($mode, ['percent', 'fixed', 'per_lamp'], true)) throw new RuntimeException('佣金模式无效');
        if ($mode === 'percent') {
            if ($value > 100) throw new RuntimeException('佣金百分比不能超过100');
            $commissionUnitPrice = round($unitPrice * $value / 100, 4, PHP_ROUND_HALF_UP);
            $commissionMoney = qm_calculate([['qty' => $qty, 'price' => $commissionUnitPrice, 'currency' => $currency]], $currency);
            $commissionAmount = (float)$commissionMoney['amount'];
            $commissionUnitPrice = (float)$commissionMoney['items'][0]['price'];
        } elseif ($mode === 'per_lamp') {
            $commissionUnitPrice = round($value, 4, PHP_ROUND_HALF_UP);
            $commissionMoney = qm_calculate([['qty' => $qty, 'price' => $commissionUnitPrice, 'currency' => $currency]], $currency);
            $commissionAmount = (float)$commissionMoney['amount'];
            $commissionUnitPrice = (float)$commissionMoney['items'][0]['price'];
        } else {
            $commissionAmount = round($value, 2, PHP_ROUND_HALF_UP);
            $commissionUnitPrice = $qty > 0 ? round($commissionAmount / $qty, 4, PHP_ROUND_HALF_UP) : 0.0;
        }
        if ($commissionAmount > $amount + 0.000001) throw new RuntimeException('佣金不能超过对外金额');
    }
    $netUnitPrice = round(max(0, $unitPrice - $commissionUnitPrice), 4, PHP_ROUND_HALF_UP);
    $netMoney = qm_calculate([['qty' => $qty, 'price' => $netUnitPrice, 'currency' => $currency]], $currency);
    return [
        'list_unit_price' => $listUnitPrice,
        'list_amount' => $listAmount,
        'commission_unit_price' => $commissionUnitPrice,
        'commission_amount' => $commissionAmount,
        'net_unit_price' => (float)$netMoney['items'][0]['price'],
        'net_amount' => (float)$netMoney['amount'],
    ];
}

function mq_field_number(array $row, array $keys, string $label, bool $required = false): float {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) return mq_number($row[$key], $label);
    }
    if ($required) throw new RuntimeException($label.'缺失，不能自动按零计算');
    return 0.0;
}

function mq_row_string(array $row, array $keys): string {
    foreach ($keys as $key) if (isset($row[$key]) && is_scalar($row[$key]) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
    return '';
}

function mq_has_field(array $row, array $keys): bool {
    foreach ($keys as $key) if (array_key_exists($key, $row)) return true;
    return false;
}

function mq_shell_breakdown(array $payload, float $publishedCost): array {
    $publishedCost = mq_number($publishedCost, 'BOM版本成本');
    $currency = strtoupper(trim((string)($payload['currency'] ?? 'RMB')));
    if (!in_array($currency, ['RMB','CNY','人民币'], true)) throw new RuntimeException('BOM版本必须提供人民币成本，不能猜测币种换算');
    $rows = $payload['rows'] ?? $payload['rows_json'] ?? null;
    if (is_string($rows)) $rows = json_decode($rows, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($rows) || array_keys($rows) !== array_keys(array_values($rows))) throw new RuntimeException('BOM版本缺少完整物料行');
    if (count($rows) > 3000) throw new RuntimeException('BOM物料行过多，请先核对版本');
    $laborKeys = ['labor','labor_cost','人工费'];
    $otherKeys = ['other','other_cost','package_cost','packing_cost','包装/其它','包装费'];
    $hasLabor = mq_has_field($payload, $laborKeys);
    $hasOther = mq_has_field($payload, $otherKeys);
    if ($hasLabor !== $hasOther) throw new RuntimeException('BOM人工及其他费用资料不完整，请核对版本');
    $labor = mq_field_number($payload, $laborKeys, 'BOM人工费');
    $other = mq_field_number($payload, $otherKeys, 'BOM包装及其他费用');
    $sum = 0.0;
    $removed = ['led'=>0.0, 'driver'=>0.0];
    $quantities = ['led'=>0.0, 'driver'=>0.0];
    $componentRows = []; $notes = []; $models = ['led'=>[], 'driver'=>[]];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) throw new RuntimeException('BOM第'.($index+1).'行格式无效');
        $prefix = 'BOM第'.($index+1).'行';
        $qty = mq_field_number($row, ['qty','quantity','num','数量'], $prefix.'数量', true);
        $price = mq_field_number($row, ['price','unit_price','unitCost','unit_cost','单价'], $prefix.'单价', true);
        $process = mq_field_number($row, ['process','processCost','process_cost','加工费'], $prefix.'加工费');
        $finish = mq_field_number($row, ['finishCost','finish_cost','surfaceCost','surface_cost','表面处理费','处理费1'], $prefix.'表面处理费1');
        $finish2 = mq_field_number($row, ['finishCost2','finish_cost2','surfaceCost2','surface_cost2','处理费2'], $prefix.'表面处理费2');
        $cost = $qty * ($price + $process + $finish + $finish2);
        if (!is_finite($cost)) throw new RuntimeException($prefix.'成本超出允许范围');
        $sum += $cost;
        $kind = mq_classify($row);
        $text = mq_text($row);
        $name = mq_row_string($row, ['name','material_name','materialName','名称','物料名称']);
        $model = mq_row_string($row, ['model','model_no','code','型号','编码']);
        if ($kind !== '') {
            $removed[$kind] += $cost;
            $quantities[$kind] += $qty;
            $componentRows[] = [
                'index'=>$index, 'name'=>$name, 'model'=>$model, 'qty'=>$qty,
                'material_id'=>mq_row_string($row, ['materialId','material_id','mid']),
                'source_price'=>$price, 'process'=>$process, 'finishCost'=>$finish, 'finishCost2'=>$finish2,
                'cost'=>round($cost, 4, PHP_ROUND_HALF_UP), 'kind'=>$kind
            ];
            if ($qty > 0) $models[$kind][$model !== '' ? $model : $name] = true;
            if (mq_driver_text($text) && mq_led_text($text)) $notes[] = '第'.($index+1).'行同时含芯片和电源特征，已按电源识别，请核对：'.($name ?: $model);
        } elseif (preg_match('/光源|\bled\b|\bcob\b|\bchip\b|芯片|灯珠|电源|驱动|driver|power\s*supply|恒流/iu', $text)) {
            // Keep possible covers, holders and unrecognised light-source rows in the shell.
            $notes[] = '第'.($index+1).'行与光源或电源有关但未明确归类，未扣除，请核对：'.($name ?: $model);
        }
    }
    // Reference publications omit separate labor/other fields; their immutable total retains them.
    // Approved snapshots with explicit overhead must instead reconcile all terms exactly.
    if (!is_finite($sum)) throw new RuntimeException('BOM行成本超出允许范围');
    if ($hasLabor) {
        $overhead = $labor + $other;
        if (abs($sum + $overhead - $publishedCost) > 0.000100001) throw new RuntimeException('BOM版本金额与物料、人工及其他费用不一致，请先核对该版本');
    } else {
        $overhead = $publishedCost - $sum;
        if ($overhead < -0.000100001) throw new RuntimeException('BOM物料成本超过发布成本，请先核对该版本');
        $overhead = max(0, $overhead);
    }
    $shell = $publishedCost - $removed['led'] - $removed['driver'];
    if ($shell < -0.000100001) throw new RuntimeException('BOM关键件成本超过版本总成本，请先核对');
    foreach (['led'=>'芯片','driver'=>'电源'] as $kind => $label) {
        if (count($models[$kind]) > 1) $notes[] = 'BOM含多种'.$label.'，替换时请核对每灯总用量和选用型号';
    }
    $notes = array_values(array_unique($notes));
    return [
        'published_cost_rmb'=>round($publishedCost, 4, PHP_ROUND_HALF_UP),
        'retained_overhead_rmb'=>round($overhead, 4, PHP_ROUND_HALF_UP),
        'overhead_source'=>$hasLabor ? 'explicit' : 'publication_residual',
        'shell_cost_rmb'=>round(max(0, $shell), 4, PHP_ROUND_HALF_UP),
        'removed_led_cost'=>round($removed['led'], 4, PHP_ROUND_HALF_UP),
        'removed_driver_cost'=>round($removed['driver'], 4, PHP_ROUND_HALF_UP),
        'led_qty'=>round($quantities['led'], 6, PHP_ROUND_HALF_UP),
        'driver_qty'=>round($quantities['driver'], 6, PHP_ROUND_HALF_UP),
        'component_rows'=>$componentRows, 'needs_review'=>(bool)$notes, 'review_notes'=>$notes
    ];
}

/**
 * config: mode shell/led/full; led/driver {price (RMB),qty (per lamp),...};
 * level {id,name,multiplier}; currency RMB/USD; exchange_rate RMB per USD;
 * qty order quantity; review_confirmed must be boolean true when review is needed.
 * Root resolves IDs and current source prices; this pure function never trusts catalogue input on its own.
 */
function mq_calculate(array $breakdown, array $config): array {
    if (!function_exists('qm_calculate')) throw new RuntimeException('缺少网站统一金额计算库');
    $mode = (string)($config['mode'] ?? '');
    if ($mode === 'led_driver') $mode = 'full';
    if (!in_array($mode, ['shell','led','full'], true)) throw new RuntimeException('请选择空壳、灯具加芯片或灯具加芯片电源');
    $shell = mq_field_number($breakdown, ['shell_cost_rmb'], '空壳成本', true);
    if (!array_key_exists('needs_review', $breakdown) || !is_bool($breakdown['needs_review'])) throw new RuntimeException('缺少BOM关键件核对状态');
    if ($breakdown['needs_review'] && ($config['review_confirmed'] ?? null) !== true) throw new RuntimeException('BOM关键件需要核对，请确认识别及每灯用量后再试算');
    $level = $config['level'] ?? null;
    if (!is_array($level)) throw new RuntimeException('请选择网站报价等级');
    $multiplier = mq_field_number($level, ['multiplier'], '报价倍率', true);
    if ($multiplier <= 0) throw new RuntimeException('报价倍率必须大于零');
    $currency = strtoupper(trim((string)($config['currency'] ?? '')));
    if ($currency === 'CNY' || $currency === '人民币') $currency = 'RMB';
    if (!in_array($currency, ['RMB','USD'], true)) throw new RuntimeException('试算仅支持人民币或美元');
    $rate = mq_field_number($config, ['exchange_rate'], '汇率', true);
    if ($rate <= 0) throw new RuntimeException('汇率必须大于零');
    $qty = round(mq_field_number($config, ['qty'], '报价数量', true), 3, PHP_ROUND_HALF_UP);
    if ($qty <= 0) throw new RuntimeException('报价数量必须大于零，精确到三位小数');
    $parts = []; $partCosts = ['led'=>0.0, 'driver'=>0.0];
    foreach (['led'=>in_array($mode, ['led','full'], true), 'driver'=>$mode === 'full'] as $kind => $enabled) {
        if (!$enabled) continue;
        $part = $config[$kind] ?? null;
        $label = $kind === 'led' ? '芯片' : '电源';
        if (!is_array($part)) throw new RuntimeException('请选择'.$label.'物料');
        $price = mq_field_number($part, ['price'], $label.'物料单价', true);
        $perLamp = round(mq_field_number($part, ['qty'], $label.'每灯用量', true), 3, PHP_ROUND_HALF_UP);
        if ($perLamp <= 0) throw new RuntimeException($label.'每灯用量必须大于零，精确到三位小数');
        $cost = $price * $perLamp;
        if (!is_finite($cost)) throw new RuntimeException($label.'成本超出允许范围');
        $partCosts[$kind] = $cost;
        $parts[$kind] = array_intersect_key($part, array_flip(['id','name','model','brand','spec','unit'])) + ['price'=>$price,'qty'=>$perLamp,'cost_rmb'=>round($cost, 4, PHP_ROUND_HALF_UP)];
    }
    $cost = $shell + $partCosts['led'] + $partCosts['driver'];
    $saleRmb = $cost * $multiplier;
    $unitPrice = round($currency === 'USD' ? $saleRmb / $rate : $saleRmb, 4, PHP_ROUND_HALF_UP);
    if (!is_finite($unitPrice)) throw new RuntimeException('配置售价超出允许范围');
    $money = qm_calculate([['qty'=>$qty,'price'=>$unitPrice,'currency'=>$currency]], $currency);
    $formula = '('.number_format($shell, 4, '.', '').' + '.number_format($partCosts['led'], 4, '.', '').' + '.number_format($partCosts['driver'], 4, '.', '').') × '.number_format($multiplier, 4, '.', '');
    if ($currency === 'USD') $formula .= ' ÷ '.number_format($rate, 6, '.', '');
    $notes = $breakdown['review_notes'] ?? [];
    $warning = is_array($notes) ? implode('；', array_map('strval', $notes)) : '';
    $result = [
        'mode'=>$mode, 'shell_cost_rmb'=>round($shell, 4, PHP_ROUND_HALF_UP),
        'led_cost_rmb'=>round($partCosts['led'], 4, PHP_ROUND_HALF_UP), 'driver_cost_rmb'=>round($partCosts['driver'], 4, PHP_ROUND_HALF_UP),
        'cost_rmb'=>round($cost, 4, PHP_ROUND_HALF_UP), 'multiplier'=>$multiplier,
        'exchange_rate'=>$rate, 'currency'=>$currency, 'unit_price'=>$money['items'][0]['price'],
        'qty'=>$money['qty'], 'amount'=>$money['amount'], 'subtotal_amount'=>$money['subtotal_amount'],
        'price_level'=>array_intersect_key($level, array_flip(['id','name','multiplier'])),
        'components'=>$parts, 'price_source'=>'configuration_cost', 'formula'=>$formula,
        'warning'=>$warning, 'review_confirmed'=>$breakdown['needs_review'] && ($config['review_confirmed'] ?? null) === true,
        'money'=>$money
    ];
    $commission = $config['commission'] ?? null;
    return $result + mq_commission_money([
        'qty'=>$result['qty'], 'unit_price'=>$result['unit_price'], 'amount'=>$result['amount'], 'currency'=>$result['currency'],
    ], is_array($commission) ? $commission : null);
}
