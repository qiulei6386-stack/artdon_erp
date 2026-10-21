<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/yifei_sync/lib.php';
require_once dirname(__DIR__) . '/yifei_sync/page.php';

$fail = 0;

function yf_check($ok, string $message): void
{
    global $fail;
    if ($ok) {
        echo "OK {$message}\n";
        return;
    }
    $fail++;
    echo "FAIL {$message}\n";
}

$root = dirname(__DIR__);
$bom = (string)file_get_contents($root . '/bom.php');
$bomApi = (string)file_get_contents($root . '/bom_api.php');
$portal = (string)file_get_contents($root . '/index.php');
$agent = (string)file_get_contents($root . '/yifei_agent/sync.py');
$agentReadme = (string)file_get_contents($root . '/yifei_agent/README.md');
$syncReadme = (string)file_get_contents($root . '/yifei_sync/README.md');
$ignore = (string)file_get_contents($root . '/.gitignore');
$page = (string)file_get_contents($root . '/yifei_sync/index.php');
$bootstrap = (string)file_get_contents($root . '/yifei_sync/bootstrap.php');
$auth = (string)file_get_contents($root . '/includes/auth.php');
$example = json_decode((string)file_get_contents($root . '/yifei_agent/config.example.json'), true);
$examplePhp = (string)file_get_contents($root . '/yifei_sync/config.example.php');

yf_check(strpos($bom, "goModule('yifei_sync/index.php')") !== false && strpos($bom, '易飞数据') !== false, 'BOM 页有易飞数据入口');
yf_check(strpos($bom, 'yifei_invmb_search') !== false && strpos($bom, 'applyYifeiMaterial') !== false && strpos($bom, '易飞镜像') !== false, 'BOM 物料名称联想与选料弹窗接入易飞镜像');
yf_check(strpos($bomApi, "'yifei_invmb_search'=>'view_dashboard'") !== false && strpos($bomApi, 'yf_bom_picker_item') !== false, 'bom_api 提供 yifei_invmb_search');
yf_check(strpos($portal, 'yifei_sync/index.php') !== false && strpos($portal, '易飞数据') !== false, '首页 BOM 旁有易飞数据入口');
yf_check(strpos($page, 'yf_require_bom_user()') !== false && strpos($page, 'artdon_sso_core.php') === false, '查询页经易飞引导登录，不直接套用子目录相对跳转');
yf_check(strpos($bootstrap, "artdon_sso_require_page('bom')") !== false, '登录后仍检查 BOM 查看权限');
yf_check(strpos($auth, "redirect('login.php'") !== false, '其他页面仍使用原来的相对登录地址');
$prefixed = yf_site_login_url('/artdon_erp/yifei_sync/index.php', '/artdon_erp/yifei_sync/index.php?q=' . rawurlencode('散热器'));
yf_check(strpos($prefixed, '/artdon_erp/login.php?redirect=') === 0 && strpos($prefixed, '/yifei_sync/login.php') === false, '子目录登录跳到 /artdon_erp/login.php');
yf_check(rawurldecode(substr($prefixed, strlen('/artdon_erp/login.php?redirect='))) === '/artdon_erp/yifei_sync/index.php?q=' . rawurlencode('散热器'), '回跳保留易飞查询页和关键词');
$rootLogin = yf_site_login_url('/yifei_sync/index.php', '/yifei_sync/index.php?code=A4-2-01-4286');
yf_check($rootLogin === '/login.php?redirect=' . rawurlencode('/yifei_sync/index.php?code=A4-2-01-4286'), '站点装在根目录时登录是 /login.php');
yf_check(yf_site_login_url('/artdon_erp/yifei_sync/index.php', '') === '/artdon_erp/login.php', '没有来源地址时只给登录页');
yf_check(strpos(yf_site_login_url('/artdon_erp/yifei_sync/index.php', 'https://evil.example/phish'), 'redirect=') === false, '回跳拒绝带协议的地址');
yf_check(strpos(yf_site_login_url('/artdon_erp/yifei_sync/index.php', '//evil.example/phish'), 'redirect=') === false, '回跳拒绝协议相对地址');
yf_check(yf_site_login_url('/artdon_erp/yifei_sync/index.php', '/artdon_erp/login.php') === '/artdon_erp/login.php', '回跳拒绝登录页自身');
yf_check(strpos((string)file_get_contents($root . '/yifei_sync/lib.php'), "getenv('YIFEI_SYNC_ALLOW_PREVIEW') !== '1'") !== false, '预览开关不能单靠配置文件打开');
yf_check(strpos($page, 'bom.cost_view') !== false, '成本沿用 bom.cost_view');
yf_check(strpos($ignore, 'yifei_sync/config.local.php') !== false && strpos($ignore, 'yifei_agent/config.json') !== false, '密钥和代理配置不入库');
yf_check(is_array($example) && ($example['sqlserver']['password'] ?? null) === '' && ($example['guangzhou']['token'] ?? null) === '', '示例配置没有真实密码或密钥');
yf_check(strpos($examplePhp, 'allow_preview_without_login') === false, '示例密钥文件没有预览开关');
yf_check(strpos($agent, 'RTRIM(MB001)') !== false && strpos($agent, 'RTRIM(MB007)') !== false && strpos($agent, 'RTRIM(MD001)') !== false, '代理 SQL 对品号、主件使用 RTRIM');
yf_check(strpos($agent, 'MB005') !== false && strpos($agent, 'RTRIM(MB002)') !== false && strpos($agent, "RTRIM(ISNULL(MB004, ''))") !== false, '成本取 MB005，品号是 MB007，MB004 只作单据号');
yf_check(!preg_match('/(?:FROM|JOIN)\s+CSTLA\b|(?:FROM|JOIN)\s+CSTMC\b/', $agent), '代理不读 CSTLA / CSTMC');
yf_check(!preg_match('/(?:FROM|JOIN)\s+BOMM[EF]\b/', $agent), '代理不读产品工艺路线，避免和会计页回写重复相加');
yf_check(strpos($agentReadme, 'RTRIM(MB001)') !== false && strpos($agentReadme, 'RTRIM(MB007)') !== false && strpos($agentReadme, 'db_datareader') !== false, '代理说明写了 RTRIM 和只读角色');
yf_check(strpos($agentReadme, '10') !== false && strpos($agentReadme, '不写易飞') !== false, '代理说明是约 10 分钟且不写回');
yf_check(strpos($syncReadme, 'migrate.php') !== false && strpos($syncReadme, '/www/server/artdon_secrets/yifei_sync.php') !== false, '广州说明包含建表和仓库外密钥');

