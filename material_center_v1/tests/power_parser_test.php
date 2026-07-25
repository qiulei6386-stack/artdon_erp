<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Artdon\MaterialCenter\Domain\PowerSupply\PowerSpecParser;

$parser=new PowerSpecParser();
$result=$parser->parse(['name'=>'内置恒流电源','model'=>'TEST-22W','spec'=>'22W，500/700mA，输入220-240V，输出30-40V，50-60Hz，尺寸100*40*25mm，DALI，PF0.95，五年质保']);
$expected=['max_output_power_w'=>'22','installation_type'=>'internal','output_type'=>'constant_current','dimming_mode'=>'dali','supplier_warranty_years'=>'5','length_mm'=>'100','output_voltage_min_v'=>'30'];
foreach($expected as$key=>$value){if(($result[$key]['candidate_value']??null)!==$value){fwrite(STDERR,"{$key} failed\n");exit(1);}}
if(($result['installation_type']['is_human_confirmed']??1)!==0){fwrite(STDERR,"parser must not confirm fields\n");exit(1);}
echo "Power parser test passed.\n";
