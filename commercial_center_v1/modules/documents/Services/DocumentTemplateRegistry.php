<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Modules\Documents\Services;
final class DocumentTemplateRegistry
{
    public const TYPES=['quotation','order_usd','order_cny','packing_list','commercial_invoice'];
    public function resolve(string $type): string
    {
        if(!in_array($type,self::TYPES,true)) throw new \InvalidArgumentException('Unknown document type.');
        return dirname(__DIR__).'/Templates/'.$type.'/legacy_v1/template.php';
    }
    public function version(): string{return 'legacy_v1';}
}