$schema = yf_mysql_schema_sql();
foreach (array('yf_invmb', 'yf_bommd', 'yf_cstmb', 'yf_item_feature', 'yf_sync_batches', 'MB001', 'MB007', 'MD001') as $needle) {
    yf_check(strpos($schema, $needle) !== false, '建表脚本包含 ' . $needle);
}
yf_check(stripos($schema, 'CSTLA') === false && stripos($schema, 'DROP TABLE') === false, '建表脚本不删表、不建未核实成本表');

$sqlite = new PDO('sqlite::memory:');
yf_install($sqlite);
$item = yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-item',
    'rows' => array(array(
        'item_code' => 'A4-2-01-4286   ',
        'item_name' => 'DL0067-10W双色温散热器+25*10mm紧打块(紧打)-粗坯',
        'spec' => '外径53mm, 高60mm',
    )),
));
yf_check($item['rows'][0]['item_code'] === 'A4-2-01-4286', '品号按 RTRIM 去掉尾部空格');
$first = yf_apply($sqlite, $item, '127.0.0.1');
$second = yf_apply($sqlite, $item, '127.0.0.1');
yf_check($first['upserted'] === 1 && $second['upserted'] === 1, '品号重复推送仍是一行');
$count = (int)$sqlite->query('SELECT COUNT(*) FROM yf_invmb')->fetchColumn();
yf_check($count === 1, '尾部空格没有变成第二个品号');

$bomPayload = yf_normalize_payload(array(
    'type' => 'bommd',
    'batch_id' => 't-bom',
    'rows' => array(
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0211', 'qty' => 1),
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0967', 'qty' => 1),
        array('parent_code' => 'A4-2-01-4286', 'component_code' => 'A4-2-01-0211', 'qty' => '1'),
    ),
));
$bomResult = yf_apply($sqlite, $bomPayload, '127.0.0.1');
$qty = $sqlite->query("SELECT qty FROM yf_bommd WHERE parent_code = 'A4-2-01-4286' AND component_code = 'A4-2-01-0211'")->fetchColumn();
yf_check($bomResult['upserted'] === 2 && $bomResult['deduped'] === 1 && yf_format_decimal($qty) === '2', '相同主件元件合并用量');

yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-old',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20260101', 'doc_no' => 'OLD', 'cost' => '1.000000')),
)), '127.0.0.1');
$latest = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-new',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20261001', 'doc_no' => 'SAMPLE-DOC', 'cost' => '27.564722')),
)), '127.0.0.1');
$older = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-cost-back',
    'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20251201', 'doc_no' => 'OLDER', 'cost' => '99')),
)), '127.0.0.1');
$cost = $sqlite->query("SELECT cost, period, doc_no FROM yf_cstmb WHERE item_code = 'A4-2-01-4286'")->fetch(PDO::FETCH_ASSOC);
yf_check($latest['upserted'] === 1 && $older['skipped'] === 1, '较旧期间不覆盖较新成本');
yf_check(yf_format_decimal($cost['cost']) === '27.564722' && $cost['period'] === '20261001' && $cost['doc_no'] === 'SAMPLE-DOC', '保留最新期间的 MB005');

