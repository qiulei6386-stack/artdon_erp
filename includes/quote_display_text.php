<?php
// Pure quotation rendering. Legacy frozen text and order snapshots keep their semantics.
function qdt_description_lines(string $text): array {
    $lines=[];
    foreach(preg_split('/\R/u',$text) as $line){
        $line=trim(preg_replace('/^\s*\d+\.(?:\s+|$)/u','',$line));
        if($line===''||preg_match('/^(?:Power|Beam\s*Angle|CCT|CRI|IP|功率|角度|色温|显指)\s*[:：]/iu',$line)||preg_match('/^(?:IP\s*[0-9][A-Z0-9]*|[0-9]{3,5}\s*K|CRI\s*[0-9]{1,3})$/iu',$line))continue;
        if(preg_match('/^LED\s*[:：]/iu',$line))$line=preg_replace('/(?:\s+(?:[0-9]{3,5}\s*K|CRI\s*[0-9]{1,3}))+$/iu','',$line);
        $lines[]=trim($line);
    }
    return $lines;
}
function qdt_render_manual(array $item): ?string {
    $p=$item['product']??[];
    if(!is_array($p)||!is_string($p['quote_spec_display_override']??null))return null;
    if(!empty($item['is_order_snapshot'])&&is_string($item['specification']??null))return $item['specification'];
    if(($p['quote_spec_display_mode']??'')!=='live_parameters')return $p['quote_spec_display_override'];
    $lines=qdt_description_lines($p['quote_spec_display_override']);
    $power=trim((string)($item['power']??$p['power']??''));
    $core=preg_replace('/[^0-9.\-+xX\/]/u','',preg_replace('/w$/iu','',preg_replace('/\s+/u','',str_replace(['瓦','ｗ','Ｗ'],'W',$power))));
    if($core!==''&&preg_match('/^\d+(?:\.\d+)?(?:[Xx\/\-]\d+(?:\.\d+)?)*$/',$core))$power=strtoupper($core).'W';
    elseif(preg_match('/w$/iu',$power))$power=preg_replace('/w$/iu','W',$power);
    $beam=str_replace('x','X',preg_replace('/[^0-9.\-xX\/]/u','',(string)($item['beam_angle']??$item['beamAngle']??'')));
    if($beam!==''&&preg_match('/^\d+(?:\.\d+)?(?:[X\/\-]\d+(?:\.\d+)?)*$/',$beam))$beam.='°';
    $cct=strtoupper(preg_replace('/\s+/u','',trim((string)($item['cct']??''))));if($cct!==''&&!preg_match('/K$/',$cct))$cct.='K';
    $cri=strtoupper(preg_replace('/\s+/u','',preg_replace('/^CRI\s*[:：-]?\s*/iu','',trim((string)($item['cri']??'')))));if($cri!=='')$cri='CRI'.$cri;
    $led=false;foreach($lines as &$line){if(preg_match('/^LED\s*[:：]/iu',$line)){$line=trim($line.' '.$cct.' '.$cri);$led=true;}}unset($line);
    if($power!=='')$lines[]='Power: '.$power;
    if($beam!=='')$lines[]='Beam Angle: '.$beam;
    if(!$led&&$cct!=='')$lines[]='CCT: '.$cct;
    if(!$led&&$cri!=='')$lines[]='CRI: '.substr($cri,3);
    $ip=trim((string)($item['ip']??''));
    if($ip!=='')$lines[]=preg_match('/^ip/iu',$ip)?strtoupper(preg_replace('/\s+/u','',$ip)):'IP'.preg_replace('/[^0-9A-Za-z]/u','',$ip);
    $remarks=is_array($item['quote_remarks']??null)?$item['quote_remarks']:[];
    if(!$remarks&&!empty($item['extra_spec']))$remarks=preg_split('/\n+/u',(string)$item['extra_spec']);
    foreach(array_slice(array_values(array_filter(array_map('trim',$remarks),static fn($x)=>$x!=='')),0,4) as $remark)$lines[]=$remark;
    return implode("\n",array_map(static fn($i,$line)=>($i+1).'. '.$line,array_keys($lines),$lines));
}
