<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use Artdon\MaterialCenter\Adapters\LegacyProductAdapter;
use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use Artdon\MaterialCenter\Services\SettingsService;

$required=['mc_ui_settings','mc_ui_setting_scopes','mc_ui_themes','mc_ui_theme_versions','mc_user_preferences','mc_role_preferences','mc_setting_audit_logs','mc_permissions','mc_permission_grants','mc_permission_audit_logs','mc_product_power_rules','mc_product_power_rule_dimming_modes','mc_product_power_rule_brands','mc_product_power_approved_alternatives','mc_power_match_simulations'];
foreach($required as$table)if(!mc_table_exists($table)){fwrite(STDERR,"missing {$table}\n");exit(1);}
$legacyBefore=(int)db()->query('SELECT COUNT(*) FROM naming_models')->fetchColumn();
(new LegacyProductAdapter())->search('',3);
$legacyAfter=(int)db()->query('SELECT COUNT(*) FROM naming_models')->fetchColumn();
if($legacyBefore!==$legacyAfter){fwrite(STDERR,"legacy product count changed\n");exit(1);}
$context=new MaterialCenterUserContext(999999999,'mc_contract_test','MC Contract Test','test',true);
$settings=new SettingsService();$settings->save($context,'user',(string)$context->id,['font.base_px'=>15,'theme.primary'=>'#087f8c']);$resolved=$settings->resolved($context);
if((float)($resolved['values']['font.base_px']??0)!==15.0){fwrite(STDERR,"settings resolution failed\n");exit(1);}
$settings->reset($context,'user',(string)$context->id);
echo "F3/F4/F9 schema, settings and legacy product read-only contract passed.\n";
