<?php
declare(strict_types=1);

/**
 * 易飞镜像物料品牌识别（品名 / 规格 → 展示用品牌字符串）。
 *
 * 规则：
 * - 词典命中时返回规范英文名（见下表 canonical）。
 * - 仅中文别名（科谷、欧切斯、南电、智观）无英文规范名时，返回该中文作为 brand。
 * - 先扫 item_name，再扫 spec；同一条内按词典顺序，先匹配先生效。
 * - 未匹配 → null（调用方输出 JSON null）。
 */
function yf_feed_brand_rules(): array
{
    return array(
        array('brand' => 'Bridgelux', 'needles' => array('普瑞', 'BRIDGELUX', 'BXRE', 'BXKC')),
        array('brand' => 'CITIZEN', 'needles' => array('西铁城', 'CITIZEN', 'CLU')),
        array('brand' => 'CREE', 'needles' => array('科锐', 'CREE', 'CMB', 'CXA')),
        array('brand' => 'Eaglerise', 'needles' => array('伊戈尔', 'Eaglerise')),
        array('brand' => 'Lifud', 'needles' => array('莱福德', 'Lifud', 'LIFUD')),
        array('brand' => 'Tridonic', 'needles' => array('锐高', 'Tridonic', 'TRIDONIC')),
        array('brand' => 'MEAN WELL', 'needles' => array('明纬', 'MEAN WELL', 'MEANWELL')),
        array('brand' => 'OSRAM', 'needles' => array('欧司朗', 'OSRAM')),
        array('brand' => 'Philips', 'needles' => array('飞利浦', 'Philips', 'PHILIPS')),
        array('brand' => 'Luminus', 'needles' => array('朗明纳斯', 'Luminus', 'LUMINUS')),
        array('brand' => '科谷', 'needles' => array('科谷')),
        array('brand' => '欧切斯', 'needles' => array('欧切斯')),
        array('brand' => '南电', 'needles' => array('南电')),
        array('brand' => '智观', 'needles' => array('智观')),
    );
}

function yf_feed_brand_canonical_list(): array
{
    $out = array();
    foreach (yf_feed_brand_rules() as $rule) {
        $out[] = $rule['brand'];
    }
    return $out;
}

function yf_feed_parse_brand(string $itemName, string $spec): ?string
{
    $haystacks = array($itemName, $spec);
    foreach ($haystacks as $hay) {
        if ($hay === '') {
            continue;
        }
        foreach (yf_feed_brand_rules() as $rule) {
            foreach ($rule['needles'] as $needle) {
                if ($needle === '') {
                    continue;
                }
                if (yf_feed_text_has_token($hay, $needle)) {
                    return $rule['brand'];
                }
            }
        }
    }
    return null;
}

/**
 * ASCII 品牌按不区分大小写的子串；中文按原始 UTF-8 子串。
 */
function yf_feed_text_has_token(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 \\-\\.]*$/', $needle)) {
        return stripos($haystack, $needle) !== false;
    }
    return strpos($haystack, $needle) !== false;
}

/**
 * 按 brand 查询参数生成 (item_name OR spec) 的 LIKE 条件片段，供 feed 列表过滤。
 *
 * @return array{sql:string,params:array<int,string>}|null
 */
function yf_feed_brand_sql_filter(string $brand): ?array
{
    $brand = trim($brand);
    if ($brand === '') {
        return null;
    }
    foreach (yf_feed_brand_rules() as $rule) {
        if ($rule['brand'] !== $brand) {
            continue;
        }
        $parts = array();
        $params = array();
        $i = 0;
        foreach ($rule['needles'] as $needle) {
            if ($needle === '') {
                continue;
            }
            $param = ':bf' . $i;
            $i++;
            $parts[] = '(item_name LIKE ' . $param . ' OR spec LIKE ' . $param . ')';
            $params[$param] = '%' . $needle . '%';
        }
        if ($parts === array()) {
            return null;
        }
        return array(
            'sql' => '(' . implode(' OR ', $parts) . ')',
            'params' => $params,
        );
    }
    return null;
}
