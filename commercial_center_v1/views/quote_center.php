<?php
declare(strict_types=1);

$quoteMode = in_array((string)($_GET['quote_mode'] ?? ''), ['website','stock','standard','custom'], true)
    ? (string)$_GET['quote_mode']
    : '';
$quickMode = (string)($_GET['quick'] ?? '') === '1';

if ($quoteMode === '') {
    require __DIR__ . '/quote_custom.php';
    return;
}
if ($quoteMode === 'custom') {
    require __DIR__ . '/quote_custom_editor.php';
    return;
}
require __DIR__ . '/quote_' . $quoteMode . '.php';
