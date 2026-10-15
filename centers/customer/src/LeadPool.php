<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 客户暂存池（crm_lead_pool）。查看：customer.lead_pool_view 或 customer.lead_pool；处理：customer.lead_pool。
 * 处理方式：确认入库（建成正式客户）、关联已有客户（可选择把联系人补到已有客户）、丢弃（要写原因）。
 * 资料存在 payload_json 里，新旧系统写的格式都能读。疑似重复每次打开时重新查（存的是进池时的结果，可能已过时）。
 */
final class LeadPool
{
    public const PER_PAGE = 50;
    public const STATUS_LABELS = ['pending' => '待处理', 'confirmed' => '已入库', 'merged' => '已关联', 'rejected' => '已丢弃'];
    public const VIEWS = ['pending' => '待处理', 'duplicate' => '有疑似重复', 'done' => '已处理', 'rejected' => '已丢弃'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        if (!self::canView($operator)) {
            throw new PermissionException('没有查看暂存池的权限（customer.lead_pool_view）');
        }
    }

    public static function canView(array $user): bool
    {
        return Permission::allows($user, 'customer.lead_pool_view') || Permission::allows($user, 'customer.lead_pool');
    }

    public function canProcess(): bool
    {
        return Permission::allows($this->operator, 'customer.lead_pool');
    }

