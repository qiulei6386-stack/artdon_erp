<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use Artdon\Centers\Base\Db;
use PDO;

/**
 * 邮件工作台的数据：指标（带最近 7 天每天的数量）、要处理的邮件、往来最多的客户、分类分布、邮箱状态。
 * 全部来自 crm_mails / crm_user_mail_accounts 的真实数据。权限检查沿用 Mailbox（看别人的需要 mail.view_all）。
 */
final class MailDashboard
{
    /** @var Mailbox */
    private $box;

    /** @var PDO */
    private $pdo;

    /** @var int */
    private $owner;

    public function __construct(array $operator, int $ownerId = 0, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
        $this->box = new Mailbox($operator, $ownerId, $this->pdo);
        $this->owner = $this->box->ownerId();
    }

    public function mailbox(): Mailbox
    {
        return $this->box;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $days = self::lastDays(7);
        $inDaily = $this->daily("folder = 'inbox'", $days);
        $custDaily = $this->daily("folder = 'inbox' AND linked_customer_id IS NOT NULL", $days);
        $sentDaily = $this->daily("folder = 'sent'", $days);
        $today = end($inDaily) ?: 0;
        $yesterday = $inDaily[count($inDaily) - 2] ?? 0;
        $counts = $this->box->counts();
        $unreadCustomer = $this->scalar("SELECT COUNT(*) FROM crm_mails WHERE user_id = ? AND is_deleted = 0 AND folder = 'inbox' AND is_read = 0 AND linked_customer_id IS NOT NULL");
        $unreplied = $this->box->list('unreplied', [], 1);
        $since30 = date('Y-m-d H:i:s', time() - 30 * 86400);
        $customers30 = $this->scalar("SELECT COUNT(DISTINCT linked_customer_id) FROM crm_mails WHERE user_id = ? AND is_deleted = 0 AND folder <> 'deleted' AND linked_customer_id IS NOT NULL AND COALESCE(received_at, sent_at, created_at) >= ?", [$since30]);
        return [
            'days' => $days,
            'kpi' => [
                'today' => $today,
                'yesterday' => $yesterday,
                'in_daily' => $inDaily,
                'unread' => (int) ($counts['inbox'] ?? 0),
                'unread_customer' => $unreadCustomer,
                'unreplied' => $unreplied['total'],
                'unreplied_oldest' => $unreplied['total'] > 0 ? $this->oldestDays(array_column($this->box->list('unreplied', [], PHP_INT_MAX)['rows'], 'time')) : 0,
                'customer_week' => array_sum($custDaily),
                'customer_daily' => $custDaily,
                'customers_30' => $customers30,
                'sent_week' => array_sum($sentDaily),
                'sent_daily' => $sentDaily,
            ],
            'customer_waiting' => $this->customerWaiting(),
            'unreplied' => array_slice($unreplied['rows'], 0, 6),
            'maybe_customer' => $this->maybeCustomer(),
            'top_customers' => $this->topCustomers($since30),
            'categories' => $this->box->categoryCounts('inbox', ['period' => '30d']),
            'accounts' => $this->accounts($since30),
        ];
    }

