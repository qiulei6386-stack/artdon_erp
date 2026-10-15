<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use PDO;

/**
 * 客户查重：和全部未删除客户比对（旧系统只比对最近更新的 500 个）。规则与旧系统一致：
 *   名称 / 英文名相似度 ≥ 阈值（默认 80%）；邮箱一致 100；联系人邮箱一致 98；电话、WhatsApp 一致 96；
 *   联系人电话一致 94；网站或邮箱域名一致 92；同一国家且名称接近 84。最多返回 10 个，相似度高的在前。
 * 原因键沿用旧系统（写进暂存池时旧系统也能读懂）。
 */
final class DuplicateFinder
{
    public const REASON_LABELS = [
        'name_similarity' => '名称相似',
        'english_name_similarity' => '英文名相似',
        'email_match' => '邮箱相同',
        'contact_email_match' => '联系人邮箱相同',
        'phone_match' => '电话相同',
        'whatsapp_match' => 'WhatsApp 相同',
        'contact_phone_match' => '联系人电话相同',
        'domain_match' => '网站域名相同',
        'country_name_fuzzy' => '同国家且名称接近',
    ];

    /** 这些原因或相似度 ≥ 90 算高风险重复。 */
    public const HIGH_RISK_REASONS = ['email_match', 'domain_match', 'phone_match', 'whatsapp_match', 'contact_email_match', 'contact_phone_match'];

