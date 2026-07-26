<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/views/quote_standard.php');
$script = (string)file_get_contents($root . '/assets/js/quote_center.js');

$buttonSelectors = [
    'data-draft-save',
    'data-quote-output="preview"',
    'data-quote-output="print"',
    'data-quote-output="pdf"',
    'data-quote-output="excel"',
    'data-quote-output="send"',
    'data-submit-approval',
    'data-open-product',
    'data-add-line',
    'data-batch-qty',
    'data-batch-discount',
    'data-configure',
    'data-remove-line',
    'data-config-close',
    'data-apply-config',
];
foreach ($buttonSelectors as $selector) {
    if (!str_contains($view, $selector) && !str_contains($script, $selector)) {
        fwrite(STDERR, "FAIL: standard quote button missing {$selector}\n");
        exit(1);
    }
}

foreach ([
    "configToggle?.addEventListener('change'",
    "event.target.closest('[data-configure]')",
    "event.target.closest('[data-remove-line]')",
    "$('[data-apply-config]', editor)?.addEventListener",
    "$('[data-draft-save]', editor)?.addEventListener",
    "$('[data-submit-approval]', editor)?.addEventListener",
    "outputButtons.forEach",
] as $binding) {
    if (!str_contains($script, $binding)) {
        fwrite(STDERR, "FAIL: standard quote handler missing {$binding}\n");
        exit(1);
    }
}

foreach (['data-standard-sidebar', 'data-summary-panel', 'data-config-panel'] as $target) {
    if (!str_contains($view, $target)) {
        fwrite(STDERR, "FAIL: standard quote target missing {$target}\n");
        exit(1);
    }
}
foreach (['for="standard-config-toggle"', 'id="standard-config-toggle"', '.standard-config-toggle:checked'] as $fallback) {
    $style = (string)file_get_contents($root . '/assets/css/app.css');
    if (!str_contains($view . $style, $fallback)) {
        fwrite(STDERR, "FAIL: native add-product fallback missing {$fallback}\n");
        exit(1);
    }
}

echo "PASS: standard quote button and target contract verified.\n";
