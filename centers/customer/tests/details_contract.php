<?php
declare(strict_types=1);

/**
 * 客户中心第二批（二）：分组、多地址、聊天群、客户关系。php centers/customer/tests/details_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';
require_once __DIR__ . '/schema.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\CustomerDetails;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\GroupService;
use Artdon\Centers\Customer\RuleConfig;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pdo = centers_test_reset();
customer_test_schema($pdo);
customer_test_dictionary($pdo);
RuleConfig::clearCache();
$pdo->exec("INSERT INTO crm_rule_configs (rule_key, config_json) VALUES ('customer_admission', '{\"mode\":\"direct\",\"enabled\":1}')");
$perm = $pdo->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'medium')");
foreach (['customer.view', 'customer.create', 'customer.edit', 'customer.batch', 'customer.graph_manage', 'customer.transfer_public', 'customer.view_all', 'contact.view', 'contact.create'] as $k) {
    [$m, $a] = explode('.', $k);
    $perm->execute([$k, $m, $a]);
}
$pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'manager', '主管', 'active')");
foreach ([[2, ['customer.view', 'customer.create', 'customer.edit', 'contact.view', 'contact.create']],
          [3, ['customer.view', 'customer.create', 'customer.edit', 'customer.batch', 'customer.graph_manage', 'customer.transfer_public', 'customer.view_all', 'contact.view', 'contact.create']]] as [$role, $keys]) {
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
$a = (new CustomerService($u($s1)))->create(['customer_name' => 'Alpha Lights', 'country' => 'IN', 'city' => 'Delhi', 'address' => 'Old street 1', 'contact_name' => 'Amit'])['id'];
$b = (new CustomerService($u($s1)))->create(['customer_name' => 'Beta Projects', 'country' => 'AE'])['id'];
$c = (new CustomerService($u($s2)))->create(['customer_name' => 'Gamma Hidden', 'country' => 'DE'])['id'];

// ---------- 分组 ----------
expect_throws(PermissionException::class, static function () use ($u, $s1): void {
    (new GroupService($u($s1)))->create(['group_name' => 'X']);
}, '没有 customer.batch 不能管理分组');
$gs = new GroupService($u($mgr));
$e = expect_throws(ValidationException::class, static function () use ($gs): void {
    $gs->create(['group_name' => '', 'sort_order' => 'abc']);
}, '分组校验');
check(isset($e->fieldErrors()['group_name'], $e->fieldErrors()['sort_order']), '名称必填、排序为数字');
$g1 = $gs->create(['group_name' => '重点项目', 'group_color' => '#DC2626', 'sort_order' => '10']);
$g2 = $gs->create(['group_name' => '印度', 'group_color' => 'red']);
check($pdo->query("SELECT group_color FROM crm_customer_groups WHERE id = {$g1}")->fetchColumn() === '#dc2626' && $pdo->query("SELECT group_color FROM crm_customer_groups WHERE id = {$g2}")->fetchColumn() === GroupService::COLORS[0], '颜色只接受 #RRGGBB');
expect_throws(ValidationException::class, static function () use ($gs): void {
    $gs->create(['group_name' => '重点项目']);
}, '分组名称不能重复');
check($gs->apply([$a, $b], $g1, 'add') === 2, '批量加入分组');
$gs->apply([$a], $g2, 'add');
check(GroupService::forCustomer($pdo, $a) === [$g1, $g2], '加入时保留原有分组');
$gs->apply([$a], $g2, 'replace');
check(GroupService::forCustomer($pdo, $a) === [$g2], '“只放在这个分组”去掉原有分组');
$gs->apply([$b], $g1, 'remove');
check(GroupService::forCustomer($pdo, $b) === [], '移出分组');
expect_throws(PermissionException::class, static function () use ($u, $s1, $c, $g1): void {
    (new GroupService($u($s1)))->apply([$c], $g1, 'add');
}, '没有 customer.batch 不能批量调整分组');
$pdo->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$s1}, 'customer.batch', 'allow')");
\Artdon\Centers\Base\Permission::clearCache();
expect_throws(NotFoundException::class, static function () use ($u, $s1, $c, $g1): void {
    (new GroupService($u($s1)))->apply([$c], $g1, 'add');
}, '不能调整看不到的客户的分组');
// 新建、编辑客户时勾选分组
$svc = new CustomerService($u($s1));
$d = $svc->create(['customer_name' => 'Delta Grouped', 'country' => 'IN', 'group_ids' => [(string) $g1, '9999']])['id'];
check(GroupService::forCustomer($pdo, $d) === [$g1], '新建时勾选分组（不存在的分组忽略）');
$changed = $svc->update($d, ['customer_name' => 'Delta Grouped', 'country' => 'IN', 'group_ids' => [(string) $g2]]);
check(GroupService::forCustomer($pdo, $d) === [$g2] && isset($changed['group_ids']), '编辑时改分组，并记入修改内容');
$svc->update($d, ['customer_name' => 'Delta Grouped', 'country' => 'IN', 'city' => 'Pune']);
check(GroupService::forCustomer($pdo, $d) === [$g2], '没提交分组时不动分组');
// 删除分组：客户不受影响，数据范围也不再按它算
$pdo->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value) VALUES (?, ?, ?, ?)')->execute([$s2, 'customer', 'customer_group', (string) $g2]);
\Artdon\Centers\Base\Permission::clearCache();
$seen = array_map('intval', array_column((new CustomerService($u($s2)))->list('all', [])['rows'], 'id'));
check(in_array($a, $seen, true) && in_array($d, $seen, true), '数据范围“指定分组”能看到分组里的客户');
$gs->delete($g2);
$seen = array_map('intval', array_column((new CustomerService($u($s2)))->list('all', [])['rows'], 'id'));
check(!in_array($a, $seen, true), '分组删除后不再按它算数据范围');
check(GroupService::forCustomer($pdo, $a) === [] && array_keys(GroupService::options()) === [$g1], '删除的分组不再显示');
$again = $gs->create(['group_name' => '印度']);
check($again === $g2 && GroupService::all()[1]['customer_count'] == 0, '重新建同名分组时启用原来的，并从空分组开始');
$gs->apply([$b], $g1, 'add');
check(array_map('intval', array_column($svc->list('all', ['group_id' => $g1])['rows'], 'id')) === [$b], '按分组筛选');

// ---------- 地址 ----------
$det = new CustomerDetails($u($s1));
check(count($det->addresses($a)) === 1 && (int) $det->addresses($a)[0]['is_primary'] === 1, '新建客户时已有主地址');
$e = expect_throws(ValidationException::class, static function () use ($det, $a): void {
    $det->saveAddress($a, ['address_type' => 'X', 'country' => '', 'address' => '']);
}, '地址校验');
check(isset($e->fieldErrors()['address_type'], $e->fieldErrors()['country'], $e->fieldErrors()['address']), '类型、国家、详细地址必填');
$w = $det->saveAddress($a, ['address_type' => 'Warehouse', 'country' => 'India', 'city' => 'Mumbai', 'address' => 'Port road 9', 'contact_name' => 'Ravi', 'phone' => '123']);
check((int) $pdo->query("SELECT is_primary FROM crm_customer_addresses WHERE id = {$w}")->fetchColumn() === 0 && $pdo->query("SELECT country FROM crm_customer_addresses WHERE id = {$w}")->fetchColumn() === 'IN', '新增非主地址，国家存代码');
$det->setPrimaryAddress($a, $w);
check($pdo->query("SELECT country || '/' || city || '/' || address FROM crm_customers WHERE id = {$a}")->fetchColumn() === 'IN/Mumbai/Port road 9', '设为主地址后同步客户资料');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_customer_addresses WHERE customer_id = {$a} AND is_primary = 1 AND deleted_at IS NULL")->fetchColumn() === 1, '只有一个主地址');
expect_throws(RuntimeException::class, static function () use ($det, $a, $w): void {
    $det->deleteAddress($a, $w);
}, '主地址不能删除');
$old = (int) $pdo->query("SELECT id FROM crm_customer_addresses WHERE customer_id = {$a} AND is_primary = 0")->fetchColumn();
$det->deleteAddress($a, $old);
check(count($det->addresses($a)) === 1, '删除非主地址');
$det->saveAddress($a, ['id' => (string) $w, 'address_type' => 'Warehouse', 'country' => 'IN', 'city' => 'Pune', 'address' => 'New road 2']);
check($pdo->query("SELECT city FROM crm_customers WHERE id = {$a}")->fetchColumn() === 'Pune', '编辑主地址也同步客户资料');
expect_throws(NotFoundException::class, static function () use ($det, $c): void {
    $det->saveAddress($c, ['address_type' => 'HQ', 'country' => 'DE', 'address' => 'x']);
}, '不能给看不到的客户加地址');
expect_throws(NotFoundException::class, static function () use ($det, $b, $w): void {
    $det->deleteAddress($b, $w);
}, '不能删别的客户的地址');
(new CustomerService($u($mgr)))->transferPublic([$b], '测试');
expect_throws(PermissionException::class, static function () use ($det, $b): void {
    $det->saveAddress($b, ['address_type' => 'HQ', 'country' => 'AE', 'address' => 'x']);
}, '公海客户要先领取才能维护地址');

// ---------- 聊天群 ----------
$amit = (int) $pdo->query("SELECT id FROM crm_contacts WHERE customer_id = {$a}")->fetchColumn();
$pdo->exec("INSERT INTO crm_contacts (customer_id, name) VALUES ({$c}, 'Other')");
$otherContact = (int) $pdo->lastInsertId();
$e = expect_throws(ValidationException::class, static function () use ($det, $a): void {
    $det->saveChatGroup($a, ['group_name' => '', 'group_platform' => 'telegram']);
}, '聊天群校验');
check(isset($e->fieldErrors()['group_name'], $e->fieldErrors()['group_platform']), '群名称必填、平台只能是微信群或 WhatsApp 群');
$chat = $det->saveChatGroup($a, ['group_name' => 'Alpha 采购群', 'group_platform' => 'whatsapp_group', 'group_owner' => 'Amit', 'contact_ids' => [(string) $amit, (string) $otherContact], 'use_for_promotion' => '1']);
$rows = $det->chatGroups($a);
check(count($rows) === 1 && $rows[0]['contact_ids'] === [$amit] && (int) $rows[0]['use_for_promotion'] === 1 && $rows[0]['status'] === 'active', '新增聊天群，只关联本客户的联系人');
$det->saveChatGroup($a, ['id' => (string) $chat, 'group_name' => 'Alpha 采购群', 'group_platform' => 'whatsapp_group', 'status' => 'paused']);
$rows = $det->chatGroups($a);
check($rows[0]['status'] === 'paused' && (int) $rows[0]['use_for_promotion'] === 0 && $rows[0]['contact_ids'] === [], '编辑聊天群');
$det->deleteChatGroup($a, $chat);
check($det->chatGroups($a) === [], '删除聊天群（软删除）');

// ---------- 客户关系 ----------
expect_throws(PermissionException::class, static function () use ($det, $a, $d): void {
    $det->addRelation($a, ['related_customer_id' => (string) $d, 'relation_type' => 'agent']);
}, '没有 customer.graph_manage 不能登记关系');
$pdo->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$s1}, 'customer.graph_manage', 'allow')");
\Artdon\Centers\Base\Permission::clearCache();
$det = new CustomerDetails($u($s1));
$e = expect_throws(ValidationException::class, static function () use ($det, $a): void {
    $det->addRelation($a, ['related_customer_id' => (string) $a, 'relation_type' => 'xx']);
}, '关系校验');
check(isset($e->fieldErrors()['related_customer_id'], $e->fieldErrors()['relation_type']), '不能和自己建立关系，关系类型要从列表选');
expect_throws(NotFoundException::class, static function () use ($det, $a, $c): void {
    $det->addRelation($a, ['related_customer_id' => (string) $c, 'relation_type' => 'agent']);
}, '不能和看不到的客户建立关系');
$r1 = $det->addRelation($a, ['related_customer_id' => (string) $d, 'relation_type' => 'parent', 'remark' => '集团总部']);
expect_throws(ValidationException::class, static function () use ($det, $a, $d): void {
    $det->addRelation($a, ['related_customer_id' => (string) $d, 'relation_type' => 'parent']);
}, '同一关系不能重复登记');
$det->addRelation($d, ['related_customer_id' => (string) $a, 'relation_type' => 'agent']);
$ra = $det->relations($a);
$rd = $det->relations($d);
check(count($ra) === 2 && count($rd) === 2, '两边都能看到两条关系');
$labels = array_column($ra, 'label');
check(in_array('对方是：母公司', $labels, true) && in_array('我是对方的：代理商', $labels, true), 'A 这边：Delta 是母公司；A 是 Delta 的代理商');
check(in_array('对方是：子公司', array_column($rd, 'label'), true), 'Delta 这边自动显示“对方是子公司”');
$pdo->exec("INSERT INTO crm_customer_relations (customer_id, related_customer_id, relation_type) VALUES ({$c}, {$a}, 'competitor')");
$hidden = array_values(array_filter($det->relations($a), static function (array $r): bool {
    return $r['other_visible'] === false;
}));
check(count($hidden) === 1 && $hidden[0]['other_name'] === '（无权查看的客户）', '看不到的客户不显示名称');
$det->deleteRelation($d, $r1);
check(count($det->relations($a)) === 2, '从另一边也能删除关系');

// ---------- 选客户 ----------
$found = (new CustomerService($u($s1)))->search('a', $a);
$names = array_column($found, 'name');
check(!in_array('Gamma Hidden', $names, true) && !in_array('Alpha Lights', $names, true) && in_array('Delta Grouped', $names, true), '查找客户只找自己看得到的，排除当前客户');
check(array_column((new CustomerService($u($s1)))->search((string) $d), 'id') === [$d], '可以按编号找');
check((new CustomerService($u($s1)))->search('100%') === [], '搜索里的 % 当普通字符');

centers_test_finish('客户中心第二批（二）：分组、地址、聊天群、关系');
