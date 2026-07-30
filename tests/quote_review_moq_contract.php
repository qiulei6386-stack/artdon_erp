<?php
declare(strict_types=1);

$root = getenv('QUOTATION_CONTRACT_ROOT') ?: dirname(__DIR__);
$page = (string) file_get_contents($root . '/quotation.php');
$api = (string) file_get_contents($root . '/quote_api.php');

$checks = [
    'review table has MOQ header after cost formula'
        => strpos($page, '<th class="review-cost-th">成本公式</th><th class="review-moq-th">MOQ</th><th>Specification</th>') !== false,
    'review rows render editable MOQ input from quote item'
        => strpos($page, "moq=(it.moq??'')") !== false
            && strpos($page, '<td class="review-moq-cell"><input class="review-moq" type="number"') !== false,
    'review submit collects MOQ into approved item snapshot'
        => strpos($page, "moq=String(tr.querySelector('.review-moq')?.value??'').trim()") !== false
            && strpos($page, 'it.moq=moq;it.approved_moq=moq;') !== false,
    'review CSS allocates a stable MOQ column'
        => strpos($page, '.review-modal .review-table th.review-moq-th,.review-modal .review-table td.review-moq-cell') !== false
            && strpos($page, '.review-modal .review-table input.review-moq') !== false,
    'backend preserves reviewed MOQ during approval merge'
        => strpos($api, 'function quote_review_moq_value($v): string') !== false
            && strpos($api, "\$merged['moq']=\$moq;") !== false
            && strpos($api, "\$merged['approved_moq']=\$moq;") !== false,
    'approval log tracks MOQ changes'
        => strpos($api, "\$oldMoq=quote_review_moq_value") !== false
            && strpos($api, "'old_moq'=>\$oldMoq,'new_moq'=>\$newMoq,'moq_changed'=>\$moqChanged?1:0") !== false
            && strpos($page, "if(c.moq_changed)parts.push('MOQ '") !== false,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "missing contract: {$label}\n");
        exit(1);
    }
}

echo "quote review MOQ contract passed\n";
