<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

Artdon\PromotionCenter\PmSchema::apply(db());
fwrite(STDOUT, "pm_groups / pm_group_members / pm_audit_logs / pm_undo 已就绪\n");
