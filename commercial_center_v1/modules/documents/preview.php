<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
require_once __DIR__.'/Services/DocumentTemplateRegistry.php';
require_once __DIR__.'/Services/DocumentRenderService.php';
$type=(string)($_GET['type']??'quotation');
$fixture=require __DIR__.'/Fixtures/legacy_v1_fixture.php';
try{echo (new Artdon\CommercialCenter\Modules\Documents\Services\DocumentRenderService())->render($type,$fixture);}
catch(Throwable $e){http_response_code(404);echo 'Document template unavailable.';}
