<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/includes/bom_miniapp_read.php';

function mp_fail(string $message): void
{
    throw new RuntimeException($message);
}

function mp_check($ok, string $message): void
{
    if (!$ok) {
        mp_fail($message);
    }
}

function mp_near($left, $right, string $message): void
{
    mp_check(is_numeric($left) && is_numeric($right) && abs((float)$left - (float)$right) < 0.001, $message . ' got ' . json_encode($left) . ' expected ' . json_encode($right));
}

function mp_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE bom_projects (
        id INTEGER PRIMARY KEY,
        project_uid TEXT,
        name TEXT,
        customer TEXT,
        model TEXT,
        product_type TEXT,
        version_no TEXT,
        variant_label TEXT,
        currency TEXT,
        note TEXT,
        review_status TEXT,
        labor REAL,
        other REAL,
        profit_rate REAL,
        quote_mode TEXT,
        rows_json TEXT,
        totals_json TEXT,
        row_count INTEGER,
        updated_at TEXT,
        is_active INTEGER
    )");
    return $pdo;
}

function mp_insert(PDO $pdo, array $row): void
{
    $defaults = [
        'name' => '', 'customer' => '', 'model' => '', 'product_type' => '', 'version_no' => 'V1',
        'variant_label' => '', 'currency' => 'RMB', 'note' => '', 'review_status' => 'draft',
        'labor' => 0, 'other' => 0, 'profit_rate' => 30, 'quote_mode' => 'markup',
        'rows_json' => '[]', 'totals_json' => null, 'row_count' => null, 'is_active' => 1,
    ];
    $row = array_merge($defaults, $row);
    $pdo->prepare('INSERT INTO bom_projects (project_uid,name,customer,model,product_type,version_no,variant_label,currency,note,review_status,labor,other,profit_rate,quote_mode,rows_json,totals_json,row_count,updated_at,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,?)')
        ->execute([
            $row['project_uid'], $row['name'], $row['customer'], $row['model'], $row['product_type'],
            $row['version_no'], $row['variant_label'], $row['currency'], $row['note'], $row['review_status'],
            $row['labor'], $row['other'], $row['profit_rate'], $row['quote_mode'], $row['rows_json'],
            $row['totals_json'], $row['row_count'], $row['updated_at'], $row['is_active'],
        ]);
}

function mp_quote_keys(string $src): array
{
    preg_match_all("/'([^']+)'/", $src, $keys);
    return $keys[1];
}

$website = file_get_contents($root . '/bom_api.php');
$helper = file_get_contents($root . '/includes/bom_miniapp_read.php');
$api = file_get_contents($root . '/miniapp/api/index.php');
mp_check($website !== false && $helper !== false && $api !== false, '来源文件可读');

$fnStart = strpos($website, 'function bom_hide_sensitive_row');
$fnEnd = strpos($website, 'function bom_hide_sensitive_list');
mp_check($fnStart !== false && $fnEnd !== false, '网站脱敏函数存在');
$body = substr($website, $fnStart, $fnEnd - $fnStart);
preg_match_all('/foreach\s*\(\s*array\((.*?)\)\s*as\s*\$k\)/s', $body, $groups);
mp_check(count($groups[1]) === 2, '网站脱敏有金额和供应商两组键');
mp_check(mp_quote_keys($groups[1][0]) === bom_mp_cost_keys(), '金额脱敏键与网站一致');
mp_check(mp_quote_keys($groups[1][1]) === bom_mp_supplier_keys(), '供应商脱敏键与网站一致');

$hidden = bom_mp_hide_row(['name' => '灯', 'price' => 9, 'supplier' => 'SecretCo'], false, false);
mp_check($hidden['name'] === '灯' && $hidden['price'] === '' && $hidden['supplier'] === '' && !array_key_exists('cost', $hidden), '只清空已有的敏感键');
mp_check(bom_mp_review_status_label('preliminary') === '预审' && bom_mp_review_status_label('pending') === '待审核' && bom_mp_review_status_label('approved') === '终审', '阶段文案与网站一致');

foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE '] as $sql) {
    mp_check(strpos($helper, $sql) === false, '只读助手不含 ' . trim($sql));
}
mp_check(strpos($helper, 'bom_dashboard_search') !== false, '线上关键词走网站总览搜索');
foreach (['save_project', 'delete_project', 'submit_review', 'withdraw_review', 'approve_project', 'reject_project', 'unapprove_project', 'create_snapshot', 'void_snapshot', 'save_material', 'delete_material', 'import_materials_bulk', 'create_from_naming', 'bind_naming_to_project'] as $action) {
    mp_check(preg_match("/case\\s+'" . $action . "'/", $api) !== 1, '小程序不暴露 ' . $action);
    mp_check(strpos($helper, $action) === false, '只读助手不引用 ' . $action);
}

$case = substr($api, (int)strpos($api, "case 'bom_meta'"));
mp_check(strpos($case, 'mapi_auth($input)') !== false, 'BOM 接口先校验 X-Token');
mp_check(strpos($case, "artdon_sso_can('bom', 'view')") !== false, '查看权限走网站 SSO');
mp_check(strpos($case, "artdon_sso_can('bom', 'cost_view')") !== false, '成本权限走网站 SSO');
mp_check(strpos($case, "artdon_sso_can('bom', 'supplier_view')") !== false, '供应商权限走网站 SSO');
mp_check(strpos($case, 'artdon_bom_ensure_permissions()') !== false, '沿用网站 BOM 权限项');
mp_check(strpos($case, 'bom_mp_handle') !== false, '三个只读 action 走同一只读入口');
mp_check(strpos($api, "'bom_list' => '进入 BOM 成本'") !== false && strpos($api, "'bom_detail' => '查看成本表'") !== false, '页面日志沿用小程序审计');

$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$meta = bom_mp_handle($bare, 'bom_meta', [], true, false, true);
mp_check($meta['ok'] === true && $meta['data']['can'] === ['view' => true, 'cost_view' => false, 'supplier_view' => true], 'bom_meta 只返回权限');
$denied = bom_mp_handle($bare, 'bom_list', [], false, true, true);
mp_check($denied['ok'] === false && $denied['message'] === '当前账号没有 BOM 查看权限' && $denied['data']['code'] === 'BOM_FORBIDDEN', '无查看权限拒绝列表');
$deniedDetail = bom_mp_handle($bare, 'bom_detail', ['id' => 'BOM-1'], false, true, true);
mp_check($deniedDetail['data']['code'] === 'BOM_FORBIDDEN', '无查看权限拒绝详情');
$blocked = bom_mp_handle($bare, 'save_project', ['name' => 'x'], true, true, true);
mp_check($blocked['ok'] === false && $blocked['data']['code'] === 'BOM_UNKNOWN', '写动作没有入口');
$tables = $bare->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
mp_check($tables === [], '拒绝和元数据请求不建表');

$long = bom_mp_handle(mp_pdo(), 'bom_list', ['keyword' => str_repeat('测', 200)], true, true, false);
mp_check($long['ok'] === false && $long['message'] === '搜索文字过长', '关键词最长 480 字节');

$mathRows = [
    ['category' => '光学', 'name' => '透镜', 'spec' => '直径 20', 'qty' => 2, 'price' => 10, 'process' => 1, 'finish' => '阳极', 'finishCost' => 0.5, 'finish2' => '喷涂', 'finishCost2' => 0.25, 'supplier' => 'SecretCo'],
    ['category' => '紧固', 'name' => '螺丝', 'spec' => 'M3', 'qty' => 1, 'unit_price' => 4, 'process_cost' => 0, 'supplier' => 'SecretCo'],
];
$mathProject = ['labor' => 3, 'other' => 1, 'profit_rate' => 30, 'quote_mode' => 'markup', 'rows' => $mathRows];
$computed = bom_mp_compute_totals($mathProject);
$expected = bw_totals($mathProject);
mp_check($computed === $expected, '加价汇总与网站 bw_totals 相同');
mp_near($expected['material'], 27.5, '材料成本');
mp_near($expected['total'], 31.5, '总成本');
mp_near($expected['suggest'], 40.95, '建议报价');
mp_near($expected['profit'], 9.45, '利润');
$margin = bom_mp_compute_totals(['labor' => 0, 'other' => 0, 'profit_rate' => 20, 'quote_mode' => 'margin', 'rows' => [['qty' => 1, 'price' => 80]]]);
$marginSite = bw_totals(['labor' => 0, 'other' => 0, 'profit_rate' => 20, 'quote_mode' => 'margin', 'rows' => [['qty' => 1, 'price' => 80]]]);
mp_check($margin === $marginSite, '毛利率汇总与网站 bw_totals 相同');
mp_near($margin['suggest'], 100, '毛利率建议报价');

