<?php
// Opt-in isolated schema only. Does not load application configuration.
if(getenv('CRM_PHASE1_MYSQL_TEST')!=='1')exit(2);
$socket=getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(!preg_match('~^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]+/mysql.sock$~',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/',$schema))exit(2);
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require dirname(__DIR__).'/includes/bom_material_read.php';function bmcheck($ok,$msg){if(!$ok)throw new RuntimeException($msg);}
date_default_timezone_set('Asia/Shanghai');
bmcheck((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Fresh schema');
$pdo->exec("CREATE TABLE bom_materials(id INT PRIMARY KEY,category VARCHAR(100),brand VARCHAR(100),name VARCHAR(100),model VARCHAR(100),spec VARCHAR(100),price DECIMAL(12,4),unit VARCHAR(20),supplier VARCHAR(100),keyword VARCHAR(100),weight_kg_per_m DECIMAL(12,4),raw_bar_length_m DECIMAL(12,4),material_grade VARCHAR(100),created_at DATETIME,updated_at DATETIME,is_active TINYINT,image MEDIUMTEXT) CHARACTER SET utf8mb4");
$st=$pdo->prepare('INSERT INTO bom_materials VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$image=str_repeat('A',2*1024*1024);
for($i=1;$i<=121;$i++)$st->execute([$i,'电源','品牌','伊戈尔 高频 '.$i,'M-'.$i,'test',7,'PCS','HiddenSupplier','key',0,0,'',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),1,$image]);unset($image);
$pdo->exec("UPDATE bom_materials SET is_active=0 WHERE id=121");
$r=bmr_read($pdo,['page'=>2,'page_size'=>20],true,true);bmcheck($r['total']===120&&count($r['materials'])===20&&$r['pages']===6&&$r['page']===2,'Actual pagination');
bmcheck(!isset($r['materials'][0]['image'])&&strlen(json_encode($r))<20000,'Large images never enter JSON');
bmcheck(bmr_read($pdo,['keyword'=>'伊戈尔 高频 120'],true,true)['total']===1,'Search beyond page one');
bmcheck(bmr_read($pdo,['keyword'=>"%' OR 1=1 --"],true,true)['total']===0,'Literal SQL search');
bmcheck(bmr_read($pdo,['keyword'=>'HiddenSupplier'],true,false)['total']===0,'Hidden supplier not searchable');
$hidden=bmr_read($pdo,[],false,false)['materials'][0];bmcheck($hidden['price']===''&&$hidden['supplier']==='','Sensitive fields hidden');
try{bmr_read($pdo,['supplier'=>'HiddenSupplier'],true,false);throw new LogicException('Hidden filter allowed');}catch(RuntimeException $e){}
bmcheck(bmr_read($pdo,['page'=>999,'page_size'=>20],true,true)['page']===6,'Clamp deleted last page');
bmcheck(bmr_read($pdo,['date'=>'yesterday'],true,true)['total']===0,'Date filter');
$im=imagecreatetruecolor(1800,1000);imagefill($im,0,0,imagecolorallocate($im,200,0,0));ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);$src='data:image/png;base64,'.base64_encode($png);
$pdo->prepare('UPDATE bom_materials SET image=? WHERE id=1')->execute([$src]);$thumb=bmr_image($pdo,1);$info=getimagesizefromstring(base64_decode(explode(',',$thumb['image'],2)[1]));bmcheck(max($info[0],$info[1])<=160,'Thumbnail bounds');bmcheck(bmr_image($pdo,1,true)['image']===$src,'Original unchanged');
// Execute the actual update block with a summary payload and verify original byte-for-byte.
$code=file_get_contents(dirname(__DIR__).'/bom_api.php');preg_match('/\/\/ Summaries do not contain original images\.[^\n]*\n([\s\S]*?)\n        \}else\{/',$code,$m);bmcheck(isset($m[1]),'Save block extraction');
function artdon_sso_can($system,$perm){return true;}
$id=1;$d=['id'=>1,'image_unchanged'=>true];$category='电源';$brand='品牌';$name='Edited';$model='M-1';$spec='test';$price=8;$unit='PCS';$supplier='HiddenSupplier';$keyword='key';$image='';eval($m[1]);bmcheck(bmr_image($pdo,1,true)['image']===$src,'Summary edit preserves original');
$d=['id'=>1,'image'=>''];eval($m[1]);bmcheck(bmr_image($pdo,1,true)['image']==='','Explicit remove clears image');
$d=['id'=>1,'image'=>$src];$image=$src;eval($m[1]);bmcheck(bmr_image($pdo,1,true)['image']===$src,'Explicit upload replaces image');
bmcheck(memory_get_peak_usage(true)<64*1024*1024,'128MiB budget');echo 'BOM isolated MySQL pagination, Unicode/literal filters, permissions, large data, thumbnails and original-preserving edit passed; peak='.memory_get_peak_usage(true)."\n";
