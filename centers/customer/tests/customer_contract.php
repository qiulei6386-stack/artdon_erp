<?php
declare(strict_types=1);

/**
 * 客户中心第一批契约测试。不连正式库：php centers/customer/tests/customer_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';
require_once __DIR__ . '/schema.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\ContactService;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\DuplicateFinder;
use Artdon\Centers\Customer\RuleConfig;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pdo = centers_test_reset();
customer_test_schema($pdo);
customer_test_dictionary($pdo);
RuleConfig::clearCache();

$perm = $pdo->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'medium')");
foreach (['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.delete', 'customer.restore', 'customer.view_logs', 'customer.view_all', 'customer.view_department',
          'customer.transfer_public', 'customer.claim_public', 'contact.view', 'contact.create', 'contact.edit', 'contact.delete'] as $k) {
    [$m, $a] = explode('.', $k);
    $perm->execute([$k, $m, $a]);
}
$pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'manager', '主管', 'active'), (4, 'viewer', '只读', 'active')");
$grant = static function (int $role, array $keys) use ($pdo): void {
    foreach ($keys as $k) {
        $pdo->prepare('INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$role, $k]);
    }
};
$grant(2, ['customer.view', 'customer.create', 'customer.edit', 'contact.view', 'contact.create', 'contact.edit']);
$grant(3, ['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.delete', 'customer.restore', 'customer.view_logs', 'customer.view_department',
           'customer.transfer_public', 'customer.claim_public', 'contact.view', 'contact.create', 'contact.edit', 'contact.delete']);
$grant(4, ['contact.view']);
$s1 = centers_test_user(['username' => 's1', 'real_name' => '业务一', 'email' => 's1@x.com', 'department_id' => 1]);
$s2 = centers_test_user(['username' => 's2', 'real_name' => '业务二', 'email' => 's2@x.com', 'department_id' => 1]);
$s3 = centers_test_user(['username' => 's3', 'real_name' => '财务业务', 'email' => 's3@x.com', 'department_id' => 2]);
$mgr = centers_test_user(['username' => 'mgr', 'real_name' => '主管', 'email' => 'm@x.com', 'role_id' => 3, 'department_id' => 1]);
$viewer = centers_test_user(['username' => 'v', 'real_name' => '只读', 'email' => 'v@x.com', 'role_id' => 4]);
$off = centers_test_user(['username' => 'off', 'real_name' => '离职', 'email' => 'o@x.com', 'status' => 'disabled']);
$svc = static function (int $id): CustomerService {
    return new CustomerService(Auth::loadUser($id));
};
$setRule = static function (string $key, array $config) use ($pdo): void {
    $pdo->prepare('DELETE FROM crm_rule_configs WHERE rule_key = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO crm_rule_configs (rule_key, rule_name, rule_group, config_json) VALUES (?, ?, ?, ?)')->execute([$key, $key, 'test', json_encode($config)]);
    RuleConfig::clearCache();
};
$errs = static function (?Throwable $e): array {
    return $e instanceof ValidationException ? $e->fieldErrors() : [];
};

expect_throws(PermissionException::class, static function () use ($svc, $viewer): void {
    $svc($viewer);
}, '没有 customer.view 不能进客户中心');
check(RuleConfig::admissionMode() === 'lead_pool_first', '没有配置时准入规则默认“先进暂存池”（与旧系统一致）');
$setRule('customer_admission', ['mode' => 'direct', 'enabled' => 1]);

// ---------- 新建与校验 ----------
$a = $svc($s1);
$e = expect_throws(ValidationException::class, static function () use ($a): void {
    $a->create(['customer_name' => '', 'country' => '', 'email' => 'bad', 'website' => 'not a site', 'level' => 'PX']);
}, '新建校验');
$err = $errs($e);
check(isset($err['customer_name'], $err['country'], $err['email'], $err['website'], $err['level']), '名称、国家必填，邮箱、网站、等级校验');
$e = expect_throws(ValidationException::class, static function () use ($a): void {
    $a->create(['customer_name' => 'X', 'country' => 'Atlantis']);
}, '国家必须从列表选');
$r = $a->create(['customer_name' => 'Acme Lighting', 'customer_name_en' => 'Acme Lighting Pvt', 'country' => 'India', 'city' => 'Mumbai', 'website' => 'www.acme-light.in',
    'email' => 'info@acme-light.in', 'phone' => '+91 22 1234 5678', 'contact_name' => 'Raj', 'contact_email' => 'raj@acme-light.in', 'owner_user_id' => (string) $s2]);
check($r['status'] === 'created' && $r['id'] > 0, '直接建成正式客户');
$acme = (int) $r['id'];
$row = $pdo->query("SELECT * FROM crm_customers WHERE id = {$acme}")->fetch();
check($row['country'] === 'IN' && $row['website'] === 'https://www.acme-light.in', '国家存代码、网站自动补 https');
check($row['level'] === 'P3' && $row['lifecycle_key'] === 'lead' && $row['status'] === 'lead' && $row['source'] === 'website', '等级、阶段、状态、来源用默认值');
check((int) $row['owner_user_id'] === $s1 && $row['owner_department'] === '外贸部', '没有分配权限时负责人只能是自己（忽略提交的负责人）');
check($pdo->query("SELECT user_id || ':' || is_primary FROM crm_customer_owners WHERE customer_id = {$acme}")->fetchAll(PDO::FETCH_COLUMN) === [$s1 . ':1'], '负责人表同步');
check($pdo->query("SELECT country || '/' || city FROM crm_customer_addresses WHERE customer_id = {$acme} AND is_primary = 1")->fetchColumn() === 'IN/Mumbai', '主地址同步（旧系统显示用）');
check($pdo->query("SELECT status FROM crm_customer_promotion_status WHERE customer_id = {$acme}")->fetchColumn() === 'not_promoted', '推广状态默认未推广');
check($pdo->query("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id = {$acme}")->fetchAll(PDO::FETCH_COLUMN) === ['email'], '推广方式默认邮件');
check($pdo->query("SELECT name || ':' || is_primary FROM crm_contacts WHERE customer_id = {$acme}")->fetchColumn() === 'Raj:1', '同时建主联系人');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_logs WHERE customer_id = {$acme} AND action = 'customer_create' AND message LIKE '[新系统]%'")->fetchColumn() === 1, '写客户日志（旧系统也能看到）');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_customer_timeline WHERE customer_id = {$acme}")->fetchColumn() === 1, '写时间轴');

// ---------- 查重 ----------
$r = $a->create(['customer_name' => 'Acme Lightings', 'country' => 'IN']);
check($r['status'] === 'duplicates' && $r['matches'][0]['customer_id'] === $acme && in_array('name_similarity', $r['matches'][0]['reasons'], true), '名称相似时先列出疑似重复让用户确认');
$r = $a->create(['customer_name' => 'Acme Lightings', 'country' => 'IN', 'confirm_duplicates' => '1']);
check($r['status'] === 'lead_pool' && $r['lead_id'] > 0, '确认后高度相似按查重规则进入暂存池');
$lead = $pdo->query('SELECT * FROM crm_lead_pool WHERE id = ' . (int) $r['lead_id'])->fetch();
$payload = json_decode((string) $lead['payload_json'], true);
check($lead['status'] === 'pending' && $payload['customer_name'] === 'Acme Lightings' && (int) $payload['owner_user_id'] === $s1, '暂存池记录旧系统能读懂的资料');
check(count(json_decode((string) $lead['similarity_matches_json'], true)) >= 1, '暂存池记录疑似重复');
$m = DuplicateFinder::find(['customer_name' => 'Totally Different', 'email' => 'someone@acme-light.in']);
check($m && $m[0]['customer_id'] === $acme && in_array('domain_match', $m[0]['reasons'], true), '邮箱域名相同算重复');
$m = DuplicateFinder::find(['customer_name' => 'Another Co', 'contact_email' => 'RAJ@acme-light.in']);
check($m && in_array('contact_email_match', $m[0]['reasons'], true), '联系人邮箱相同算重复（不分大小写）');
$m = DuplicateFinder::find(['customer_name' => 'Another Co', 'phone' => '0091-22-1234-5678']);
check($m === [], '号码数字不同不算重复');
$m = DuplicateFinder::find(['customer_name' => 'Another Co', 'phone' => '+91 (22) 1234-5678']);
check($m && in_array('phone_match', $m[0]['reasons'], true), '电话按数字比较');
$pdo->exec("UPDATE crm_customers SET email = 'acme@gmail.com', website = '' WHERE id = {$acme}");
check(DuplicateFinder::find(['customer_name' => 'Zeta', 'email' => 'zeta@gmail.com']) === [], 'gmail 等公共邮箱不按域名算重复');
$pdo->exec("UPDATE crm_customers SET email = 'info@acme-light.in', website = 'https://www.acme-light.in' WHERE id = {$acme}");
// 查全部客户：600 个客户后，最早的也能查到
$ins = $pdo->prepare("INSERT INTO crm_customers (customer_name, country, created_at, updated_at) VALUES (?, 'DE', ?, ?)");
for ($i = 0; $i < 600; $i++) {
    $ins->execute(['Filler Company ' . $i, '2026-01-01 00:00:00', date('Y-m-d H:i:s')]);
}
$pdo->exec("UPDATE crm_customers SET updated_at = '2020-01-01 00:00:00' WHERE id = {$acme}");
$m = DuplicateFinder::find(['customer_name' => 'Acme Lightin']);
check($m && $m[0]['customer_id'] === $acme, '查重查全部客户，不再只看最近 500 个');
$pdo->exec('DELETE FROM crm_customers WHERE customer_name LIKE \'Filler Company %\'');
// 同名直接保存时拦下
$setRule('duplicate_detection', ['name_threshold' => 80, 'action' => 'warn']);
$e = expect_throws(ValidationException::class, static function () use ($a): void {
    $a->create(['customer_name' => 'Acme Lighting', 'country' => 'IN', 'confirm_duplicates' => '1']);
}, '完全同名不能新建');
check(isset($errs($e)['customer_name']), '同名错误在客户名称上');
$setRule('duplicate_detection', ['name_threshold' => 80, 'action' => 'block']);
expect_throws(ValidationException::class, static function () use ($a): void {
    $a->create(['customer_name' => 'Acme Lightingz', 'country' => 'IN', 'confirm_duplicates' => '1']);
}, '查重规则为“阻止”时高度重复不能保存');
$setRule('duplicate_detection', ['name_threshold' => 80, 'action' => 'lead_pool']);
$setRule('customer_admission', ['mode' => 'lead_pool_first', 'enabled' => 1]);
$r = $a->create(['customer_name' => 'Brand New Co', 'country' => 'DE']);
check($r['status'] === 'lead_pool', '准入规则“先进暂存池”时新客户进暂存池');
$setRule('customer_admission', ['mode' => 'duplicate_risk_only', 'enabled' => 1]);
$r = $a->create(['customer_name' => 'Acme Lightingq', 'country' => 'IN']);
check($r['status'] === 'duplicates', '“有重复才进暂存池”：先提示疑似重复');
$r = $a->create(['customer_name' => 'Acme Lightingq', 'country' => 'IN', 'confirm_duplicates' => '1']);
check($r['status'] === 'lead_pool', '“有重复才进暂存池”：确认后进暂存池');
$r = $a->create(['customer_name' => 'Unique Gmbh', 'country' => 'DE']);
check($r['status'] === 'created', '“有重复才进暂存池”：没有重复直接建');
$setRule('customer_admission', ['mode' => 'direct', 'enabled' => 1]);
$unique = (int) $r['id'];

// ---------- 可见范围 ----------
$b = $svc($s2);
$r = $b->create(['customer_name' => 'Beta Trading', 'country' => 'AE']);
$beta = (int) $r['id'];
$c = $svc($s3);
$r = $c->create(['customer_name' => 'Gamma Finance', 'country' => 'DE']);
$gamma = (int) $r['id'];
$ids = static function (array $list): array {
    return array_map('intval', array_column($list['rows'], 'id'));
};
$seen = $ids($a->list('all', []));
sort($seen);
check($seen === [$acme, $unique], '业务员只看到自己负责或创建的');
expect_throws(NotFoundException::class, static function () use ($a, $beta): void {
    $a->get($beta);
}, '看不到别人的客户');
$mg = $svc($mgr);
$mIds = $ids($mg->list('all', []));
check(in_array($acme, $mIds, true) && in_array($beta, $mIds, true) && !in_array($gamma, $mIds, true), '主管看本部门（按负责人所在部门），看不到别的部门');
$pdo->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value) VALUES (?, ?, ?, ?)')->execute([$s1, 'customer', 'country', 'DE']);
\Artdon\Centers\Base\Permission::clearCache();
$a = $svc($s1);
check(in_array($gamma, $ids($a->list('all', [])), true), '权限中心设置“指定国家：DE”后能看到德国客户');
check(!in_array($gamma, $ids($a->list('mine', [])), true), '“我的客户”只看自己负责的');
$pdo->exec('DELETE FROM crm_user_scopes');

// ---------- 列表筛选 ----------
check($ids($mg->list('all', ['q' => 'raj@acme'])) === [$acme], '按联系人邮箱搜索');
check($ids($mg->list('all', ['q' => '100%'])) === [], '搜索里的 % 当普通字符');
check($ids($mg->list('all', ['country' => '阿联酋'])) === [$beta], '按国家筛选（中文名也能匹配代码）');
$pdo->exec("UPDATE crm_customers SET country = 'United Arab Emirates' WHERE id = {$beta}");
check($ids($mg->list('all', ['country' => 'AE'])) === [$beta], '旧数据存英文国家名也能按代码筛出');
$pdo->exec("UPDATE crm_customers SET country = 'AE' WHERE id = {$beta}");
$pdo->prepare('INSERT INTO crm_customer_followups (customer_id, followup_time, content, created_by) VALUES (?, ?, ?, ?)')->execute([$acme, date('Y-m-d H:i:s', time() - 3 * 86400), '电话沟通', $s1]);
$pdo->prepare('INSERT INTO crm_customer_followups (customer_id, followup_time, content, created_by) VALUES (?, ?, ?, ?)')->execute([$beta, date('Y-m-d H:i:s', time() - 20 * 86400), '发了目录', $s2]);
check($ids($mg->list('all', ['follow' => '7'])) === array_values(array_diff($ids($mg->list('all', [])), [$acme])), '7 天未跟进');
check(!in_array($beta, $ids($mg->list('all', ['follow' => 'never'])), true) && in_array($unique, $ids($mg->list('all', ['follow' => 'never'])), true), '从未跟进');
check(in_array($beta, $ids($mg->list('all', ['follow' => '15'])), true) && !in_array($beta, $ids($mg->list('all', ['follow' => '30'])), true), '15 / 30 天未跟进');
check($ids($mg->list('all', ['no_contact' => '1'])) === array_values(array_diff($ids($mg->list('all', [])), [$acme])), '没有联系人');
check($ids($mg->list('all', ['owner_id' => (string) $s2])) === [$beta], '按负责人筛选');
$list = $mg->list('all', ['sort' => 'followup']);
check((int) $list['rows'][0]['id'] === $acme && $list['rows'][0]['primary_owner'] === '业务一' && (int) $list['rows'][0]['contact_count'] === 1, '按最近跟进排序，带负责人、联系人数');

// ---------- 编辑 ----------
$a = $svc($s1);
$changed = $a->update($acme, ['customer_name' => 'Acme Lighting Ltd', 'country' => 'IN', 'city' => 'Pune', 'level' => 'P1', 'owner_user_id' => (string) $s2, 'source_tags' => ['exhibition', 'referral']]);
$row = $pdo->query("SELECT * FROM crm_customers WHERE id = {$acme}")->fetch();
check($row['customer_name'] === 'Acme Lighting Ltd' && $row['level'] === 'P1' && (int) $row['owner_user_id'] === $s1, '编辑保存，提交负责人也不会改负责人');
check(isset($changed['customer_name'], $changed['city'], $changed['level'], $changed['source_tags']) && !isset($changed['owner_user_id']), '记录改了哪些字段');
check($pdo->query("SELECT city FROM crm_customer_addresses WHERE customer_id = {$acme} AND is_primary = 1")->fetchColumn() === 'Pune', '编辑同步主地址');
check($pdo->query("SELECT source_key FROM crm_customer_source_tags WHERE customer_id = {$acme} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) === ['exhibition', 'referral'], '编辑来源');
check($pdo->query("SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id = {$acme}")->fetchAll(PDO::FETCH_COLUMN) === ['email'], '没提交的多选项保持不变');
$a->update($acme, ['customer_name' => 'Acme Lighting Ltd', 'country' => 'IN', 'source_tags' => []]);
check($pdo->query("SELECT COUNT(*) FROM crm_customer_source_tags WHERE customer_id = {$acme}")->fetchColumn() == 0, '多选全不选时清空');
$pdo->exec("UPDATE crm_customers SET level = 'OLD_LEVEL' WHERE id = {$acme}");
$a->update($acme, ['customer_name' => 'Acme Lighting Ltd', 'country' => 'IN', 'level' => 'OLD_LEVEL']);
check($pdo->query("SELECT level FROM crm_customers WHERE id = {$acme}")->fetchColumn() === 'OLD_LEVEL', '字典里已没有的原值可以保留');
$a->update($acme, ['customer_name' => 'Acme Lighting Ltd', 'country' => 'IN', 'level' => 'P3']);
expect_throws(ValidationException::class, static function () use ($a, $acme): void {
    $a->update($acme, ['customer_name' => 'Unique Gmbh', 'country' => 'IN']);
}, '改名不能和别的客户同名');
expect_throws(NotFoundException::class, static function () use ($a, $beta): void {
    $a->update($beta, ['customer_name' => 'x', 'country' => 'IN']);
}, '不能编辑看不到的客户');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_logs WHERE customer_id = {$acme} AND action = 'customer_update'")->fetchColumn() >= 3, '编辑写日志');

// ---------- 分配 ----------
expect_throws(PermissionException::class, static function () use ($a, $acme, $s2): void {
    $a->assign([$acme], $s2);
}, '没有分配权限不能分配');
expect_throws(ValidationException::class, static function () use ($mg, $acme, $off): void {
    $mg->assign([$acme], $off);
}, '不能分配给停用账号');
\Artdon\Centers\Base\Notify::clearCache();
check($mg->assign([$acme, $beta], $s2, [$s1, $s2]) === 2, '批量分配');
check($pdo->query("SELECT user_id || ':' || is_primary FROM crm_customer_owners WHERE customer_id = {$acme} ORDER BY is_primary DESC")->fetchAll(PDO::FETCH_COLUMN) === [$s2 . ':1', $s1 . ':0'], '第一负责人 + 协助（重复的去掉）');
check(\Artdon\Centers\Base\Notify::unreadCount($s2) === 1 && \Artdon\Centers\Base\Notify::unreadCount($s1) === 1, '分配后通知负责人');
$a = $svc($s1);
check(in_array($acme, $ids($a->list('mine', [])), true), '协助负责人也算“我的客户”');

// ---------- 公海 ----------
$a = $svc($s1);
check(!isset($a->tabs()['public']), '没有公海相关权限看不到公海');
expect_throws(PermissionException::class, static function () use ($a): void {
    $a->list('public', []);
}, '没有权限不能看公海列表');
expect_throws(ValidationException::class, static function () use ($mg, $beta): void {
    $mg->transferPublic([$beta], ' ');
}, '转入公海要写原因');
check($mg->transferPublic([$acme], '长期没回复') === 1, '转入公海');
check($pdo->query("SELECT owner_user_id FROM crm_customers WHERE id = {$acme}")->fetchColumn() === null && (int) $pdo->query("SELECT COUNT(*) FROM crm_customer_owners WHERE customer_id = {$acme}")->fetchColumn() === 0, '转入公海清掉全部负责人（包括协助）');
check($pdo->query("SELECT protection_status FROM crm_customer_protection WHERE customer_id = {$acme}")->fetchColumn() === 'public_pool', '记录公海状态');
check(in_array($acme, $ids($mg->list('public', [])), true) && !in_array($acme, $ids($mg->list('all', [])), true), '公海客户在公海标签里');
expect_throws(RuntimeException::class, static function () use ($mg, $acme): void {
    $mg->transferPublic([$acme], '再转一次');
}, '已在公海的不能再转');
expect_throws(PermissionException::class, static function () use ($mg, $acme): void {
    $mg->update($acme, ['customer_name' => 'x', 'country' => 'IN']);
}, '公海客户要先领取才能编辑');
check($mg->get($acme)['is_public'] === true, '能看公海的人能打开公海客户');
check($a->get($acme)['is_public'] === true, '创建人在客户转公海后仍能看到（沿用旧规则）');
expect_throws(PermissionException::class, static function () use ($a, $acme): void {
    $a->update($acme, ['customer_name' => 'x', 'country' => 'IN']);
}, '创建人也要先领取才能编辑公海客户');
expect_throws(NotFoundException::class, static function () use ($acme, $s1): void {
    (new ContactService(Auth::loadUser($s1)))->create($acme, ['name' => 'x']);
}, '公海客户不能加联系人');
expect_throws(NotFoundException::class, static function () use ($svc, $s2, $acme): void {
    $svc($s2)->get($acme);
}, '原负责人转公海后看不到（没有公海权限）');
$pdo->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$s1}, 'customer.claim_public', 'allow')");
\Artdon\Centers\Base\Permission::clearCache();
$a = $svc($s1);
check(isset($a->tabs()['public']) && in_array($acme, $ids($a->list('public', [])), true), '有领取权限的业务员能看到公海');
$a->claim($acme);
check((int) $pdo->query("SELECT owner_user_id FROM crm_customers WHERE id = {$acme}")->fetchColumn() === $s1 && $pdo->query("SELECT claimed_by FROM crm_customer_protection WHERE customer_id = {$acme}")->fetchColumn() == $s1, '领取后自己是负责人');
expect_throws(RuntimeException::class, static function () use ($mg, $acme): void {
    $mg->claim($acme);
}, '已有负责人不能领取');

// ---------- 删除与恢复 ----------
expect_throws(PermissionException::class, static function () use ($a, $acme): void {
    $a->delete($acme, 'x');
}, '没有删除权限不能删');
expect_throws(ValidationException::class, static function () use ($mg, $beta): void {
    $mg->delete($beta, '');
}, '删除要写原因');
$mg->delete($beta, '重复录入');
check(!in_array($beta, $ids($mg->list('all', [])), true) && in_array($beta, $ids($mg->list('deleted', [])), true), '删除后进“已删除”');
expect_throws(PermissionException::class, static function () use ($a): void {
    $a->list('deleted', []);
}, '没有删除或恢复权限看不到已删除');
$mg->restore($beta);
check(in_array($beta, $ids($mg->list('all', [])), true), '恢复');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_sensitive_audit_logs WHERE action_key IN ('customer_delete','customer_restore','customer_public_pool','customer_claim_public','customer_assign')")->fetchColumn() >= 5, '敏感操作写敏感审计');

// ---------- 联系人 ----------
$ct = new ContactService(Auth::loadUser($s1));
$e = expect_throws(ValidationException::class, static function () use ($ct, $acme): void {
    $ct->create($acme, ['name' => '', 'email' => '']);
}, '联系人至少填一项');
$k1 = $ct->create($acme, ['email' => 'buyer@acme-light.in', 'position' => '采购']);
check($pdo->query("SELECT name FROM crm_contacts WHERE id = {$k1}")->fetchColumn() === 'buyer', '没填姓名时用邮箱前缀');
expect_throws(ValidationException::class, static function () use ($ct, $acme): void {
    $ct->create($acme, ['name' => '重复', 'email' => 'buyer@acme-light.in']);
}, '同一客户联系人邮箱不能重复');
$k2 = $ct->create($acme, ['name' => 'Priya', 'phone' => '123456', 'is_primary' => '1']);
check($pdo->query("SELECT id FROM crm_contacts WHERE customer_id = {$acme} AND is_primary = 1 AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) == [$k2], '设为主联系人时其他人取消');
$ct->update($k2, ['name' => 'Priya S', 'phone' => '123456', 'is_primary' => '0']);
check((int) $pdo->query("SELECT is_primary FROM crm_contacts WHERE id = {$k2}")->fetchColumn() === 1, '主联系人不能直接取消（要把别人设为主联系人）');
expect_throws(PermissionException::class, static function () use ($ct, $k2): void {
    $ct->delete($k2);
}, '没有 contact.delete 不能删联系人');
(new ContactService(Auth::loadUser($mgr)))->delete($k2);
check((int) $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE customer_id = {$acme} AND is_primary = 1 AND deleted_at IS NULL")->fetchColumn() === 1, '删掉主联系人后自动补一个主联系人');
expect_throws(NotFoundException::class, static function () use ($ct, $gamma): void {
    $ct->create($gamma, ['name' => 'x']);
}, '不能给看不到的客户加联系人');

// ---------- 详情 ----------
$d = $mg->get($acme);
check($d['primary_owner'] === '业务一' && $d['followup_count'] === 1 && count($d['addresses']) === 1, '详情含负责人、跟进、地址');
check(in_array('来源', $mg->missing($d, (new ContactService(Auth::loadUser($mgr)))->list($acme)), true), '资料缺失提示');
check(count(\Artdon\Centers\Customer\CustomerLog::forCustomer($acme)) >= 8, '客户日志（新旧系统都写同一张表）');
check($mg->counts()['public'] === 0 && $mg->counts()['all'] >= 2, '标签数量');

centers_test_finish('客户中心第一批');
