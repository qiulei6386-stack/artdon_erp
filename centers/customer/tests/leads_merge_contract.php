<?php
declare(strict_types=1);

/**
 * 客户中心第二批（一）：暂存池处理、合并客户。php centers/customer/tests/leads_merge_contract.php
 */

require_once dirname(__DIR__, 2) . '/_base/tests/harness.php';
require_once __DIR__ . '/schema.php';

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Customer\CustomerMerger;
use Artdon\Centers\Customer\CustomerService;
use Artdon\Centers\Customer\LeadPool;
use Artdon\Centers\Customer\RuleConfig;

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$pdo = centers_test_reset();
customer_test_schema($pdo);
customer_test_dictionary($pdo);
RuleConfig::clearCache();
$pdo->exec("INSERT INTO crm_rule_configs (rule_key, config_json) VALUES ('customer_admission', '{\"mode\":\"direct\",\"enabled\":1}')");
$perm = $pdo->prepare("INSERT INTO crm_permissions (permission_key, module, action, description, risk_level) VALUES (?, ?, ?, '', 'medium')");
foreach (['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.merge', 'customer.lead_pool', 'customer.lead_pool_view', 'customer.view_all', 'contact.view', 'contact.create'] as $k) {
    [$m, $a] = explode('.', $k);
    $perm->execute([$k, $m, $a]);
}
$pdo->exec("INSERT INTO crm_roles (id, role_key, role_name, status) VALUES (3, 'manager', '主管', 'active'), (4, 'clerk', '暂存员', 'active')");
foreach ([[2, ['customer.view', 'customer.create', 'customer.edit', 'contact.view', 'contact.create']],
          [3, ['customer.view', 'customer.create', 'customer.edit', 'customer.assign', 'customer.merge', 'customer.lead_pool', 'customer.view_all', 'contact.view', 'contact.create']],
          [4, ['customer.view', 'customer.create', 'customer.lead_pool', 'customer.view_all']]] as [$role, $keys]) {
    foreach ($keys as $k) {
        $pdo->prepare('INSERT INTO crm_role_permissions (role_id, permission_key) VALUES (?, ?)')->execute([$role, $k]);
    }
}
$s1 = centers_test_user(['username' => 's1', 'real_name' => '业务一', 'email' => 's1@x.com']);
$s2 = centers_test_user(['username' => 's2', 'real_name' => '业务二', 'email' => 's2@x.com']);
$mgr = centers_test_user(['username' => 'mgr', 'real_name' => '主管', 'email' => 'm@x.com', 'role_id' => 3]);
$clerk = centers_test_user(['username' => 'clerk', 'real_name' => '暂存员', 'email' => 'c@x.com', 'role_id' => 4]);
$u = static function (int $id): array {
    return Auth::loadUser($id);
};
$addLead = static function (array $payload, ?int $by, string $matches = '[]') use ($pdo): int {
    $pdo->prepare("INSERT INTO crm_lead_pool (raw_name, raw_email, raw_country, payload_json, similarity_matches_json, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)")
        ->execute([$payload['customer_name'] ?? ($payload['raw_name'] ?? ''), $payload['email'] ?? '', $payload['country'] ?? '', json_encode($payload, JSON_UNESCAPED_UNICODE), $matches, $by, '2026-10-01 10:00:00', '2026-10-01 10:00:00']);
    return (int) $pdo->lastInsertId();
};

