<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap.php';
use Artdon\MaterialCenter\Services\PowerStandardizationService;

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$user=mc_current_user();if(!$user){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'需要统一登录'],JSON_UNESCAPED_UNICODE);exit;}
$service=new PowerStandardizationService();$action=(string)($_GET['action']??$_POST['action']??'');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'&&$action==='detail'){echo json_encode(['ok'=>true,'data'=>$service->detail((int)($_GET['id']??0))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
    if($_SERVER['REQUEST_METHOD']!=='POST'||!function_exists('verify_csrf')||!verify_csrf()){http_response_code(419);throw new RuntimeException('请求已过期，请刷新页面重试。');}
    if(!(function_exists('is_super_admin')&&is_super_admin())&&!(function_exists('has_permission')&&has_permission('bom.edit'))){http_response_code(403);throw new RuntimeException('需要 BOM 编辑权限。');}
    $uid=(int)$user['id'];
    if($action==='stage_pilot')$data=$service->stagePilot();
    elseif($action==='create_material')$data=['material_id'=>$service->confirmAndCreate((int)$_POST['staging_id'],$_POST,$uid)];
    elseif($action==='link_existing'){$service->linkExisting((int)$_POST['staging_id'],(int)$_POST['material_id'],$uid,(string)($_POST['decision']??'existing_material'));$data=[];}
    elseif($action==='decide_duplicate'){$service->decideDuplicate((int)$_POST['candidate_id'],(string)$_POST['decision'],$uid);$data=[];}
    elseif($action==='reject'){$service->reject((int)$_POST['staging_id'],$uid);$data=[];}
    elseif($action==='save_band')$data=['id'=>$service->saveBand($_POST,$uid)];
    else throw new RuntimeException('未知操作。');
    echo json_encode(['ok'=>true,'data'=>$data,'message'=>'保存成功'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable$e){http_response_code(http_response_code()>=400?http_response_code():422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
