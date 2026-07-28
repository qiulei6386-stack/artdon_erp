<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/crm_task_center.php');
if ($source === false) {
    throw new RuntimeException('crm_task_center.php is not readable');
}

$functionStart = strpos($source, 'function crm_quote_followup_save');
$functionEnd = strpos($source, 'function crm_task_detail', $functionStart === false ? 0 : $functionStart);
if ($functionStart === false || $functionEnd === false) {
    throw new RuntimeException('crm_quote_followup_save function boundaries are missing');
}

$function = substr($source, $functionStart, $functionEnd - $functionStart);
$begin = strpos($function, '$pdo->beginTransaction();');
$commit = strrpos($function, '$pdo->commit();');
$log = strpos($function, "crm_log_event('tasks', 'quote_followup_save'");
$reload = strpos($function, '$resultData = crm_quote_followup_context');

if ($begin === false || $commit === false || $log === false || $reload === false) {
    throw new RuntimeException('quote follow-up transaction markers are incomplete');
}
if (!($begin < $commit && $commit < $log && $log < $reload)) {
    throw new RuntimeException('quote follow-up business transaction must commit before operation logging');
}
if (!str_contains($function, 'if ($pdo->inTransaction()) $pdo->rollBack();')) {
    throw new RuntimeException('quote follow-up rollback guard is missing');
}
if (str_contains(substr($function, $begin, $commit - $begin), 'crm_log_event(')) {
    throw new RuntimeException('operation logging must not run inside the quote follow-up transaction');
}

echo "CRM quote follow-up transaction contract: OK\n";
