<?php
declare(strict_types=1);

/**
 * 客户中心第二批（三）：导入、导出、名片识别。php centers/customer/tests/import_export_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';
require_once __DIR__ . '/schema.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\BusinessCard;
use Artdon\Centers\Customer\CustomerExport;
use Artdon\Centers\Customer\CustomerImport;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\GroupService;
use Artdon\Centers\Customer\RuleConfig;
use Artdon\Centers\Customer\Spreadsheet;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pdo = centers_test_reset();
customer_test_schema($pdo);
customer_test_dictionary($pdo);
RuleConfig::clearCache();
$pdo->exec("INSERT INTO crm_rule_configs (rule_key, config_json) VALUES ('customer_admission', '{\"mode\":\"direct\",\"enabled\":1}')");
$perm = $pdo->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'medium')");
foreach (['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.import', 'customer.export', 'customer.batch', 'customer.file_delete', 'customer.view_all'] as $k) {
    [$m, $a] = explode('.', $k);
    $perm->execute([$k, $m, $a]);
}
$pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'manager', '主管', 'active')");
foreach ([[2, ['customer.view', 'customer.create', 'customer.edit', 'customer.import']],
          [3, ['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.import', 'customer.export', 'customer.batch', 'customer.file_delete', 'customer.view_all']]] as [$role, $keys]) {
    foreach ($keys as $k) {
        $pdo->prepare('INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$role, $k]);
    }
}
$s1 = centers_test_user(['username' => 's1', 'real_name' => '业务一', 'email' => 's1@x.com']);
$s2 = centers_test_user(['username' => 's2', 'real_name' => '业务二', 'email' => 's2@x.com']);
$mgr = centers_test_user(['username' => 'mgr', 'real_name' => '主管', 'email' => 'm@x.com', 'role_id' => 3]);
$u = static function (int $id): array {
    return Auth::loadUser($id);
};
$tmp = sys_get_temp_dir() . '/centers_import_test_' . getmypid();
@mkdir($tmp);
$write = static function (string $name, string $content) use ($tmp): string {
    file_put_contents($tmp . '/' . $name, $content);
    return $tmp . '/' . $name;
};

// ---------- 读表格 ----------
$rows = Spreadsheet::read($write('a.csv', "\xEF\xBB\xBF客户名称,国家\nAlpha,印度\n"), 'a.csv');
check($rows === [['客户名称', '国家'], ['Alpha', '印度']], 'CSV：UTF-8 带 BOM');
$rows = Spreadsheet::read($write('b.csv', mb_convert_encoding("客户名称;国家\n北方灯饰;德国\n", 'GB18030', 'UTF-8')), 'b.csv');
check($rows === [['客户名称', '国家'], ['北方灯饰', '德国']], 'CSV：GBK 编码、分号分隔');
$rows = Spreadsheet::read($write('c.csv', "name\tcountry\n\"Comma, Inc\"\tDE\n"), 'c.txt');
check($rows[1] === ['Comma, Inc', 'DE'], 'CSV：制表符分隔、带引号');
expect_throws(RuntimeException::class, static function () use ($write): void {
    Spreadsheet::read($write('d.xls', 'xx'), 'd.xls');
}, '老格式 .xls 提示另存');
if (class_exists('ZipArchive')) {
    $x = $tmp . '/e.xlsx';
    $zip = new ZipArchive();
    $zip->open($x, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="客户" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>客户名称</t></si><si><t>国家</t></si><si><r><t>Rich</t></r><r><t> Text Co</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="inlineStr"><is><t>电话</t></is></c></row>'
        . '<row r="3"><c r="A3" t="s"><v>2</v></c><c r="B3" t="inlineStr"><is><t>IN</t></is></c><c r="D3"><v>9.1221234567E+11</v></c></row></sheetData></worksheet>');
    $zip->close();
    $rows = Spreadsheet::read($x, 'e.xlsx');
    check($rows === [['客户名称', '国家', '', '电话'], [], ['Rich Text Co', 'IN', '', '912212345670']], 'Excel：共享文本、行内文本、空列空行、长数字不变成科学计数');
    Spreadsheet::$withoutZipArchive = true;
    check(Spreadsheet::read($x, 'e.xlsx') === $rows, '服务器没有 ZipArchive 时用内置解压读出相同内容');
    Spreadsheet::$withoutZipArchive = false;
} else {
    check(true, '（本机没有 ZipArchive，跳过 Excel 读取测试）');
}

// ---------- 导入：预览 ----------
expect_throws(PermissionException::class, static function () use ($u): void {
    new CustomerImport($u(centers_test_user(['username' => 'v', 'email' => 'v@x.com', 'role_id' => 1, 'status' => 'active', 'is_super_admin' => 0])));
}, '没有导入权限不能导入');
$existing = (new CustomerService($u($s1)))->create(['customer_name' => 'Existing Co', 'country' => 'IN', 'customer_code' => 'C001', 'website' => 'existing-co.in'])['id'];
$g = (new GroupService($u($mgr)))->create(['group_name' => '展会客户']);
$imp = new CustomerImport($u($mgr));
$e = expect_throws(ValidationException::class, static function () use ($imp): void {
    $imp->preview([['国家', '城市'], ['IN', 'Mumbai']]);
}, '没有客户名称列时报错');
$matrix = [
    [],
    ['客户名称', 'Country', '城市', '邮箱', '网站', '客户代码', '等级', '阶段', '来源', '推广方式', '分组', '负责人', '联系人1姓名', '联系人1邮箱', '联系人2姓名', '联系人2电话', '奇怪的列'],
    ['New Lights', '印度', 'Pune', 'info@newlights.in', 'newlights.in', 'N001', '重点客户', '报价中', '展会、介绍', 'WhatsApp', '展会客户', '业务二', 'Asha', 'asha@newlights.in', 'Bo', '998877', 'x'],
    ['', 'IN', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['No Country', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['Bad Country', 'Atlantis', '', 'bad-mail', 'not a site', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['Existing Co', 'IN', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['Code Clash', 'IN', '', '', '', 'C001', '', '', '', '', '', '', '', '', '', '', ''],
    ['New Lights', 'IN', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['Odd Values', 'DE', '', '', '', '', '神秘等级', '', '外星来源', '', '不存在的分组', '不认识的人', '', 'oops', '', '', ''],
    ['Existing Copy Ltd', 'IN', '', 'sales@existing-co.in', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
];
$p = $imp->preview($matrix);
check($p['unknown'] === ['奇怪的列'], '认不出的列单独列出');
check(count($p['rows']) === 9 && $p['rows'][0]['row_no'] === 3 && $p['rows'][8]['row_no'] === 11, '空行跳过，行号按文件里的行');
$r0 = $p['rows'][0];
check(!$r0['errors'] && $r0['data']['country'] === 'IN' && $r0['data']['level'] === 'P1' && $r0['data']['lifecycle_key'] === 'quoting' && $r0['data']['source_tags'] === ['exhibition', 'referral'] && $r0['data']['promotion_channels'] === ['whatsapp'], '中文名称对应到国家代码和字典');
check($r0['data']['group_ids'] === [$g] && (int) $r0['data']['owner_user_id'] === $s2, '分组按名称、负责人按姓名对应（有分配权限）');
check(array_column($r0['data']['contacts'], 'name') === ['Asha', 'Bo'] && $r0['data']['contacts'][1]['phone'] === '998877', '读出两个联系人');
$err = static function (int $i) use ($p): string {
    return implode('；', $p['rows'][$i]['errors']);
};
check(strpos($err(1), '缺少客户名称') !== false, '缺少客户名称');
check(strpos($err(2), '缺少国家') !== false, '缺少国家');
check(strpos($err(3), '不认识') !== false && strpos($err(3), '邮箱格式') !== false && strpos($err(3), '网站格式') !== false, '国家不认识、邮箱网站格式不对');
check(strpos($err(4), '已有同名客户') !== false, '已有同名客户');
check(strpos($err(5), '客户代码已被使用') !== false, '客户代码已被使用');
check(strpos($err(6), '文件里有同名客户（第 3 行）') !== false, '文件里同名');
$w7 = implode('；', $p['rows'][7]['warnings']);
check(!$p['rows'][7]['errors'] || strpos($err(7), '联系人1') !== false, '联系人邮箱格式不对算错误');
check(strpos($w7, '等级“神秘等级”不认识') !== false && strpos($w7, '外星来源') !== false && strpos($w7, '不存在的分组') !== false && strpos($w7, '不认识的人') !== false, '不认识的等级、来源、分组、负责人给提示');
check($p['rows'][8]['high_risk'] && strpos(implode('；', $p['rows'][8]['warnings']), 'Existing Co') !== false, '和已有客户域名相同：高度疑似重复');
check($p['valid'] === 2, '可导入行数');
$imp1 = new CustomerImport($u($s1));
$p1 = $imp1->preview([['客户名称', '国家', '负责人'], ['Self Owned', 'IN', '业务二']]);
check((int) $p1['rows'][0]['data']['owner_user_id'] === $s1 && strpos(implode('', $p1['rows'][0]['warnings']), '没有分配权限') !== false, '没有分配权限时负责人是自己');

// ---------- 导入：确认 ----------
$csv = "客户名称,国家,城市,来源,分组,联系人1姓名,联系人1邮箱\nImport One,印度,Pune,展会,展会客户,Asha,asha@one.in\nImport Two,DE,,,,,\nExisting Co,IN,,,,,\nExisting Twin,IN,,,,,sales@existing-co.in\n";
$token = $imp->upload(['tmp_name' => $write('f.csv', $csv), 'name' => 'customers.csv', 'size' => strlen($csv), 'error' => UPLOAD_ERR_OK]);
check(preg_match('/^[a-f0-9]{32}$/', $token) === 1, '上传后得到预览编号');
expect_throws(RuntimeException::class, static function () use ($imp1, $token): void {
    $imp1->load($token);
}, '别人的导入预览打不开');
$sum = $imp->commit($token);
check($sum === ['created' => 2, 'lead_pool' => 1, 'failed' => 0, 'skipped' => 1], '导入：新建 2、疑似重复进暂存池 1、同名跳过 1');
$one = $pdo->query("SELECT * FROM crm_customers WHERE customer_name = 'Import One'")->fetch();
check($one['country'] === 'IN' && (int) $one['owner_user_id'] === $mgr, '导入的客户资料');
check($pdo->query("SELECT name FROM crm_contacts WHERE customer_id = {$one['id']}")->fetchColumn() === 'Asha' && GroupService::forCustomer($pdo, (int) $one['id']) === [$g], '联系人、分组一起导入');
check($pdo->query("SELECT source_key FROM crm_customer_source_tags WHERE customer_id = {$one['id']}")->fetchAll(PDO::FETCH_COLUMN) === ['exhibition'], '来源按文件');
$two = $pdo->query("SELECT id FROM crm_customers WHERE customer_name = 'Import Two'")->fetchColumn();
check($pdo->query("SELECT source_key FROM crm_customer_source_tags WHERE customer_id = {$two}")->fetchAll(PDO::FETCH_COLUMN) === ['website'], '没填来源时用默认值');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_lead_pool WHERE raw_name = 'Existing Twin'")->fetchColumn() === 1, '高度疑似重复的进暂存池');
check($imp->load($token)['result']['created'] === 2, '导入结果保存在预览里');
expect_throws(RuntimeException::class, static function () use ($imp, $token): void {
    $imp->commit($token);
}, '同一份文件不能导入两次');
$pdo->exec("UPDATE crm_rule_configs SET config_json = '{\"mode\":\"import_only\",\"enabled\":1}' WHERE rule_key = 'customer_admission'");
RuleConfig::clearCache();
$csv2 = "客户名称,国家\nPool Only One,DE\n";
$t2 = $imp->upload(['tmp_name' => $write('g.csv', $csv2), 'name' => 'g.csv', 'size' => strlen($csv2), 'error' => UPLOAD_ERR_OK]);
check($imp->commit($t2)['lead_pool'] === 1, '准入规则“只有导入的进暂存池”时导入的全部进暂存池');
$pdo->exec("UPDATE crm_rule_configs SET config_json = '{\"mode\":\"direct\",\"enabled\":1}' WHERE rule_key = 'customer_admission'");
RuleConfig::clearCache();
expect_throws(ValidationException::class, static function () use ($imp, $write): void {
    $imp->upload(['tmp_name' => $write('h.pdf', 'x'), 'name' => 'h.pdf', 'size' => 1, 'error' => UPLOAD_ERR_OK]);
}, '只接受 xlsx、csv');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_sensitive_audit_logs WHERE action_key = 'customer_import_commit'")->fetchColumn() === 2, '导入写敏感审计');

// ---------- 导出 ----------
expect_throws(PermissionException::class, static function () use ($u, $s1): void {
    new CustomerExport($u($s1));
}, '没有导出权限不能导出');
$rows = (new CustomerExport($u($mgr)))->rows('all', ['q' => 'Import One']);
check($rows[0] === CustomerExport::headers() && count($rows) === 2, '导出：表头 + 符合筛选的客户');
$line = array_combine($rows[0], $rows[1]);
check($line['客户名称'] === 'Import One' && $line['国家'] === '印度' && $line['来源'] === '展会' && $line['分组'] === '展会客户' && $line['联系人1姓名'] === 'Asha' && $line['负责人'] === '主管', '导出内容（国家、来源用中文）');
[$map, $unknown] = CustomerImport::mapHeaders(CustomerExport::headers());
check(in_array('customer_name', $map, true) && in_array('contact3_whatsapp', $map, true) && in_array('owner', $map, true), '导出的文件表头能直接导入');
check(CustomerExport::safeCell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && CustomerExport::safeCell('+91 22 1234') === '+91 22 1234' && CustomerExport::safeCell('-') === "'-", '防止 Excel 公式注入，电话号码不受影响');
(new CustomerService($u($s2)))->create(['customer_name' => 'S2 Private', 'country' => 'DE']);
$pdo->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$s1}, 'customer.export', 'allow')");
\Artdon\Centers\Base\Permission::clearCache();
$names = array_column(array_slice((new CustomerExport($u($s1)))->rows('all', []), 1), 1);
check(!in_array('S2 Private', $names, true) && in_array('Existing Co', $names, true), '只导出自己看得到的客户');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_sensitive_audit_logs WHERE action_key = 'customer_export'")->fetchColumn() === 2, '导出写敏感审计');

// ---------- 名片识别 ----------
$response = ['BusinessCardInfos' => [
    ['Name' => '公司', 'Value' => '迪拜灯饰贸易有限公司'], ['Name' => '公司英文', 'Value' => 'Dubai Lights Trading LLC'], ['Name' => '姓名', 'Value' => '阿里'], ['Name' => '英文姓名', 'Value' => 'Ali Hassan'],
    ['Name' => '职位', 'Value' => 'Purchase Manager'], ['Name' => '手机', 'Value' => '+971 50 123 4567'], ['Name' => '邮箱', 'Value' => 'ali@dubailights.ae'], ['Name' => '地址', 'Value' => 'Deira, Dubai, UAE'],
    ['Name' => '网址', 'Value' => 'www.dubailights.ae'], ['Name' => '微信', 'Value' => 'ali_wx'],
], 'RequestId' => 'req-1'];
$pdo->exec("INSERT INTO crm_dictionary_items (type_key, item_key, name_cn, name_en, is_default, is_enabled, sort_order) VALUES ('country_region', 'ID', '印度尼西亚', 'Indonesia', 0, 1, 99)");
\Artdon\Centers\Base\Dictionary::clearCache();
$parsed = BusinessCard::parse($response);
check($parsed['customer']['customer_name'] === '迪拜灯饰贸易有限公司' && $parsed['customer']['customer_name_en'] === 'Dubai Lights Trading LLC' && $parsed['customer']['country'] === 'AE', '名片：中英文公司名、按地址电话猜国家');
check($parsed['contact']['name'] === '阿里' && $parsed['contact']['name_en'] === 'Ali Hassan' && $parsed['contact']['position'] === 'Purchase Manager' && $parsed['contact']['wechat'] === 'ali_wx', '名片：联系人');
check(BusinessCard::guessCountry('Jakarta, Indonesia') === 'ID' && BusinessCard::guessCountry('Somewhere') === '', '印度尼西亚不会认成印度；认不出时为空');
expect_throws(RuntimeException::class, static function (): void {
    BusinessCard::parse(['Error' => ['Code' => 'FailedOperation.OcrFailed', 'Message' => 'OCR识别失败']]);
}, '识别服务报错时显示原因');
expect_throws(RuntimeException::class, static function (): void {
    BusinessCard::parse(['BusinessCardInfos' => []]);
}, '没识别到内容时提示换照片');
// 32×20 的灰色 PNG（不依赖 GD 扩展）
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAUCAIAAABj86gYAAAAHklEQVR4nGP4QGPAMGrBqAWjFoxaMGrBqAWjFhADAOc/CGo3KHXfAAAAAElFTkSuQmCC');
$storage = $tmp . '/storage';
$sent = [];
$transport = static function (string $url, array $headers) use (&$sent, $response): string {
    $sent = ['url' => $url, 'headers' => $headers];
    return (string) json_encode(['Response' => $response]);
};
$card = new BusinessCard($u($s1), $pdo, $transport, $storage, ['secret_id' => 'AKID', 'secret_key' => 'KEY']);
$r = $card->recognize(['tmp_name' => $write('card.png', $png), 'name' => 'card.png', 'size' => strlen($png), 'error' => UPLOAD_ERR_OK]);
check($sent['url'] === 'https://ocr.tencentcloudapi.com' && $sent['headers']['X-TC-Action'] === 'BusinessCardOCR' && strpos($sent['headers']['Authorization'], 'TC3-HMAC-SHA256 Credential=AKID/') === 0, '按腾讯云签名方式请求');
check(strlen($r['token']) === 40 && is_file($storage . '/pending_business_cards/' . $r['token'] . '.jpg'), '识别后图片暂存（转成 JPG）');
expect_throws(RuntimeException::class, static function () use ($u, $s1, $pdo, $transport, $storage, $write, $png): void {
    (new BusinessCard($u($s1), $pdo, $transport, $storage, []))->recognize(['tmp_name' => $write('card2.png', $png), 'name' => 'c.png', 'size' => 1, 'error' => UPLOAD_ERR_OK]);
}, '服务器没配置密钥时提示');
expect_throws(ValidationException::class, static function () use ($card, $write): void {
    $card->recognize(['tmp_name' => $write('t.txt', 'hello'), 'name' => 't.txt', 'size' => 5, 'error' => UPLOAD_ERR_OK]);
}, '不是图片时提示');
$fileId = $card->attach($existing, $r['token']);
check($fileId > 0 && !is_file($storage . '/pending_business_cards/' . $r['token'] . '.jpg') && count($card->forCustomer($existing)) === 1, '名片图移到客户下并记录');
check($card->attach($existing, $r['token']) === null && $card->attach($existing, '../../etc/passwd') === null, '令牌只能用一次，非法令牌忽略');
check($card->file($fileId)['customer_id'] == $existing, '能看到客户就能看名片');
expect_throws(NotFoundException::class, static function () use ($u, $s2, $pdo, $storage, $fileId): void {
    (new BusinessCard($u($s2), $pdo, null, $storage))->file($fileId);
}, '看不到客户就看不到名片');
expect_throws(PermissionException::class, static function () use ($card, $fileId): void {
    $card->delete($fileId);
}, '没有 customer.file_delete 不能删名片');
$path = (string) $pdo->query("SELECT file_path FROM crm_customer_files WHERE id = {$fileId}")->fetchColumn();
(new BusinessCard($u($mgr), $pdo, null, $storage))->delete($fileId);
check(!is_file($path) && $card->forCustomer($existing) === [], '删除名片图片（文件一起删）');
// 新建客户时带上令牌
$r2 = $card->recognize(['tmp_name' => $write('card3.png', $png), 'name' => 'card.png', 'size' => strlen($png), 'error' => UPLOAD_ERR_OK]);
$created = (new CustomerService($u($s1)))->create(['customer_name' => 'Card Customer', 'country' => 'AE', 'business_card_token' => $r2['token']]);
check($created['status'] === 'created', '带名片令牌新建客户');

array_map('unlink', array_filter((array) glob($tmp . '/*'), 'is_file'));
centers_test_finish('客户中心第二批（三）：导入、导出、名片识别');
