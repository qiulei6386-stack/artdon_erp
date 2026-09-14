<?php
// Produces synthetic files only, outside the live website, as an unprivileged OS user.
if(PHP_SAPI!=='cli'||getenv('QUOTE_MAIL_RENDER_TEST')!=='1'||strpos(realpath(dirname(__DIR__)),'/tmp/quote-mail-test-')!==0)throw new RuntimeException('Isolated source required');
require __DIR__.'/quote_mail_contract.php';
function crm_mail_datasheet_chrome_bin(){return '/usr/bin/google-chrome';}
$dir=$argv[1]??'';
if(!preg_match('#^/tmp/quote-mail-[a-f0-9]{24}$#D',$dir)||!is_dir($dir))throw new RuntimeException('Private fixture output required');
$s=qfixture();
$image=imagecreatetruecolor(120,80);$bg=imagecolorallocate($image,232,242,255);imagefill($image,0,0,$bg);imagefilledellipse($image,60,40,45,45,imagecolorallocate($image,40,100,220));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$items=json_decode($s['items_json'],true);$items[0]['product']['image']='data:image/png;base64,'.base64_encode($bytes);$s['items_json']=json_encode($items);
if(($argv[2]??'')==='long'){
    $items=array_fill(0,32,$items[0]);foreach($items as $i=>&$item){$item['product']['code']='QA-LAMP-'.($i+1);$item['product']['description']='Acceptance lamp with a long specification, dimensions and finishes';}unset($item);
    $s=array_replace($s,qm_calculate($items,'USD',['type'=>'discount_amount','value'=>2.5]));$s['items_json']=json_encode($items);$s['bank_json']=json_encode(['text'=>'Acceptance bank information - not a real bank account','extra_terms'=>'Acceptance conditions. No real business transaction.']);
}
$start=microtime(true);$files=qmail_files($s,['pdf','excel'],$dir);
foreach($files as $f)echo $f['name'].' '.$f['size']." bytes\n";
echo 'Render seconds '.round(microtime(true)-$start,3).' peak '.memory_get_peak_usage(true)."\n";