$pdo = mp_pdo();
$secretRows = json_encode([['name' => '外壳', 'spec' => '铝', 'qty' => 1, 'price' => 987654.32, 'supplier' => 'SecretCo']], JSON_UNESCAPED_UNICODE);
$cache = json_encode(['material' => 1, 'labor' => 1, 'other' => 1, 'total' => 111.25, 'suggest' => 2, 'profit' => 1], JSON_UNESCAPED_UNICODE);
mp_insert($pdo, [
    'project_uid' => 'BOM-SECRET', 'name' => 'Alpha', 'customer' => '客户甲', 'model' => 'M-1', 'product_type' => '筒灯',
    'review_status' => 'preliminary', 'rows_json' => $secretRows, 'totals_json' => $cache, 'row_count' => 7,
    'updated_at' => gmdate('Y-m-d H:i:s'), 'currency' => 'RMB', 'note' => '备注一行', 'version_no' => 'V3', 'variant_label' => '欧版',
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-MATH', 'name' => 'Beta', 'customer' => '客户乙', 'model' => 'M-2', 'product_type' => '射灯',
    'review_status' => 'draft', 'labor' => 3, 'other' => 1, 'profit_rate' => 30, 'quote_mode' => 'markup',
    'rows_json' => json_encode($mathRows, JSON_UNESCAPED_UNICODE), 'totals_json' => null, 'row_count' => null,
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 10),
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-OLD', 'name' => 'Gamma', 'customer' => '客户丙', 'model' => 'M-3',
    'review_status' => 'approved', 'rows_json' => '[]', 'row_count' => 0,
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 40),
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-HIDDEN', 'name' => 'INACTIVE-SHOULD-HIDE', 'model' => 'M-9',
    'review_status' => 'preliminary', 'is_active' => 0, 'rows_json' => $secretRows, 'row_count' => 1,
    'updated_at' => gmdate('Y-m-d H:i:s'),
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-PEND', 'name' => 'Pending', 'model' => 'M-4', 'review_status' => 'pending',
    'rows_json' => '[]', 'row_count' => 0, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400),
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-WILD', 'name' => 'A_B%灯', 'model' => 'M-5', 'review_status' => 'draft',
    'rows_json' => '[]', 'row_count' => 0, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 2),
]);
mp_insert($pdo, [
    'project_uid' => 'BOM-WILD2', 'name' => 'AXB灯', 'model' => 'M-6', 'review_status' => 'draft',
    'rows_json' => '[]', 'row_count' => 0, 'updated_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 2),
]);

$all = bom_mp_handle($pdo, 'bom_list', ['page' => 1, 'page_size' => 20], true, true, false);
mp_check($all['ok'] === true, '有权限可以列成本单');
$ids = array_column($all['data']['list'], 'project_uid');
mp_check(!in_array('BOM-HIDDEN', $ids, true), '停用成本单不出现');
mp_check(in_array('BOM-SECRET', $ids, true) && in_array('BOM-PEND', $ids, true), '待审核留在全部里');
mp_check($all['data']['list'][0]['project_uid'] === 'BOM-SECRET', '按更新时间倒序');
$secret = null;
foreach ($all['data']['list'] as $item) {
    if ($item['project_uid'] === 'BOM-SECRET') $secret = $item;
}
mp_check($secret !== null && $secret['row_count'] === 7 && $secret['review_status_label'] === '预审', '列表用轻量行数和阶段文案');
mp_near($secret['totals_summary']['total'], 111.25, '列表优先用网站缓存总成本');
$listed = json_encode($all['data'], JSON_UNESCAPED_UNICODE);
mp_check(strpos($listed, '987654.32') === false && strpos($listed, 'SecretCo') === false && strpos($listed, 'rows_json') === false, '列表不带回明细、单价和供应商');

$blind = bom_mp_handle($pdo, 'bom_list', [], true, false, false);
$blindJson = json_encode($blind['data'], JSON_UNESCAPED_UNICODE);
mp_check(strpos($blindJson, '987654.32') === false && strpos($blindJson, '111.25') === false && strpos($blindJson, 'SecretCo') === false, '无成本权限的列表没有金额');
foreach ($blind['data']['list'] as $item) {
    mp_check(json_encode($item['totals_summary']) === '{}', '无成本权限汇总为空对象');
    mp_check(!array_key_exists('price', $item) && !array_key_exists('rows', $item), '列表项没有金额行');
}

