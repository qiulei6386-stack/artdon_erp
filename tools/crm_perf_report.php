<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$defaultLog = $root . '/storage/crm_api_perf.log';
$logFile = $defaultLog;
$limit = 30;
$since = '';
$actionFilter = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    } elseif (str_starts_with($arg, '--action=')) {
        $actionFilter = preg_replace('/[^a-zA-Z0-9_\-]/', '', substr($arg, 9)) ?: '';
    } elseif (str_starts_with($arg, '--log=')) {
        $logFile = substr($arg, 6) ?: $defaultLog;
    } elseif ($arg !== '' && $arg[0] !== '-' && $logFile === $defaultLog && is_file($arg)) {
        $logFile = $arg;
    } elseif ($arg !== '' && ctype_digit($arg)) {
        $limit = (int)$arg;
    }
}
$limit = max(5, min(100, $limit));

$sinceTs = 0;
if ($since !== '') {
    if (preg_match('/^(\d+)(m|h|d)$/i', $since, $m)) {
        $unit = strtolower($m[2]);
        $seconds = (int)$m[1] * ($unit === 'm' ? 60 : ($unit === 'h' ? 3600 : 86400));
        $sinceTs = time() - $seconds;
    } else {
        $parsed = strtotime($since);
        if ($parsed !== false) $sinceTs = $parsed;
    }
}

if (!is_file($logFile)) {
    fwrite(STDERR, "CRM performance log not found: {$logFile}\n");
    exit(1);
}

$handle = fopen($logFile, 'rb');
if (!$handle) {
    fwrite(STDERR, "Cannot open CRM performance log: {$logFile}\n");
    exit(1);
}

$stats = [];
$totalRows = 0;
$matchedRows = 0;
while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $row = json_decode($line, true);
    if (!is_array($row)) continue;
    $totalRows++;
    if ($sinceTs > 0) {
        $rowTs = strtotime((string)($row['time'] ?? ''));
        if ($rowTs === false || $rowTs < $sinceTs) continue;
    }
    $action = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($row['action'] ?? 'unknown')) ?: 'unknown';
    if ($actionFilter !== '' && $action !== $actionFilter) continue;
    $elapsed = (int)($row['elapsed_ms'] ?? 0);
    if ($elapsed <= 0) continue;
    $matchedRows++;
    if (!isset($stats[$action])) {
        $stats[$action] = [
            'action' => $action,
            'count' => 0,
            'total_ms' => 0,
            'max_ms' => 0,
            'last_ms' => 0,
            'last_time' => '',
            'samples' => [],
            'memory_max_mb' => 0.0,
        ];
    }
    $stats[$action]['count']++;
    $stats[$action]['total_ms'] += $elapsed;
    $stats[$action]['max_ms'] = max($stats[$action]['max_ms'], $elapsed);
    $stats[$action]['last_ms'] = $elapsed;
    $stats[$action]['last_time'] = (string)($row['time'] ?? '');
    $stats[$action]['samples'][] = $elapsed;
    $stats[$action]['memory_max_mb'] = max((float)$stats[$action]['memory_max_mb'], (float)($row['memory_mb'] ?? 0));
}
fclose($handle);

foreach ($stats as &$item) {
    sort($item['samples']);
    $count = max(1, (int)$item['count']);
    $p95Index = min($count - 1, (int)ceil($count * 0.95) - 1);
    $item['avg_ms'] = (int)round($item['total_ms'] / $count);
    $item['p95_ms'] = (int)$item['samples'][$p95Index];
    unset($item['samples']);
}
unset($item);

usort($stats, static function (array $a, array $b): int {
    if ($a['total_ms'] === $b['total_ms']) return $b['max_ms'] <=> $a['max_ms'];
    return $b['total_ms'] <=> $a['total_ms'];
});

echo "CRM API performance report\n";
echo "Log: {$logFile}\n";
echo "Rows: {$matchedRows} matched / {$totalRows} total\n";
if ($since !== '') echo "Since: {$since}" . ($sinceTs > 0 ? ' (' . date('Y-m-d H:i:s', $sinceTs) . ')' : '') . "\n";
if ($actionFilter !== '') echo "Action: {$actionFilter}\n";
echo "Top {$limit} actions by total elapsed time\n\n";
printf("%-34s %7s %8s %8s %8s %10s %19s\n", 'action', 'count', 'avg', 'p95', 'max', 'mem_max', 'last_time');
printf("%'-100s\n", '');
foreach (array_slice($stats, 0, $limit) as $row) {
    printf(
        "%-34s %7d %7dms %7dms %7dms %9.2fM %19s\n",
        $row['action'],
        $row['count'],
        $row['avg_ms'],
        $row['p95_ms'],
        $row['max_ms'],
        $row['memory_max_mb'],
        $row['last_time'] ?: '-'
    );
}
