<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyProductAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['naming_models', 'quote_products'];
    public function name(): string { return '产品与命名'; }
}
