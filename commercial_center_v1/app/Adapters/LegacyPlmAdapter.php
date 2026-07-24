<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class LegacyPlmAdapter extends AbstractLegacyReadOnlyAdapter
{
    protected array $requiredTables = ['plm_projects', 'plm_models'];
    public function name(): string { return 'PLM'; }
}
