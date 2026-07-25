<?php
declare(strict_types=1);
$page=file_get_contents(dirname(__DIR__).'/power_workbench.php');
$expected=['all'=>'全部','organize'=>'待整理','confirm'=>'待确认','formal'=>'正式','exception'=>'异常'];
foreach($expected as$key=>$label)if(strpos($page,"'{$key}'=>'{$label}'")===false){fwrite(STDERR,"locked tab missing: {$key}\n");exit(1);}
if(strpos($page,'class="stats"')!==false){fwrite(STDERR,"statistics cards remain\n");exit(1);}
if(strpos($page,"'source'=>'来源数据'")!==false||strpos($page,"'duplicates'=>'重复候选'")!==false){fwrite(STDERR,"legacy tabs remain visible\n");exit(1);}
echo "Power UI tab test passed; visible tabs=5; statistic cards=0.\n";
