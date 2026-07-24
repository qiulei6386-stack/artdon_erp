<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\AdapterRegistry;
use Artdon\CommercialCenter\Services\DatabaseHealthService;

$database = (new DatabaseHealthService())->check();
$adapters = (new AdapterRegistry())->statuses();

if (($database['database'] ?? '') !== 'artdon_new_erp') {
    fwrite(STDERR, "Unexpected database.\n");
    exit(1);
}
if (count($adapters) !== 10) {
    fwrite(STDERR, "Adapter registry is incomplete.\n");
    exit(1);
}
echo "PASS: bootstrap, database health, and 10 adapter checks completed.\n";