$recent = bom_mp_handle($pdo, 'bom_list', ['date_range' => '7d', 'review_status' => 'preliminary'], true, false, false);
$recentIds = array_column($recent['data']['list'], 'project_uid');
mp_check($recentIds === ['BOM-SECRET'], '预审且近 7 天只留对应成本单');
$month = array_column(bom_mp_handle($pdo, 'bom_list', ['date_range' => 'month'], true, false, false)['data']['list'], 'project_uid');
mp_check(!in_array('BOM-OLD', $month, true) && in_array('BOM-MATH', $month, true), '本月按 updated_at，40 天前不在本月');
$customer = array_column(bom_mp_handle($pdo, 'bom_list', ['keyword' => '客户甲'], true, false, false)['data']['list'], 'project_uid');
mp_check($customer === ['BOM-SECRET'], '客户名搜索');
$material = array_column(bom_mp_handle($pdo, 'bom_list', ['keyword' => '透镜'], true, true, true)['data']['list'], 'project_uid');
mp_check($material === ['BOM-MATH'], '物料名称搜索不扫供应商');
$supplierHit = bom_mp_handle($pdo, 'bom_list', ['keyword' => 'SecretCo'], true, true, true);
mp_check($supplierHit['data']['list'] === [], '供应商文字不能被搜出来');
$wild = array_column(bom_mp_handle($pdo, 'bom_list', ['keyword' => 'A_B%'], true, false, false)['data']['list'], 'project_uid');
mp_check($wild === ['BOM-WILD'], '关键词里的下划线和百分号按字面匹配');
$paged = bom_mp_handle($pdo, 'bom_list', ['page' => 1, 'page_size' => 2], true, false, false);
mp_check($paged['data']['page_size'] === 2 && $paged['data']['has_more'] === true && count($paged['data']['list']) === 2, '第一页还有下一页');
$paged2 = bom_mp_handle($pdo, 'bom_list', ['page' => 2, 'page_size' => 2], true, false, false);
mp_check(count($paged2['data']['list']) === 2 && $paged['data']['list'][0]['project_uid'] !== $paged2['data']['list'][0]['project_uid'], '第二页不重复第一页');
$capped = bom_mp_handle($pdo, 'bom_list', ['page_size' => 80], true, false, false);
mp_check($capped['data']['page_size'] === 50, '每页最多 50 条');
$unknownStage = bom_mp_handle($pdo, 'bom_list', ['review_status' => 'rejected'], true, false, false);
mp_check(count($unknownStage['data']['list']) === count($all['data']['list']), '列表芯片以外的阶段归入全部');

$detail = bom_mp_handle($pdo, 'bom_detail', ['id' => 'BOM-MATH'], true, true, true);
mp_check($detail['ok'] === true && $detail['data']['project']['row_count'] === 2, '详情行数');
$project = $detail['data']['project'];
mp_check($project['rows'][0]['finish'] === '阳极' && $project['rows'][0]['name'] === '透镜' && (float)$project['rows'][0]['qty'] === 2.0, '详情保留名称规格数量');
mp_near($project['rows'][0]['subtotal'], 23.5, '第一行小计');
mp_near($project['rows'][1]['subtotal'], 4, '别名字段单价计入小计');
mp_near($project['totals_summary']['material'], $expected['material'], '详情材料成本与网站相同');
mp_near($project['totals_summary']['labor'], $expected['labor'], '详情人工与网站相同');
mp_near($project['totals_summary']['other'], $expected['other'], '详情其它与网站相同');
mp_near($project['totals_summary']['total'], $expected['total'], '详情总成本与网站相同');
mp_near($project['totals_summary']['suggest'], $expected['suggest'], '详情建议报价与网站相同');
mp_near($project['totals_summary']['profit'], $expected['profit'], '详情利润与网站相同');
$sum = 0.0;
foreach ($project['rows'] as $line) $sum += (float)$line['subtotal'];
mp_near($sum, $project['totals_summary']['material'], '行小计之和等于材料成本');
$detailJson = json_encode($project, JSON_UNESCAPED_UNICODE);
mp_check(strpos($detailJson, 'SecretCo') === false && strpos($detailJson, 'rows_json') === false, '有供应商权限也不在一期详情里返回供应商');

