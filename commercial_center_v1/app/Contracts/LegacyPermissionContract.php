<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Contracts;

interface LegacyPermissionContract extends LegacyAdapterContract
{
    /** @return array{allowed:bool,source:string,status:string} */
    public function check(string $permission): array;
}
