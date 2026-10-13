<?php
/**
 * Promotion Center — live API for the new SPA.
 * Read-only against CRM customers / mail accounts.
 * Does NOT touch crm_marketing_* tables or CRM marketing workers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/promo_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    api_response(false, '请先登录', [], 'AUTH_REQUIRED');
}
if (!has_permission('promotion.view')) {
    api_response(false, '权限不足', [], 'PERMISSION_DENIED');
}

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$input = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        $input = $json;
    }
    $input = array_merge($_POST, $input);
} else {
    $input = $_GET;
}

/**
 * Whether current user can see all promotion customers / stats.
 */
function promo_live_normalize_signature_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8,GBK,GB2312,ISO-8859-1');
    }
    if (strpos($html, '<') === false && (strpos($html, '&lt;') !== false || strpos($html, '&#') !== false)) {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($decoded, '<') !== false) $html = $decoded;
    }
    return $html;
}

function promo_live_render_signature(string $html, array $account = []): string {
    $html = promo_live_normalize_signature_html($html);
    if ($html === '') return '';
    if (function_exists('promo_render_signature_html')) {
        return promo_render_signature_html($html, $account);
    }
    $mailUserName = trim((string)($account['sender_name'] ?? ''));
    $sendEmail = (string)($account['email_address'] ?? '');
    $user = [];
    if (!empty($account['user_id'])) {
        try {
            $st = db()->prepare('SELECT real_name, username, email, phone, position FROM crm_users WHERE id=? LIMIT 1');
            $st->execute([(int)$account['user_id']]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }
    if ($mailUserName === '') $mailUserName = (string)($user['real_name'] ?? ($user['username'] ?? ''));
    if ($sendEmail === '') $sendEmail = (string)($user['email'] ?? '');
    $mailUserMobile = (string)($user['phone'] ?? '');
    $mailUserPosition = trim((string)($user['position'] ?? ''));
    return strtr($html, [
        '{customer_name}' => '',
        '{mail_user_name}' => $mailUserName,
        '{mail_user_mobile}' => $mailUserMobile,
        '{send_email}' => $sendEmail,
        '{mail_user_position}' => $mailUserPosition,
        '{user_name}' => $mailUserName,
        '{position}' => $mailUserPosition,
        '{email}' => $sendEmail,
        '{mobile}' => $mailUserMobile,
    ]);
}

function promo_live_is_manager(): bool
{
    return is_super_admin() || has_permission('promotion.manage');
}

/**
 * Collect promotion.* permission keys the user actually has.
 */
function promo_live_permissions(): array
{
    $keys = [
        'promotion.view',
        'promotion.manage',
        'promotion.task_create',
        'promotion.execute',
        'promotion.analytics',
        'promotion.export',
        'promotion.create_group',
        'promotion.edit_group',
        'promotion.delete_group',
        'promotion.create_project',
        'promotion.edit_project',
        'promotion.delete_project',
        'promotion.move_customer',
        'promotion.stop_promotion',
        'promotion.restore_promotion',
    ];
    $out = [];
    foreach ($keys as $k) {
        if (has_permission($k)) {
            $out[] = $k;
        }
    }
    return $out;
}

try {
    switch ($action) {
        case 'csrf_token': {
            api_response(true, '', ['csrf_token' => csrf_token()]);
            break;
        }

        case 'me': {
            api_response(true, '', [
                'id' => $userId,
                'username' => (string)($user['username'] ?? ''),
                'real_name' => (string)($user['real_name'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'role_key' => (string)($user['role_key'] ?? ''),
                'role_name' => (string)($user['role_name'] ?? ''),
                'department_name' => (string)($user['department_name'] ?? ''),
                'is_super_admin' => (int)($user['is_super_admin'] ?? 0) === 1,
                'is_manager' => promo_live_is_manager(),
                'permissions' => promo_live_permissions(),
                'csrf_token' => csrf_token(),
            ]);
            break;
        }

        case 'dashboard': {
            $isManager = promo_live_is_manager();
            $uidFilter = $isManager ? '' : (' AND c.created_by=' . $userId);

            $stats = [
                'customers_total' => 0,
                'customers_with_email' => 0,
                'customers_mine' => 0,
                'mailboxes_mine' => 0,
                'promo_groups' => 0,
                'promo_campaigns' => 0,
                'promo_sent' => 0,
                'promo_opened' => 0,
                'promo_clicked' => 0,
                'promo_replied' => 0,
                'promo_bounced' => 0,
                'promo_unsubscribed' => 0,
                'offline_pending' => 0,
                'is_manager' => $isManager,
            ];

            $stats['customers_total'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL'
            )->fetchColumn();
            $stats['customers_with_email'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL AND email IS NOT NULL AND email <> ''"
            )->fetchColumn();

            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM crm_customers WHERE deleted_at IS NULL AND owner_user_id = ?'
            );
            $st->execute([$userId]);
            $stats['customers_mine'] = (int)$st->fetchColumn();

            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM crm_user_mail_accounts WHERE user_id = ? AND is_enabled = 1 AND deleted_at IS NULL'
            );
            $st->execute([$userId]);
            $stats['mailboxes_mine'] = (int)$st->fetchColumn();

            try {
                $stats['promo_groups'] = (int)$pdo->query(
                    'SELECT COUNT(*) FROM promo_groups WHERE deleted_at IS NULL'
                )->fetchColumn();
                $stats['promo_campaigns'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_campaigns c WHERE 1=1{$uidFilter}"
                )->fetchColumn();
                $stats['promo_sent'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.status='sent'{$uidFilter}"
                )->fetchColumn();
                $stats['promo_opened'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.open_count>0{$uidFilter}"
                )->fetchColumn();
                $stats['promo_clicked'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.click_count>0{$uidFilter}"
                )->fetchColumn();
                $stats['promo_replied'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.has_reply=1{$uidFilter}"
                )->fetchColumn();
                $stats['promo_bounced'] = (int)$pdo->query(
                    "SELECT COUNT(*) FROM promo_recipients r JOIN promo_campaigns c ON c.id=r.campaign_id WHERE r.status IN ('bounced','failed'){$uidFilter}"
                )->fetchColumn();
                $stats['promo_unsubscribed'] = (int)$pdo->query(
                    'SELECT COUNT(*) FROM promo_unsubscribes'
                )->fetchColumn();
                if ($isManager) {
                    $stats['offline_pending'] = (int)$pdo->query(
                        "SELECT COUNT(*) FROM promo_offline_tasks WHERE status <> 'done'"
                    )->fetchColumn();
                } else {
                    $st = $pdo->prepare(
                        "SELECT COUNT(*) FROM promo_offline_tasks WHERE assigned_to = ? AND status <> 'done'"
                    );
                    $st->execute([$userId]);
                    $stats['offline_pending'] = (int)$st->fetchColumn();
                }
            } catch (Throwable $e) {
                $stats['promo_note'] = 'promo tables not fully ready: ' . $e->getMessage();
            }

            $stats['customers_by_country'] = $pdo->query(
                "SELECT country, COUNT(*) AS n FROM crm_customers WHERE deleted_at IS NULL AND country IS NOT NULL AND country <> '' GROUP BY country ORDER BY n DESC LIMIT 8"
            )->fetchAll(PDO::FETCH_ASSOC);

            api_response(true, '', $stats);
            break;
        }

        case 'customers_search': {
            $owner = (int)($input['owner'] ?? 0);
            $page = max(1, (int)($input['page'] ?? 1));
            $pageSize = min(5000, max(10, (int)($input['page_size'] ?? 20)));
            $withContacts = !empty($input['with_contacts']) && $pageSize <= 200;
            $flt = is_array($input['filter'] ?? null) ? array_merge($input, $input['filter']) : $input;

            $where = ['c.deleted_at IS NULL'];
            $params = [];

            if (!promo_live_is_manager()) {
                $where[] = 'c.owner_user_id = ?';
                $params[] = $userId;
            }
            [$fw, $fp] = promo_customer_filter_where($flt, 'c');
            $where = array_merge($where, $fw);
            $params = array_merge($params, $fp);

            $ws = implode(' AND ', $where);
            $st = $pdo->prepare("SELECT COUNT(*) FROM crm_customers c WHERE {$ws}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $offset = ($page - 1) * $pageSize;
            $sql = "SELECT c.id, c.customer_code, c.customer_code AS code, c.customer_name, c.customer_name AS name, c.customer_name_en, c.country, c.city,
                           c.created_at, c.email, c.phone, c.wechat, c.whatsapp, c.level, c.status,
                           c.owner_user_id, c.do_not_contact, c.no_promotion_reason,
                           u.real_name AS owner_name, u.real_name AS owner
                    FROM crm_customers c
                    LEFT JOIN crm_users u ON u.id = c.owner_user_id
                    WHERE {$ws}
                    ORDER BY c.id DESC
                    LIMIT {$offset}, {$pageSize}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            if ($withContacts && $rows) {
                $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $cst = $pdo->prepare(
                    "SELECT id, customer_id, name, position, email, phone, wechat, whatsapp, is_primary
                     FROM crm_contacts
                     WHERE deleted_at IS NULL AND is_left = 0 AND customer_id IN ({$ph})
                     ORDER BY is_primary DESC, id ASC"
                );
                $cst->execute($ids);
                $byCust = [];
                foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $ct) {
                    $cid = (int)$ct['customer_id'];
                    if (!isset($byCust[$cid])) {
                        $byCust[$cid] = [];
                    }
                    if (count($byCust[$cid]) < 5) {
                        $byCust[$cid][] = $ct;
                    }
                }
                foreach ($rows as &$r) {
                    $r['contacts'] = $byCust[(int)$r['id']] ?? [];
                }
                unset($r);
            }

            if ($rows) {
                $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
                $map = promo_customer_channels_all($pdo, $ids);
                $statusMap = [];
                try {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $st = $pdo->prepare("SELECT customer_id, status FROM crm_customer_promotion_status WHERE customer_id IN ({$ph})");
                    $st->execute($ids);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $statusMap[(int)$row['customer_id']] = (string)$row['status'];
                    }
                } catch (Throwable $e) {}
                foreach ($rows as &$r) {
                    $cid = (int)$r['id'];
                    $chs = $map[$cid] ?? [];
                    if (!$chs) $chs = ['email'];
                    $r['channels'] = $chs;
                    $r['channel_keys'] = $chs;
                    $r['channel_key'] = $chs[0];
                    $r['promotion_status'] = $statusMap[$cid] ?? null;
                    $r['no_promotion'] = (($statusMap[$cid] ?? '') === 'no_promotion') || in_array('no_promotion', $chs, true) || !empty($r['do_not_contact']);
                }
                unset($r);
            }

            api_response(true, '', [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'rows' => $rows,
            ]);
            break;
        }

        case 'settings_mailboxes': {
            // Managers need all enabled mailboxes for sender rules; others only own.
            if (promo_live_is_manager()) {
                $st = $pdo->query(
                    'SELECT id, user_id, email_address, email_username, sender_name, smtp_host, smtp_port, smtp_secure,
                            imap_host, imap_port, imap_secure, is_default, is_enabled,
                            last_sync_time, sync_status, sync_error, delay_send_minutes,
                            signature_id, signature_html
                     FROM crm_user_mail_accounts
                     WHERE is_enabled = 1
                       AND deleted_at IS NULL
                     ORDER BY is_default DESC, id ASC'
                );
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $pdo->prepare(
                    'SELECT id, user_id, email_address, email_username, sender_name, smtp_host, smtp_port, smtp_secure,
                            imap_host, imap_port, imap_secure, is_default, is_enabled,
                            last_sync_time, sync_status, sync_error, delay_send_minutes,
                            signature_id, signature_html
                     FROM crm_user_mail_accounts
                     WHERE user_id = ?
                       AND is_enabled = 1
                       AND deleted_at IS NULL
                     ORDER BY is_default DESC, id ASC'
                );
                $st->execute([$userId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as &$r) {
                $r['has_password'] = true;
                $r['smtp_ready'] = !empty($r['smtp_host']) && (int)$r['smtp_port'] > 0;
                $acctId = (int)($r['id'] ?? 0);
                $uid = (string)($r['user_id'] ?? $userId);
                $r['mail_account_id'] = (string)$acctId;
                $r['id'] = (string)$acctId; // real mailbox id for sender rules
                $r['user_id'] = $uid;       // owner user id for 按负责人
                $r['owner_user_id'] = $uid;
                $r['email'] = $r['email_address'];
                $r['name'] = $r['sender_name'] ?: $r['email_address'];
                $rawSig = promo_live_normalize_signature_html((string)($r['signature_html'] ?? ''));
                $sigHtml = promo_live_render_signature($rawSig, $r);
                $r['signature_html'] = $sigHtml;
                $r['signature_preview_html'] = $sigHtml;
                $r['signature_plain'] = trim(html_entity_decode(strip_tags($sigHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $r['has_signature'] = $rawSig !== '';
                $dns = promo_account_dns_ok($pdo, $r);
                $r['spf'] = (bool)$dns['spf'];
                $r['dkim'] = (bool)$dns['dkim'];
                $r['dmarc'] = (bool)$dns['dmarc'];
                $r['dns_ok'] = (bool)$dns['ok'];
                $r['dns'] = $dns;
                $quota = promo_quota_remaining($pdo, $acctId);
                $r['daily_limit'] = $quota['limit'];
                $r['sent_today'] = $quota['sent'];
                $r['quota_remaining'] = $quota['remaining'];
                $r['inbox_sync'] = ($r['sync_status'] ?? '') === 'success';
            }
            unset($r);
            api_response(true, '', ['rows' => $rows, 'mailboxes' => $rows, 'accounts' => $rows, 'list' => $rows]);
            break;
        }

        case 'filter_options': {
            if (promo_live_is_manager()) {
                $countries = $pdo->query(
                    "SELECT DISTINCT country FROM crm_customers WHERE deleted_at IS NULL AND country <> '' ORDER BY country"
                )->fetchAll(PDO::FETCH_COLUMN);
                $levels = $pdo->query(
                    "SELECT DISTINCT level FROM crm_customers WHERE deleted_at IS NULL AND level <> '' ORDER BY level"
                )->fetchAll(PDO::FETCH_COLUMN);
                $owners = $pdo->query(
                    'SELECT DISTINCT u.id, u.real_name FROM crm_customers c
                     INNER JOIN crm_users u ON u.id = c.owner_user_id
                     WHERE c.deleted_at IS NULL
                     ORDER BY u.real_name'
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $pdo->prepare(
                    "SELECT DISTINCT country FROM crm_customers WHERE deleted_at IS NULL AND owner_user_id = ? AND country <> '' ORDER BY country"
                );
                $st->execute([$userId]);
                $countries = $st->fetchAll(PDO::FETCH_COLUMN);
                $st = $pdo->prepare(
                    "SELECT DISTINCT level FROM crm_customers WHERE deleted_at IS NULL AND owner_user_id = ? AND level <> '' ORDER BY level"
                );
                $st->execute([$userId]);
                $levels = $st->fetchAll(PDO::FETCH_COLUMN);
                $owners = [['id' => $userId, 'real_name' => (string)($user['real_name'] ?? '')]];
            }
            $groups = [];
            try {
                $groups = $pdo->query(
                    'SELECT id, group_name FROM promo_groups WHERE deleted_at IS NULL ORDER BY id DESC'
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $groups = [];
            }
            $__rmap = promo_region_map(); $__regions = []; $__cset = array_flip(array_map('strval', (array)$countries));
            foreach ($__rmap as $__rn => $__rc) { $__regions[] = ['name' => $__rn, 'countries' => $__rc, 'matched' => array_values(array_filter($__rc, static fn($x) => isset($__cset[$x])))]; }
            api_response(true, '', [
                'countries' => $countries,
                'levels' => $levels,
                'owners' => $owners,
                'groups' => $groups,
                'regions' => $__regions,
            ]);
            break;
        }

        case 'settings_approval': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($input['require_approval']) || isset($input['require_test_send']) || isset($input['block_bulk_on_dns_fail'])) {
                if (!promo_live_is_manager()) api_response(false, '只有主管可以修改审批设置');
                foreach (['require_approval','require_test_send','block_bulk_on_dns_fail'] as $k) {
                    if (isset($input[$k])) {
                        $v = !empty($input[$k]) && $input[$k] !== '0' && $input[$k] !== false ? '1' : '0';
                        promo_setting_set($pdo, $k, $v, $userId);
                    }
                }
            }
            if (isset($input['dns_block_mode']) && in_array((string)$input['dns_block_mode'], ['spf','strict'], true)) {
                if (!promo_live_is_manager()) api_response(false, '只有主管可以修改 DNS 拦截模式');
                promo_setting_set($pdo, 'dns_block_mode', (string)$input['dns_block_mode'], $userId);
            }
            api_response(true, '', [
                'require_approval' => promo_setting_get($pdo, 'require_approval', '1') === '1',
                'require_test_send' => promo_setting_get($pdo, 'require_test_send', '1') === '1',
                'block_bulk_on_dns_fail' => promo_setting_get($pdo, 'block_bulk_on_dns_fail', '1') === '1',
                'dns_block_mode' => promo_setting_get($pdo, 'dns_block_mode', 'spf'),
            ]);
            break;
        }

        case 'settings_frequency': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($input['frequency_days']) || isset($input['default_daily_limit']) || isset($input['accounts'])) {
                if (!promo_live_is_manager()) api_response(false, '只有主管可以修改频率设置');
                if (isset($input['frequency_days'])) {
                    promo_setting_set($pdo, 'frequency_days', (string)max(0, min(90, (int)$input['frequency_days'])), $userId);
                }
                if (isset($input['default_daily_limit'])) {
                    promo_setting_set($pdo, 'default_daily_limit', (string)max(10, min(2000, (int)$input['default_daily_limit'])), $userId);
                }
                foreach ((array)($input['accounts'] ?? []) as $row) {
                    if (!is_array($row)) continue;
                    $mid = (int)($row['mail_account_id'] ?? $row['id'] ?? 0);
                    $lim = max(10, min(2000, (int)($row['daily_limit'] ?? 200)));
                    if ($mid <= 0) continue;
                    $pdo->prepare("INSERT INTO promo_mail_account_settings (mail_account_id, daily_limit, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE daily_limit=VALUES(daily_limit), updated_by=VALUES(updated_by), updated_at=NOW()")
                        ->execute([$mid, $lim, $userId]);
                    $pdo->prepare("INSERT INTO promo_mail_quotas (mail_account_id, quota_date, daily_limit) VALUES (?, CURDATE(), ?) ON DUPLICATE KEY UPDATE daily_limit=?")
                        ->execute([$mid, $lim, $lim]);
                }
            }
            $today = date('Y-m-d');
            $accounts = $pdo->query("SELECT m.id, m.email_address, m.sender_name,
                COALESCE(s.daily_limit, CAST((SELECT setting_value FROM promo_settings WHERE setting_key='default_daily_limit') AS UNSIGNED), 200) AS daily_limit,
                COALESCE(q.sent_count,0) AS sent_today
                FROM crm_user_mail_accounts m
                LEFT JOIN promo_mail_account_settings s ON s.mail_account_id=m.id
                LEFT JOIN promo_mail_quotas q ON q.mail_account_id=m.id AND q.quota_date='$today'
                WHERE m.is_enabled=1 AND m.deleted_at IS NULL
                ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
            api_response(true, '', [
                'frequency_days' => (int)promo_setting_get($pdo, 'frequency_days', '14'),
                'default_daily_limit' => (int)promo_setting_get($pdo, 'default_daily_limit', '200'),
                'accounts' => $accounts,
            ]);
            break;
        }

        case 'dns_check': {
            $mid = (int)($input['mail_account_id'] ?? 0);
            $domain = trim((string)($input['domain'] ?? ''));
            $force = !empty($input['force']);
            if ($mid > 0) {
                $st = $pdo->prepare("SELECT * FROM crm_user_mail_accounts WHERE id=?");
                $st->execute([$mid]);
                $a = $st->fetch(PDO::FETCH_ASSOC);
                if (!$a) api_response(false, '邮箱不存在');
                api_response(true, '', promo_account_dns_ok($pdo, $a, $force));
            }
            if ($domain === '') api_response(false, '请提供 domain 或 mail_account_id');
            api_response(true, '', promo_dns_check_domain($pdo, $domain, $force));
            break;
        }

        default:
            api_response(false, '未知接口: ' . $action . '（CRUD 请用 promotion/api.php）', [], 'UNKNOWN_ACTION');
    }
} catch (Throwable $e) {
    api_response(false, '系统错误: ' . $e->getMessage(), [], 'SERVER_ERROR');
}
