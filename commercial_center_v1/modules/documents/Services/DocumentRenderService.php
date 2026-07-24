<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Modules\Documents\Services;
final class DocumentRenderService
{
    public function render(string $type,array $viewModel): string
    {
        $registry=new DocumentTemplateRegistry();$template=$registry->resolve($type);
        ob_start();$document=$viewModel;$documentType=$type;$templateVersion=$registry->version();require $template;
        return (string)ob_get_clean();
    }
}
