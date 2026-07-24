<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Contracts;

interface LegacyAuthContract extends LegacyAdapterContract
{
    /** @return array{authenticated:bool,user:?array,status:string} */
    public function currentUser(): array;
}
