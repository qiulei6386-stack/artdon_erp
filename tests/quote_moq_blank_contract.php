<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/quotation.php');
$api = file_get_contents($root . '/quote_api.php');

$checks = [
    'quote editor MOQ input has no hardcoded 200 default' =>
        strpos($page, '<input id="moq" class="q-small-input" type="number" min="0" max="99999" step="1" inputmode="numeric">') !== false
        && strpos($page, '<input id="moq" class="q-small-input" type="number" min="0" max="99999" step="1" value="200"') === false,
    'product editor MOQ input has no hardcoded 200 default' =>
        strpos($page, '<label>MOQ</label><input id="pMoq" type="number"></div>') !== false
        && strpos($page, '<label>MOQ</label><input id="pMoq" type="number" value="200"></div>') === false,
    'new quote and clear editor keep MOQ blank' =>
        strpos($page, "\$('moq').value='';fillOptionSelect('color',colorItems(),'');") !== false
        && strpos($page, "\$('moq').value=200") === false,
    'selecting product and loading item do not fallback MOQ to 200' =>
        strpos($page, "\$('moq').value=S.product.moq??'';") !== false
        && strpos($page, "\$('moq').value=it.moq??S.product?.moq??'';") !== false
        && strpos($page, "S.product.moq||200") === false
        && strpos($page, "??200") === false,
    'price policy refresh does not overwrite a blank quote MOQ' =>
        strpos($page, "\$('moq').value=Number(m.moq)") === false,
    'preview respects explicitly blank item MOQ instead of falling back to product MOQ' =>
        strpos($page, "Object.prototype.hasOwnProperty.call(it,'moq')?it.moq:(p.moq||'')") !== false
        && strpos($page, 'it.moq||p.moq||') === false,
    'naming products do not receive synthetic MOQ 200' =>
        strpos($api, "'moq'=>first_existing_val(\$r,['moq','MOQ'],''),") !== false
        && strpos($api, "'moq'=>first_existing_val(\$r,['moq','MOQ'],'200'),") === false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, "quote MOQ blank contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "quote MOQ blank contract passed\n";

