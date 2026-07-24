<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyQuotationAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['quote_orders', 'quote_products'];
    public function name(): string { return '旧报价'; }
}
