<?php
// Pure quotation display ordering. No I/O, current master data, or saved writes.
function qso_trim(string $value): string {
    return preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value) ?? $value;
}
function qso_flag($value): bool {
    return $value !== null && $value !== false && $value !== 0 && $value !== '' && $value !== '0'
        && (!is_array($value) || count($value) > 0);
}
function qso_excluded(array $item): bool {
    $p = is_array($item['product'] ?? null) ? $item['product'] : [];
    return qso_flag($item['is_order_snapshot'] ?? null) || qso_flag($item['is_material_sale'] ?? null)
        || qso_flag($item['is_virtual_item'] ?? null) || ($item['item_type'] ?? '') === 'virtual'
        || ($item['product_type'] ?? '') === 'virtual' || ($item['product_type'] ?? '') === 'material'
        || qso_flag($item['virtual_type'] ?? null) || strpos((string)($p['id'] ?? ''), 'mat-') === 0
        || (array_key_exists('shippable', $item) && in_array($item['shippable'], [false, 0, '0', 'false'], true));
}
function qso_rank(string $line): int {
    static $ranks = [
        'series'=>0, 'seriesname'=>0, 'productname'=>0, 'product'=>0, '系列'=>0, '系列名称'=>0, '产品名称'=>0, '品名'=>0,
        'power'=>10, 'ratedpower'=>10, 'wattage'=>10, '功率'=>10, '额定功率'=>10,
        'size'=>20, 'dimension'=>20, 'dimensions'=>20, 'overallsize'=>20, '尺寸'=>20, '外形尺寸'=>20, '灯具尺寸'=>20,
        'cutout'=>30, 'cutoutsize'=>30, '开孔'=>30, '开孔尺寸'=>30,
        'beamangle'=>40, 'beam'=>40, '角度'=>40, '光束角'=>40, '光束角度'=>40,
        'optic'=>50, 'optics'=>50, 'lens'=>50, 'reflector'=>50, '光学'=>50, '透镜'=>50, '反光杯'=>50,
        'led'=>60, 'cob'=>60, 'ledchip'=>60, 'ledchips'=>60, 'chip'=>60, '芯片'=>60, '灯珠'=>60, '光源'=>60,
        'adapter'=>70, 'connector'=>70, '接头'=>70, '连接头'=>70, '连接器'=>70, '适配器'=>70,
        'leddriver'=>80, 'driver'=>80, 'powersupply'=>80, '电源'=>80, '驱动'=>80, '驱动电源'=>80,
        'accessories'=>90, 'accessory'=>90, 'extra'=>90, '附件'=>90, '配件'=>90, '客户可选附件'=>90,
        'cct'=>100, 'colourtemperature'=>100, 'colortemperature'=>100, '色温'=>100,
        'cri'=>110, 'ra'=>110, '显指'=>110, '显色指数'=>110,
        'ip'=>120, 'iprating'=>120, 'ingressprotection'=>120, '防护等级'=>120, '防水等级'=>120
    ];
    $text = qso_trim($line);
    if (preg_match('/^([^:：]+)[:：]/u', $text, $label)) {
        $key = preg_replace('/[ \t\x{3000}_-]+/u', '', strtolower(qso_trim($label[1])));
        return $ranks[$key] ?? 130;
    }
    if (preg_match('/^IP[ \t]*[0-9][A-Z0-9]*[ \t]*$/i', $text)) return 120;
    if (preg_match('/^(?:CRI|Ra)[ \t]*[0-9]{1,3}[ \t]*$/i', $text)) return 110;
    if (preg_match('/^(?:CCT[ \t]*)?[0-9]{3,5}[ \t]*K[ \t]*$/i', $text)) return 100;
    return 130;
}
function qso_remarks(array $item, string $source): array {
    $out = [];
    if (is_array($item['quote_remarks'] ?? null)) {
        foreach ($item['quote_remarks'] as $remark) {
            if (is_string($remark)) {
                $remark = qso_trim(str_replace(["\r\n", "\r"], "\n", $remark));
                if ($remark !== '') $out[] = $remark;
            }
        }
    }
    if (!$out && is_string($item['extra_spec'] ?? null) && qso_trim($item['extra_spec']) !== qso_trim($source)) {
        foreach (preg_split('/\n+/', str_replace(["\r\n", "\r"], "\n", $item['extra_spec'])) as $remark) {
            $remark = qso_trim($remark);
            if ($remark !== '') $out[] = $remark;
        }
    }
    return $out;
}
function qso_order_spec(string $text, array $item = []): string {
    if ($text === '' || qso_excluded($item)) return $text;
    preg_match_all('/\r\n|\r|\n/', $text, $matches);
    $endings = $matches[0];
    foreach ($endings as $ending) if ($ending !== $endings[0]) return $text;
    $newline = $endings[0] ?? "\n";
    $lines = explode($newline, $text);
    $first = 0; $last = count($lines) - 1;
    while ($first <= $last && qso_trim($lines[$first]) === '') $first++;
    while ($last >= $first && qso_trim($lines[$last]) === '') $last--;
    $blocks = [];
    for ($i = $first; $i <= $last; $i++) {
        if (preg_match('/^([ \t\x{3000}]*)([0-9]+)\.([ \t\x{3000}]+)(.*)$/u', $lines[$i], $m)) {
            if ($m[2] !== (string)(count($blocks) + 1) || qso_trim($m[4]) === '') return $text;
            $blocks[] = ['index'=>count($blocks), 'indent'=>$m[1], 'space'=>$m[3], 'lines'=>[$m[4]], 'rank'=>qso_rank($m[4]), 'remark'=>false];
        } else {
            if (!$blocks || preg_match('/^[ \t\x{3000}]*[0-9]+\.[^0-9\s\x{3000}]/u', $lines[$i])) return $text;
            $blocks[count($blocks) - 1]['lines'][] = $lines[$i];
        }
    }
    if (count($blocks) < 2) return $text;
    $tail = qso_remarks($item, $text);
    for ($b = count($blocks) - 1, $r = count($tail) - 1; $b >= 0 && $r >= 0; $b--, $r--) {
        if (qso_trim(implode("\n", $blocks[$b]['lines'])) !== $tail[$r]) break;
        $blocks[$b]['remark'] = true;
        $blocks[$b]['rank'] = 130;
    }
    if (!$blocks[0]['remark'] && $blocks[0]['rank'] === 130 && !preg_match('/[:：]/u', $blocks[0]['lines'][0])) $blocks[0]['rank'] = 0;
    $ordered = $blocks;
    usort($ordered, static function (array $a, array $b): int { return ($a['rank'] <=> $b['rank']) ?: ($a['index'] <=> $b['index']); });
    $changed = false;
    foreach ($ordered as $index => $block) if ($block['index'] !== $index) { $changed = true; break; }
    if (!$changed) return $text;
    $body = [];
    foreach ($ordered as $index => $block) $body[] = $block['indent'].($index + 1).'.'.$block['space'].implode($newline, $block['lines']);
    return implode($newline, array_merge(array_slice($lines, 0, $first), $body, array_slice($lines, $last + 1)));
}