$byCode = yf_search($sqlite, 'A4-2-01-4286');
$byName = yf_search($sqlite, '散热器');
yf_check(count($byCode['rows']) === 1 && count($byName['rows']) === 1, '可按品号和品名查到样例');
$detail = yf_detail($sqlite, 'A4-2-01-4286');
yf_check(is_array($detail) && count($detail['children']) === 2 && $detail['synced_at'] !== '', '详情含 BOM 和同步时间');
$html = yf_render_page(array(
    'q' => 'A4-2-01-4286',
    'code' => 'A4-2-01-4286',
    'rows' => $byCode['rows'],
    'detail' => $detail,
    'can_cost' => true,
    'ready' => true,
    'mirror_synced_at' => $detail['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($html, '易飞只读数据') !== false && strpos($html, '27.564722') !== false && strpos($html, 'A4-2-01-0211') !== false && strpos($html, '单据号') !== false, '页面展示品名路径、成本、BOM 和同步字段');
$hidden = yf_render_page(array(
    'q' => 'A4-2-01-4286',
    'code' => 'A4-2-01-4286',
    'rows' => $byCode['rows'],
    'detail' => $detail,
    'can_cost' => false,
    'ready' => true,
    'mirror_synced_at' => $detail['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($hidden, '27.564722') === false && strpos($hidden, 'bom.cost_view') !== false && strpos($hidden, 'A4-2-01-0967') !== false, '没有成本权限时不输出成本数字');
yf_check(strpos($schema, 'MB057') !== false && strpos($schema, 'MD007') !== false && strpos($schema, 'MD008') !== false, '建表脚本包含标准材料成本、底数和损耗率');
yf_check(strpos($agentReadme, 'py -3.13 sync.py --once') !== false && strpos($agentReadme, 'MB057') !== false, '代理说明要求重跑同步并写了 MB057');
yf_check(strpos($agentReadme, 'py -3.13 sync.py --full') !== false && strpos($agentReadme, 'DSCLB') !== false, '代理说明写了特征数据与全量重推');
yf_check(strpos($syncReadme, 'A4-2-01-0254') !== false && strpos($syncReadme, 'yf_item_feature') !== false, '广州说明写了粗胚特征核对');
yf_check(strpos($agent, 'type": "feature"') !== false || strpos($agent, "'feature'") !== false, '代理推送 feature 类型');
yf_check(strpos($syncReadme, '171.9508') !== false && strpos($syncReadme, 'A1-1-01-0381') !== false, '广州说明写了成品核对目标');

$baseZero = yf_line_material(array('qty' => '4', 'base_qty' => '0', 'scrap_rate' => '0', 'std_material' => '2'));
yf_check(yf_format_decimal($baseZero['material_cost']) === '8', '底数 0 按 1 计算材料成本');
$scrapLine = yf_line_material(array('qty' => '1', 'base_qty' => '2', 'scrap_rate' => '0.5', 'std_material' => '10'));
yf_check(yf_format_decimal($scrapLine['material_cost']) === '7.5', '用量/底数×(1+损耗率) 后再乘标准材料成本');

yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-std-parent',
    'rows' => array(
        array('item_code' => 'A1-1-01-0381', 'item_name' => '成品灯', 'spec' => '样例', 'item_attr' => 'M', 'std_material' => '171.950800', 'std_labor' => '1', 'std_subcontract' => '0.5', 'latest_purchase' => '63.717', 'hour_base' => '1'),
        array('item_code' => 'A5-2-06-0029', 'item_name' => '驱动', 'spec' => '黑色', 'item_attr' => 'P', 'std_material' => '74', 'std_mfg' => '2', 'std_subcontract' => '1'),
        array('item_code' => 'A4-2-01-0235', 'item_name' => '元件', 'spec' => '', 'item_attr' => 'P', 'std_material' => '22.4', 'std_mfg' => '0', 'std_subcontract' => '0.5'),
        array('item_code' => 'COMP-BASE', 'item_name' => '有底数', 'spec' => '', 'std_material' => '10'),
        array('item_code' => 'COMP-ZERO', 'item_name' => '零成本', 'spec' => '', 'std_material' => '0', 'std_mfg' => '0'),
        array('item_code' => 'COMP-MISS', 'item_name' => '虚设', 'spec' => '', 'item_attr' => 'Y'),
    ),
)), '127.0.0.1');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'bommd',
    'batch_id' => 't-std-bom',
    'rows' => array(
        array('parent_code' => 'A1-1-01-0381', 'component_code' => 'A5-2-06-0029', 'qty' => '1', 'base_qty' => '1', 'scrap_rate' => '0'),
        array('parent_code' => 'A1-1-01-0381', 'component_code' => 'A4-2-01-0235', 'qty' => '2', 'base_qty' => '1', 'scrap_rate' => '0'),
        array('parent_code' => 'A1-1-01-0381', 'component_code' => 'COMP-BASE', 'qty' => '1', 'base_qty' => '2', 'scrap_rate' => '0.5'),
        array('parent_code' => 'A1-1-01-0381', 'component_code' => 'COMP-ZERO', 'qty' => '3', 'base_qty' => '1', 'scrap_rate' => '0'),
        array('parent_code' => 'A1-1-01-0381', 'component_code' => 'COMP-MISS', 'qty' => '1', 'base_qty' => null, 'scrap_rate' => null),
    ),
)), '127.0.0.1');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'cstmb',
    'batch_id' => 't-std-ledger',
    'rows' => array(array('item_code' => 'A1-1-01-0381', 'period' => '20260909', 'doc_no' => 'LEDGER', 'cost' => '6.229167')),
)), '127.0.0.1');
$keptStd = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-std-old-shape',
    'rows' => array(array('item_code' => 'A5-2-06-0029', 'item_name' => '驱动-改名', 'spec' => '黑色')),
)), '127.0.0.1');
$driverRow = $sqlite->query("SELECT item_name, std_material FROM yf_invmb WHERE item_code = 'A5-2-06-0029'")->fetch(PDO::FETCH_ASSOC);
yf_check($keptStd['upserted'] === 1 && $driverRow['item_name'] === '驱动-改名' && yf_format_decimal($driverRow['std_material']) === '74', '旧品号批次不覆盖已有单位标准材料成本');
$cleared = yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-std-null',
    'rows' => array(array('item_code' => 'COMP-ZERO', 'item_name' => '零成本', 'spec' => '', 'std_material' => null)),
)), '127.0.0.1');
$zeroAfter = $sqlite->query("SELECT std_material FROM yf_invmb WHERE item_code = 'COMP-ZERO'")->fetchColumn();
yf_check($cleared['ok'] === true && $zeroAfter === null, '显式 null 才把标准材料成本写成空');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-std-zero-back',
    'rows' => array(array('item_code' => 'COMP-ZERO', 'item_name' => '零成本', 'spec' => '', 'std_material' => '0')),
)), '127.0.0.1');
$fg = yf_detail($sqlite, 'A1-1-01-0381');
yf_check(is_array($fg) && yf_format_decimal($fg['cost']['cost']) === '6.229167', '成品保留账上最新单位成本 6.229167');
yf_check(yf_format_decimal($fg['item']['std_material']) === '171.9508', '成品页头保留主件单位标准材料成本 171.9508');
yf_check(yf_format_decimal($fg['rollup']['material_total']) === '126.3' && (int)$fg['rollup']['missing_std'] === 1 && (int)$fg['rollup']['zero_std'] === 1 && (int)$fg['rollup']['phantom'] === 1, '单阶材料合计 126.3，缺 1 个，0 成本 1 个，含虚设件');
yf_check(yf_format_decimal($fg['rollup']['mfg_total']) === '2' && (int)$fg['rollup']['missing_mfg'] === 2 && (int)$fg['rollup']['zero_mfg'] === 2, '单阶制费合计只加已同步的子件，0 也计入');
yf_check(yf_format_decimal($fg['rollup']['process_total']) === '2' && (int)$fg['rollup']['missing_process'] === 3, '单阶加工按有效用量展开');
yf_check(yf_format_decimal($fg['rollup']['amount_total']) === '130.3', '表尾成本金额是材料+制费+加工');
yf_check(yf_format_decimal($fg['std_total']['total']) === '172.4508' && yf_format_decimal($fg['std_total']['labor']) === '1' && in_array('制费', $fg['std_total']['absent'], true) && !in_array('人工', $fg['std_total']['present'], true), '主件成本金额只加材料、制费、加工，人工另列');
$report = yf_std_total(array(
    'std_material' => '153.8348',
    'std_labor' => '0.0992',
    'std_mfg' => '10',
    'std_subcontract' => '8.116',
    'level_mfg' => '18.116',
    'level_process' => '1',
));
yf_check(yf_format_decimal($report['total']) === '171.9508' && yf_format_decimal($report['labor']) === '0.0992', '材料 153.8348 加制费加工等于报表成本金额，本阶不重复加');
$pickerDriver = yf_bom_picker_item(array(
    'item_code' => 'A5-2-06-0029',
    'item_name' => '驱动',
    'spec' => '黑色',
    'std_material' => '74',
    'std_mfg' => '2',
    'std_subcontract' => '1',
    'feature_rows' => array(),
), true);
yf_check($pickerDriver['item_code'] === 'A5-2-06-0029' && $pickerDriver['unit_material'] === 74.0 && $pickerDriver['unit_process'] === 3.0, 'BOM 选料映射：材料单价与制费+加工');
$pickerHidden = yf_bom_picker_item(array('item_code' => 'X', 'item_name' => '隐', 'spec' => ''), false);
yf_check(!array_key_exists('unit_material', $pickerHidden), '无成本权限时不返回易飞金额字段');
$fgRows = yf_search($sqlite, 'A1-1-01-0381');
$fgHtml = yf_render_page(array(
    'q' => 'A1-1-01-0381',
    'code' => 'A1-1-01-0381',
    'rows' => $fgRows['rows'],
    'detail' => $fg,
    'can_cost' => true,
    'ready' => true,
    'mirror_synced_at' => $fg['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($fgHtml, '成本金额（材料+制费+加工）') !== false && strpos($fgHtml, '不是成本金额') !== false && strpos($fgHtml, 'class="num cost">172.4508') !== false && strpos($fgHtml, 'class="cost primary-num">172.4508') !== false && strpos($fgHtml, 'class="num ledger">6.229167') !== false && strpos($fgHtml, 'class="num cost">6.229167') === false && strpos($fgHtml, '126.3') !== false && strpos($fgHtml, 'class="num cost">130.3') !== false && strpos($fgHtml, '缺标准材料成本 1 个') !== false && strpos($fgHtml, '子件合计和主件成本金额') !== false && strpos($fgHtml, '74') !== false, '页面以成本金额为主，CSTMB 降为次要，表尾有分项合计');
$fgHidden = yf_render_page(array(
    'q' => 'A1-1-01-0381',
    'code' => 'A1-1-01-0381',
    'rows' => $fgRows['rows'],
    'detail' => $fg,
    'can_cost' => false,
    'ready' => true,
    'mirror_synced_at' => $fg['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
$leaked = array('6.229167', '171.9508', '172.4508', '126.3', '130.3', '74', '44.8', '45.8', '22.4', '7.5', '63.717', '77');
$hiddenOk = strpos($fgHidden, 'A5-2-06-0029') !== false && strpos($fgHidden, '>材料合计<') === false && strpos($fgHidden, '缺标准材料成本') === false;
foreach ($leaked as $secret) {
    if (strpos($fgHidden, '>' . $secret . '<') !== false) {
        $hiddenOk = false;
    }
}
yf_check($hiddenOk, '没有成本权限时不输出标准成本、材料合计和最近进价');

yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'invmb',
    'batch_id' => 't-rough-item',
    'rows' => array(array(
        'item_code' => 'A4-2-01-0254',
        'item_name' => '粗胚样例',
        'spec' => '',
        'std_material' => '3.18',
        'std_mfg' => '0',
        'std_subcontract' => '0',
    )),
)), '127.0.0.1');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'feature',
    'batch_id' => 't-rough-feat',
    'items' => array('A4-2-01-0254'),
    'rows' => array(
        array('item_code' => 'A4-2-01-0254', 'feature_code' => 'UDF51', 'feature_name' => '数控开料', 'amount' => '0.05', 'sort_no' => 1),
        array('item_code' => 'A4-2-01-0254', 'feature_code' => 'UDF52', 'feature_name' => '数控加工', 'amount' => '1.095', 'sort_no' => 2),
        array('item_code' => 'A4-2-01-0254', 'feature_code' => 'UDF53', 'feature_name' => '五金钻孔', 'amount' => '0.2376', 'sort_no' => 3),
    ),
)), '127.0.0.1');
$rough = yf_detail($sqlite, 'A4-2-01-0254');
yf_check(is_array($rough) && yf_format_decimal($rough['std_total']['total']) === '4.5626' && !empty($rough['std_total']['features_applied']), '粗胚 A4-2-01-0254 成本金额含特征约 4.5626');
$roughHtml = yf_render_page(array(
    'q' => 'A4-2-01-0254',
    'code' => 'A4-2-01-0254',
    'rows' => yf_search($sqlite, 'A4-2-01-0254')['rows'],
    'detail' => $rough,
    'can_cost' => true,
    'ready' => true,
    'mirror_synced_at' => $rough['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($roughHtml, '4.5626') !== false && strpos($roughHtml, '数控开料') !== false && strpos($roughHtml, '特征数据') !== false, '粗胚页展示特征明细与成本金额');
$roughHidden = yf_render_page(array(
    'q' => 'A4-2-01-0254',
    'code' => 'A4-2-01-0254',
    'rows' => yf_search($sqlite, 'A4-2-01-0254')['rows'],
    'detail' => $rough,
    'can_cost' => false,
    'ready' => true,
    'mirror_synced_at' => $rough['synced_at'],
    'user_label' => '测试',
    'notice' => '',
    'recent' => false,
));
yf_check(strpos($roughHidden, '4.5626') === false && strpos($roughHidden, '数控开料') === false, '无权限时不泄露粗胚特征金额');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'feature',
    'batch_id' => 't-driver-feat',
    'items' => array('A5-2-06-0029'),
    'rows' => array(array('item_code' => 'A5-2-06-0029', 'feature_code' => 'UDF51', 'feature_name' => '数控开料', 'amount' => '9', 'sort_no' => 1)),
)), '127.0.0.1');
$driverAmt = yf_std_total(array('std_material' => '74', 'std_mfg' => '2', 'std_subcontract' => '1'), array(array('amount' => '9')));
yf_check(yf_format_decimal($driverAmt['total']) === '77' && empty($driverAmt['features_applied']), '制费或加工非零时不双计特征');
yf_apply($sqlite, yf_normalize_payload(array(
    'type' => 'feature',
    'batch_id' => 't-rough-clear',
    'items' => array('A4-2-01-0254'),
    'rows' => array(),
)), '127.0.0.1');
$clearedFeat = $sqlite->query("SELECT COUNT(*) FROM yf_item_feature WHERE item_code = 'A4-2-01-0254'")->fetchColumn();
yf_check((int)$clearedFeat === 0, 'feature 空行按 items 清空旧特征');

