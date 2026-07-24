<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Services;
use PDO;
final class CommercialModuleService
{
    public function rows(string $module): array
    {
        $map=['inventory'=>['cc_inventory_skus','id,sku_code,product_type,actual_stock,reserved_stock,safety_stock,sellable_stock,in_transit_stock,location,publishable,status'],
          'publishing'=>['cc_channel_packages','id,package_code,public_title,english_name,public_price,currency,moq,lead_time_days,allow_order,inquiry_only,status'],
          'configuration'=>['cc_config_groups','id,group_code,name,is_required,is_multiple,sort_order,status'],
          'rules'=>['cc_compatibility_rules','id,rule_code,name,rule_type,status'],
          'materials_cc'=>['cc_materials','id,material_code,category,brand,model,unit,procurement_price,currency,moq,lead_time_days,status']];
        if(!isset($map[$module]))return [];$x=$map[$module];
        $s=db()->prepare("SELECT {$x[1]} FROM {$x[0]} ORDER BY id DESC LIMIT 100");$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