// ---------- 暂存池：读取旧格式 ----------
$old = $addLead([
    'raw_name' => 'Old Format Trading', 'raw_country' => '印度', 'raw_email' => 'info@oldformat.in', 'source_tags' => 'exhibition,referral', 'owner_user_ids' => (string) $s2,
    'contacts_json' => json_encode([
        ['name' => 'Second', 'email' => 'second@oldformat.in'],
        ['contact_name' => 'Main Person', 'contact_email' => 'main@oldformat.in', 'is_primary' => 1],
        ['name' => 'Bad Mail', 'email' => 'not-an-email', 'phone' => '+91 99999 11111'],
        ['name' => 'Dup', 'email' => 'SECOND@oldformat.in'],
        ['name' => '', 'email' => ''],
    ]),
], $s1, '[{"customer_id":1}]');
$n = LeadPool::normalize($pdo->query("SELECT * FROM crm_lead_pool WHERE id = {$old}")->fetch());
check($n['fields']['customer_name'] === 'Old Format Trading' && $n['fields']['country'] === '印度' && $n['fields']['email'] === 'info@oldformat.in', '旧格式：raw_ 开头的字段也能读');
check($n['fields']['source_tags'] === ['exhibition', 'referral'] && $n['owner_user_id'] === $s2, '旧格式：逗号分隔的来源、owner_user_ids');
check(array_column($n['contacts'], 'name') === ['Main Person', 'Second', 'Bad Mail'], '主联系人排第一；重复和空的联系人略过');
check($n['skipped_contacts'] === 2, '略过的联系人数');
check($n['contacts'][2]['email'] === '' && strpos($n['contacts'][2]['remark'], 'not-an-email') !== false, '格式不对的邮箱挪到备注，不丢');
$inline = $addLead(['customer_name' => 'Inline Co', 'country' => 'DE', 'contact_name' => 'Hans', 'contact_email' => 'hans@inline.de'], null);
$n2 = LeadPool::normalize($pdo->query("SELECT * FROM crm_lead_pool WHERE id = {$inline}")->fetch());
check(count($n2['contacts']) === 1 && $n2['contacts'][0]['name'] === 'Hans', '旧格式：联系人写在 contact_ 字段里');

// ---------- 权限与列表 ----------
expect_throws(PermissionException::class, static function () use ($u, $s1): void {
    new LeadPool($u($s1));
}, '没有暂存池权限不能看');
$pool = new LeadPool($u($mgr));
$counts = $pool->counts();
check($counts['pending'] === 2 && $counts['duplicate'] === 1 && $counts['done'] === 0, '分类数量');
check(count($pool->list('pending', 'inline')['rows']) === 1, '搜索');

// ---------- 确认入库 ----------
$existing = (new CustomerService($u($s1)))->create(['customer_name' => 'Old Format Trading', 'country' => 'IN'])['id'];
$e = expect_throws(ValidationException::class, static function () use ($pool, $old): void {
    $pool->confirm($old, ['customer_name' => 'Old Format Trading', 'country' => 'IN']);
}, '已有同名客户时不能确认入库');
check($pdo->query("SELECT status FROM crm_lead_pool WHERE id = {$old}")->fetchColumn() === 'pending', '确认失败时暂存记录还是待处理');
$lead = $pool->get($old);
check(count($pool->matches($lead)) >= 1 && $pool->matches($lead)[0]['customer_id'] === $existing, '打开时重新查疑似重复');
$clerkPool = new LeadPool($u($clerk));
$cid = $clerkPool->confirm($inline, ['customer_name' => 'Inline Co', 'country' => 'DE', 'owner_user_id' => (string) $mgr]);
$row = $pdo->query("SELECT * FROM crm_customers WHERE id = {$cid}")->fetch();
check($row['customer_name'] === 'Inline Co' && (int) $row['owner_user_id'] === $clerk, '没有分配权限、暂存里也没有负责人时，负责人是处理人');
check($pdo->query("SELECT status || ':' || target_customer_id FROM crm_lead_pool WHERE id = {$inline}")->fetchColumn() === 'confirmed:' . $cid, '暂存记录标为已入库并记下客户');
check($pdo->query("SELECT name FROM crm_contacts WHERE customer_id = {$cid}")->fetchAll(PDO::FETCH_COLUMN) === ['Hans'], '联系人一起建');
expect_throws(RuntimeException::class, static function () use ($pool, $inline): void {
    $pool->confirm($inline, ['customer_name' => 'Inline Co 2', 'country' => 'DE']);
}, '处理过的不能再处理');
$owned = $addLead(['customer_name' => 'Owned Lead', 'country' => 'AE', 'owner_user_id' => $s2], $s1);
$cid2 = $clerkPool->confirm($owned, ['customer_name' => 'Owned Lead', 'country' => 'AE']);
check((int) $pdo->query("SELECT owner_user_id FROM crm_customers WHERE id = {$cid2}")->fetchColumn() === $s2, '暂存里写了负责人时用暂存的负责人');
check(Notify::unreadCount($s1) >= 1, '处理后通知提交人');

