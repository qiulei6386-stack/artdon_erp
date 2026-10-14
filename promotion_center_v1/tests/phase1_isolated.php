<?php
declare(strict_types=1);

/**
 * 阶段 1 隔离验收。使用本机 artdon_new_erp 里带 PM1- 前缀的夹具，不写邮件队列。
 * 用法：php promotion_center_v1/tests/phase1_isolated.php
 */

$root = dirname(__DIR__, 2);
$config = require $root . '/includes/config.php';
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$failures = [];
function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "通过  {$message}\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDOUT, "失败  {$message}\n");
}

function pm_user(): ?array
{
    return $GLOBALS['pm_user'] ?? null;
}
function current_user(): ?array
{
    return pm_user();
}
function is_super_admin(): bool
{
    $user = pm_user();
    return $user && (int) ($user['is_super_admin'] ?? 0) === 1;
}
function has_permission($permission): bool
{
    $user = pm_user();
    if (!$user) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $grants = $GLOBALS['pm_grants'][(int) $user['id']] ?? [];
    if (in_array($permission, $grants, true)) {
        return true;
    }
    $aliases = [
        'promotion.create_group' => ['promotion.view'],
        'promotion.edit_group' => ['promotion.view'],
        'promotion.move_customer' => ['promotion.view'],
    ];
    foreach ($aliases[$permission] ?? [] as $alias) {
        if (in_array($alias, $grants, true)) {
            return true;
        }
    }
    return false;
}
function get_user_scope($module): array
{
    $GLOBALS['pm_scope_calls'][] = (string) $module;
    $user = pm_user();
    if (!$user) {
        return ['scope_type' => 'none', 'scope_value' => ''];
    }
    if (is_super_admin()) {
        return ['scope_type' => 'all', 'scope_value' => ''];
    }
    $row = $GLOBALS['pm_scopes'][(int) $user['id']][$module] ?? null;
    return $row ?: ['scope_type' => 'self', 'scope_value' => ''];
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Artdon\\PromotionCenter\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Artdon\PromotionCenter\ApiHandler;
use Artdon\PromotionCenter\LegacyCustomerAdapter;
use Artdon\PromotionCenter\LegacyPermissionAdapter;
use Artdon\PromotionCenter\PmHttpException;
use Artdon\PromotionCenter\PmSchema;

$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(80) NULL,
    customer_name VARCHAR(190) NOT NULL,
    customer_name_en VARCHAR(190) NULL,
    country VARCHAR(120) NULL,
    status VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    backup_email VARCHAR(190) NULL,
    owner_user_id INT UNSIGNED NULL,
    owner_department VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    do_not_contact TINYINT(1) NOT NULL DEFAULT 0,
    no_promotion_reason VARCHAR(500) NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_left TINYINT(1) NOT NULL DEFAULT 0,
    do_not_contact TINYINT(1) NOT NULL DEFAULT 0,
    unsubscribe_email TINYINT(1) NOT NULL DEFAULT 0,
    wechat VARCHAR(120) NULL,
    whatsapp VARCHAR(80) NULL,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_owners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role_type VARCHAR(40) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_promotion_channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    channel_key VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_contact_promotions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    channel VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_promotion_status (
    customer_id INT UNSIGNED PRIMARY KEY,
    status VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_chat_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(190) NOT NULL,
    group_platform VARCHAR(60) NOT NULL,
    status VARCHAR(40) NOT NULL,
    use_for_promotion TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    country VARCHAR(120) NOT NULL,
    city VARCHAR(120) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_customer_followups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    followup_time DATETIME NOT NULL,
    deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS crm_marketing_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(190) NOT NULL,
    task_status VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    customer_count INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS promo_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

PmSchema::apply($pdo);

$dept1 = ensure_department($pdo, '阶段1一部', 'pm1-d1');
$dept2 = ensure_department($pdo, '阶段1二部', 'pm1-d2');
$password = password_hash('PmPhase1-test', PASSWORD_DEFAULT);
$userA = ensure_user($pdo, 'pm_phase1_a', '业务员甲', 'pm_phase1_a@example.invalid', 4, null, $password);
$userB = ensure_user($pdo, 'pm_phase1_b', '业务员乙', 'pm_phase1_b@example.invalid', 4, null, $password);
$userC = ensure_user($pdo, 'pm_phase1_c', '无推广权限', 'pm_phase1_c@example.invalid', null, null, $password);
$userD = ensure_user($pdo, 'pm_phase1_d', '部门丁', 'pm_phase1_d@example.invalid', 4, $dept1, $password);
$userE = ensure_user($pdo, 'pm_phase1_e', '部门戊', 'pm_phase1_e@example.invalid', 4, $dept1, $password);
cleanup_fixtures($pdo, [$userA, $userB, $userC, $userD, $userE]);
$pdo->prepare('DELETE FROM crm_user_permissions WHERE user_id = ? AND permission_key = ?')->execute([$userA, 'promotion.delete_group']);
$pdo->prepare("INSERT INTO crm_user_permissions (user_id, permission_key, effect, created_at) VALUES (?, 'promotion.delete_group', 'allow', NOW())")->execute([$userA]);
foreach ([$userA, $userB, $userD] as $uid) {
    set_scope($pdo, $uid, 'customer', 'self', '');
    set_scope($pdo, $uid, 'promotion', 'self', '');
}
set_scope($pdo, $userD, 'customer', 'department', '阶段1一部');
set_scope($pdo, $userD, 'promotion', 'department', '阶段1一部');

$ids = [];
$ids['a1'] = insert_customer($pdo, 'PM1-A-01', '北欧灯饰', 'Germany', $userA, $userA, null);
$ids['a2'] = insert_customer($pdo, 'PM1-A-02', '莱茵照明', '德国', $userA, $userA, null);
$ids['a3'] = insert_customer($pdo, 'PM1-A-03', '柏林轨道', 'DE', $userA, $userA, null);
$ids['a4'] = insert_customer($pdo, 'PM1-A-04', '巴黎灯具', 'France', $userA, $userA, null);
$ids['a5'] = insert_customer($pdo, 'PM1-A-05', '大阪电工', '日本', $userA, $userA, null);
$ids['addr'] = insert_customer($pdo, 'PM1-A-06', '地址灯饰', '', $userA, $userA, null);
$pdo->prepare('INSERT INTO crm_customer_addresses (customer_id, country, is_primary) VALUES (?, ?, 1)')->execute([$ids['addr'], '德国']);
$ids['b1'] = insert_customer($pdo, 'PM1-B-01', '华南灯饰', '中国', $userB, $userB, null);
$ids['none'] = insert_customer($pdo, 'PM1-N-01', '无主灯具', 'Italy', null, $userA, '阶段1一部');
$ids['shared'] = insert_customer($pdo, 'PM1-C-01', '共同客户', 'Spain', $userB, $userB, null);
$pdo->prepare('INSERT INTO crm_customer_owners (customer_id, user_id, role_type, is_primary) VALUES (?, ?, ?, 0)')->execute([$ids['shared'], $userA, 'collaborator']);
$ids['created'] = insert_customer($pdo, 'PM1-A-07', '只是创建', 'France', $userB, $userA, null);
$ids['d1'] = insert_customer($pdo, 'PM1-D-01', '一部灯饰', '法国', $userD, $userD, '阶段1一部');
$ids['e1'] = insert_customer($pdo, 'PM1-E-01', '同事客户', '法国', $userE, $userE, '阶段1一部');
$ids['other'] = insert_customer($pdo, 'PM1-X-01', '二部客户', '法国', $userB, $userB, '阶段1二部');

$pdo->prepare('INSERT INTO crm_contacts (customer_id, name, email, is_primary, is_left, do_not_contact, unsubscribe_email, wechat, whatsapp) VALUES (?, ?, ?, 1, 0, 0, 1, ?, ?)')
    ->execute([$ids['a1'], '安娜', 'anna@example.invalid;anna.backup@example.invalid', 'anna-wx', '']);
$pdo->prepare('INSERT INTO crm_contacts (customer_id, name, email, is_primary, is_left, do_not_contact, unsubscribe_email) VALUES (?, ?, ?, 0, 1, 0, 0)')
    ->execute([$ids['a1'], '离职李', 'left@example.invalid']);
$contactStmt = $pdo->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? ORDER BY id');
$contactStmt->execute([$ids['a1']]);
$contactIds = array_map('intval', $contactStmt->fetchAll(PDO::FETCH_COLUMN));
foreach (['email', 'wechat', 'whatsapp'] as $channel) {
    $pdo->prepare('INSERT INTO crm_customer_promotion_channels (customer_id, channel_key) VALUES (?, ?)')->execute([$ids['a1'], $channel]);
}
$pdo->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status) VALUES (?, ?, ?)')->execute([$contactIds[0], 'email', 'active']);
$pdo->prepare('INSERT INTO crm_contact_promotions (contact_id, channel, status) VALUES (?, ?, ?)')->execute([$contactIds[0], 'wechat', 'stopped']);
$pdo->prepare("INSERT INTO crm_customer_promotion_status (customer_id, status) VALUES (?, 'promoting')")->execute([$ids['a1']]);
$pdo->prepare("INSERT INTO crm_customer_chat_groups (customer_id, group_name, group_platform, status, use_for_promotion) VALUES (?, ?, 'wechat_group', 'active', 1)")->execute([$ids['a1'], '可推广群']);
$pdo->prepare("INSERT INTO crm_customer_chat_groups (customer_id, group_name, group_platform, status, use_for_promotion) VALUES (?, ?, 'wechat_group', 'active', 0)")->execute([$ids['a1'], '不用于推广']);
$pdo->prepare("INSERT INTO crm_customer_chat_groups (customer_id, group_name, group_platform, status, use_for_promotion) VALUES (?, ?, 'whatsapp_group', 'paused', 1)")->execute([$ids['a1'], '暂停群']);
$pdo->prepare("INSERT INTO crm_marketing_tasks (task_name, task_status, created_by, customer_count) VALUES ('秋季新品', 'running', ?, 3)")->execute([$userA]);
$pdo->prepare("INSERT INTO crm_marketing_tasks (task_name, task_status, created_by, customer_count) VALUES ('别人的任务', 'draft', ?, 1)")->execute([$userB]);
$pdo->prepare("INSERT INTO promo_campaigns (name, status, created_by) VALUES ('旧中心春季', 'sending', ?)")->execute([$userA]);

$baseGrants = ['promotion.view', 'customer.view'];
$GLOBALS['pm_grants'] = [
    $userA => array_merge($baseGrants, ['promotion.delete_group']),
    $userB => $baseGrants,
    $userC => ['customer.view'],
    $userD => array_merge($baseGrants, ['customer.view_department']),
    $userE => $baseGrants,
];
$GLOBALS['pm_scopes'] = [
    $userA => ['customer' => ['scope_type' => 'self', 'scope_value' => ''], 'promotion' => ['scope_type' => 'self', 'scope_value' => '']],
    $userB => ['customer' => ['scope_type' => 'self', 'scope_value' => ''], 'promotion' => ['scope_type' => 'self', 'scope_value' => '']],
    $userD => ['customer' => ['scope_type' => 'department', 'scope_value' => '阶段1一部'], 'promotion' => ['scope_type' => 'department', 'scope_value' => '阶段1一部']],
];

function login_fixture(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.real_name, u.is_super_admin, u.department_id, r.role_name, d.name AS department_name FROM crm_users u LEFT JOIN crm_roles r ON r.id = u.role_id LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ?');
    $stmt->execute([$id]);
    $GLOBALS['pm_user'] = $stmt->fetch() ?: null;
    $GLOBALS['pm_scope_calls'] = [];
}

function ids_of(array $rows): array
{
    return array_map(static function ($row) {
        return (int) $row['id'];
    }, $rows);
}

login_fixture($pdo, $userA);
$access = new LegacyPermissionAdapter();
$adapter = new LegacyCustomerAdapter($pdo, $access);
$handler = new ApiHandler($pdo, $access, $adapter);
$GLOBALS['pm_scope_calls'] = [];
$listA = $adapter->list(['page_size' => 50, 'q' => '']);
$codesA = array_column($listA['rows'], 'customer_code');
check(!in_array('PM1-B-01', $codesA, true), '业务员甲看不到乙的客户');
check(in_array('PM1-C-01', $codesA, true), '业务员甲能看到自己作为共同负责人的客户');
check(!in_array('PM1-A-07', $codesA, true), '仅创建、不是负责人的客户不会因为范围被放大');
check(in_array('customer', $GLOBALS['pm_scope_calls'], true) && in_array('promotion', $GLOBALS['pm_scope_calls'], true), '列表同时读取 customer 与 promotion 范围');

login_fixture($pdo, $userB);
$listB = $adapter->list(['page_size' => 50]);
$codesB = array_column($listB['rows'], 'customer_code');
check(in_array('PM1-B-01', $codesB, true) && !in_array('PM1-A-01', $codesB, true), '业务员乙只能看到自己负责的客户');

login_fixture($pdo, $userA);
$byName = $adapter->list(['q' => '灯', 'page_size' => 50]);
$nameIds = ids_of($byName['rows']);
$ref = $pdo->prepare("SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND c.customer_code LIKE 'PM1-%' AND (c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.customer_name_en LIKE ? OR c.email LIKE ? OR EXISTS (SELECT 1 FROM crm_contacts ct_search WHERE ct_search.customer_id = c.id AND ct_search.deleted_at IS NULL AND (ct_search.name LIKE ? OR ct_search.email LIKE ?))) AND (c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?)) ORDER BY c.id");
$ref->execute(['%灯%', '%灯%', '%灯%', '%灯%', '%灯%', '%灯%', $userA, $userA]);
$refIds = array_map('intval', $ref->fetchAll(PDO::FETCH_COLUMN));
sort($nameIds);
sort($refIds);
check($nameIds === $refIds && count($nameIds) >= 3, '公司名搜索与客户中心公司名/编码谓词一致，抽样不少于 3 条命中');
check(!in_array($ids['b1'], $nameIds, true), '公司名搜索不会带出其他业务员的同名词');
$byContact = array_column($adapter->list(['q' => '安娜', 'page_size' => 50])['rows'], 'customer_code');
check($byContact === ['PM1-A-01'], '联系人姓名能搜到自己负责的客户');
$byEmail = array_column($adapter->list(['q' => 'anna@example.invalid', 'page_size' => 50])['rows'], 'customer_code');
check($byEmail === ['PM1-A-01'], '联系人邮箱能搜到自己负责的客户');

$byCountry = $adapter->list(['country' => '德国', 'page_size' => 50]);
$countryCodes = array_column($byCountry['rows'], 'customer_code');
sort($countryCodes);
check($countryCodes === ['PM1-A-01', 'PM1-A-02', 'PM1-A-03', 'PM1-A-06'], '国家“德国”命中 Germany / 德国 / DE / 地址国家，共 4 条');

$detail = $adapter->detail($ids['a1']);
$anna = $detail['contacts'][0];
check($anna['emails'] === ['anna@example.invalid', 'anna.backup@example.invalid'], '联系人多个邮箱都展示');
check($detail['contacts'][1]['is_left'] === true, '离职标记展示');
check($anna['unsubscribe_email'] === true, '退订标记展示');
check($detail['channels'] === ['email', 'wechat', 'whatsapp'], '客户渠道偏好全部返回，不截断为 1 条');
check(count($anna['channels']) === 2, '联系人渠道全部返回');
check(count($detail['chat_groups']) === 1 && $detail['chat_groups'][0]['name'] === '可推广群', '只返回启用且允许推广的聊天群');
check($detail['promotion_status'] === 'promoting' && $detail['promotion_status_label'] === '推广中', '推广状态沿用现网字典');

$beforeCustomer = $pdo->query('SELECT updated_at FROM crm_customers WHERE id = ' . (int) $ids['a1'])->fetchColumn();
$mailBefore = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'crm_mail_send_jobs'")->fetchColumn();
$created = $handler->handle('group_create', ['name' => '阶段1甲组', 'visibility' => 'team'], 'POST');
check($created['ok'] === true, '甲可以新建分组');
$groupId = (int) $created['data']['id'];
$added = $handler->handle('group_members_add', ['group_id' => $groupId, 'customer_ids' => [$ids['a1']]], 'POST');
check($added['ok'] === true && (int) $added['data']['added'] === 1, '甲可以把自己的客户加入分组');
$rejected = $handler->handle('group_members_add', ['group_id' => $groupId, 'customer_ids' => [$ids['b1']]], 'POST');
check($rejected['ok'] === false && $rejected['error_code'] === 'FORBIDDEN', '甲不能把乙的客户加入分组');
$memberCount = (int) $pdo->query('SELECT COUNT(*) FROM pm_group_members WHERE group_id = ' . $groupId)->fetchColumn();
check($memberCount === 1, '被拒绝的客户没有写入分组成员');
$afterCustomer = $pdo->query('SELECT updated_at FROM crm_customers WHERE id = ' . (int) $ids['a1'])->fetchColumn();
check($beforeCustomer === $afterCustomer, '分组操作不修改客户资料');
$mailAfter = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'crm_mail_send_jobs'")->fetchColumn();
check($mailBefore === 0 && $mailAfter === 0, '本阶段没有产生邮件发送任务表');

login_fixture($pdo, $userB);
$seen = $handler->handle('group', ['id' => $groupId], 'GET');
check($seen['ok'] === true && (int) $seen['data']['visible_count'] === 0 && (int) $seen['data']['total_count'] === 1, '乙能看到团队分组，但看不到甲的成员');
$private = null;
login_fixture($pdo, $userA);
$privateResult = $handler->handle('group_create', ['name' => '阶段1私有', 'visibility' => 'private'], 'POST');
$private = (int) $privateResult['data']['id'];
login_fixture($pdo, $userB);
$hidden = $handler->handle('groups', [], 'GET');
$hiddenIds = array_map(static function ($row) {
    return (int) $row['id'];
}, $hidden['data']['rows']);
check(!in_array($private, $hiddenIds, true), '乙看不到甲的仅自己可见分组');
$noDelete = $handler->handle('group_delete', ['id' => $groupId], 'POST');
check($noDelete['ok'] === false, '没有 delete_group 显式权限时不能删除');

login_fixture($pdo, $userA);
$second = $handler->handle('group_create', ['name' => '阶段1乙目标', 'visibility' => 'team'], 'POST');
$targetId = (int) $second['data']['id'];
$moved = $handler->handle('group_members_move', ['from_id' => $groupId, 'to_id' => $targetId, 'customer_ids' => [$ids['a1']]], 'POST');
check($moved['ok'] === true, '可以把可见客户移到其他分组');
$undo = $handler->handle('undo', ['token' => $moved['data']['undo_token']], 'POST');
check($undo['ok'] === true, '10 秒内可以撤销移动');
$back = (int) $pdo->query('SELECT COUNT(*) FROM pm_group_members WHERE group_id = ' . $groupId . ' AND customer_id = ' . (int) $ids['a1'])->fetchColumn();
check($back === 1, '撤销后客户回到原分组');

$deleted = $handler->handle('group_delete', ['id' => $private], 'POST');
check($deleted['ok'] === true, '创建人有 delete_group 时可以软删');
$gone = (int) $pdo->query('SELECT COUNT(*) FROM pm_groups WHERE id = ' . $private . ' AND deleted_at IS NULL')->fetchColumn();
check($gone === 0, '软删后列表不再出现');
$restored = $handler->handle('undo', ['token' => $deleted['data']['undo_token']], 'POST');
check($restored['ok'] === true, '软删可以撤销');

$history = $handler->handle('history', [], 'GET');
$historyNames = array_column($history['data']['rows'], 'name');
check(in_array('秋季新品', $historyNames, true) && in_array('旧中心春季', $historyNames, true), '能只读看到自己的 CRM 和旧中心历史');
check(!in_array('别人的任务', $historyNames, true), '历史列表不包含其他业务员创建的任务');
check(($history['data']['rows'][0]['editable'] ?? true) === false, '历史行标记为不可编辑');
$historyWrite = $handler->handle('history', ['id' => 1], 'POST');
check($historyWrite['ok'] === false && $historyWrite['status'] === 405, '历史推广不能写入');

login_fixture($pdo, $userD);
$deptList = $adapter->list(['page_size' => 50, 'q' => '']);
$deptCodes = array_column($deptList['rows'], 'customer_code');
check(in_array('PM1-D-01', $deptCodes, true) && in_array('PM1-E-01', $deptCodes, true), '部门范围能看到本部门同事负责的客户');
check(in_array('PM1-N-01', $deptCodes, true), '部门范围内无负责人客户会标出来');
$unowned = null;
foreach ($deptList['rows'] as $row) {
    if ($row['customer_code'] === 'PM1-N-01') {
        $unowned = $row;
    }
}
check($unowned && $unowned['owner_label'] === '无负责人', '无负责人客户显示“无负责人”');
check(!in_array('PM1-X-01', $deptCodes, true) && !in_array('PM1-A-01', $deptCodes, true), '部门范围不会扩到其他部门或本人范围之外');

$GLOBALS['pm_scopes'][$userA]['customer'] = ['scope_type' => 'all', 'scope_value' => ''];
$GLOBALS['pm_grants'][$userA][] = 'customer.view_all';
login_fixture($pdo, $userA);
$wide = array_column($adapter->list(['page_size' => 100])['rows'], 'customer_code');
check(in_array('PM1-B-01', $wide, true), '范围 all 且有 customer.view_all 时与客户中心全部可见一致');
$GLOBALS['pm_scopes'][$userA]['customer'] = ['scope_type' => 'self', 'scope_value' => ''];
login_fixture($pdo, $userA);
$narrow = array_column($adapter->list(['page_size' => 100])['rows'], 'customer_code');
check(!in_array('PM1-B-01', $narrow, true), '即使有 view_all，customer 范围为 self 时也不放大');

$perm = file_get_contents($root . '/includes/permission.php');
check(strpos($perm, "'promotion.create_group' => ['promotion.view']") !== false, 'create_group 仍别名到 view，本阶段未去掉');
check(strpos($perm, "'promotion.delete_group'") === false, 'delete_group 不在全局别名表里');
$tree = shell_exec('grep -RIn --include="*.php" --exclude-dir=tests -E "crm_mail_send_jobs|PHPMailer|fsockopen\\(" ' . escapeshellarg($root . '/promotion_center_v1') . ' || true');
check(trim((string) $tree) === '', '模块源码不引用邮件队列或 SMTP');
$dirty = shell_exec('cd ' . escapeshellarg($root) . ' && git diff --name-only -- crm_marketing.php crm_marketing_delivery.php crm_marketing_execution.php promotion.php promotion includes/permission.php');
check(trim((string) $dirty) === '', '没有修改 CRM 推广、现网 promotion 或权限别名');

$gateFile = tempnam(sys_get_temp_dir(), 'pmgate');
file_put_contents($gateFile, "<?php\nsession_start();\n\$_SESSION['user_id'] = " . (int) $userC . ";\n\$_SERVER['SCRIPT_NAME'] = 'api.php';\nrequire " . var_export($root . '/promotion_center_v1/bootstrap.php', true) . ";\necho json_encode(pm_module_access_error(), JSON_UNESCAPED_UNICODE);\n");
$deniedOut = shell_exec('php ' . escapeshellarg($gateFile) . ' 2>/dev/null');
unlink($gateFile);
check(strpos((string) $deniedOut, 'promotion.view') !== false, '无 promotion.view 的账号不能进模块');
$anon = tempnam(sys_get_temp_dir(), 'pmanon');
file_put_contents($anon, "<?php\nsession_start();\n\$_SESSION = [];\n\$_SERVER['SCRIPT_NAME'] = 'api.php';\nrequire " . var_export($root . '/promotion_center_v1/bootstrap.php', true) . ";\necho json_encode(pm_module_access_error(), JSON_UNESCAPED_UNICODE);\n");
$anonOut = shell_exec('php ' . escapeshellarg($anon) . ' 2>/dev/null');
unlink($anon);
check(strpos((string) $anonOut, '请先登录') !== false, '未登录不能进模块');

file_put_contents('/tmp/pm_phase1_fixtures.json', json_encode([
    'password' => 'PmPhase1-test',
    'user_a' => $userA,
    'user_b' => $userB,
    'user_c' => $userC,
    'username_a' => 'pm_phase1_a',
    'username_b' => 'pm_phase1_b',
    'username_c' => 'pm_phase1_c',
    'customer_a' => $ids['a1'],
    'customer_b' => $ids['b1'],
], JSON_UNESCAPED_UNICODE));

if ($failures) {
    fwrite(STDERR, "\n失败 " . count($failures) . " 项\n");
    exit(1);
}
fwrite(STDOUT, "\n阶段 1 验收全部通过\n");

function ensure_department(PDO $pdo, string $name, string $code): int
{
    $stmt = $pdo->prepare('SELECT id FROM crm_departments WHERE code = ?');
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO crm_departments (name, code, status, sort_order) VALUES (?, ?, "active", 0)')->execute([$name, $code]);
    return (int) $pdo->lastInsertId();
}

function ensure_user(PDO $pdo, string $username, string $realName, string $email, ?int $roleId, ?int $departmentId, string $password): int
{
    $stmt = $pdo->prepare('SELECT id FROM crm_users WHERE username = ?');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE crm_users SET password_hash = ?, real_name = ?, email = ?, role_id = ?, department_id = ?, status = "active", is_super_admin = 0, force_password_change = 0 WHERE id = ?')
            ->execute([$password, $realName, $email, $roleId, $departmentId, $id]);
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO crm_users (username, password_hash, real_name, email, role_id, department_id, status, is_super_admin, force_password_change, failed_login_count) VALUES (?, ?, ?, ?, ?, ?, "active", 0, 0, 0)')
        ->execute([$username, $password, $realName, $email, $roleId, $departmentId]);
    return (int) $pdo->lastInsertId();
}

function set_scope(PDO $pdo, int $userId, string $module, string $type, string $value): void
{
    $pdo->prepare('DELETE FROM crm_user_scopes WHERE user_id = ? AND module = ?')->execute([$userId, $module]);
    $pdo->prepare('INSERT INTO crm_user_scopes (user_id, module, scope_type, scope_value) VALUES (?, ?, ?, ?)')->execute([$userId, $module, $type, $value]);
}

function cleanup_fixtures(PDO $pdo, array $userIds): void
{
    $cust = $pdo->query("SELECT id FROM crm_customers WHERE customer_code LIKE 'PM1-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($cust) {
        $in = implode(',', array_map('intval', $cust));
        $contacts = $pdo->query("SELECT id FROM crm_contacts WHERE customer_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        if ($contacts) {
            $pdo->exec('DELETE FROM crm_contact_promotions WHERE contact_id IN (' . implode(',', array_map('intval', $contacts)) . ')');
        }
        foreach (['crm_contacts', 'crm_customer_owners', 'crm_customer_promotion_channels', 'crm_customer_promotion_status', 'crm_customer_chat_groups', 'crm_customer_addresses', 'crm_customer_followups'] as $table) {
            $pdo->exec("DELETE FROM {$table} WHERE customer_id IN ({$in})");
        }
        $pdo->exec("DELETE FROM crm_customers WHERE id IN ({$in})");
    }
    if ($userIds) {
        $users = implode(',', array_map('intval', $userIds));
        $groups = $pdo->query("SELECT id FROM pm_groups WHERE created_by IN ({$users})")->fetchAll(PDO::FETCH_COLUMN);
        if ($groups) {
            $gin = implode(',', array_map('intval', $groups));
            $pdo->exec("DELETE FROM pm_group_members WHERE group_id IN ({$gin})");
            $pdo->exec("DELETE FROM pm_undo WHERE actor_id IN ({$users})");
            $pdo->exec("DELETE FROM pm_audit_logs WHERE actor_id IN ({$users})");
            $pdo->exec("DELETE FROM pm_groups WHERE id IN ({$gin})");
        }
        $pdo->exec("DELETE FROM crm_marketing_tasks WHERE task_name IN ('秋季新品','别人的任务')");
        $pdo->exec("DELETE FROM promo_campaigns WHERE name = '旧中心春季'");
    }
}

function insert_customer(PDO $pdo, string $code, string $name, string $country, ?int $owner, ?int $createdBy, ?string $department): int
{
    $stmt = $pdo->prepare('INSERT INTO crm_customers (customer_code, customer_name, customer_name_en, country, status, owner_user_id, owner_department, created_by, do_not_contact, updated_at, created_at) VALUES (?, ?, ?, ?, "active", ?, ?, ?, 0, NOW(), NOW())');
    $stmt->execute([$code, $name, $name, $country, $owner, $department, $createdBy]);
    return (int) $pdo->lastInsertId();
}
