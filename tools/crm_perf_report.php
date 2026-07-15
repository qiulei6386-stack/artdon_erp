<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$defaultLog = $root . '/storage/crm_api_perf.log';
$logFile = $argv[1] ?? $defaultLog;
$limit = max(5, min(100, (int)($argv[2] ?? 30)));

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
while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $row = json_decode($line, true);
    if (!is_array($row)) continue;
    $action = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($row['action'] ?? 'unknown')) ?: 'unknown';
    $elapsed = (int)($row['elapsed_ms'] ?? 0);
    if ($elapsed <= 0) continue;
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
    $totalRows++;
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
echo "Rows: {$totalRows}\n";
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