    // ---------------------------------------------------------------- 查询

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public function list(string $view, string $q, int $page = 1): array
    {
        [$where, $params] = $this->where($view, $q);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_lead_pool lp WHERE ' . $where);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $stmt = $this->pdo->prepare('SELECT lp.*, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS creator_name, tc.customer_name AS target_name
            FROM crm_lead_pool lp LEFT JOIN crm_users u ON u.id = lp.created_by LEFT JOIN crm_customers tc ON tc.id = lp.target_customer_id
            WHERE ' . $where . ' ORDER BY lp.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = self::normalize($row);
            $row['fields'] = $n['fields'];
            $row['contact_count'] = count($n['contacts']);
            $row['source_name'] = $n['source_name'];
            $matches = json_decode((string) ($row['similarity_matches_json'] ?? ''), true);
            $row['match_count'] = is_array($matches) ? count($matches) : 0;
            $rows[] = $row;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $out = [];
        foreach (array_keys(self::VIEWS) as $view) {
            [$where, $params] = $this->where($view, '');
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_lead_pool lp WHERE ' . $where);
            $stmt->execute($params);
            $out[$view] = (int) $stmt->fetchColumn();
        }
        return $out;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(string $view, string $q): array
    {
        $where = [];
        $params = [];
        if ($view === 'duplicate') {
            $where[] = "lp.status = 'pending' AND lp.similarity_matches_json IS NOT NULL AND lp.similarity_matches_json <> '' AND lp.similarity_matches_json <> '[]'";
        } elseif ($view === 'done') {
            $where[] = "lp.status IN ('confirmed', 'merged')";
        } elseif ($view === 'rejected') {
            $where[] = "lp.status = 'rejected'";
        } else {
            $where[] = "lp.status = 'pending'";
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(lp.raw_name LIKE ? OR lp.raw_email LIKE ? OR lp.raw_phone LIKE ? OR lp.raw_country LIKE ? OR lp.raw_domain LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT lp.*, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS creator_name, tc.customer_name AS target_name
            FROM crm_lead_pool lp LEFT JOIN crm_users u ON u.id = lp.created_by LEFT JOIN crm_customers tc ON tc.id = lp.target_customer_id WHERE lp.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('暂存客户不存在');
        }
        $n = self::normalize($row);
        return $row + $n;
    }

    /** 现在的疑似重复（重新查）。 @return list<array<string, mixed>> */
    public function matches(array $lead): array
    {
        $f = $lead['fields'];
        $first = $lead['contacts'][0] ?? [];
        return DuplicateFinder::find($f + ['contact_email' => $first['email'] ?? '', 'contact_phone' => $first['phone'] ?? ''], 0, $this->pdo);
    }

    // ---------------------------------------------------------------- 处理

    /** 确认入库：$input 为页面上核对、修改过的客户资料（含负责人）。返回新客户编号。 */
    public function confirm(int $id, array $input): int
    {
        $lead = $this->pending($id);
        $owner = (int) ($lead['owner_user_id'] ?: ($lead['created_by'] ?? 0));
        $customers = new CustomerService($this->operator, $this->pdo);
        $this->lock($id, 'confirmed');
        try {
            $customerId = $customers->createConfirmed($input, $lead['contacts'], $owner);
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE crm_lead_pool SET status = 'pending' WHERE id = ? AND status = 'confirmed' AND target_customer_id IS NULL")->execute([$id]);
            throw $e;
        }
        $this->pdo->prepare('UPDATE crm_lead_pool SET target_customer_id = ?, updated_at = ? WHERE id = ?')->execute([$customerId, Db::now(), $id]);
        $name = (string) ($input['customer_name'] ?? $lead['fields']['customer_name']);
        CustomerLog::write('lead_pool_confirm', $customerId, '暂存池确认入库', '暂存编号 ' . $id, null, ['lead_id' => $id], $this->operator, 'lead', (string) $id);
        $this->notifyCreator($lead, '你提交的客户已入库：' . $name, ['center' => 'customer', 'path' => 'view.php?id=' . $customerId]);
        return $customerId;
    }

    /**
     * 关联已有客户：这条暂存记录就是已有客户。$addContacts 为 true 时把暂存里的联系人补到已有客户（邮箱或电话已有的跳过）。
     *
     * @return int 补上的联系人数
     */
    public function attach(int $id, int $customerId, bool $addContacts): int
    {
        $lead = $this->pending($id);
        $customers = new CustomerService($this->operator, $this->pdo);
        $customer = $customers->find($customerId);
        $added = 0;
        $this->lock($id, 'merged');
        try {
            $this->pdo->beginTransaction();
            if ($addContacts && $lead['contacts']) {
                $stmt = $this->pdo->prepare('SELECT email, phone FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL');
                $stmt->execute([$customerId]);
                $emails = [];
                $phones = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $emails[] = strtolower(trim((string) $r['email']));
                    $phones[] = preg_replace('/\D+/', '', (string) $r['phone']);
                }
                $contacts = new ContactService($this->operator, $this->pdo);
                foreach ($lead['contacts'] as $c) {
                    $e = strtolower($c['email']);
                    $p = (string) preg_replace('/\D+/', '', $c['phone']);
                    if (($e !== '' && in_array($e, $emails, true)) || ($p !== '' && in_array($p, $phones, true))) {
                        continue;
                    }
                    $contacts->insert($customerId, $c + ['is_primary' => 0], false);
                    $emails[] = $e;
                    $phones[] = $p;
                    $added++;
                }
            }
            $this->pdo->prepare('UPDATE crm_lead_pool SET target_customer_id = ?, updated_at = ? WHERE id = ?')->execute([$customerId, Db::now(), $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare("UPDATE crm_lead_pool SET status = 'pending' WHERE id = ? AND status = 'merged'")->execute([$id]);
            throw $e;
        }
        CustomerLog::write('lead_pool_attach', $customerId, '暂存客户关联到已有客户', '暂存编号 ' . $id . '：' . $lead['fields']['customer_name'] . ($added ? '；补上联系人 ' . $added . ' 个' : ''), null, ['lead_id' => $id, 'added_contacts' => $added], $this->operator, 'lead', (string) $id);
        $this->notifyCreator($lead, '你提交的客户“' . $lead['fields']['customer_name'] . '”已关联到已有客户：' . $customer['customer_name'], ['center' => 'customer', 'path' => 'view.php?id=' . $customerId]);
        return $added;
    }

    public function reject(int $id, string $reason): void
    {
        $lead = $this->pending($id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => '请填写丢弃原因']);
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ValidationException(['reason' => '原因不能超过 500 个字']);
        }
        $this->lock($id, 'rejected');
        CustomerLog::write('lead_pool_reject', 0, '丢弃暂存客户', $lead['fields']['customer_name'] . '：' . $reason, null, ['lead_id' => $id, 'reason' => $reason], $this->operator, 'lead', (string) $id);
        $this->notifyCreator($lead, '你提交的客户未入库：' . $lead['fields']['customer_name'], ['center' => 'customer', 'path' => 'lead.php?id=' . $id], '原因：' . $reason);
    }

    /** 丢弃原因（从客户日志里取）。 */
    public function rejectReason(int $id): string
    {
        if (!Db::tableExists('crm_logs')) {
            return '';
        }
        $stmt = $this->pdo->prepare("SELECT after_json FROM crm_logs WHERE action = 'lead_pool_reject' AND object_type = 'lead' AND object_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(string) $id]);
        $data = json_decode((string) $stmt->fetchColumn(), true);
        return is_array($data) ? (string) ($data['reason'] ?? '') : '';
    }

    // ---------------------------------------------------------------- 资料整理

    /**
     * 把暂存记录整理成客户表单字段和联系人（兼容旧系统写入的各种格式）。
     *
     * @return array{fields: array<string, mixed>, contacts: list<array<string, string>>, owner_user_id: int, source_name: string, skipped_contacts: int}
     */
    public static function normalize(array $row): array
    {
        $p = json_decode((string) ($row['payload_json'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        $str = static function (...$values): string {
            foreach ($values as $v) {
                if (is_scalar($v) && trim((string) $v) !== '') {
                    return trim((string) $v);
                }
            }
            return '';
        };
        $list = static function ($v): array {
            if (is_string($v)) {
                $decoded = json_decode($v, true);
                $v = is_array($decoded) ? $decoded : preg_split('/[,，\s]+/u', $v, -1, PREG_SPLIT_NO_EMPTY);
            }
            return is_array($v) ? array_values(array_filter(array_map(static function ($x): string {
                return is_scalar($x) ? trim((string) $x) : '';
            }, $v))) : [];
        };
        $addresses = $p['addresses'] ?? ($p['addresses_json'] ?? []);
        if (is_string($addresses)) {
            $addresses = json_decode($addresses, true) ?: [];
        }
        $primaryAddress = [];
        foreach ((array) $addresses as $a) {
            if (is_array($a) && ($primaryAddress === [] || !empty($a['is_primary']))) {
                $primaryAddress = $a;
            }
        }
        $fields = [
            'customer_name' => $str($p['customer_name'] ?? null, $p['raw_name'] ?? null, $row['raw_name'] ?? null),
            'customer_name_en' => $str($p['customer_name_en'] ?? null),
            'customer_code' => $str($p['customer_code'] ?? null),
            'country' => $str($p['country'] ?? null, $p['raw_country'] ?? null, $primaryAddress['country'] ?? null, $row['raw_country'] ?? null),
            'city' => $str($p['city'] ?? null, $primaryAddress['city'] ?? null),
            'address' => $str($p['address'] ?? null, $primaryAddress['address'] ?? null),
            'website' => $str($p['website'] ?? null),
            'email' => $str($p['email'] ?? null, $p['raw_email'] ?? null),
            'phone' => $str($p['phone'] ?? null, $p['raw_phone'] ?? null),
            'whatsapp' => $str($p['whatsapp'] ?? null),
            'wechat' => $str($p['wechat'] ?? null),
            'level' => $str($p['level'] ?? null),
            'lifecycle_key' => $str($p['lifecycle_key'] ?? null),
            'source_tags' => $list($p['source_tags'] ?? ($p['source'] ?? [])),
            'promotion_channels' => $list($p['promotion_channels'] ?? []),
            'remark' => $str($p['remark'] ?? null),
        ];
        $source = '';
        foreach (['entry_source', 'source_label', 'source_name', 'import_source', 'origin', 'channel'] as $k) {
            if (is_scalar($p[$k] ?? null) && trim((string) $p[$k]) !== '') {
                $source = trim((string) $p[$k]);
                break;
            }
        }
        if ($source === '' && $fields['source_tags']) {
            $source = implode('、', array_map(static function (string $k): string {
                return \Artdon\Centers\Base\Dictionary::label('customer_source', $k);
            }, $fields['source_tags']));
        }
        $raw = $p['contacts'] ?? ($p['contacts_json'] ?? []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        $raw = is_array($raw) ? array_values($raw) : [];
        if (!$raw && $str($p['contact_name'] ?? null, $p['contact_email'] ?? null, $p['contact_phone'] ?? null, $p['contact_whatsapp'] ?? null) !== '') {
            $raw = [['name' => $p['contact_name'] ?? '', 'email' => $p['contact_email'] ?? '', 'phone' => $p['contact_phone'] ?? '', 'whatsapp' => $p['contact_whatsapp'] ?? '',
                'wechat' => $p['contact_wechat'] ?? '', 'position' => $p['contact_position'] ?? '', 'is_primary' => 1]];
        }
        [$contacts, $skipped] = self::cleanContacts($raw);
        $owner = (int) ($p['owner_user_id'] ?? 0);
        if ($owner <= 0 && !empty($p['owner_user_ids'])) {
            $ids = $list($p['owner_user_ids']);
            $owner = (int) ($ids[0] ?? 0);
        }
        return ['fields' => $fields, 'contacts' => $contacts, 'owner_user_id' => $owner, 'source_name' => $source, 'skipped_contacts' => $skipped];
    }

    /**
     * 整理联系人：主联系人排第一；邮箱格式不对的挪到备注；同一邮箱或电话只留一个；什么都没有的跳过。
     *
     * @param list<mixed> $raw
     * @return array{0: list<array<string, string>>, 1: int}
     */
    private static function cleanContacts(array $raw): array
    {
        usort($raw, static function ($a, $b): int {
            return (int) !empty(is_array($b) ? ($b['is_primary'] ?? $b['primary'] ?? 0) : 0) <=> (int) !empty(is_array($a) ? ($a['is_primary'] ?? $a['primary'] ?? 0) : 0);
        });
        $out = [];
        $skipped = 0;
        $seen = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                $skipped++;
                continue;
            }
            $c = [
                'name' => $c['name'] ?? ($c['contact_name'] ?? ''), 'name_en' => $c['name_en'] ?? '', 'position' => $c['position'] ?? ($c['contact_position'] ?? ''),
                'email' => $c['email'] ?? ($c['contact_email'] ?? ''), 'phone' => $c['phone'] ?? ($c['contact_phone'] ?? ''), 'whatsapp' => $c['whatsapp'] ?? ($c['contact_whatsapp'] ?? ''),
                'wechat' => $c['wechat'] ?? '', 'linkedin' => $c['linkedin'] ?? '', 'remark' => $c['remark'] ?? '',
            ];
            $email = is_scalar($c['email']) ? trim((string) $c['email']) : '';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $c['remark'] = trim((is_scalar($c['remark']) ? (string) $c['remark'] : '') . ' 原邮箱：' . $email);
                $c['email'] = '';
            }
            try {
                $c = ContactService::check($c);
            } catch (ValidationException $e) {
                $skipped++;
                continue;
            }
            $keys = array_filter([strtolower($c['email']), (string) preg_replace('/\D+/', '', $c['phone'])]);
            if (array_intersect($keys, $seen)) {
                $skipped++;
                continue;
            }
            $seen = array_merge($seen, $keys);
            $out[] = $c;
        }
        return [$out, $skipped];
    }

    /** @return array<string, mixed> */
    private function pending(int $id): array
    {
        if (!$this->canProcess()) {
            throw new PermissionException('没有处理暂存池的权限（customer.lead_pool）');
        }
        $lead = $this->get($id);
        if ($lead['status'] !== 'pending') {
            throw new RuntimeException('这条暂存记录已经处理过了（' . (self::STATUS_LABELS[$lead['status']] ?? $lead['status']) . '）');
        }
        return $lead;
    }

    /** 抢占这条记录，防止两个人同时处理。 */
    private function lock(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare("UPDATE crm_lead_pool SET status = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
        $stmt->execute([$status, Db::now(), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('这条暂存记录刚被别人处理了，请刷新');
        }
    }

    private function notifyCreator(array $lead, string $title, array $link, string $content = ''): void
    {
        $creator = (int) ($lead['created_by'] ?? 0);
        if ($creator > 0 && $creator !== (int) $this->operator['id']) {
            Notify::send([$creator], 'lead_pool_processed', $title, $content !== '' ? $content : '处理人：' . Auth::displayName($this->operator), $link);
        }
    }
}
