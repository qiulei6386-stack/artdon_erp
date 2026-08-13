<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/website_inquiry_staging_bridge.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read bridge source.\n");
    exit(1);
}

$required = [
    "function gz_bridge_complete_inquiry",
    "'inquiry.status_changed'",
    "Only replied or closed inquiry status can complete linked tasks.",
    "SET status='done', updated_at=NOW()",
    "SET status='done', completed_at=COALESCE(completed_at,NOW())",
    "SET status='done', progress=100, completed_at=COALESCE(completed_at,NOW())",
    "linked_system='website_inquiry'",
    "linked_table='website_inquiry_staging'",
    "status NOT IN ('done','cancelled')",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Missing status-sync contract: {$needle}\n");
        exit(1);
    }
}

echo "website inquiry status sync contract passed\n";
