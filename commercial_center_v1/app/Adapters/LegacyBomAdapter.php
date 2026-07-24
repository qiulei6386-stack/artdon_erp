<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyBomAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['bom_projects', 'bom_materials'];
    public function name(): string { return 'BOM'; }
}
