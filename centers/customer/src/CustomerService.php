<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Auth;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Dictionary;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Notify;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\UserDirectory;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 客户中心第一批：列表、详情、新建（查重 + 准入规则）、编辑、分配、公海、删除与恢复。
 * 只读写正式库已有的表，规则见 WORK_CONTEXT.md 中用户确认的 9 项决定。
 */
final class CustomerService
{
    public const PER_PAGE = 50;
    public const TABS = ['mine' => '我的客户', 'all' => '全部客户', 'public' => '公海', 'deleted' => '已删除'];
    public const FOLLOW_FILTERS = ['7' => '7 天未跟进', '15' => '15 天未跟进', '30' => '30 天未跟进', 'never' => '从未跟进'];
    public const CREATED_FILTERS = ['today' => '今天新增', '7d' => '7 天内新增', 'month' => '本月新增'];
    public const SORTS = ['updated' => '最近更新', 'created' => '最近新建', 'followup' => '最近跟进', 'name' => '名称'];

    /** 表单里的客户字段 => [名称, 最长]。 */
    public const TEXT_FIELDS = [
        'customer_name' => ['客户名称', 190],
        'customer_name_en' => ['英文名', 190],
        'customer_code' => ['客户代码', 80],
        'city' => ['城市', 120],
        'address' => ['地址', 500],
        'phone' => ['电话', 80],
        'whatsapp' => ['WhatsApp', 80],
        'wechat' => ['微信', 120],
        'remark' => ['备注', 5000],
    ];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        if (!$this->can('customer.view')) {
            throw new PermissionException('没有查看客户的权限（customer.view）');
        }
    }

    public function can(string $permission): bool
    {
        return Permission::allows($this->operator, $permission);
    }

    public function canSeePublic(): bool
    {
        return CustomerScope::canSeePublic($this->operator);
    }

    public function canSeeDeleted(): bool
    {
        return $this->can('customer.restore') || $this->can('customer.delete');
    }

    /** @return array<string, string> 当前用户能看到的标签 */
    public function tabs(): array
    {
        $tabs = self::TABS;
        if (!$this->canSeePublic()) {
            unset($tabs['public']);
        }
        if (!$this->canSeeDeleted()) {
            unset($tabs['deleted']);
        }
        return $tabs;
    }

    // ---------------------------------------------------------------- 列表

    /**
     * @param array<string, mixed> $f q、country、level、lifecycle、owner_id、follow、created、no_contact、sort
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function list(string $tab, array $f, int $page = 1): array
    {
        [$where, $params] = $this->where($tab, $f);
        $sql = ' FROM crm_customers c WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $orders = [
            'updated' => 'c.updated_at DESC, c.id DESC',
            'created' => 'c.created_at DESC, c.id DESC',
            'followup' => 'last_followup IS NULL, last_followup DESC, c.id DESC',
            'name' => 'c.customer_name ASC, c.id ASC',
        ];
        $sort = $orders[(string) ($f['sort'] ?? '')] ?? ($tab === 'deleted' ? 'c.deleted_at DESC, c.id DESC' : $orders['updated']);
        $stmt = $this->pdo->prepare('SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.country, c.city, c.level, c.lifecycle_key, c.owner_user_id,
                c.created_at, c.updated_at, c.deleted_at, c.delete_reason, c.email, c.do_not_contact,
                (SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL) AS last_followup,
                (SELECT COUNT(*) FROM crm_contacts ct WHERE ct.customer_id = c.id AND ct.deleted_at IS NULL) AS contact_count'
            . $sql . ' ORDER BY ' . $sort . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $owners = $this->ownersFor(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['owners'] = $owners[$id] ?? [];
            $row['primary_owner'] = $this->primaryOwnerName($row['owners'], (int) ($row['owner_user_id'] ?? 0));
            $row['assistants'] = array_values(array_filter($row['owners'], static function (array $o): bool {
                return !$o['is_primary'];
            }));
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** 各标签的数量。 @return array<string, int> */
    public function counts(): array
    {
        $out = [];
        foreach (array_keys($this->tabs()) as $tab) {
            if ($tab === 'deleted') {
                continue;
            }
            [$where, $params] = $this->where($tab, []);
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_customers c WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);
            $out[$tab] = (int) $stmt->fetchColumn();
        }
        return $out;
    }

    /** @return array{0: list<string>, 1: list<mixed>} */
    private function where(string $tab, array $f): array
    {
        $params = [];
        $where = [];
        $userId = (int) $this->operator['id'];
        if ($tab === 'public') {
            if (!$this->canSeePublic()) {
                throw new PermissionException('没有查看公海的权限');
            }
            $where[] = 'c.deleted_at IS NULL';
            $where[] = CustomerScope::publicSql();
        } elseif ($tab === 'deleted') {
            if (!$this->canSeeDeleted()) {
                throw new PermissionException('没有查看已删除客户的权限');
            }
            $where[] = 'c.deleted_at IS NOT NULL';
            $where[] = CustomerScope::sql($this->operator, $params);
        } elseif ($tab === 'mine') {
            $where[] = 'c.deleted_at IS NULL';
            $where[] = CustomerScope::mineSql($userId, $params);
        } else {
            $where[] = 'c.deleted_at IS NULL';
            $where[] = CustomerScope::sql($this->operator, $params);
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $cols = ['c.customer_name', 'c.customer_name_en', 'c.customer_code', 'c.email', 'c.phone', 'c.whatsapp', 'c.wechat', 'c.website', 'c.city'];
            $where[] = '(' . implode(' LIKE ? OR ', $cols) . ' LIKE ? OR EXISTS (SELECT 1 FROM crm_contacts qct WHERE qct.customer_id = c.id AND qct.deleted_at IS NULL AND (qct.name LIKE ? OR qct.email LIKE ? OR qct.phone LIKE ? OR qct.whatsapp LIKE ?)))';
            for ($i = 0; $i < count($cols) + 4; $i++) {
                $params[] = $like;
            }
        }
        $country = trim((string) ($f['country'] ?? ''));
        if ($country !== '') {
            $variants = Country::variants($country);
            $where[] = 'c.country IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
            foreach ($variants as $v) {
                $params[] = $v;
            }
        }
        foreach (['level' => 'c.level', 'lifecycle' => 'c.lifecycle_key'] as $key => $col) {
            $v = trim((string) ($f[$key] ?? ''));
            if ($v !== '') {
                $where[] = $col . ' = ?';
                $params[] = $v;
            }
        }
        $ownerId = (int) ($f['owner_id'] ?? 0);
        if ($ownerId > 0 && $tab !== 'public') {
            $where[] = CustomerScope::mineSql($ownerId, $params);
        }
        $follow = (string) ($f['follow'] ?? '');
        if ($follow === 'never') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups ff WHERE ff.customer_id = c.id AND ff.deleted_at IS NULL)';
        } elseif (isset(self::FOLLOW_FILTERS[$follow])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM crm_customer_followups ff WHERE ff.customer_id = c.id AND ff.deleted_at IS NULL AND ff.followup_time >= ?)';
            $params[] = date('Y-m-d H:i:s', time() - (int) $follow * 86400);
        }
        $created = (string) ($f['created'] ?? '');
        if ($created === 'today') {
            $where[] = 'c.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00');
        } elseif ($created === '7d') {
            $where[] = 'c.created_at >= ?';
            $params[] = date('Y-m-d H:i:s', time() - 7 * 86400);
        } elseif ($created === 'month') {
            $where[] = 'c.created_at >= ?';
            $params[] = date('Y-m-01 00:00:00');
        }
        if (!empty($f['no_contact'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM crm_contacts nct WHERE nct.customer_id = c.id AND nct.deleted_at IS NULL)';
        }
        return [$where, $params];
    }

    // ---------------------------------------------------------------- 详情

    /** 查看一个客户（看不到时抛 NotFoundException）。 @return array<string, mixed> */
    public function get(int $id, bool $includeDeleted = false): array
    {
        $row = $this->visibleRow($id, $includeDeleted);
        $row['owners'] = $this->ownersFor([$id])[$id] ?? [];
        $row['primary_owner'] = $this->primaryOwnerName($row['owners'], (int) ($row['owner_user_id'] ?? 0));
        $row['is_public'] = $this->isPublic($row);
        $row['source_tags'] = $this->keys('crm_customer_source_tags', 'source_key', $id);
        $row['promotion_channels'] = $this->keys('crm_customer_promotion_channels', 'channel_key', $id);
        $stmt = $this->pdo->prepare('SELECT status FROM crm_customer_promotion_status WHERE customer_id = ?');
        $stmt->execute([$id]);
        $row['promotion_status'] = (string) ($stmt->fetchColumn() ?: '');
        $stmt = $this->pdo->prepare('SELECT protection_status, reason, public_pool_at, claimed_at FROM crm_customer_protection WHERE customer_id = ?');
        $stmt->execute([$id]);
        $row['protection'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt = $this->pdo->prepare('SELECT g.id, g.group_name, g.group_color FROM crm_customer_group_relations r JOIN crm_customer_groups g ON g.id = r.group_id WHERE r.customer_id = ? AND g.deleted_at IS NULL ORDER BY g.sort_order, g.id');
        $stmt->execute([$id]);
        $row['groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT f.id, f.followup_time, f.followup_type, f.content, f.next_plan, f.next_remind_time, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS user_name
            FROM crm_customer_followups f LEFT JOIN crm_users u ON u.id = f.created_by WHERE f.customer_id = ? AND f.deleted_at IS NULL ORDER BY f.followup_time DESC, f.id DESC LIMIT 20');
        $stmt->execute([$id]);
        $row['followups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM crm_customer_followups WHERE customer_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row['followup_count'] = (int) $stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT address_type, country, city, address, is_primary FROM crm_customer_addresses WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id');
        $stmt->execute([$id]);
        $row['addresses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT COALESCE(NULLIF(real_name, \'\'), username) FROM crm_users WHERE id = ?');
        $stmt->execute([(int) ($row['created_by'] ?? 0)]);
        $row['created_by_name'] = (string) ($stmt->fetchColumn() ?: '');
        return $row;
    }

    /** 资料完整度提示：缺哪些常用信息。 @return list<string> */
    public function missing(array $customer, array $contacts): array
    {
        $out = [];
        if (trim((string) ($customer['city'] ?? '')) === '') {
            $out[] = '城市';
        }
        if (trim((string) ($customer['website'] ?? '')) === '') {
            $out[] = '网站';
        }
        if (!$contacts) {
            $out[] = '联系人';
        } elseif (!array_filter($contacts, static function (array $c): bool {
            return trim((string) $c['email']) !== '';
        }) && trim((string) ($customer['email'] ?? '')) === '') {
            $out[] = '邮箱';
        }
        if (!$customer['source_tags']) {
            $out[] = '来源';
        }
        if (!$customer['owners'] && empty($customer['owner_user_id'])) {
            $out[] = '负责人';
        }
        return $out;
    }

    /** 客户是否由自己维护：未删除、不在公海、在自己的范围内（联系人等也按这个判断）。 */
    public function canMaintain(array $customer): bool
    {
        return empty($customer['deleted_at']) && !$this->isPublic($customer) && $this->inScope((int) $customer['id']);
    }

    /** 能否编辑这个客户。 */
    public function canEdit(array $customer): bool
    {
        return $this->can('customer.edit') && $this->canMaintain($customer);
    }

    // ---------------------------------------------------------------- 新建

    /**
     * 新建客户。
     * 第一次提交时，若查到疑似重复客户，先返回 status = duplicates 让用户确认（confirm_duplicates = 1 再提交）。
     * 确认后按准入规则：进入暂存池（status = lead_pool）或直接建成正式客户（status = created）。
     *
     * @return array{status: string, id?: int, lead_id?: int, matches?: list<array<string, mixed>>, reason?: string}
     */
    public function create(array $input): array
    {
        if (!$this->can('customer.create')) {
            throw new PermissionException('没有新建客户的权限（customer.create）');
        }
        $data = $this->validate($input, null);
        $contact = $this->firstContact($input);
        [$primaryId, $assistantIds] = $this->ownerInput($input);

        $matches = DuplicateFinder::find($data + ['contact_email' => $contact['email'] ?? '', 'contact_phone' => $contact['phone'] ?? ''], 0, $this->pdo);
        if ($matches && empty($input['confirm_duplicates'])) {
            return ['status' => 'duplicates', 'matches' => $matches];
        }
        $toPool = false;
        $reason = '';
        if (RuleConfig::leadPoolEnabled()) {
            $mode = RuleConfig::admissionMode();
            if ($mode === 'lead_pool_first') {
                $toPool = true;
                $reason = '按准入规则，新建客户先进入暂存池';
            } elseif ($mode === 'duplicate_risk_only' && $matches) {
                $toPool = true;
                $reason = '有疑似重复客户，按准入规则进入暂存池';
            } elseif ($mode === 'external_sources_only') {
                $external = (array) (RuleConfig::get('customer_admission')['external_sources_to_pool'] ?? []);
                if (array_intersect($data['source_tags'], $external)) {
                    $toPool = true;
                    $reason = '外部来源客户，按准入规则进入暂存池';
                }
            }
        }
        if (!$toPool && DuplicateFinder::hasHighRisk($matches)) {
            $action = RuleConfig::duplicateAction();
            if ($action === 'block') {
                throw new ValidationException(['customer_name' => '存在高度疑似重复的客户，按查重规则不能保存']);
            }
            if (in_array($action, ['lead_pool', 'admin_confirm'], true) && RuleConfig::leadPoolEnabled()) {
                $toPool = true;
                $reason = '和已有客户高度疑似重复，按查重规则进入暂存池';
            }
        }
        if (!$toPool) {
            $this->assertUnique($data, 0);
        }
        if ($toPool) {
            $leadId = $this->createLead($data, $contact, $primaryId, $assistantIds, $matches);
            return ['status' => 'lead_pool', 'lead_id' => $leadId, 'matches' => $matches, 'reason' => $reason];
        }
        $id = $this->insertCustomer($data, $contact, $primaryId, $assistantIds);
        return ['status' => 'created', 'id' => $id, 'matches' => $matches];
    }

    /** @return int 暂存池记录编号 */
    private function createLead(array $data, ?array $contact, int $primaryId, array $assistantIds, array $matches): int
    {
        $payload = $data;
        $payload['source'] = $data['source_tags'][0] ?? '';
        $payload['owner_user_id'] = $primaryId;
        $payload['owner_user_ids'] = array_values(array_merge([$primaryId], $assistantIds));
        $payload['entry_source'] = '新系统客户中心';
        if ($contact) {
            $payload['contacts'] = [$contact + ['is_primary' => 1]];
        }
        $now = Db::now();
        $this->pdo->prepare('INSERT INTO crm_lead_pool (raw_name, raw_email, raw_phone, raw_country, raw_domain, payload_json, similarity_matches_json, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?)')
            ->execute([
                $data['customer_name'], $data['email'] !== '' ? $data['email'] : ($contact['email'] ?? ''), $data['phone'] !== '' ? $data['phone'] : ($contact['phone'] ?? ''),
                $data['country'], DuplicateFinder::domain($data['website'] !== '' ? $data['website'] : $data['email']),
                json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode($matches, JSON_UNESCAPED_UNICODE), (int) $this->operator['id'], $now, $now,
            ]);
        $leadId = (int) $this->pdo->lastInsertId();
        CustomerLog::write('lead_pool_create', 0, '客户进入暂存池', $data['customer_name'], null, ['lead_id' => $leadId, 'matches' => count($matches)], $this->operator, 'lead', (string) $leadId);
        return $leadId;
    }

    private function insertCustomer(array $data, ?array $contact, int $primaryId, array $assistantIds): int
    {
        $now = Db::now();
        $me = (int) $this->operator['id'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO crm_customers (customer_code, customer_name, customer_name_en, country, city, address, website, email, phone, whatsapp, wechat, source, level, status, lifecycle_key, owner_user_id, owner_department, created_by, updated_by, remark, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $data['customer_code'] !== '' ? $data['customer_code'] : null, $data['customer_name'], $data['customer_name_en'], $data['country'], $data['city'], $data['address'],
                    $data['website'], $data['email'], $data['phone'], $data['whatsapp'], $data['wechat'], $data['source_tags'][0] ?? '', $data['level'],
                    (string) RuleConfig::defaultValue('customer_status', Dictionary::defaultKey('customer_status', 'lead')), $data['lifecycle_key'],
                    $primaryId, $this->departmentName($primaryId), $me, $me, $data['remark'], $now, $now,
                ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->savePrimaryAddress($id, $data);
            $this->saveKeys($id, 'crm_customer_source_tags', 'source_key', $data['source_tags']);
            $this->saveKeys($id, 'crm_customer_promotion_channels', 'channel_key', $data['promotion_channels']);
            $status = (string) RuleConfig::defaultValue('promotion_status', Dictionary::defaultKey('promotion_status', 'not_promoted'));
            $this->pdo->prepare('INSERT INTO crm_customer_promotion_status (customer_id, status, updated_by, updated_at) VALUES (?, ?, ?, ?)')->execute([$id, $status, $me, $now]);
            $this->saveOwners($id, $primaryId, $assistantIds);
            if ($contact) {
                (new ContactService($this->operator, $this->pdo))->insert($id, $contact + ['is_primary' => 1], false);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        CustomerLog::write('customer_create', $id, '新建客户', $data['customer_name'], null, ['customer' => $data, 'owner_user_id' => $primaryId, 'assistant_ids' => $assistantIds, 'contact' => $contact], $this->operator);
        if ($primaryId !== $me) {
            Notify::send([$primaryId], 'customer_assigned', '你被设为客户负责人：' . $data['customer_name'], '由 ' . Auth::displayName($this->operator) . ' 新建并分配给你', ['center' => 'customer', 'path' => 'view.php?id=' . $id]);
        }
        return $id;
    }

    // ---------------------------------------------------------------- 编辑

    public function update(int $id, array $input): array
    {
        if (!$this->can('customer.edit')) {
            throw new PermissionException('没有编辑客户的权限（customer.edit）');
        }
        $before = $this->visibleRow($id, false);
        if ($this->isPublic($before) || !$this->inScope($id)) {
            throw new PermissionException('公海客户需要先领取或分配后才能编辑');
        }
        $beforeKeys = [
            'source_tags' => $this->keys('crm_customer_source_tags', 'source_key', $id),
            'promotion_channels' => $this->keys('crm_customer_promotion_channels', 'channel_key', $id),
        ];
        $data = $this->validate($input, $before + $beforeKeys);
        $this->assertUnique($data, $id);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE crm_customers SET customer_code = ?, customer_name = ?, customer_name_en = ?, country = ?, city = ?, address = ?, website = ?, email = ?, phone = ?, whatsapp = ?, wechat = ?,
                    source = ?, level = ?, lifecycle_key = ?, remark = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([
                    $data['customer_code'] !== '' ? $data['customer_code'] : null, $data['customer_name'], $data['customer_name_en'], $data['country'], $data['city'], $data['address'], $data['website'],
                    $data['email'], $data['phone'], $data['whatsapp'], $data['wechat'], $data['source_tags'][0] ?? (string) ($before['source'] ?? ''), $data['level'], $data['lifecycle_key'],
                    $data['remark'], (int) $this->operator['id'], $now, $id,
                ]);
            $this->savePrimaryAddress($id, $data);
            $this->saveKeys($id, 'crm_customer_source_tags', 'source_key', $data['source_tags']);
            $this->saveKeys($id, 'crm_customer_promotion_channels', 'channel_key', $data['promotion_channels']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $changed = [];
        $old = [];
        foreach ($data as $k => $v) {
            $prev = $beforeKeys[$k] ?? ($before[$k] ?? '');
            if ($prev === null) {
                $prev = '';
            }
            if ($prev != $v) {
                $old[$k] = $prev;
                $changed[$k] = $v;
            }
        }
        if ($changed) {
            CustomerLog::write('customer_update', $id, '编辑客户', '修改了 ' . implode('、', array_map([self::class, 'fieldLabel'], array_keys($changed))), $old, $changed, $this->operator);
        }
        return $changed;
    }

    public static function fieldLabel(string $key): string
    {
        $labels = [
            'country' => '国家', 'website' => '网站', 'email' => '邮箱', 'level' => '等级', 'lifecycle_key' => '阶段',
            'source_tags' => '来源', 'promotion_channels' => '推广方式',
        ];
        return $labels[$key] ?? (self::TEXT_FIELDS[$key][0] ?? $key);
    }

    // ---------------------------------------------------------------- 分配与公海

    /**
     * 分配负责人（可批量）：第一负责人 + 协助负责人，替换原有负责人。需要 customer.assign。
     *
     * @param list<int> $ids
     * @param list<int> $assistantIds
     */
    public function assign(array $ids, int $primaryId, array $assistantIds = [], string $note = ''): int
    {
        if (!$this->can('customer.assign')) {
            throw new PermissionException('没有分配客户的权限（customer.assign）');
        }
        $ids = $this->ids($ids);
        $errors = [];
        if (!UserDirectory::isActive($primaryId)) {
            $errors['owner_user_id'] = '请选择正常状态的第一负责人';
        }
        $assistantIds = array_values(array_diff(array_unique(array_filter(array_map('intval', $assistantIds))), [$primaryId]));
        foreach ($assistantIds as $a) {
            if (!UserDirectory::isActive($a)) {
                $errors['assistant_ids'] = '协助负责人里有不可用的账号';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $rows = [];
        foreach ($ids as $id) {
            $rows[$id] = $this->visibleRow($id, false);
        }
        $now = Db::now();
        $dept = $this->departmentName($primaryId);
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $id => $row) {
                $this->pdo->prepare('UPDATE crm_customers SET owner_user_id = ?, owner_department = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$primaryId, $dept, (int) $this->operator['id'], $now, $id]);
                $this->saveOwners($id, $primaryId, $assistantIds);
                $this->setProtection($id, 'normal', $note !== '' ? $note : '分配负责人');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $names = [];
        foreach ($rows as $id => $row) {
            $names[] = (string) $row['customer_name'];
            CustomerLog::write('customer_assign', $id, '分配负责人', '第一负责人：' . $this->userName($primaryId) . ($assistantIds ? '；协助：' . implode('、', array_map([$this, 'userName'], $assistantIds)) : '') . ($note !== '' ? '；' . $note : ''),
                ['owner_user_id' => $row['owner_user_id']], ['owner_user_id' => $primaryId, 'assistant_ids' => $assistantIds], $this->operator);
        }
        $notify = array_diff(array_merge([$primaryId], $assistantIds), [(int) $this->operator['id']]);
        $title = count($names) === 1 ? '你被分配了客户：' . $names[0] : '你被分配了 ' . count($names) . ' 个客户';
        Notify::send(array_values($notify), 'customer_assigned', $title, '分配人：' . Auth::displayName($this->operator) . ($note !== '' ? '；' . $note : ''),
            ['center' => 'customer', 'path' => count($ids) === 1 ? 'view.php?id=' . $ids[0] : 'index.php?tab=mine']);
        return count($ids);
    }

    /** 转入公海：清掉所有负责人。 @param list<int> $ids */
    public function transferPublic(array $ids, string $reason): int
    {
        if (!$this->can('customer.transfer_public')) {
            throw new PermissionException('没有转入公海的权限（customer.transfer_public）');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => '请填写转入公海的原因']);
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ValidationException(['reason' => '原因不能超过 500 个字']);
        }
        $ids = $this->ids($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->visibleRow($id, false);
            if ($this->isPublic($row)) {
                throw new RuntimeException('“' . $row['customer_name'] . '”已经在公海里了');
            }
            if (!$this->inScope($id)) {
                throw new NotFoundException('客户不存在或无权查看');
            }
            $rows[$id] = $row;
        }
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $id => $row) {
                $this->pdo->prepare('UPDATE crm_customers SET owner_user_id = NULL, owner_department = \'\', updated_by = ?, updated_at = ? WHERE id = ?')->execute([(int) $this->operator['id'], $now, $id]);
                $this->pdo->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([$id]);
                $this->setProtection($id, 'public_pool', $reason);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        foreach ($rows as $id => $row) {
            $owners = $this->ownerIdsBefore($row);
            CustomerLog::write('customer_public_pool', $id, '转入公海', $reason, ['owner_user_id' => $row['owner_user_id']], ['public_pool' => true, 'reason' => $reason], $this->operator);
            $notify = array_values(array_diff($owners, [(int) $this->operator['id']]));
            Notify::send($notify, 'customer_public_pool', '客户已转入公海：' . $row['customer_name'], '原因：' . $reason . '；操作人：' . Auth::displayName($this->operator), ['center' => 'customer', 'path' => 'index.php?tab=public']);
        }
        return count($rows);
    }

    /** 领取公海客户：自己成为唯一负责人。 */
    public function claim(int $id): void
    {
        if (!$this->can('customer.claim_public')) {
            throw new PermissionException('没有领取公海客户的权限（customer.claim_public）');
        }
        $row = $this->visibleRow($id, false);
        if (!$this->isPublic($row)) {
            throw new RuntimeException('这个客户已经有负责人了，不能领取');
        }
        $me = (int) $this->operator['id'];
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE crm_customers SET owner_user_id = ?, owner_department = ?, updated_by = ?, updated_at = ? WHERE id = ? AND owner_user_id IS NULL AND deleted_at IS NULL');
            $stmt->execute([$me, $this->departmentName($me), $me, $now, $id]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('这个客户刚被别人领取了');
            }
            $this->saveOwners($id, $me, []);
            $this->setProtection($id, 'normal', '公海领取', true);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        CustomerLog::write('customer_claim_public', $id, '领取公海客户', Auth::displayName($this->operator) . ' 领取', null, ['owner_user_id' => $me], $this->operator);
    }

    // ---------------------------------------------------------------- 删除与恢复

    public function delete(int $id, string $reason): void
    {
        if (!$this->can('customer.delete')) {
            throw new PermissionException('没有删除客户的权限（customer.delete）');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => '请填写删除原因']);
        }
        $row = $this->visibleRow($id, false);
        $this->pdo->prepare('UPDATE crm_customers SET deleted_at = ?, deleted_by = ?, delete_reason = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL')
            ->execute([Db::now(), (int) $this->operator['id'], mb_substr($reason, 0, 500, 'UTF-8'), Db::now(), $id]);
        CustomerLog::write('customer_delete', $id, '删除客户', $reason, ['customer_name' => $row['customer_name']], ['deleted' => true, 'reason' => $reason], $this->operator);
    }

    public function restore(int $id): void
    {
        if (!$this->can('customer.restore')) {
            throw new PermissionException('没有恢复客户的权限（customer.restore）');
        }
        $row = $this->visibleRow($id, true);
        if (empty($row['deleted_at'])) {
            throw new RuntimeException('这个客户没有被删除');
        }
        $this->pdo->prepare('UPDATE crm_customers SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, updated_at = ? WHERE id = ?')->execute([Db::now(), $id]);
        CustomerLog::write('customer_restore', $id, '恢复客户', (string) $row['customer_name'], ['deleted_at' => $row['deleted_at'], 'delete_reason' => $row['delete_reason']], ['restored' => true], $this->operator);
    }

    // ---------------------------------------------------------------- 表单选项

    /** @return array<string, array<string, string>> */
    public static function options(): array
    {
        return [
            'country' => Country::options(),
            'level' => Dictionary::options('customer_level'),
            'lifecycle' => Dictionary::options('customer_lifecycle'),
            'source' => Dictionary::options('customer_source'),
            'promotion_channel' => Dictionary::options('promotion_channel'),
        ];
    }

    // ---------------------------------------------------------------- 内部

    /**
     * 校验表单。$current 为编辑时的原数据：原来的值即使已不在字典里也允许保留。
     *
     * @return array<string, mixed>
     */
    private function validate(array $input, ?array $current): array
    {
        $this->lastInput = $input;
        $v = new Validator($input);
        $data = [];
        foreach (self::TEXT_FIELDS as $key => [$label, $max]) {
            $data[$key] = $v->text($key, $label, ['required' => $key === 'customer_name', 'max' => $max]);
        }
        $data['email'] = $v->email('email', '邮箱');
        $country = $v->text('country', '国家', ['required' => true, 'max' => 120]);
        if ($country !== '') {
            $normalized = Country::normalize($country);
            $known = Country::options();
            if ($known && !isset($known[$normalized]) && ($current === null || $country !== (string) $current['country'])) {
                $v->fail('country', '请从列表里选择国家');
            }
            $country = isset($known[$normalized]) ? $normalized : $country;
        }
        $data['country'] = $country;
        $website = $v->text('website', '网站', ['max' => 255]);
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || strpos((string) parse_url($website, PHP_URL_HOST), '.') === false)) {
            $v->fail('website', '网站格式不正确，例如 www.example.com');
        }
        $data['website'] = $website;
        $data['level'] = $this->pick($v, 'level', '等级', 'customer_level', $current['level'] ?? null, (string) RuleConfig::defaultValue('customer_level', 'P3'));
        $data['lifecycle_key'] = $this->pick($v, 'lifecycle_key', '阶段', 'customer_lifecycle', $current['lifecycle_key'] ?? null, Dictionary::defaultKey('customer_lifecycle', 'lead'));
        $data['source_tags'] = $this->pickMany($v, 'source_tags', '来源', 'customer_source', $current['source_tags'] ?? null, [(string) RuleConfig::defaultValue('source', Dictionary::defaultKey('customer_source', 'website'))]);
        $default = RuleConfig::defaultValue('promotion_channels', [Dictionary::defaultKey('promotion_channel', 'email')]);
        $data['promotion_channels'] = $this->pickMany($v, 'promotion_channels', '推广方式', 'promotion_channel', $current['promotion_channels'] ?? null, is_array($default) ? array_map('strval', $default) : []);
        $v->check();
        return $data;
    }

    private function pick(Validator $v, string $field, string $label, string $dict, ?string $current, string $default): string
    {
        $input = trim((string) $v->raw($field));
        if ($input === '') {
            return $current !== null && $current !== '' ? $current : $default;
        }
        $options = Dictionary::options($dict);
        if ($options && !isset($options[$input]) && $input !== $current) {
            $v->fail($field, '请从列表里选择' . $label);
        }
        return mb_substr($input, 0, 120, 'UTF-8');
    }

    /** @return list<string> */
    private function pickMany(Validator $v, string $field, string $label, string $dict, ?array $current, array $default): array
    {
        $raw = $this->rawArray($field);
        if ($raw === null) {
            return $current ?? array_values(array_filter($default, static function (string $k) use ($dict): bool {
                return Dictionary::has($dict, $k);
            }));
        }
        $options = Dictionary::options($dict);
        $out = [];
        foreach ($raw as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if ($options && !isset($options[$key]) && !in_array($key, (array) $current, true)) {
                $v->fail($field, $label . '不正确');
                continue;
            }
            $out[] = $key;
        }
        return array_values(array_unique($out));
    }

    /** @var array<string, mixed> */
    private $lastInput = [];

    /** 数组字段：没提交返回 null；提交了（哪怕为空）返回数组。 @return list<mixed>|null */
    private function rawArray(string $field): ?array
    {
        if (!array_key_exists($field, $this->lastInput)) {
            return null;
        }
        $value = $this->lastInput[$field];
        if (is_string($value)) {
            $value = $value === '' ? [] : preg_split('/[,，\s]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        return is_array($value) ? array_values($value) : [];
    }

    /** 新建时可同时填一个联系人。 @return array<string, string>|null */
    private function firstContact(array $input): ?array
    {
        $contact = [];
        foreach (['contact_name' => 'name', 'contact_position' => 'position', 'contact_email' => 'email', 'contact_phone' => 'phone', 'contact_whatsapp' => 'whatsapp'] as $from => $to) {
            $value = $input[$from] ?? '';
            $contact[$to] = is_scalar($value) ? trim((string) $value) : '';
        }
        if (implode('', $contact) === '') {
            return null;
        }
        return ContactService::check($contact, 'contact_');
    }

    /** @return array{0: int, 1: list<int>} */
    private function ownerInput(array $input): array
    {
        $me = (int) $this->operator['id'];
        if (!$this->can('customer.assign')) {
            return [$me, []];
        }
        $primary = (int) ($input['owner_user_id'] ?? 0) ?: $me;
        $assistants = $input['assistant_ids'] ?? [];
        $assistants = is_array($assistants) ? $assistants : [];
        $assistants = array_values(array_diff(array_unique(array_filter(array_map('intval', $assistants))), [$primary]));
        $errors = [];
        if (!UserDirectory::isActive($primary)) {
            $errors['owner_user_id'] = '请选择正常状态的负责人';
        }
        foreach ($assistants as $a) {
            if (!UserDirectory::isActive($a)) {
                $errors['assistant_ids'] = '协助负责人里有不可用的账号';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return [$primary, $assistants];
    }

    /** 同名（完全相同）和客户代码不能重复。 */
    private function assertUnique(array $data, int $ignoreId): void
    {
        $errors = [];
        $stmt = $this->pdo->prepare('SELECT id FROM crm_customers WHERE customer_name = ? AND deleted_at IS NULL AND id <> ? LIMIT 1');
        $stmt->execute([$data['customer_name'], $ignoreId]);
        if ($stmt->fetchColumn()) {
            $errors['customer_name'] = '已经有同名客户了，请先确认是不是同一个客户';
        }
        if ($data['customer_code'] !== '') {
            $stmt = $this->pdo->prepare('SELECT id FROM crm_customers WHERE customer_code = ? AND deleted_at IS NULL AND id <> ? LIMIT 1');
            $stmt->execute([$data['customer_code'], $ignoreId]);
            if ($stmt->fetchColumn()) {
                $errors['customer_code'] = '客户代码已被使用';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    /** 找到一个能看的客户（本人范围内，或公海且有权看公海）。 @return array<string, mixed> */
    private function visibleRow(int $id, bool $includeDeleted): array
    {
        $stmt = $this->pdo->prepare('SELECT c.* FROM crm_customers c WHERE c.id = ?' . ($includeDeleted ? '' : ' AND c.deleted_at IS NULL'));
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('客户不存在或无权查看');
        }
        if (!empty($row['deleted_at']) && !$this->canSeeDeleted()) {
            throw new NotFoundException('客户不存在或无权查看');
        }
        if ($this->inScope($id) || ($this->isPublic($row) && empty($row['deleted_at']) && $this->canSeePublic())) {
            return $row;
        }
        throw new NotFoundException('客户不存在或无权查看');
    }

    private function inScope(int $id): bool
    {
        $params = [];
        $sql = CustomerScope::sql($this->operator, $params);
        array_unshift($params, $id);
        $stmt = $this->pdo->prepare('SELECT 1 FROM crm_customers c WHERE c.id = ? AND ' . $sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    private function isPublic(array $row): bool
    {
        if (!empty($row['owner_user_id'])) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM crm_customer_owners WHERE customer_id = ? LIMIT 1');
        $stmt->execute([(int) $row['id']]);
        return !$stmt->fetchColumn();
    }

    /** @return list<int> */
    private function ownerIdsBefore(array $row): array
    {
        $ids = [(int) ($row['owner_user_id'] ?? 0)];
        $stmt = $this->pdo->prepare('SELECT user_id FROM crm_customer_owners WHERE customer_id = ?');
        $stmt->execute([(int) $row['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $ids[] = (int) $u;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array{user_id: int, name: string, is_primary: bool, role_type: string}>>
     */
    private function ownersFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT o.customer_id, o.user_id, o.role_type, o.is_primary, COALESCE(NULLIF(u.real_name, \'\'), u.username) AS name, u.status
            FROM crm_customer_owners o LEFT JOIN crm_users u ON u.id = o.user_id WHERE o.customer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY o.is_primary DESC, o.id');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['customer_id']][] = [
                'user_id' => (int) $r['user_id'],
                'name' => (string) ($r['name'] ?? ('#' . $r['user_id'])) . ((string) ($r['status'] ?? 'active') !== 'active' ? '（已停用）' : ''),
                'is_primary' => (int) $r['is_primary'] === 1 || $r['role_type'] === 'primary',
                'role_type' => (string) $r['role_type'],
            ];
        }
        return $out;
    }

    private function primaryOwnerName(array $owners, int $ownerUserId): string
    {
        foreach ($owners as $o) {
            if ($o['is_primary']) {
                return $o['name'];
            }
        }
        return $ownerUserId > 0 ? $this->userName($ownerUserId) : '';
    }

    public function userName(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(NULLIF(real_name, \'\'), username) FROM crm_users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: ('#' . $userId));
    }

    private function departmentName(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT d.name FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id WHERE u.id = ?');
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @param list<int> $assistantIds */
    private function saveOwners(int $customerId, int $primaryId, array $assistantIds): void
    {
        $this->pdo->prepare('DELETE FROM crm_customer_owners WHERE customer_id = ?')->execute([$customerId]);
        $insert = $this->pdo->prepare('INSERT INTO crm_customer_owners (customer_id, user_id, role_type, is_primary, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$customerId, $primaryId, 'primary', 1, (int) $this->operator['id'], Db::now()]);
        foreach ($assistantIds as $a) {
            if ($a !== $primaryId) {
                $insert->execute([$customerId, $a, 'secondary', 0, (int) $this->operator['id'], Db::now()]);
            }
        }
    }

    private function setProtection(int $customerId, string $status, string $reason, bool $claimed = false): void
    {
        $stmt = $this->pdo->prepare('SELECT customer_id FROM crm_customer_protection WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $now = Db::now();
        $me = (int) $this->operator['id'];
        $publicAt = $status === 'public_pool' ? $now : null;
        if ($stmt->fetchColumn()) {
            $sql = 'UPDATE crm_customer_protection SET protection_status = ?, reason = ?, updated_by = ?, updated_at = ?'
                . ($status === 'public_pool' ? ', public_pool_at = ?' : '')
                . ($claimed ? ', claimed_by = ?, claimed_at = ?' : '') . ' WHERE customer_id = ?';
            $params = [$status, mb_substr($reason, 0, 500, 'UTF-8'), $me, $now];
            if ($status === 'public_pool') {
                $params[] = $now;
            }
            if ($claimed) {
                $params[] = $me;
                $params[] = $now;
            }
            $params[] = $customerId;
            $this->pdo->prepare($sql)->execute($params);
            return;
        }
        $this->pdo->prepare('INSERT INTO crm_customer_protection (customer_id, protection_status, reason, public_pool_at, claimed_by, claimed_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$customerId, $status, mb_substr($reason, 0, 500, 'UTF-8'), $publicAt, $claimed ? $me : null, $claimed ? $now : null, $me, $now]);
    }

    /** 主地址：旧系统显示时优先用 crm_customer_addresses 里的主地址，所以两处一起更新。 */
    private function savePrimaryAddress(int $customerId, array $data): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM crm_customer_addresses WHERE customer_id = ? AND is_primary = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
        $stmt->execute([$customerId]);
        $addressId = (int) $stmt->fetchColumn();
        $now = Db::now();
        $me = (int) $this->operator['id'];
        if ($addressId > 0) {
            $this->pdo->prepare('UPDATE crm_customer_addresses SET country = ?, city = ?, address = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$data['country'], $data['city'], $data['address'], $me, $now, $addressId]);
            return;
        }
        $this->pdo->prepare('INSERT INTO crm_customer_addresses (customer_id, address_type, country, city, address, is_primary, created_by, updated_by, created_at, updated_at) VALUES (?, \'HQ\', ?, ?, ?, 1, ?, ?, ?, ?)')
            ->execute([$customerId, $data['country'], $data['city'], $data['address'], $me, $me, $now, $now]);
    }

    /** @return list<string> */
    private function keys(string $table, string $column, int $customerId): array
    {
        $stmt = $this->pdo->prepare("SELECT {$column} FROM {$table} WHERE customer_id = ? ORDER BY id");
        $stmt->execute([$customerId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * 替换来源 / 推广方式。旧表的字段是固定选项，字典里新加的选项写不进去时跳过，不影响保存。
     *
     * @param list<string> $keys
     */
    private function saveKeys(int $customerId, string $table, string $column, array $keys): void
    {
        $this->pdo->prepare("DELETE FROM {$table} WHERE customer_id = ?")->execute([$customerId]);
        foreach ($keys as $key) {
            $this->pdo->exec('SAVEPOINT centers_key');
            try {
                $this->pdo->prepare("INSERT INTO {$table} (customer_id, {$column}, created_by, created_at) VALUES (?, ?, ?, ?)")->execute([$customerId, $key, (int) $this->operator['id'], Db::now()]);
                $this->pdo->exec('RELEASE SAVEPOINT centers_key');
            } catch (Throwable $e) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT centers_key');
            }
        }
    }

    /** @return list<int> */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            throw new ValidationException(['ids' => '请先选择客户']);
        }
        if (count($ids) > 500) {
            throw new ValidationException(['ids' => '一次最多处理 500 个客户']);
        }
        return $ids;
    }
}
