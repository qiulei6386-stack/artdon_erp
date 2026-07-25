<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';use Artdon\MaterialCenter\Services\ProductPowerRuleService;
$user=mc_current_user();$ready=mc_table_exists('mc_product_power_rules');$rules=$user&&$ready?(new ProductPowerRuleService())->rules():[];header('Content-Type:text/html;charset=utf-8');mc_page_start('匹配模拟','simulate',$user);
?><div class="ui-page-head"><div><span class="ui-eyebrow">F9 · READ-ONLY SIMULATION</span><h1>产品电源匹配模拟</h1><p>只评估已确认规则与正式电源，不写旧产品、不生成报价。</p></div></div>
<?php if(!$user):?><?php mc_state('permission','需要统一登录','复用广州 ERP 账号。');?>
<?php elseif(!$ready):?><?php mc_state('config','F9 尚未安装','请先执行数据库迁移。');?>
<?php elseif(!$rules):?><?php mc_state('config','尚无可模拟规则','先建立产品电源草稿规则。','product_power_rules.php','前往规则页');?>
<?php else:?><section class="ui-card ui-card-body"><form id="simulation-form" class="ui-toolbar"><input type="hidden" name="csrf_token" value="<?=mc_h(function_exists('csrf_token')?csrf_token():'')?>"><label class="ui-field"><span class="ui-label">产品电源规则</span><select class="ui-select" name="rule_id"><?php foreach($rules as$r):?><option value="<?=$r['id']?>"><?=mc_h($r['rule_name'])?></option><?php endforeach;?></select></label><button class="ui-btn" type="submit">运行模拟</button></form><div id="simulation-result"><?php mc_state('empty','等待运行','选择规则后运行真实匹配评估。');?></div></section><?php endif;?>
<?php mc_page_end('','assets/js/power-match-simulator.js');?>
