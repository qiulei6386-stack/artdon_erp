<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyCustomerAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['crm_customers'];
    public function name(): string { return 'CRM 客户'; }
}
