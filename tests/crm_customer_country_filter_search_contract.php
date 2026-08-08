<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/crm.php');
$css = file_get_contents($root . '/assets/crm/crm.css');
$js = file_get_contents($root . '/assets/crm/crm.js');
if ($page === false || $css === false || $js === false) {
    fwrite(STDERR, "Cannot read CRM customer country filter sources\n");
    exit(1);
}

$markers = [
    [$page, 'data-filter-country-search', '高级筛选国家手输搜索框'],
    [$page, 'data-country-search', '国家 option 带模糊搜索索引'],
    [$page, 'data-city-country', '城市 option 带所属国家索引'],
    [$page, '输入国家中文 / 英文 / 代码查找', '国家搜索输入提示'],
    [$css, '.customer-filter-group input, .customer-filter-group select', '高级筛选输入框与下拉统一样式'],
    [$css, '.customer-filter-group select option[hidden] { display: none; }', '隐藏不匹配国家选项'],
    [$js, 'bindCountryFilterSearch: function ()', '绑定国家搜索交互'],
    [$js, 'filterCountryOptions: function (keyword)', '国家下拉实时过滤'],
    [$js, 'filterCityOptionsForCountry: function (countryValue, clearInvalid)', '城市按国家联动过滤'],
    [$js, 'syncCityFilterForCountry: function (clearInvalid)', '国家变更同步城市'],
    [$js, 'if (selected && selected.hidden) select.value = \'\';', '城市不属于国家时自动清空'],
    [$js, 'resolveCountrySearchSelection: function ()', '应用筛选前自动选择匹配国家'],
    [$js, 'setCountryFilterValue((this.filterState.advanced || {}).country || \'\')', '高级筛选状态同步回国家控件'],
    [$js, 'setCityFilterValue((this.filterState.advanced || {}).city || \'\')', '高级筛选状态同步回城市控件'],
    [$js, 'event.key === \'Enter\'', '回车选择第一项'],
];

foreach ($markers as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing customer country filter search marker: {$label}\n");
        exit(1);
    }
}

echo "crm_customer_country_filter_search_contract ok\n";
