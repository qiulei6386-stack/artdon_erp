<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyDispatchAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['dispatch_next_tasks'];
    public function name(): string { return '派工待办'; }
}
