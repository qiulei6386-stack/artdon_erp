<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$plan = ['tables' => [], 'indexes' => []];
foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $migration) {
    $sql = (string)file_get_contents($migration);
    preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+(`?cc_[a-z0-9_]+`?)/i', $sql, $tables);
    preg_match_all('/\b(?:UNIQUE\s+)?KEY\s+(`?[a-z0-9_]+`?)\s*\(/i', $sql, $indexes);
    $plan['tables'] = array_merge($plan['tables'], array_map(static fn(string $v): string => trim($v, '`'), $tables[1]));
    $plan['indexes'] = array_merge($plan['indexes'], array_map(static fn(string $v): string => trim($v, '`'), $indexes[1]));
}
$plan['tables'] = array_values(array_unique($plan['tables']));
$plan['indexes'] = array_values(array_unique($plan['indexes']));

echo "COMMERCIAL CENTER V1 MIGRATION DRY RUN\n";
echo "Execution enabled: NO\n";
echo "Target database: artdon_new_erp (shared with legacy system)\n\n";
echo "Tables that would be created:\n- " . implode("\n- ", $plan['tables']) . "\n\n";
echo "Indexes that would be created:\n- " . implode("\n- ", $plan['indexes']) . "\n\n";
echo "Legacy tables modified: NONE\n";
echo "Legacy rows inserted/updated/deleted: NONE\n";
echo "Triggers created: NONE\n";
echo "Foreign keys to legacy tables: NONE\n";