    /** 最近 $n 天的日期（旧到新）。 @return list<string> */
    public static function lastDays(int $n): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = date('Y-m-d', strtotime('-' . $i . ' day'));
        }
        return $out;
    }

    /** 柱子高度百分比。 @param list<int> $values @return list<int> */
    public static function bars(array $values): array
    {
        $max = max(1, ...array_map('intval', $values ?: [0]));
        return array_map(static function ($v) use ($max): int {
            return (int) round(((int) $v) / $max * 100);
        }, $values);
    }

    /** @param list<string> $days @return list<int> */
    private function daily(string $cond, array $days): array
    {
        $stmt = $this->pdo->prepare('SELECT SUBSTR(COALESCE(received_at, sent_at, created_at), 1, 10) AS d, COUNT(*) AS n FROM crm_mails
            WHERE user_id = ? AND is_deleted = 0 AND ' . $cond . ' AND COALESCE(received_at, sent_at, created_at) >= ? GROUP BY SUBSTR(COALESCE(received_at, sent_at, created_at), 1, 10)');
        $stmt->execute([$this->owner, $days[0] . ' 00:00:00']);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['d']] = (int) $r['n'];
        }
        return array_map(static function (string $d) use ($map): int {
            return $map[$d] ?? 0;
        }, $days);
    }

    /** 客户来信、还没回复（最近 14 天）。 @return list<array<string, mixed>> */
    private function customerWaiting(): array
    {
        $stmt = $this->pdo->prepare("SELECT m.id, m.subject, m.from_email, m.from_name, m.received_at, m.created_at, m.is_read, m.mail_category, m.linked_customer_id, c.customer_name
            FROM crm_mails m LEFT JOIN crm_customers c ON c.id = m.linked_customer_id
            WHERE m.user_id = ? AND m.is_deleted = 0 AND m.folder = 'inbox' AND m.linked_customer_id IS NOT NULL AND m.is_replied = 0
              AND COALESCE(m.received_at, m.created_at) >= ?
            ORDER BY m.is_read ASC, COALESCE(m.received_at, m.created_at) DESC, m.id DESC LIMIT 6");
        $stmt->execute([$this->owner, date('Y-m-d H:i:s', time() - 14 * 86400)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['category'] = Mailbox::category($r);
            $r['time'] = (string) ($r['received_at'] ?: $r['created_at']);
        }
        unset($r);
        return $rows;
    }

    /** 没关联客户、但发件人看起来是客户的来信（最近 14 天）。 @return list<array<string, mixed>> */
    private function maybeCustomer(): array
    {
        $stmt = $this->pdo->prepare("SELECT id, subject, from_email, from_name, to_emails, cc_emails, received_at, created_at FROM crm_mails
            WHERE user_id = ? AND is_deleted = 0 AND folder = 'inbox' AND linked_customer_id IS NULL AND COALESCE(received_at, created_at) >= ?
            ORDER BY COALESCE(received_at, created_at) DESC, id DESC LIMIT 40");
        $stmt->execute([$this->owner, date('Y-m-d H:i:s', time() - 14 * 86400)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = $this->box->suggestions($r);
            if ($s) {
                $r['suggestion'] = $s[0];
                $r['time'] = (string) ($r['received_at'] ?: $r['created_at']);
                $out[] = $r;
                if (count($out) >= 5) {
                    break;
                }
            }
        }
        return $out;
    }

    /** 最近 30 天往来最多的客户。 @return list<array<string, mixed>> */
    private function topCustomers(string $since): array
    {
        $stmt = $this->pdo->prepare("SELECT m.linked_customer_id AS id, c.customer_name, c.country,
                SUM(CASE WHEN m.folder = 'inbox' THEN 1 ELSE 0 END) AS received, SUM(CASE WHEN m.folder = 'sent' THEN 1 ELSE 0 END) AS sent,
                MAX(COALESCE(m.received_at, m.sent_at, m.created_at)) AS last_time
            FROM crm_mails m JOIN crm_customers c ON c.id = m.linked_customer_id
            WHERE m.user_id = ? AND m.is_deleted = 0 AND m.folder IN ('inbox', 'sent') AND COALESCE(m.received_at, m.sent_at, m.created_at) >= ?
            GROUP BY m.linked_customer_id, c.customer_name, c.country
            ORDER BY (SUM(CASE WHEN m.folder = 'inbox' THEN 1 ELSE 0 END) + SUM(CASE WHEN m.folder = 'sent' THEN 1 ELSE 0 END)) DESC, last_time DESC LIMIT 6");
        $stmt->execute([$this->owner, $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $max = 1;
        foreach ($rows as $r) {
            $max = max($max, (int) $r['received'] + (int) $r['sent']);
        }
        foreach ($rows as &$r) {
            $r['received'] = (int) $r['received'];
            $r['sent'] = (int) $r['sent'];
            $r['percent'] = (int) round(($r['received'] + $r['sent']) / $max * 100);
        }
        unset($r);
        return $rows;
    }

    /** 邮箱状态和最近 30 天收发数量。 @return list<array<string, mixed>> */
    private function accounts(string $since): array
    {
        $out = [];
        foreach ($this->box->accounts() as $a) {
            $stmt = $this->pdo->prepare("SELECT SUM(CASE WHEN folder = 'inbox' THEN 1 ELSE 0 END) AS received, SUM(CASE WHEN folder = 'sent' THEN 1 ELSE 0 END) AS sent
                FROM crm_mails WHERE user_id = ? AND mail_account_id = ? AND is_deleted = 0 AND COALESCE(received_at, sent_at, created_at) >= ?");
            $stmt->execute([$this->owner, (int) $a['id'], $since]);
            $n = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out[] = $a + ['received_30' => (int) ($n['received'] ?? 0), 'sent_30' => (int) ($n['sent'] ?? 0), 'state' => MailAccounts::state($a)];
        }
        return $out;
    }

    /** @param list<string> $times */
    private function oldestDays(array $times): int
    {
        $min = null;
        foreach ($times as $t) {
            $ts = strtotime((string) $t);
            if ($ts && ($min === null || $ts < $min)) {
                $min = $ts;
            }
        }
        return $min === null ? 0 : (int) floor((time() - $min) / 86400);
    }

    /** @param list<mixed> $extra */
    private function scalar(string $sql, array $extra = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$this->owner], $extra));
        return (int) $stmt->fetchColumn();
    }
}
