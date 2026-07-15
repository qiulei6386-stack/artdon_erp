<?php
/** Artdon Datasheet Center V2.0 BOM material bridge */
declare(strict_types=1);
require_once __DIR__.'/datasheet_lib.php';
try{
    ds_init();
    ds_require_perm('select_material','读取BOM物料库');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>true,'data'=>ds_bom_materials($_GET),'msg'=>'BOM物料桥接'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>false,'msg'=>$e->getMessage(),'data'=>null),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
