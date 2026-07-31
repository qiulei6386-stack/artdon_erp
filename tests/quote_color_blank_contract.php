<?php
declare(strict_types=1);

$root = getenv('QUOTATION_CONTRACT_ROOT') ?: dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');

$checks = [
    'color options include a real blank value'
        => strpos($page, "{id:'',value:'',label:'空白'}") !== false,
    'option renderer preserves explicit empty option values'
        => strpos($page, 'function optionValue(o){') !== false
            && strpos($page, "Object.prototype.hasOwnProperty.call(o,'value')?String(o.value??'')") !== false
            && strpos($page, 'value="${esc(optionValue(o))}"') !== false,
    'blank default is treated as an explicit selected value'
        => strpos($page, 'let hasDef=arguments.length>=3;') !== false
            && strpos($page, "let old=hasDef?String(def??''):String(el.value||'');") !== false,
    'quote editor no longer defaults empty color to White'
        => strpos($page, "fillOptionSelect('color',colors,\$('color')?.value??'')") !== false
            && strpos($page, "fillOptionSelect('color',colorItems(),S.product.color??'')") !== false
            && strpos($page, "fillOptionSelect('color',colorItems(),'')") !== false,
    'historical item blank color is not overwritten by product color'
        => strpos($page, "let itemColor=Object.prototype.hasOwnProperty.call(it,'color')?it.color:(S.product?.color??'');") !== false,
    'product library color can also be blank'
        => strpos($page, "fillOptionSelect('pColor',colors,\$('pColor')?.value??'')") !== false
            && strpos($page, "fillOptionSelect('pColor',colorItems(),'')") !== false,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "missing contract: {$label}\n");
        exit(1);
    }
}

if (strpos($page, "fillOptionSelect('color',colorItems(),'White')") !== false
    || strpos($page, "fillOptionSelect('pColor',colorItems(),'White')") !== false
    || strpos($page, "fillOptionSelect('color',colorItems(),it.color||S.product?.color||'White')") !== false) {
    fwrite(STDERR, "legacy forced White color default still exists\n");
    exit(1);
}

echo "quote color blank contract passed\n";
