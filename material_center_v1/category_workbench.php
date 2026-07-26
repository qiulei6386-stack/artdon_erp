<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$map=['chips'=>'material/chip.php','optics'=>'material/optical.php','profiles'=>'material/profile.php','mounting'=>'material/connector.php','accessories'=>'material/accessories.php','packaging'=>'material/packaging.php'];
$key=(string)($_GET['category']??'');if(!isset($map[$key])){http_response_code(404);exit('类别不存在');}
header('Location: '.mc_url($map[$key]),true,302);exit;