$closed = bom_mp_handle($pdo, 'bom_detail', ['project_uid' => 'BOM-SECRET'], true, false, false);
$closedJson = json_encode($closed['data'], JSON_UNESCAPED_UNICODE);
mp_check($closed['ok'] === true && json_encode($closed['data']['project']['totals_summary']) === '{}', '无成本权限详情没有汇总');
mp_check(strpos($closedJson, '987654.32') === false && strpos($closedJson, 'SecretCo') === false && strpos($closedJson, 'price') === false, '无成本权限详情没有单价和供应商');
mp_check($closed['data']['project']['rows'][0]['name'] === '外壳' && (float)$closed['data']['project']['rows'][0]['qty'] === 1.0, '无成本权限仍能看结构');
mp_check(!array_key_exists('subtotal', $closed['data']['project']['rows'][0]), '无成本权限没有小计');

$missing = bom_mp_handle($pdo, 'bom_detail', ['id' => 'BOM-NONE'], true, true, false);
mp_check($missing['ok'] === false && $missing['message'] === '没有找到这份 BOM', '找不到成本单');
$inactive = bom_mp_handle($pdo, 'bom_detail', ['id' => 'BOM-HIDDEN'], true, true, false);
mp_check($inactive['message'] === '没有找到这份 BOM', '停用成本单不能打开');

$mini = $root . '/wechat-miniprogram';
foreach ([
    'app.json',
    'pages/home/home.wxml',
    'pages/home/home.js',
    'pages/bom/list.js',
    'pages/bom/list.wxml',
    'pages/bom/list.wxss',
    'pages/bom/list.json',
    'pages/bom/detail.js',
    'pages/bom/detail.wxml',
    'pages/bom/detail.wxss',
    'pages/bom/detail.json',
    'utils/api.js',
    'utils/i18n.js',
    'utils/money.js',
] as $rel) {
    mp_check(is_file($mini . '/' . $rel), '缺少小程序文件 ' . $rel);
}
$appJson = file_get_contents($mini . '/app.json');
mp_check(strpos($appJson, 'pages/bom/list') !== false && strpos($appJson, 'pages/bom/detail') !== false, '页面已注册');
mp_check(strpos($appJson, 'tabBar') === false, '不加底栏');
$homeWxml = file_get_contents($mini . '/pages/home/home.wxml');
$homeJs = file_get_contents($mini . '/pages/home/home.js');
mp_check(strpos($homeWxml, 'menu-grid') !== false && strpos($homeWxml, 'goBom') !== false && strpos($homeWxml, 'menu_bom') !== false, '首页宫格入口');
mp_check(strpos($homeJs, '/pages/bom/list') !== false, '宫格进入列表');
$i18n = file_get_contents($mini . '/utils/i18n.js');
mp_check(strpos($i18n, "menu_bom: 'BOM 成本'") !== false && strpos($i18n, "menu_bom_desc: '查看成本表'") !== false, '中文入口文案');
$client = file_get_contents($mini . '/utils/api.js');
mp_check(strpos($client, 'getBomMeta') !== false && strpos($client, 'getBomList') !== false && strpos($client, 'getBomDetail') !== false, '前端三个只读方法');
mp_check(strpos($client, 'X-Token') !== false && strpos($client, 'success === false') !== false, '前端认小程序响应信封');
mp_check(strpos($client, 'logPage') !== false && strpos(file_get_contents($mini . '/pages/bom/list.js'), "logPage('bom_list')") !== false, '列表记页面日志');
mp_check(strpos(file_get_contents($mini . '/pages/bom/detail.js'), "logPage('bom_detail'") !== false, '详情记页面日志');
$ui = '';
foreach (['pages/home/home.wxml', 'pages/bom/list.wxml', 'pages/bom/list.js', 'pages/bom/detail.wxml', 'pages/bom/detail.js'] as $rel) {
    $ui .= file_get_contents($mini . '/' . $rel);
}
foreach (['保存', '删除', '导入', '导出', '改价', '退审', '审核通过', '撤回'] as $word) {
    mp_check(strpos($ui, $word) === false, '手机页面没有「' . $word . '」');
}
mp_check(strpos(file_get_contents($mini . '/pages/bom/detail.wxml'), 'totals') !== false, '详情有吸底汇总');
mp_check(strpos(file_get_contents($mini . '/pages/bom/list.js'), 'cost_view') !== false, '列表金额看成本权限');

echo "BOM miniapp read-only contracts passed.\n";
