<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Domain\PowerSupply;

final class PowerSpecParser
{
    public function parse(array $legacy): array
    {
        $text=trim(implode(' ',array_filter([$legacy['name']??'',$legacy['model']??'',$legacy['spec']??''])));
        $out=[];
        $range=function(string $pattern,string $min,string $max,string $rule)use(&$out,$text){if(preg_match($pattern,$text,$m)){ $out[$min]=$this->result($m[1],$text,'high',$rule);$out[$max]=$this->result($m[2]??$m[1],$text,'high',$rule);}};
        $range('/(?:输入|input)?\s*(\d{2,3})\s*[-~至]\s*(\d{2,3})\s*V(?:AC)?/iu','input_voltage_min_v','input_voltage_max_v','voltage_range_input');
        $range('/(?:输出|output)\s*(\d{1,3})\s*[-~至]\s*(\d{1,3})\s*V/iu','output_voltage_min_v','output_voltage_max_v','voltage_range_output');
        $range('/(\d{2})\s*[-~至]\s*(\d{2})\s*Hz/iu','input_frequency_min_hz','input_frequency_max_hz','frequency_range');
        if(preg_match('/((?:\d{3,4}\s*[\/,，]\s*)+\d{3,4})\s*mA/iu',$text,$listMatch))$currentValues=preg_split('/\D+/',trim($listMatch[1]));
        elseif(preg_match_all('/(?<!\d)(\d{3,4})\s*mA/iu',$text,$m)&&$m[1])$currentValues=$m[1];
        if(!empty($currentValues)){ $values=array_values(array_unique(array_map('intval',$currentValues)));$out['current_options_ma']=$this->result(json_encode($values),$text,'high','current_ma_list');$out['output_current_ma']=$this->result((string)max($values),$text,count($values)===1?'high':'medium','current_ma_max_candidate');if(count($values)>1)$out['is_dip_switch']=$this->result('1',$text,'medium','multiple_current_options');}
        if(preg_match_all('/(?<![\d.])(\d{1,2}(?:\.\d+)?)\s*W\b/iu',$text,$m)&&$m[1]){ $powers=array_map('floatval',$m[1]);$out['nominal_power_w']=$this->result((string)$powers[0],$text,'medium','power_w_first');$out['max_output_power_w']=$this->result((string)max($powers),$text,'high','power_w_max');}
        if(preg_match('/(\d{2,4}(?:\.\d+)?)\s*[x×*]\s*(\d{1,4}(?:\.\d+)?)\s*[x×*]\s*(\d{1,4}(?:\.\d+)?)\s*(?:mm|毫米)?/iu',$text,$m)){foreach(['length_mm'=>1,'width_mm'=>2,'height_mm'=>3] as $key=>$i)$out[$key]=$this->result($m[$i],$text,'high','dimensions_lwh');}
        $modes=['DALI-2'=>'dali_2','DALI'=>'dali','0-10V'=>'0_10v','1-10V'=>'1_10v','Triac'=>'triac','可控硅'=>'triac','Push'=>'push','DMX'=>'dmx','NFC'=>'nfc','不调光'=>'none'];
        foreach($modes as $needle=>$value){if(stripos($text,$needle)!==false){$out['dimming_mode']=$this->result($value,$text,'high','explicit_dimming');break;}}
        if(preg_match('/\bPF\s*[≥>:：]?\s*(0?\.\d+)/iu',$text,$m))$out['power_factor']=$this->result($m[1],$text,'high','power_factor');
        if(preg_match('/(?:效率|efficiency)\s*[≥>:：]?\s*(\d{2,3}(?:\.\d+)?)\s*%/iu',$text,$m))$out['efficiency']=$this->result((string)((float)$m[1]/100),$text,'high','efficiency_percent');
        if(preg_match('/(\d+(?:\.\d+)?)\s*年(?:质保|保修)/u',$text,$m))$out['supplier_warranty_years']=$this->result($m[1],$text,'medium','explicit_warranty_years');
        elseif(preg_match('/([三五])年(?:质保|保修)/u',$text,$m))$out['supplier_warranty_years']=$this->result($m[1]==='三'?'3':'5',$text,'medium','explicit_chinese_warranty_years');
        elseif(preg_match('/(?:质保|保修)([三五])年/u',$text,$m))$out['supplier_warranty_years']=$this->result($m[1]==='三'?'3':'5',$text,'medium','explicit_chinese_warranty_years_reversed');
        if(str_contains($text,'内置'))$out['installation_type']=$this->result('internal',$text,'high','explicit_internal');
        elseif(str_contains($text,'外置'))$out['installation_type']=$this->result('external',$text,'high','explicit_external');
        elseif(str_contains($text,'一体化')||str_contains($text,'一体式'))$out['installation_type']=$this->result('integrated',$text,'high','explicit_integrated');
        else $out['installation_type']=$this->result('unknown',$text,'low','not_explicit');
        if(str_contains($text,'恒流'))$out['output_type']=$this->result('constant_current',$text,'high','explicit_constant_current');
        elseif(str_contains($text,'恒压'))$out['output_type']=$this->result('constant_voltage',$text,'high','explicit_constant_voltage');
        return $out;
    }
    private function result(string $value,string $text,string $confidence,string $rule):array{return ['candidate_value'=>$value,'original_text'=>$text,'confidence'=>$confidence,'parse_rule'=>$rule,'is_human_confirmed'=>0];}
}