$legacy = new PDO('sqlite::memory:');
$legacy->exec("CREATE TABLE yf_invmb (item_code TEXT PRIMARY KEY, item_name TEXT NOT NULL DEFAULT '', spec TEXT NOT NULL DEFAULT '', synced_at TEXT NOT NULL, batch_id TEXT NOT NULL DEFAULT '')");
$legacy->exec("CREATE TABLE yf_bommd (parent_code TEXT NOT NULL, component_code TEXT NOT NULL, qty TEXT NOT NULL DEFAULT '0', synced_at TEXT NOT NULL, batch_id TEXT NOT NULL DEFAULT '', PRIMARY KEY (parent_code, component_code))");
$legacy->exec("CREATE TABLE yf_cstmb (item_code TEXT PRIMARY KEY, period TEXT NOT NULL DEFAULT '', doc_no TEXT NOT NULL DEFAULT '', cost TEXT NOT NULL DEFAULT '0', synced_at TEXT NOT NULL, batch_id TEXT NOT NULL DEFAULT '')");
$legacy->exec("CREATE TABLE yf_sync_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT NOT NULL, payload_type TEXT NOT NULL, row_count INTEGER NOT NULL DEFAULT 0, upserted INTEGER NOT NULL DEFAULT 0, skipped INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'ok', message TEXT NOT NULL DEFAULT '', remote_ip TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$legacy->exec("INSERT INTO yf_invmb (item_code, item_name, spec, synced_at) VALUES ('KEEP', '旧品号', '', '2026-01-01 00:00:00')");
$legacy->exec("INSERT INTO yf_bommd (parent_code, component_code, qty, synced_at) VALUES ('KEEP', 'C1', '3', '2026-01-01 00:00:00')");
yf_check(!yf_tables_ready($legacy), '缺标准成本列时提示还要迁移');
yf_install($legacy);
$keptLegacy = $legacy->query("SELECT item_name, std_material FROM yf_invmb WHERE item_code = 'KEEP'")->fetch(PDO::FETCH_ASSOC);
$legacyBom = $legacy->query("SELECT qty, base_qty, scrap_rate FROM yf_bommd WHERE parent_code = 'KEEP'")->fetch(PDO::FETCH_ASSOC);
yf_check($keptLegacy['item_name'] === '旧品号' && $keptLegacy['std_material'] === null, '补列后旧品号还在，标准材料成本为空');
yf_check(yf_format_decimal($legacyBom['qty']) === '3' && yf_format_decimal($legacyBom['base_qty']) === '1' && yf_format_decimal($legacyBom['scrap_rate']) === '0', '旧 BOM 补上底数 1 和损耗率 0');
yf_install($legacy);
yf_check(yf_tables_ready($legacy), '第二次迁移后标准成本列仍在');

$bad = false;
try {
    yf_normalize_payload(array('type' => 'cstla', 'rows' => array()));
} catch (InvalidArgumentException $e) {
    $bad = true;
}
yf_check($bad, '拒绝未核实的 type');

putenv('YIFEI_SYNC_TOKEN');
putenv('YIFEI_SYNC_SECRET_FILE=/tmp/yifei-sync-missing-secret.php');
$missing = false;
try {
    yf_authorize('anything-at-all-123456');
} catch (RuntimeException $e) {
    $missing = $e->getMessage() === 'TOKEN_NOT_CONFIGURED';
}
yf_check($missing, '没配置密钥时拒绝访问');
putenv('YIFEI_SYNC_TOKEN=replace-with-a-long-random-token');
$placeholder = false;
try {
    yf_authorize('replace-with-a-long-random-token');
} catch (RuntimeException $e) {
    $placeholder = $e->getMessage() === 'TOKEN_NOT_CONFIGURED';
}
yf_check($placeholder, '示例占位密钥不能用');
putenv('YIFEI_SYNC_TOKEN=contract-token-value');
$denied = false;
try {
    yf_authorize('wrong-token-value-xx');
} catch (RuntimeException $e) {
    $denied = $e->getMessage() === 'UNAUTHORIZED';
}
yf_check($denied, '错误密钥被拒绝');
$allowed = true;
try {
    yf_authorize('contract-token-value');
} catch (RuntimeException $e) {
    $allowed = false;
}
yf_check($allowed, '正确密钥通过');
yf_check(yf_request_token(array('HTTP_X_YIFEI_TOKEN' => 'abc')) === 'abc', '读取 X-Yifei-Token');

$python = trim((string)shell_exec('command -v python3'));
if ($python !== '') {
    $out = array();
    $code = 1;
    exec(escapeshellarg($python) . ' ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --self-check', $out, $code);
    yf_check($code === 0 && strpos(implode("\n", $out), 'self-check ok') !== false, '代理自检通过');
    $sqlOut = array();
    $sqlCode = 1;
    exec(escapeshellarg($python) . ' ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --print-sql', $sqlOut, $sqlCode);
    $printed = implode("\n", $sqlOut);
    yf_check($sqlCode === 0 && strpos($printed, 'RTRIM(MB007)') !== false && strpos($printed, 'MB057') !== false && strpos($printed, 'MD007') !== false && strpos($printed, 'MD008') !== false && strpos($printed, '-- feature') !== false && strpos($printed, 'UPDATE') === false, '打印的 SQL 只读且含标准材料成本、底数、损耗率、特征占位');
} else {
    yf_check(false, '找不到 python3');
}

yf_login_redirect_smoke($root);
yf_mysql_smoke($root);

if ($fail > 0) {
    fwrite(STDERR, "yifei sync contract failed: {$fail}\n");
    exit(1);
}
echo "yifei sync contract passed\n";

function yf_login_redirect_smoke(string $root): void
{
    putenv('YIFEI_SYNC_ALLOW_PREVIEW');
    putenv('YIFEI_DOCROOT=' . $root);
    $port = 8766;
    $log = sys_get_temp_dir() . '/yifei-login-redirect.log';
    $router = sys_get_temp_dir() . '/yifei-login-router.php';
    @unlink($log);
    file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$prefix = '/artdon_erp';
if (!is_string($path) || strpos($path, $prefix . '/') !== 0) {
    http_response_code(404);
    echo 'missing prefix';
    return;
}
$rel = substr($path, strlen($prefix));
$file = getenv('YIFEI_DOCROOT') . $rel;
if (!is_file($file)) {
    http_response_code(404);
    echo 'not found';
    return;
}
$_SERVER['SCRIPT_NAME'] = $prefix . $rel;
require $file;
PHP
    );
    $pipes = array();
    $proc = proc_open(
        array('php', '-S', '127.0.0.1:' . $port, '-t', $root, $router),
        array(array('file', '/dev/null', 'r'), array('file', $log, 'w'), array('file', $log, 'a')),
        $pipes,
        $root
    );
    $location = '';
    $status = 0;
    $path = '/artdon_erp/yifei_sync/index.php?q=' . rawurlencode('散热器');
    $url = 'http://127.0.0.1:' . $port . $path;
    for ($i = 0; $i < 20; $i++) {
        $ch = curl_init($url);
        if ($ch === false) {
            break;
        }
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 302) {
            if (preg_match('/^Location:\s*(\S+)/mi', (string)$raw, $m)) {
                $location = trim($m[1]);
            }
            break;
        }
        if ($status >= 400) {
            break;
        }
        usleep(100000);
    }
    yf_check($status === 302, '未登录查询页返回 302');
    yf_check(
        $location !== '' && strpos($location, 'yifei_sync/login.php') === false && preg_match('#^/artdon_erp/login\.php\?redirect=#', $location) === 1,
        '登录地址是 /artdon_erp/login.php：' . $location
    );
    $query = parse_url($location, PHP_URL_QUERY);
    $params = array();
    parse_str((string)$query, $params);
    $back = isset($params['redirect']) ? (string)$params['redirect'] : '';
    yf_check(strpos($back, '/artdon_erp/yifei_sync/index.php') === 0 && strpos($back, 'q=') !== false, '登录后回到易飞查询页：' . $back);
    require_once $root . '/includes/auth.php';
    yf_check(auth_safe_redirect($back, 'index.php') === $back, '登录页接受这个回跳地址');
    if ($status !== 302 && is_file($log)) {
        fwrite(STDERR, substr((string)file_get_contents($log), 0, 800) . "\n");
    }
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
}

