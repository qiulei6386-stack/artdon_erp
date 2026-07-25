<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Adapters\LegacyPermissionAdapter;

/** Read-only boundary for the naming center. No legacy product writes are allowed. */
final class ProductSyncService
{
    public function status(array $authentication): array
    {
        if (!$authentication['authenticated']) return ['status'=>'unauthenticated','permission'=>'naming.view'];
        $access = (new LegacyPermissionAdapter())->check('naming.view');
        return ['status'=>$access['allowed'] ? 'available' : 'forbidden','permission'=>'naming.view','source'=>'naming_models','write_mode'=>'not_configured'];
    }
}
