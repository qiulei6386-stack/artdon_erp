<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
use Artdon\CommercialCenter\Adapters\LegacyBomAdapter;
use Artdon\CommercialCenter\Adapters\LegacyCustomerAdapter;
use Artdon\CommercialCenter\Adapters\LegacyDispatchAdapter;
use Artdon\CommercialCenter\Adapters\LegacyEmailAdapter;
use Artdon\CommercialCenter\Adapters\LegacyOrderAdapter;
use Artdon\CommercialCenter\Adapters\LegacyPermissionAdapter;
use Artdon\CommercialCenter\Adapters\LegacyPlmAdapter;
use Artdon\CommercialCenter\Adapters\LegacyProductAdapter;
use Artdon\CommercialCenter\Adapters\LegacyQuotationAdapter;
use Artdon\CommercialCenter\Contracts\LegacyAdapterContract;

final class AdapterRegistry
{
    /** @return list<LegacyAdapterContract> */
    public function all(): array
    {
        return [
            new LegacyAuthAdapter(),
            new LegacyPermissionAdapter(),
            new LegacyCustomerAdapter(),
            new LegacyProductAdapter(),
            new LegacyBomAdapter(),
            new LegacyPlmAdapter(),
            new LegacyQuotationAdapter(),
            new LegacyOrderAdapter(),
            new LegacyDispatchAdapter(),
            new LegacyEmailAdapter(),
        ];
    }

    public function statuses(): array
    {
        return array_map(static fn(LegacyAdapterContract $adapter): array => $adapter->status(), $this->all());
    }
}