function yf_mysql_smoke(string $root): void
{
    $mysqld = trim((string)shell_exec('command -v mysqld || true'));
    if ($mysqld === '') {
        echo "SKIP mysql curl smoke：这台机器没有 mysqld\n";
        return;
    }
    $base = '/tmp/yifei-mysql';
    $socket = $base . '/mysql.sock';
    $ping = 'mysqladmin --socket=' . escapeshellarg($socket) . ' --user=root --password= ping --silent';
    $unused = array();
    exec($ping, $unused, $pingCode);
    if ($pingCode !== 0) {
        if (!is_dir($base . '/data/mysql')) {
            @mkdir($base . '/data', 0777, true);
            $init = $mysqld . ' --initialize-insecure --datadir=' . escapeshellarg($base . '/data')
                . ' --basedir=/usr --log-error=' . escapeshellarg($base . '/init.err');
            exec($init, $initOut, $initCode);
            yf_check($initCode === 0, '初始化本地 MySQL');
            if ($initCode !== 0) {
                return;
            }
        }
        $cmd = $mysqld
            . ' --datadir=' . escapeshellarg($base . '/data')
            . ' --socket=' . escapeshellarg($socket)
            . ' --pid-file=' . escapeshellarg($base . '/mysql.pid')
            . ' --log-error=' . escapeshellarg($base . '/mysql.err')
            . ' --port=3307 --bind-address=127.0.0.1 --mysqlx=0'
            . ' --character-set-server=utf8mb4';
        $proc = proc_open($cmd, array(array('file', '/dev/null', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes);
        yf_check(is_resource($proc), '启动本地 MySQL');
        $ready = false;
        for ($i = 0; $i < 40; $i++) {
            exec($ping, $unused, $pingCode);
            if ($pingCode === 0) {
                $ready = true;
                break;
            }
            usleep(250000);
        }
        yf_check($ready, '本地 MySQL 可连接');
        if (!$ready) {
            return;
        }
    } else {
        echo "OK 复用已启动的本地 MySQL\n";
    }

    $serverDsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
    try {
        $server = new PDO($serverDsn, 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $server->exec('CREATE DATABASE IF NOT EXISTS yifei_p1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=yifei_p1;charset=utf8mb4', 'root', '', array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        $pdo->exec('DROP TABLE IF EXISTS yf_sync_batches');
        $pdo->exec('DROP TABLE IF EXISTS yf_item_feature');
        $pdo->exec('DROP TABLE IF EXISTS yf_cstmb');
        $pdo->exec('DROP TABLE IF EXISTS yf_bommd');
        $pdo->exec('DROP TABLE IF EXISTS yf_invmb');
    } catch (Throwable $e) {
        yf_check(false, '连接 MySQL 失败：' . $e->getMessage());
        return;
    }

    putenv('YIFEI_SYNC_DSN=mysql:unix_socket=' . $socket . ';dbname=yifei_p1;charset=utf8mb4');
    putenv('YIFEI_SYNC_DB_USER=root');
    putenv('YIFEI_SYNC_DB_PASS=');
    putenv('YIFEI_SYNC_TOKEN=contract-token-value');
    $migrateOut = array();
    $migrateCode = 1;
    exec('php ' . escapeshellarg($root . '/yifei_sync/migrate.php'), $migrateOut, $migrateCode);
    yf_check($migrateCode === 0 && strpos(implode("\n", $migrateOut), 'yf_invmb') !== false, 'migrate.php 在 MySQL 上可重复准备');
    exec('php ' . escapeshellarg($root . '/yifei_sync/migrate.php'), $migrateOut2, $migrateCode2);
    yf_check($migrateCode2 === 0, 'migrate.php 第二次仍成功');
    yf_check(yf_tables_ready($pdo), '四张镜像表已存在');

    $port = 8765;
    $serverProc = proc_open(
        array('php', '-S', '127.0.0.1:' . $port, '-t', $root),
        array(array('file', '/dev/null', 'r'), array('file', $base . '/php-access.log', 'w'), array('file', $base . '/php-error.log', 'w')),
        $serverPipes,
        $root
    );
    $up = false;
    for ($i = 0; $i < 20; $i++) {
        if (yf_http_status('http://127.0.0.1:' . $port . '/yifei_sync/api.php') === 405) {
            $up = true;
            break;
        }
        usleep(100000);
    }
    yf_check($up && is_resource($serverProc), '接收接口已监听');
    if (!$up) {
        if (is_resource($serverProc)) {
            proc_terminate($serverProc);
            proc_close($serverProc);
        }
        return;
    }

    $url = 'http://127.0.0.1:' . $port . '/yifei_sync/api.php';
    $fixture = $root . '/yifei_agent/fixtures/sample_batches.json';
    $batches = json_decode((string)file_get_contents($fixture), true);
    $status = 0;
    foreach ($batches as $batch) {
        $status = yf_curl($url, 'contract-token-value', json_encode($batch, JSON_UNESCAPED_UNICODE));
        if ($status !== 200) {
            break;
        }
    }
    yf_check($status === 200, '假数据 curl 写入三个批次');
    $again = yf_curl($url, 'contract-token-value', json_encode($batches[0], JSON_UNESCAPED_UNICODE));
    yf_check($again === 200, '同一品号再次 curl 仍成功');
    $deniedStatus = yf_curl($url, 'wrong-token-value-xx', json_encode($batches[0], JSON_UNESCAPED_UNICODE));
    yf_check($deniedStatus === 401, '错误密钥 curl 返回 401');

    $configPath = $base . '/agent-config.json';
    file_put_contents($configPath, json_encode(array(
        'guangzhou' => array('url' => $url, 'token' => 'contract-token-value', 'timeout_seconds' => 10, 'verify_tls' => true),
        'batch_size' => 200,
        'log_file' => $base . '/agent.log',
        'state_file' => $base . '/watermark.json',
    ), JSON_UNESCAPED_UNICODE));
    $pushOut = array();
    $pushCode = 1;
    exec('python3 ' . escapeshellarg($root . '/yifei_agent/sync.py') . ' --push-file ' . escapeshellarg($fixture) . ' --config ' . escapeshellarg($configPath), $pushOut, $pushCode);
    yf_check($pushCode === 0, '代理 --push-file 把样例推到接收接口');

    $found = yf_search($pdo, '散热器');
    $detail = yf_detail($pdo, 'A4-2-01-4286');
    yf_check(count($found['rows']) === 1 && is_array($detail) && count($detail['children']) === 2 && yf_format_decimal($detail['cost']['cost']) === '27.564722', 'MySQL 里能按品名查到 BOM 和最新成本');

    $stale = yf_curl($url, 'contract-token-value', json_encode(array(
        'type' => 'cstmb',
        'batch_id' => 'mysql-old-period',
        'rows' => array(array('item_code' => 'A4-2-01-4286', 'period' => '20200101', 'doc_no' => 'OLD', 'cost' => '1')),
    ), JSON_UNESCAPED_UNICODE));
    $still = $pdo->query("SELECT cost FROM yf_cstmb WHERE item_code = 'A4-2-01-4286'")->fetchColumn();
    yf_check($stale === 200 && yf_format_decimal($still) === '27.564722', 'MySQL 上较旧期间没有覆盖样例成本');

    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
}

function yf_http_status(string $url): int
{
    $ch = curl_init($url);
    if ($ch === false) {
        return 0;
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ));
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function yf_curl(string $url, string $token, string $body): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Yifei-Token: ' . $token),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 10,
    ));
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 && $status !== 401) {
        fwrite(STDERR, "curl {$status}: " . substr((string)$response, 0, 400) . "\n");
    }
    return $status;
}
