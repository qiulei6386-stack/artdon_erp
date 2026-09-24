<?php
function qsd_h($v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function qsd_title(array $doc,string $type): string{return 'QB-'.(int)$doc['id'].'-'.strtoupper($type).'-R'.(int)$doc['version'];}
function qsd_state(array $doc): string {
    if($doc['state']==='cancelled')return 'CANCELLED / 已取消';
    if(empty($doc['issued']))return 'DRAFT / 草稿 - 未签发';
    return (int)$doc['version']!==(int)$doc['current_version']?'SUPERSEDED / 历史签发版 - 已替代':'ISSUED / 已签发';
}
require_once __DIR__.'/quote_batch_template_adapter.php';
// Export-only thumbnails: load one source at a time, never rewrite archived images.
function qsd_item_thumbnail(PDO $pdo,int $id,string $table='quote_shipment_items'): string {
    if(!in_array($table,['quote_shipment_items','quote_sales_order_items'],true))throw new InvalidArgumentException('Invalid image source');
    $json="IF(JSON_VALID(item_json),item_json,'{}')";$images=["NULLIF(image,'')"];
    foreach(['image','product_image','image_url','product.image','product.image_display','product.product_image','product.main_image','product.image_url'] as $path)$images[]="NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($json,'$.$path')),'null'),'')";
    $expr='COALESCE('.implode(',',$images).",'')";
    $st=$pdo->prepare("SELECT IF(OCTET_LENGTH($expr)>16777216,'__OVERSIZE__',$expr) FROM $table WHERE id=?");$st->execute([$id]);$src=(string)$st->fetchColumn();
    if($src==='__OVERSIZE__')throw new RuntimeException('产品图片过大，超过安全导出上限（明细 '.$id.'）；原图未改动');
    return qsd_thumbnail($src);
}
function qsd_thumbnail(string $src): string {
    if(stripos($src,'data:')!==0)return $src;
    if(!preg_match('#^data:image/(?:png|jpe?g|gif|webp);base64,#i',$src,$m))throw new RuntimeException('不支持的单证图片格式');
    $bytes=base64_decode(substr($src,strlen($m[0])),true);$info=$bytes!==false?@getimagesizefromstring($bytes):false;
    if(!$info||$info[0]*$info[1]>12000000||strlen($bytes)>12582912)throw new RuntimeException('单证图片异常或尺寸过大；原图未改动');
    if(!function_exists('imagecreatefromstring'))throw new RuntimeException('缺少图片缩略图组件');
    $source=@imagecreatefromstring($bytes);if(!$source)throw new RuntimeException('单证图片解码失败');
    try{$scale=min(1,256/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$thumb=imagecreatetruecolor($w,$h);imagefill($thumb,0,0,imagecolorallocate($thumb,255,255,255));imagecopyresampled($thumb,$source,0,0,0,0,$w,$h,$info[0],$info[1]);ob_start();imagejpeg($thumb,null,88);$out=ob_get_clean();imagedestroy($thumb);return 'data:image/jpeg;base64,'.base64_encode($out);}finally{imagedestroy($source);}
}
function qsd_remote_bytes(string $src,float $deadline): string {
    $u=parse_url($src);$host=strtolower($u['host']??'');$path=$u['path']??'';
    if(!in_array($host,['artdonlighting.com','www.artdonlighting.com'],true)||!in_array($u['scheme']??'',['http','https'],true)||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||(isset($u['port'])&&(int)$u['port']!==443)||!preg_match('#^/uploads/website/products/[0-9]{4}/[0-9]{2}/[A-Za-z0-9_.-]+\.(?:jpe?g|png|gif|webp)$#iD',$path))throw new RuntimeException('图片地址不在已验证的官网资源范围');
    $seconds=min(6,(int)floor($deadline-microtime(true)));if($seconds<1)throw new RuntimeException('官网图片加载超时，请重试');
    $addresses=gethostbynamel($host)?:[];$ip='';foreach($addresses as $address)if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){$ip=$address;break;}
    if($ip==='')throw new RuntimeException('官网图片地址解析异常');
    $body='';$curl=curl_init('https://'.$host.$path);
    curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>$seconds,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_WRITEFUNCTION=>function($c,$part)use(&$body){if(strlen($body)+strlen($part)>8*1024*1024)return 0;$body.=$part;return strlen($part);}]);
    try{$ok=curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);if(!$ok||$code!==200)throw new RuntimeException('官网图片暂时无法读取，请重试（不会下载缺图单证）');}finally{curl_close($curl);}
    return $body;
}
function qsd_image(string $src,float $deadline): string {
    if(stripos($src,'data:')===0)return qsd_thumbnail($src);
    $host=strtolower((string)parse_url($src,PHP_URL_HOST));
    if(in_array($host,['artdonlighting.com','www.artdonlighting.com'],true))$bytes=qsd_remote_bytes($src,$deadline);
    else{
        if($host&&!in_array($host,['novlight.com','www.novlight.com'],true))throw new RuntimeException('单证含未经验证的外部图片');
        $path=preg_replace('#^/artdon_erp/#','/',rawurldecode((string)parse_url($src,PHP_URL_PATH)));$root=dirname(__DIR__);$file=realpath($root.'/'.ltrim($path,'/'));
        if(!$file||!preg_match('#^'.preg_quote($root,'#').'/(uploads|storage|assets)/#',$file)||!is_file($file)||filesize($file)>8*1024*1024)throw new RuntimeException('单证图片不可读取，未生成不完整PDF');$bytes=file_get_contents($file);
    }
    $info=@getimagesizefromstring($bytes);if(!$info)throw new RuntimeException('单证图片格式异常');
    return qsd_thumbnail('data:'.$info['mime'].';base64,'.base64_encode($bytes));
}
function qsd_pdf(string $html): string {
    require_once __DIR__.'/quote_mail.php';$chrome='';foreach(['/usr/bin/google-chrome','/usr/bin/google-chrome-stable','/usr/bin/chromium','/usr/bin/chromium-browser'] as $bin)if(is_executable($bin)){$chrome=$bin;break;}
    if(!$chrome||!is_executable('/usr/bin/timeout')||!function_exists('proc_open'))throw new RuntimeException('PDF生成器不可用，请使用预览页打印/保存PDF并联系管理员');
    if(strlen($html)>24*1024*1024)throw new RuntimeException('单证过大，请分批下载');
    $dir=sys_get_temp_dir().'/quote-mail-'.bin2hex(random_bytes(12));if(!mkdir($dir,0700))throw new RuntimeException('无法创建单证临时目录');
    try{
        // No scripts, remote requests or credentials are passed to the renderer.
        $html=preg_replace('#<script\b[^>]*>.*?</script>#is','',$html);
        $font=dirname(__DIR__).'/assets/fonts/ARSMaqLigTr.otf';
        if(is_file($font)&&filesize($font)<=2097152)$html=str_replace('url("assets/fonts/ARSMaqLigTr.otf")','url("data:font/otf;base64,'.base64_encode(file_get_contents($font)).'")',$html);
        $imageCache=[];$deadline=microtime(true)+15;
        $html=preg_replace_callback('#<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>#i',function($m)use(&$imageCache,$deadline){$src=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');$key=hash('sha256',$src);if(!isset($imageCache[$key]))$imageCache[$key]=qsd_image($src,$deadline);return '<img style="max-width:12mm;max-height:12mm" src="'.qsd_h($imageCache[$key]).'">';},$html);
        $csp='<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:;">';$html=preg_replace('#<html\b[^>]*>#i','$0'.$csp,$html,1);
        file_put_contents($dir.'/doc.html',$html);file_put_contents($dir.'/input','');
        qmail_run(['/usr/bin/timeout','35',$chrome,'--headless','--disable-gpu','--disable-dev-shm-usage','--disable-background-networking','--no-first-run','--no-pdf-header-footer','--user-data-dir='.$dir.'/chrome','--print-to-pdf='.$dir.'/doc.pdf','file://'.$dir.'/doc.html'],$dir.'/input',$dir.'/stdout',$dir.'/stderr');
        $bytes=is_file($dir.'/doc.pdf')?file_get_contents($dir.'/doc.pdf'):'';if(strncmp($bytes,'%PDF-',5)!==0||strlen($bytes)<100||strlen($bytes)>20*1024*1024)throw new RuntimeException('PDF生成未完成，请重试');return $bytes;
    }finally{qmail_remove_temp($dir);}
}
function qsd_download_pdf(PDO $pdo,string $html,string $name): void {
    $s=$pdo->query("SELECT GET_LOCK('quote_shipment_pdf_render',0)");if(!(int)$s->fetchColumn())throw new RuntimeException('其他单证正在生成，请稍后重试');
    try{$pdf=qsd_pdf($html);}finally{$pdo->query("SELECT RELEASE_LOCK('quote_shipment_pdf_render')");}
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_.-]/','_',$name).'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;
}