    /** 常见公共邮箱，不按域名判断重复。 */
    private const PUBLIC_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'qq.com', '163.com', '126.com', 'icloud.com', 'aol.com', 'msn.com', 'yandex.com', 'mail.ru', 'protonmail.com', 'rediffmail.com'];

    /**
     * @param array<string, string> $data customer_name、customer_name_en、email、phone、whatsapp、website、country，以及联系人 contact_email、contact_phone
     * @return list<array<string, mixed>>
     */
    public static function find(array $data, int $ignoreId = 0, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?? Db::pdo();
        $name = trim((string) ($data['customer_name'] ?? ''));
        $nameEn = trim((string) ($data['customer_name_en'] ?? ''));
        $emails = array_values(array_filter(array_unique(array_map('strtolower', array_map('trim', [(string) ($data['email'] ?? ''), (string) ($data['contact_email'] ?? '')])))));
        $phones = array_values(array_filter(array_unique([self::digits((string) ($data['phone'] ?? '')), self::digits((string) ($data['contact_phone'] ?? ''))]), [self::class, 'usefulPhone']));
        $whatsapp = self::digits((string) ($data['whatsapp'] ?? ''));
        $domain = self::domain((string) ($data['website'] ?? ''));
        if ($domain === '') {
            foreach ($emails as $e) {
                $domain = self::domain($e);
                if ($domain !== '') {
                    break;
                }
            }
        }
        $countries = array_map(static function (string $v): string {
            return mb_strtolower($v, 'UTF-8');
        }, Country::variants((string) ($data['country'] ?? '')));
        $threshold = RuleConfig::nameThreshold();

        $found = [];
        $add = static function (int $id, int $score, string $reason) use (&$found): void {
            if (!isset($found[$id])) {
                $found[$id] = ['score' => 0, 'reasons' => []];
            }
            $found[$id]['score'] = max($found[$id]['score'], $score);
            if (!in_array($reason, $found[$id]['reasons'], true)) {
                $found[$id]['reasons'][] = $reason;
            }
        };

        $stmt = $pdo->prepare('SELECT id, customer_name, customer_name_en, country, email, phone, whatsapp, website FROM crm_customers WHERE deleted_at IS NULL AND id <> ?');
        $stmt->execute([$ignoreId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $score = $name !== '' ? self::similarity($name, (string) $row['customer_name'], $threshold) : 0;
            if ($score >= $threshold) {
                $add($id, $score, 'name_similarity');
            }
            if ($nameEn !== '' && (string) $row['customer_name_en'] !== '') {
                $en = self::similarity($nameEn, (string) $row['customer_name_en'], $threshold);
                if ($en >= $threshold) {
                    $add($id, $en, 'english_name_similarity');
                }
            }
            if ($emails && in_array(strtolower(trim((string) $row['email'])), $emails, true)) {
                $add($id, 100, 'email_match');
            }
            $rowPhone = self::digits((string) $row['phone']);
            if ($phones && self::usefulPhone($rowPhone) && in_array($rowPhone, $phones, true)) {
                $add($id, 96, 'phone_match');
            }
            if (self::usefulPhone($whatsapp) && $whatsapp === self::digits((string) $row['whatsapp'])) {
                $add($id, 96, 'whatsapp_match');
            }
            if ($domain !== '') {
                $rowDomain = self::domain((string) ($row['website'] ?: $row['email']));
                if ($rowDomain === $domain) {
                    $add($id, 92, 'domain_match');
                }
            }
            if ($countries && $name !== '' && in_array(mb_strtolower(trim((string) $row['country']), 'UTF-8'), $countries, true)) {
                $near = $score > 0 ? $score : self::similarity($name, (string) $row['customer_name'], max(60, $threshold - 10));
                if ($near >= max(60, $threshold - 10)) {
                    $add($id, 84, 'country_name_fuzzy');
                }
            }
        }

        if ($emails || $phones) {
            $stmt = $pdo->prepare('SELECT ct.customer_id, ct.email, ct.phone FROM crm_contacts ct JOIN crm_customers cc ON cc.id = ct.customer_id WHERE ct.deleted_at IS NULL AND cc.deleted_at IS NULL AND ct.customer_id <> ?');
            $stmt->execute([$ignoreId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($emails && in_array(strtolower(trim((string) $row['email'])), $emails, true)) {
                    $add((int) $row['customer_id'], 98, 'contact_email_match');
                }
                $p = self::digits((string) $row['phone']);
                if ($phones && self::usefulPhone($p) && in_array($p, $phones, true)) {
                    $add((int) $row['customer_id'], 94, 'contact_phone_match');
                }
            }
        }
        if (!$found) {
            return [];
        }
        uasort($found, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $found = array_slice($found, 0, 10, true);
        $ids = array_keys($found);
        $stmt = $pdo->prepare('SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.country, c.city, c.email, c.phone, c.whatsapp, c.website, c.owner_user_id,
                COALESCE(NULLIF(u.real_name, \'\'), u.username) AS owner_name,
                (SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL) AS last_followup
            FROM crm_customers c LEFT JOIN crm_users u ON u.id = c.owner_user_id WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($found as $id => $hit) {
            if (!isset($rows[$id])) {
                continue;
            }
            $r = $rows[$id];
            $out[] = [
                'customer_id' => $id,
                'customer_code' => (string) ($r['customer_code'] ?? ''),
                'customer_name' => (string) $r['customer_name'],
                'customer_name_en' => (string) ($r['customer_name_en'] ?? ''),
                'country' => (string) $r['country'],
                'city' => (string) ($r['city'] ?? ''),
                'email' => (string) ($r['email'] ?? ''),
                'phone' => (string) ($r['phone'] ?? ''),
                'whatsapp' => (string) ($r['whatsapp'] ?? ''),
                'website' => (string) ($r['website'] ?? ''),
                'similarity' => $hit['score'],
                'reasons' => $hit['reasons'],
                'owner_name' => (string) ($r['owner_name'] ?? ''),
                'last_followup' => (string) ($r['last_followup'] ?? ''),
            ];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $matches */
    public static function hasHighRisk(array $matches): bool
    {
        foreach ($matches as $m) {
            if ((int) $m['similarity'] >= 90 || array_intersect((array) $m['reasons'], self::HIGH_RISK_REASONS)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $reasons */
    public static function reasonText(array $reasons): string
    {
        return implode('、', array_map(static function (string $r): string {
            return self::REASON_LABELS[$r] ?? $r;
        }, $reasons));
    }

    public static function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (strpos($value, '@') !== false) {
            $host = (string) substr((string) strrchr($value, '@'), 1);
        } else {
            $host = (string) parse_url(preg_match('#^https?://#', $value) ? $value : 'https://' . $value, PHP_URL_HOST);
        }
        $host = (string) preg_replace('/^www\./', '', $host);
        return in_array($host, self::PUBLIC_DOMAINS, true) ? '' : $host;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private static function usefulPhone(string $digits): bool
    {
        return strlen($digits) >= 6;
    }

    /** 与旧系统相同的算法（similar_text 百分比）；长度相差太大时直接判为不相似，省去计算。 */
    private static function similarity(string $a, string $b, int $threshold): int
    {
        $a = mb_strtolower(trim($a), 'UTF-8');
        $b = mb_strtolower(trim($b), 'UTF-8');
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if (200 * min($la, $lb) < $threshold * ($la + $lb)) {
            return 0;
        }
        similar_text($a, $b, $percent);
        return (int) round($percent);
    }
}
