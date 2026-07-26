<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyAuthAdapter;
use Artdon\MaterialCenter\Security\PermissionService;
use Artdon\MaterialCenter\Services\MaterialMasterService;
try{
 $user=(new LegacyAuthAdapter())->current();(new PermissionService())->require($user,'material_center.export');
 $category=preg_replace('/[^a-z_]/','',(string)($_GET['category']??''));$status=preg_replace('/[^a-z_]/','',(string)($_GET['status']??''));$q=mb_substr(trim((string)($_GET['q']??'')),0,100);$ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??''))))));
 if(count($ids)>500)throw new RuntimeException('单次最多导出 500 条所选物料。');$rows=(new MaterialMasterService())->page($q,$category,$status,1,100)['rows'];if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=db()->prepare("SELECT m.*,c.code category_code,c.name category_name,md.spec_summary FROM mc_materials m JOIN mc_material_categories c ON c.id=m.category_id LEFT JOIN mc_material_metadata md ON md.material_id=m.id WHERE m.deleted_at IS NULL AND m.id IN($marks) ORDER BY m.id");$stmt->execute($ids);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);}
 header('Content-Type:text/csv;charset=UTF-8');header('Content-Disposition:attachment; filename="materials-'.date('Ymd-His').'.csv"');header('Cache-Control:no-store');
 $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['物料编号','类别','名称','品牌','型号','单位','状态','来源','规格']);
 $safe=static function(mixed$value):string{$value=(string)$value;return preg_match('/^[=+\\-@]/',$value)?"'".$value:$value;};
 foreach($rows as$row)fputcsv($out,array_map($safe,[$row['material_code'],$row['category_name'],$row['name'],$row['brand'],$row['model'],$row['unit'],$row['status'],$row['source'],$row['spec_summary']]));
 fclose($out);
}catch(Throwable$e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);header('Content-Type:application/json;charset=utf-8');echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
