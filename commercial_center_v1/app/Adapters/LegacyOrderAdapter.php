<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyOrderAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['quote_sales_orders', 'quote_sales_order_items'];
    public function name(): string { return '旧订单'; }
}
