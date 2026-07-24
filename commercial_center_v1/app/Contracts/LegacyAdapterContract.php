<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Contracts;

interface LegacyAdapterContract
{
    public function name(): string;

    /** @return array{name:string,status:string,detail:string,read_only:bool} */
    public function status(): array;
}