// ---------- 关联已有客户 ----------
$pdo->exec("INSERT INTO crm_contacts (customer_id, name, email, phone, is_primary) VALUES ({$existing}, 'Main Person', 'main@oldformat.in', '', 1)");
Notify::clearCache();
$before = Notify::unreadCount($s1);
$added = $pool->attach($old, $existing, true);
check($added === 2, '关联时补联系人，邮箱已有的跳过');
check($pdo->query("SELECT status || ':' || target_customer_id FROM crm_lead_pool WHERE id = {$old}")->fetchColumn() === 'merged:' . $existing, '暂存记录标为已关联');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE customer_id = {$existing} AND is_primary = 1")->fetchColumn() === 1, '补来的联系人不抢主联系人');
check(Notify::unreadCount($s1) === $before + 1, '关联后通知提交人');
$another = $addLead(['customer_name' => 'Plain', 'country' => 'DE', 'contact_name' => 'Y'], $s1);
check($pool->attach($another, $existing, false) === 0 && (int) $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE customer_id = {$existing} AND name = 'Y'")->fetchColumn() === 0, '不勾选就不补联系人');
$hidden = (new CustomerService($u($s2)))->create(['customer_name' => 'Hidden Co', 'country' => 'DE'])['id'];
$l3 = $addLead(['customer_name' => 'Zed', 'country' => 'DE'], $s1);
$pdo->exec("INSERT INTO crm_user_permissions (user_id, permission_key, effect) VALUES ({$s1}, 'customer.lead_pool', 'allow')");
\Artdon\Centers\Base\Permission::clearCache();
expect_throws(NotFoundException::class, static function () use ($u, $s1, $l3, $hidden): void {
    (new LeadPool($u($s1)))->attach($l3, $hidden, true);
}, '不能关联到自己看不到的客户');
check($pdo->query("SELECT status FROM crm_lead_pool WHERE id = {$l3}")->fetchColumn() === 'pending', '关联失败时还是待处理');

// ---------- 丢弃 ----------
expect_throws(ValidationException::class, static function () use ($pool, $l3): void {
    $pool->reject($l3, '  ');
}, '丢弃要写原因');
$pool->reject($l3, '垃圾询盘');
check($pdo->query("SELECT status FROM crm_lead_pool WHERE id = {$l3}")->fetchColumn() === 'rejected' && $pool->rejectReason($l3) === '垃圾询盘', '丢弃并记下原因');
check($pool->counts()['rejected'] === 1 && $pool->counts()['done'] === 4, '处理后分类数量');

// ---------- 合并客户 ----------
$svc = new CustomerService($u($mgr));
$a = $svc->create(['customer_name' => 'Merge Master', 'country' => 'IN', 'owner_user_id' => (string) $s1, 'source_tags' => ['website']])['id'];
$b = $svc->create(['customer_name' => 'Merge Copy Totally', 'country' => 'IN', 'website' => 'merge-copy.in', 'phone' => '12345678', 'owner_user_id' => (string) $s2, 'source_tags' => ['website', 'exhibition'],
    'contact_name' => 'Copy Boss', 'contact_email' => 'boss@merge-copy.in'])['id'];
$pdo->exec("INSERT INTO crm_contacts (customer_id, name, email, is_primary) VALUES ({$a}, 'Master Boss', 'boss@master.in', 1)");
$pdo->exec("INSERT INTO crm_customer_followups (customer_id, followup_time, content) VALUES ({$b}, '2026-10-01 10:00:00', '跟进 B')");
$pdo->exec("INSERT INTO crm_customer_relations (customer_id, related_customer_id, relation_type) VALUES ({$b}, {$existing}, 'agent')");
$pdo->exec("INSERT INTO crm_mails (subject, linked_customer_id) VALUES ('邮件', {$b})");
$pdo->exec("INSERT INTO quote_orders (customer_id) VALUES ('crm_{$b}'), ('{$b}')");
expect_throws(PermissionException::class, static function () use ($u, $s1, $a, $b): void {
    (new CustomerMerger($u($s1)))->merge([$a, $b], $a);
}, '没有合并权限不能合并');
$merger = new CustomerMerger($u($mgr));
expect_throws(ValidationException::class, static function () use ($merger, $a): void {
    $merger->merge([$a], $a);
}, '至少 2 个');
expect_throws(ValidationException::class, static function () use ($merger, $a, $b, $existing): void {
    $merger->merge([$a, $b], $existing);
}, '主客户要在选中的里面');
$r = $merger->merge([$a, $b], $a);
check($r['merged_ids'] === [$b], '合并成功');
check($pdo->query("SELECT delete_reason FROM crm_customers WHERE id = {$b}")->fetchColumn() === '合并到客户 #' . $a . '：Merge Master' && $pdo->query("SELECT deleted_at FROM crm_customers WHERE id = {$b}")->fetchColumn() !== null, '被合并客户软删除，原因格式与旧系统一致');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE customer_id = {$a}")->fetchColumn() === 2 && (int) $pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE customer_id = {$a} AND is_primary = 1")->fetchColumn() === 1, '联系人转过来，只保留一个主联系人');
check($pdo->query("SELECT name FROM crm_contacts WHERE customer_id = {$a} AND is_primary = 1")->fetchColumn() === 'Master Boss', '保留主客户原来的主联系人');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_customer_followups WHERE customer_id = {$a}")->fetchColumn() === 1, '跟进转过来');
check($pdo->query("SELECT source_key FROM crm_customer_source_tags WHERE customer_id = {$a} ORDER BY source_key")->fetchAll(PDO::FETCH_COLUMN) === ['exhibition', 'website'], '来源合并去重');
check($pdo->query("SELECT user_id || ':' || is_primary FROM crm_customer_owners WHERE customer_id = {$a} ORDER BY is_primary DESC, user_id")->fetchAll(PDO::FETCH_COLUMN) === [$s1 . ':1', $s2 . ':0'], '被合并客户的负责人成为协助负责人');
check((int) $pdo->query("SELECT related_customer_id FROM crm_customer_relations WHERE customer_id = {$a}")->fetchColumn() === $existing, '客户关系转过来');
check((int) $pdo->query('SELECT linked_customer_id FROM crm_mails')->fetchColumn() === $a, '邮件关联转过来');
check($pdo->query('SELECT customer_id FROM quote_orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === [(string) $a, (string) $a], '报价订单的客户转过来（含 crm_ 前缀写法）');
$m = $pdo->query("SELECT website, phone FROM crm_customers WHERE id = {$a}")->fetch();
check($m['website'] === 'https://merge-copy.in' && $m['phone'] === '12345678', '主客户空着的资料用被合并客户的补上');
check(in_array('website', $r['filled'], true) && isset($r['moved']['crm_contacts']), '返回补了哪些字段、转了多少记录');
check((int) $pdo->query("SELECT COUNT(*) FROM crm_logs WHERE customer_id = {$a} AND action = 'customer_merge'")->fetchColumn() === 1 && (int) $pdo->query("SELECT COUNT(*) FROM crm_sensitive_audit_logs WHERE action_key = 'customer_merge'")->fetchColumn() === 1, '写日志和敏感审计');
expect_throws(NotFoundException::class, static function () use ($merger, $a, $b): void {
    $merger->merge([$a, $b], $a);
}, '已删除的客户不能再合并');
// 主客户在公海时，合并来的第一个负责人成为第一负责人
$p1 = $svc->create(['customer_name' => 'Public Master', 'country' => 'DE'])['id'];
$pdo->exec("UPDATE crm_customers SET owner_user_id = NULL WHERE id = {$p1}");
$pdo->exec("DELETE FROM crm_customer_owners WHERE customer_id = {$p1}");
$p2 = $svc->create(['customer_name' => 'Owned Copy', 'country' => 'DE', 'owner_user_id' => (string) $s2])['id'];
$merger->merge([$p1, $p2], $p1);
check((int) $pdo->query("SELECT owner_user_id FROM crm_customers WHERE id = {$p1}")->fetchColumn() === $s2 && (int) $pdo->query("SELECT is_primary FROM crm_customer_owners WHERE customer_id = {$p1} AND user_id = {$s2}")->fetchColumn() === 1, '公海主客户合并后有了第一负责人');

centers_test_finish('客户中心第二批（一）：暂存池与合并');
