<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/crm.php');
$css = file_get_contents($root . '/assets/crm/crm.css');
$js = file_get_contents($root . '/assets/crm/crm.js');
$api = file_get_contents($root . '/crm_customer.php');
if ($page === false || $css === false || $js === false || $api === false) {
    fwrite(STDERR, "Cannot read CRM customer country filter sources\n");
    exit(1);
}

$markers = [
    [$page, 'data-filter-country-search', '高级筛选国家手输搜索框'],
    [$page, 'data-filter-country multiple', '国家 / 地区支持多选'],
    [$page, 'data-filter-level multiple', '客户等级支持多选'],
    [$page, 'data-country-search', '国家 option 带模糊搜索索引'],
    [$page, 'data-city-country', '城市 option 带所属国家索引'],
    [$page, '输入国家中文 / 英文 / 代码查找', '国家搜索输入提示'],
    [$page, '回车追加第一项', '多选国家可搜索追加'],
    [$css, '.customer-filter-group input, .customer-filter-group select', '高级筛选输入框与下拉统一样式'],
    [$css, '.customer-filter-group select option[hidden] { display: none; }', '隐藏不匹配国家选项'],
    [$js, 'normalizeFilterValues: function (value)', '筛选多选值统一清洗'],
    [$js, 'selectedFilterValues: function (selector)', '读取多选下拉值'],
    [$js, 'bindCountryFilterSearch: function ()', '绑定国家搜索交互'],
    [$js, 'filterCountryOptions: function (keyword)', '国家下拉实时过滤'],
    [$js, 'filterCityOptionsForCountry: function (countryValue, clearInvalid)', '城市按国家联动过滤'],
    [$js, 'syncCityFilterForCountry: function (clearInvalid)', '国家变更同步城市'],
    [$js, 'if (selected && selected.hidden) select.value = \'\';', '城市不属于国家时自动清空'],
    [$js, 'resolveCountrySearchSelection: function ()', '应用筛选前自动选择匹配国家'],
    [$js, 'countries: countries', '前端发送国家数组'],
    [$js, 'levels: levels', '前端发送客户等级数组'],
    [$js, 'this.setCountryFilterValue(countries)', '高级筛选状态同步回国家多选控件'],
    [$js, 'this.setSelectValues(\'[data-filter-level]\', levels)', '高级筛选状态同步回等级多选控件'],
    [$js, 'this.setCityFilterValue(advanced.city || \'\')', '高级筛选状态同步回城市控件'],
    [$js, 'event.key === \'Enter\'', '回车选择第一项'],
    [$api, 'function crm_customer_filter_values($value): array', '后端支持数组/逗号多选参数'],
    [$api, '$levelValues = crm_customer_filter_values($input[\'levels\'] ?? ($input[\'level\'] ?? \'\'));', '后端兼容等级多选与旧参数'],
    [$api, '$countryValues = crm_customer_filter_values($input[\'countries\'] ?? ($input[\'country\'] ?? \'\'));', '后端兼容国家多选与旧参数'],
    [$api, 'c.level IN ({$placeholders})', '等级多选使用 IN 查询'],
    [$api, 'implode(\' OR \', $countryParts)', '国家多选使用 OR 查询'],
];

foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing customer country filter search marker: {$label}\n");
        exit(1);
    }
}

echo "crm_customer_country_filter_search_contract ok\n";
