<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Services;
use Artdon\CommercialCenter\Repositories\ConfigurationRepository;
final class ConfigurationEngineService
{
    public function catalog(int $userId=0,?int $customerId=null): array{return (new ConfigurationRepository())->catalog($userId,$customerId);}
    public function evaluate(array $input,int $userId=0): array
    {
        $repo=new ConfigurationRepository();$catalog=$repo->catalog($userId,isset($input['customer_id'])?(int)$input['customer_id']:null);$values=is_array($input['values']??null)?$input['values']:[];
        $product=$this->product($input,$catalog);$catalog['groups']=array_merge($catalog['groups'],$catalog['material_center'][(string)(int)$product['legacy_product_id']]??[]);$preset=$this->preset((int)($input['preset_id']??0),$catalog['presets']);
        $base=[];foreach($preset['values']??[] as $key=>$entry)$base[$key]=$entry['value'];if($product['type']==='stock')$base=array_replace($base,$product['sku_values']);
        $productAllowed=$repo->allowedOptions((int)$product['legacy_product_id']);$allowed=[];$locked=[];foreach($catalog['groups'] as $group){$code=$group['group_code'];$allowed[$code]=$productAllowed[$code]??array_column($group['options'],'option_code');if($product['type']==='stock'&&array_key_exists($code,$product['sku_values']))$locked[$code]=['type'=>'hard','reason'=>'库存SKU核心配置锁定'];}
        foreach($values as $key=>$value){if(isset($locked[$key]))continue;$chosen=is_array($value)?$value:[$value];if(isset($allowed[$key])&&$allowed[$key]!==[]&&array_diff($chosen,$allowed[$key])!==[])continue;$base[$key]=$value;}
        $messages=[];$approval=[];$blocked=false;
        foreach($catalog['groups'] as $group){$code=$group['group_code'];$value=$base[$code]??null;if((int)$group['is_required']&&($value===null||$value===''||$value===[])){$blocked=true;$messages[]=['type'=>'forbid','message'=>$group['name'].'为必选配置'];}}
        foreach($repo->rules() as $rule){$condition=json_decode($rule['condition_json'],true)?:[];if(!$this->matches($condition,$base))continue;$effect=json_decode($rule['effect_json'],true)?:[];$violation=false;foreach($effect as $key=>$required)if(!$this->contains($base[$key]??null,$required))$violation=true;if($rule['rule_type']==='forbid'&&$violation){$blocked=true;$messages[]=['type'=>'forbid','message'=>$rule['name']];}elseif($rule['rule_type']==='warning')$messages[]=['type'=>'warning','message'=>$rule['name']];elseif($rule['rule_type']==='approval'){$approval[]=$rule['name'];$messages[]=['type'=>'approval','message'=>$rule['name']];}}
        $cost=20.0;$price=40.0;$moq=1.0;$lead=7;$labels=[];foreach($catalog['groups'] as $group){$code=$group['group_code'];$value=$base[$code]??null;if($value===null||$value===''||$value===[])continue;$selected=is_array($value)?$value:[$value];$names=[];foreach($selected as $selectedValue){$found=false;foreach($group['options'] as $option)if($option['option_code']===$selectedValue){$cost+=(float)$option['cost_delta'];$price+=(float)$option['sales_delta'];$moq=max($moq,1+(float)$option['moq_delta']);$lead=max($lead,7+(int)$option['lead_time_days']);$names[]=$option['name'];$found=true;break;}if(!$found)$names[]=(string)$selectedValue;}if($names)$labels[]=$group['name'].'：'.implode('、',$names);}
        $adaptation=['product_id'=>null,'approved_version'=>null,'groups'=>[],'selected_materials'=>[]];
        foreach($catalog['groups'] as$group){
            if(($group['source']??'')!=='material_center')continue;
            $adaptation['product_id']=$group['adaptation_product_id']??$adaptation['product_id'];
            $adaptation['approved_version']=$group['approved_version']??$adaptation['approved_version'];
            $adaptation['groups'][]=[
                'group_id'=>$group['id'],'group_code'=>$group['group_code'],'business_type'=>$group['business_type']??'',
                'approved_version'=>$group['approved_version']??0,'quick_rules'=>$group['quick_rules']??[],
            ];
            $chosen=$base[$group['group_code']]??null;
            foreach(is_array($chosen)?$chosen:[$chosen] as$value)foreach($group['options'] as$option)if($value!==null&&$option['option_code']===$value){
                $adaptation['selected_materials'][]=[
                    'group_id'=>$group['id'],'option_id'=>$option['id'],'material_id'=>$option['material_id']??null,
                    'material_code'=>$option['material_code']??null,'match_level'=>$option['match_level']??null,
                    'chip_variant'=>$option['chip_variant']??null,
                ];
            }
        }
        $current=max($price,(float)($input['current_price']??0));$margin=$current>0?($current-$cost)/$current*100:0;$status=$blocked?'blocked':($approval?'approval':($messages?'warning':'valid'));$differences=[];foreach($base as $k=>$v)if(($preset['values'][$k]['value']??null)!==$v)$differences[$k]=$v;
        $passport=hash('sha256',json_encode([$product,$base,$adaptation],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['status'=>$status,'mode'=>in_array($input['mode']??'quick',['quick','professional','custom'],true)?$input['mode']:'quick','product'=>$product,'preset_id'=>(int)($preset['id']??0),'preset_name'=>$preset['name']??'未选择预设','values'=>$base,'differences'=>$differences,'locks'=>$locked,'messages'=>$messages,'approval'=>['required'=>(bool)$approval,'reasons'=>$approval],'pricing'=>['currency'=>'USD','cost'=>round($cost,4),'suggested_price'=>round($price,4),'current_price'=>round($current,4),'margin_percent'=>round($margin,2)],'moq'=>$moq,'lead_time_days'=>$lead,'summary'=>implode(' / ',$labels),'adaptation'=>$adaptation,'passport_hash'=>$passport];
    }
    private function product(array $input,array $catalog): array{$key=(string)($input['product_key']??'');if(str_starts_with($key,'stock:')){$id=(int)substr($key,6);foreach($catalog['stock_skus'] as $s)if((int)$s['id']===$id)return ['type'=>'stock','inventory_sku_id'=>$id,'legacy_product_id'=>(int)$s['legacy_product_id'],'code'=>$s['sku_code'],'name'=>trim(($s['model_no']??'').' '.($s['product_name']??'')),'is_test'=>(bool)$s['is_test'],'sku_values'=>json_decode($s['configuration_snapshot'],true)?:[]];}if(preg_match('/^(standard|custom):(\d+)$/',$key,$m)){foreach($catalog['products'] as $p)if((int)$p['id']===(int)$m[2])return ['type'=>$m[1],'inventory_sku_id'=>0,'legacy_product_id'=>(int)$p['id'],'code'=>$p['model_no'],'name'=>$p['product_name'],'is_test'=>false,'sku_values'=>[]];}throw new \InvalidArgumentException('请选择有效产品。');}
    private function preset(int $id,array $presets): array{foreach($presets as $p)if((int)$p['id']===$id)return $p;foreach($presets as $p)if($p['preset_type']==='factory_standard')return $p;return $presets[0]??['id'=>0,'name'=>'无预设','values'=>[]];}
    private function matches(array $condition,array $values): bool{foreach($condition as $key=>$value)if(!$this->contains($values[$key]??null,$value))return false;return $condition!==[];}
    private function contains(mixed $actual,mixed $expected): bool{return is_array($actual)?in_array($expected,$actual,true):$actual===$expected;}
}
