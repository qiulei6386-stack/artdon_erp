<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

/**
 * 只读客户中心。页面和服务不直接写这些表。
 * 可见范围是 get_user_scope('customer') 与客户中心规则的交集，不会把范围放大。
 */
class LegacyCustomerAdapter
{
    /** @var \PDO */
    private $pdo;

    /** @var PmAccess */
    private $access;

    /** @var array<string,bool> */
    private $tables = [];

    /** @var array<string,array<int,string>>|null */
    private $aliases = null;

    public function __construct(\PDO $pdo, PmAccess $access)
    {
        $this->pdo = $pdo;
        $this->access = $access;
    }

    /** @param array<int,mixed> $params */
    public function scopeSql(array &$params): string
    {
        $customerScope = $this->access->scope('customer');
        $this->access->scope('promotion');
        $type = (string) ($customerScope['scope_type'] ?? 'self');
        if ($this->access->userId() <= 0 || $type === 'none') {
            return '1=0';
        }
        if ($type === 'self' || $type === 'assigned') {
            return $this->responsibleSql($params);
        }
        if ($type === 'all') {
            return $this->allSql($params);
        }
        if ($type === 'department' || $type === 'team') {
            return '(' . $this->centerSql($params) . ') AND (' . $this->departmentSql($customerScope, $params) . ')';
        }
        if ($type === 'country') {
            $countrySql = $this->scopeCountrySql($customerScope, $params);
            if ($countrySql === '1=0') {
                return $this->responsibleSql($params);
            }
            return '(' . $this->centerSql($params) . ') AND (' . $countrySql . ')';
        }
        if ($type === 'customer_group') {
            return $this->responsibleSql($params);
        }
        return '1=0';
    }

    /**
     * @param array<string,mixed> $input
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,page_size:int}
     */
    public function list(array $input): array
    {
        $params = [];
        $where = ['c.deleted_at IS NULL', $this->scopeSql($params)];
        $q = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($q) > 80) {
            throw new PmHttpException('搜索词请不要超过 80 个字', 400, 'VALIDATION');
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.customer_name_en LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $country = trim((string) ($input['country'] ?? ''));
        if ($country !== '') {
            $countrySql = $this->countryFilterSql($country, $params);
            if ($countrySql !== '') {
                $where[] = $countrySql;
            }
        }
        $ownerId = (int) ($input['owner_user_id'] ?? 0);
        if ($ownerId > 0) {
            $where[] = $this->ownerMatchSql($ownerId, $params);
        }
        $activity = trim((string) ($input['activity'] ?? ''));
        $activitySql = $this->activitySql($activity);
        if ($activitySql !== '') {
            $where[] = $activitySql;
        }
        $sortMap = [
            'updated_at' => 'c.updated_at',
            'customer_name' => 'c.customer_name',
            'customer_code' => 'c.customer_code',
            'country' => 'c.country',
            'created_at' => 'c.created_at',
            'last_followup' => $this->tableExists('crm_customer_followups')
                ? '(SELECT MAX(f.followup_time) FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL)'
                : 'c.updated_at',
        ];
        $sortKey = (string) ($input['sort'] ?? 'updated_at');
        $sort = $sortMap[$sortKey] ?? 'c.updated_at';
        $dir = strtoupper((string) ($input['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $pageSize = max(1, min(100, (int) ($input['page_size'] ?? 20)));
        $page = max(1, (int) ($input['page'] ?? 1));
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM crm_customers c WHERE {$sqlWhere}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $pageSize;
        $sql = "SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.country, c.status,
                c.owner_user_id, c.do_not_contact, c.no_promotion_reason, c.updated_at, c.created_at,
                u.real_name AS owner_real_name, u.username AS owner_username
            FROM crm_customers c
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE {$sqlWhere}
            ORDER BY {$sort} {$dir}, c.id DESC
            LIMIT {$pageSize} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        $extras = $this->listExtras($ids);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $owners = $extras['owners'][$id] ?? [];
            $primary = trim((string) ($row['owner_real_name'] ?: $row['owner_username'] ?: ''));
            if ($primary !== '' && !in_array($primary, $owners, true)) {
                array_unshift($owners, $primary);
            }
            $channels = $extras['channels'][$id] ?? [];
            $status = $extras['status'][$id] ?? 'not_promoted';
            $out[] = [
                'id' => $id,
                'customer_code' => (string) ($row['customer_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_name_en' => (string) ($row['customer_name_en'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'country_label' => $this->countryLabel((string) ($row['country'] ?? '')),
                'status' => (string) ($row['status'] ?? ''),
                'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
                'owner_label' => $owners ? implode('、', $owners) : '无负责人',
                'has_owner' => $owners !== [],
                'do_not_contact' => (int) ($row['do_not_contact'] ?? 0) === 1,
                'no_promotion_reason' => (string) ($row['no_promotion_reason'] ?? ''),
                'promotion_status' => $status,
                'promotion_status_label' => self::statusLabel($status, (int) ($row['do_not_contact'] ?? 0) === 1),
                'blocked' => (int) ($row['do_not_contact'] ?? 0) === 1 || $status === 'no_promotion',
                'channels' => $channels,
                'channel_labels' => array_map([self::class, 'channelLabel'], $channels),
                'contact_count' => $extras['contacts'][$id] ?? 0,
                'last_followup_at' => $extras['followups'][$id] ?? null,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /** @return array<string,mixed> */
    public function detail(int $id): array
    {
        if ($id <= 0) {
            throw new PmHttpException('客户不存在或不可见', 404, 'NOT_FOUND');
        }
        $params = [];
        $scope = $this->scopeSql($params);
        $stmt = $this->pdo->prepare("SELECT c.id, c.customer_code, c.customer_name, c.customer_name_en, c.country, c.status,
                c.email, c.backup_email, c.owner_user_id, c.do_not_contact, c.no_promotion_reason, c.updated_at,
                u.real_name AS owner_real_name, u.username AS owner_username
            FROM crm_customers c
            LEFT JOIN crm_users u ON u.id = c.owner_user_id
            WHERE c.id = ? AND c.deleted_at IS NULL AND ({$scope})
            LIMIT 1");
        $stmt->execute(array_merge([$id], $params));
        $row = $stmt->fetch();
        if (!$row) {
            throw new PmHttpException('客户不存在或不可见', 404, 'NOT_FOUND');
        }
        $owners = $this->ownerRows($id);
        $primary = trim((string) ($row['owner_real_name'] ?: $row['owner_username'] ?: ''));
        $ownerNames = array_map(static function ($owner) {
            return (string) $owner['name'];
        }, $owners);
        if ($primary !== '' && !in_array($primary, $ownerNames, true)) {
            array_unshift($owners, ['user_id' => (int) ($row['owner_user_id'] ?? 0), 'name' => $primary, 'role_type' => 'primary', 'is_primary' => 1]);
        }
        $contacts = $this->contacts($id);
        $channels = $this->customerChannels($id);
        $status = $this->promotionStatus($id);
        $blocked = (int) ($row['do_not_contact'] ?? 0) === 1 || $status === 'no_promotion';
        return [
            'id' => $id,
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'customer_name_en' => (string) ($row['customer_name_en'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'country_label' => $this->countryLabel((string) ($row['country'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'company_emails' => array_values(array_unique(array_merge(
                $this->splitEmails((string) ($row['email'] ?? '')),
                $this->splitEmails((string) ($row['backup_email'] ?? ''))
            ))),
            'owner_label' => $owners ? implode('、', array_map(static function ($owner) {
                return (string) $owner['name'];
            }, $owners)) : '无负责人',
            'has_owner' => $owners !== [],
            'owners' => $owners,
            'do_not_contact' => (int) ($row['do_not_contact'] ?? 0) === 1,
            'no_promotion_reason' => (string) ($row['no_promotion_reason'] ?? ''),
            'promotion_status' => $status,
            'promotion_status_label' => self::statusLabel($status, (int) ($row['do_not_contact'] ?? 0) === 1),
            'blocked' => $blocked,
            'block_label' => $blocked ? '禁止联系' : '',
            'channels' => $channels,
            'channel_labels' => array_map([self::class, 'channelLabel'], $channels),
            'contacts' => $contacts,
            'chat_groups' => $this->promotableChatGroups($id),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<int,int> $ids @return array<int,int> */
    public function filterVisibleIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        if (!$ids) {
            return [];
        }
        $params = [];
        $scope = $this->scopeSql($params);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND c.id IN ({$placeholders}) AND ({$scope})");
        $stmt->execute(array_merge($ids, $params));
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{countries:array<int,array<string,string>>,owners:array<int,array<string,mixed>>} */
    public function filters(): array
    {
        $params = [];
        $scope = $this->scopeSql($params);
        $stmt = $this->pdo->prepare("SELECT DISTINCT c.country FROM crm_customers c WHERE c.deleted_at IS NULL AND ({$scope}) AND c.country IS NOT NULL AND c.country <> '' ORDER BY c.country LIMIT 200");
        $stmt->execute($params);
        $countries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $country) {
            $country = (string) $country;
            $countries[] = ['value' => $country, 'label' => $this->countryLabel($country)];
        }
        $ownerParams = [];
        $ownerScope = $this->scopeSql($ownerParams);
        $ownerSql = "SELECT DISTINCT u.id, COALESCE(NULLIF(u.real_name, ''), u.username) AS name
            FROM crm_customers c
            JOIN crm_users u ON u.id = c.owner_user_id
            WHERE c.deleted_at IS NULL AND ({$ownerScope})
            ORDER BY name LIMIT 200";
        $ownerStmt = $this->pdo->prepare($ownerSql);
        $ownerStmt->execute($ownerParams);
        $owners = [];
        foreach ($ownerStmt->fetchAll() as $owner) {
            $owners[] = ['id' => (int) $owner['id'], 'name' => (string) $owner['name']];
        }
        return ['countries' => $countries, 'owners' => $owners];
    }

    /** @param array<int,mixed> $params */
    private function responsibleSql(array &$params): string
    {
        $userId = $this->access->userId();
        $params[] = $userId;
        if ($this->tableExists('crm_customer_owners')) {
            $params[] = $userId;
            return '(c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
        }
        return 'c.owner_user_id = ?';
    }

    /** @param array<int,mixed> $params */
    private function allSql(array &$params): string
    {
        if ($this->access->isSuperAdmin() || $this->access->hasPermission('customer.view_all')) {
            return '1=1';
        }
        return $this->centerSql($params);
    }

    /**
     * 与 crm_customer_scope_sql 同一口径，供“全部/部门”再取交集。
     * @param array<int,mixed> $params
     */
    private function centerSql(array &$params): string
    {
        if ($this->access->isSuperAdmin() || $this->access->hasPermission('customer.view_all')) {
            return '1=1';
        }
        $userId = $this->access->userId();
        $department = $this->access->departmentName();
        if ($this->access->hasPermission('customer.view_department') && $department !== '' && $this->tableExists('crm_customer_owners')) {
            $params[] = $department;
            $params[] = $department;
            return '(c.owner_user_id IS NULL OR c.owner_department = ? OR c.owner_user_id = ' . $userId . ' OR c.created_by = ' . $userId . ' OR EXISTS (SELECT 1 FROM crm_customer_owners co JOIN crm_users cu ON cu.id = co.user_id LEFT JOIN crm_departments cd ON cd.id = cu.department_id WHERE co.customer_id = c.id AND cd.name = ?))';
        }
        $params[] = $userId;
        $params[] = $userId;
        if ($this->tableExists('crm_customer_owners')) {
            $params[] = $userId;
            return '(c.owner_user_id = ? OR c.created_by = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
        }
        return '(c.owner_user_id = ? OR c.created_by = ?)';
    }

    /** @param array{scope_type:string,scope_value:string} $scope @param array<int,mixed> $params */
    private function departmentSql(array $scope, array &$params): string
    {
        $names = $this->scopeValues((string) ($scope['scope_value'] ?? ''));
        if (!$names) {
            $department = $this->access->departmentName();
            if ($department !== '') {
                $names = [$department];
            }
        }
        if (!$names) {
            return '1=0';
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        foreach ($names as $name) {
            $params[] = $name;
        }
        $userId = $this->access->userId();
        if ($this->tableExists('crm_customer_owners') && $this->tableExists('crm_departments')) {
            foreach ($names as $name) {
                $params[] = $name;
            }
            return "(c.owner_department IN ({$placeholders}) OR c.owner_user_id = {$userId} OR EXISTS (SELECT 1 FROM crm_customer_owners co JOIN crm_users cu ON cu.id = co.user_id LEFT JOIN crm_departments cd ON cd.id = cu.department_id WHERE co.customer_id = c.id AND cd.name IN ({$placeholders})))";
        }
        return "c.owner_department IN ({$placeholders})";
    }

    /** @param array{scope_type:string,scope_value:string} $scope @param array<int,mixed> $params */
    private function scopeCountrySql(array $scope, array &$params): string
    {
        $values = $this->scopeValues((string) ($scope['scope_value'] ?? ''));
        if (!$values) {
            return '1=0';
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        foreach ($values as $value) {
            $params[] = $value;
        }
        return "c.country IN ({$placeholders})";
    }

    /** @return array<int,string> */
    private function scopeValues(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<int,mixed> $params */
    private function countryFilterSql(string $country, array &$params): string
    {
        $terms = $this->countryTerms($country);
        if (!$terms) {
            return '';
        }
        $exact = $this->termsAreExact($country, $terms);
        $parts = [];
        $address = $this->tableExists('crm_customer_addresses');
        if ($exact) {
            $placeholders = implode(',', array_fill(0, count($terms), '?'));
            $parts[] = "c.country IN ({$placeholders})";
            foreach ($terms as $term) {
                $params[] = $term;
            }
            if ($address) {
                $parts[] = "EXISTS (SELECT 1 FROM crm_customer_addresses a_search WHERE a_search.customer_id = c.id AND a_search.country IN ({$placeholders}))";
                foreach ($terms as $term) {
                    $params[] = $term;
                }
            }
            return '(' . implode(' OR ', $parts) . ')';
        }
        $like = '%' . $country . '%';
        $parts[] = 'c.country LIKE ?';
        $params[] = $like;
        if ($address) {
            $parts[] = 'EXISTS (SELECT 1 FROM crm_customer_addresses a_search WHERE a_search.customer_id = c.id AND a_search.country LIKE ?)';
            $params[] = $like;
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param array<int,mixed> $params */
    private function ownerMatchSql(int $userId, array &$params): string
    {
        $params[] = $userId;
        if ($this->tableExists('crm_customer_owners')) {
            $params[] = $userId;
            return '(c.owner_user_id = ? OR EXISTS (SELECT 1 FROM crm_customer_owners co WHERE co.customer_id = c.id AND co.user_id = ?))';
        }
        return 'c.owner_user_id = ?';
    }

    private function activitySql(string $activity): string
    {
        $follow = $this->tableExists('crm_customer_followups');
        if ($activity === '7d') {
            return $follow
                ? '(c.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)))'
                : 'c.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        }
        if ($activity === '30d') {
            return $follow
                ? '(c.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) OR EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)))'
                : 'c.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($activity === 'inactive_30d') {
            return $follow
                ? '(c.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOT EXISTS (SELECT 1 FROM crm_customer_followups f WHERE f.customer_id = c.id AND f.deleted_at IS NULL AND f.followup_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)))'
                : 'c.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        return '';
    }

    /** @param array<int,int> $ids @return array{owners:array<int,array<int,string>>,channels:array<int,array<int,string>>,status:array<int,string>,contacts:array<int,int>,followups:array<int,string>} */
    private function listExtras(array $ids): array
    {
        $empty = ['owners' => [], 'channels' => [], 'status' => [], 'contacts' => [], 'followups' => []];
        if (!$ids) {
            return $empty;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($this->tableExists('crm_customer_owners')) {
            $stmt = $this->pdo->prepare("SELECT co.customer_id, COALESCE(NULLIF(u.real_name, ''), u.username, CONCAT('#', co.user_id)) AS name
                FROM crm_customer_owners co
                LEFT JOIN crm_users u ON u.id = co.user_id
                WHERE co.customer_id IN ({$placeholders})
                ORDER BY co.is_primary DESC, co.id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $empty['owners'][(int) $row['customer_id']][] = (string) $row['name'];
            }
        }
        if ($this->tableExists('crm_customer_promotion_channels')) {
            $stmt = $this->pdo->prepare("SELECT customer_id, channel_key FROM crm_customer_promotion_channels WHERE customer_id IN ({$placeholders}) ORDER BY id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $empty['channels'][(int) $row['customer_id']][] = (string) $row['channel_key'];
            }
        }
        if ($this->tableExists('crm_customer_promotion_status')) {
            $stmt = $this->pdo->prepare("SELECT customer_id, status FROM crm_customer_promotion_status WHERE customer_id IN ({$placeholders})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $empty['status'][(int) $row['customer_id']] = (string) $row['status'];
            }
        }
        if ($this->tableExists('crm_contacts')) {
            $stmt = $this->pdo->prepare("SELECT customer_id, COUNT(*) AS n FROM crm_contacts WHERE customer_id IN ({$placeholders}) AND deleted_at IS NULL GROUP BY customer_id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $empty['contacts'][(int) $row['customer_id']] = (int) $row['n'];
            }
        }
        if ($this->tableExists('crm_customer_followups')) {
            $stmt = $this->pdo->prepare("SELECT customer_id, MAX(followup_time) AS last_followup_at FROM crm_customer_followups WHERE customer_id IN ({$placeholders}) AND deleted_at IS NULL GROUP BY customer_id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $empty['followups'][(int) $row['customer_id']] = (string) $row['last_followup_at'];
            }
        }
        return $empty;
    }

    /** @return array<int,string> */
    private function customerChannels(int $id): array
    {
        if (!$this->tableExists('crm_customer_promotion_channels')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT channel_key FROM crm_customer_promotion_channels WHERE customer_id = ? ORDER BY id');
        $stmt->execute([$id]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function promotionStatus(int $id): string
    {
        if (!$this->tableExists('crm_customer_promotion_status')) {
            return 'not_promoted';
        }
        $stmt = $this->pdo->prepare('SELECT status FROM crm_customer_promotion_status WHERE customer_id = ? LIMIT 1');
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        return $status ? (string) $status : 'not_promoted';
    }

    /** @return array<int,array<string,mixed>> */
    private function contacts(int $id): array
    {
        if (!$this->tableExists('crm_contacts')) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT id, name, email, is_primary, is_left, do_not_contact, unsubscribe_email, wechat, whatsapp
            FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id');
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        $promotions = $this->contactPromotions($ids);
        $out = [];
        foreach ($rows as $row) {
            $contactId = (int) $row['id'];
            $emails = $this->splitEmails((string) ($row['email'] ?? ''));
            $out[] = [
                'id' => $contactId,
                'name' => (string) ($row['name'] ?? ''),
                'emails' => $emails,
                'is_primary' => (int) ($row['is_primary'] ?? 0) === 1,
                'is_left' => (int) ($row['is_left'] ?? 0) === 1,
                'do_not_contact' => (int) ($row['do_not_contact'] ?? 0) === 1,
                'unsubscribe_email' => (int) ($row['unsubscribe_email'] ?? 0) === 1,
                'wechat' => (string) ($row['wechat'] ?? ''),
                'whatsapp' => (string) ($row['whatsapp'] ?? ''),
                'channels' => $promotions[$contactId] ?? [],
            ];
        }
        return $out;
    }

    /** @param array<int,int> $ids @return array<int,array<int,array<string,string>>> */
    private function contactPromotions(array $ids): array
    {
        if (!$ids || !$this->tableExists('crm_contact_promotions')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT contact_id, channel, status FROM crm_contact_promotions WHERE contact_id IN ({$placeholders}) ORDER BY id");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['contact_id']][] = [
                'channel' => (string) $row['channel'],
                'label' => self::channelLabel((string) $row['channel']),
                'status' => (string) $row['status'],
                'status_label' => self::contactChannelStatusLabel((string) $row['status']),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function promotableChatGroups(int $id): array
    {
        if (!$this->tableExists('crm_customer_chat_groups')) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT id, group_name, group_platform, status, use_for_promotion
            FROM crm_customer_chat_groups
            WHERE customer_id = ? AND deleted_at IS NULL AND use_for_promotion = 1 AND status = 'active'
            ORDER BY id");
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $platform = (string) ($row['group_platform'] ?? '');
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['group_name'] ?? ''),
                'platform' => $platform,
                'platform_label' => $platform === 'whatsapp_group' ? 'WhatsApp群' : '微信群',
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function ownerRows(int $id): array
    {
        if (!$this->tableExists('crm_customer_owners')) {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT co.user_id, co.role_type, co.is_primary, COALESCE(NULLIF(u.real_name, ''), u.username, CONCAT('#', co.user_id)) AS name
            FROM crm_customer_owners co
            LEFT JOIN crm_users u ON u.id = co.user_id
            WHERE co.customer_id = ?
            ORDER BY co.is_primary DESC, co.id");
        $stmt->execute([$id]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'user_id' => (int) $row['user_id'],
                'name' => (string) $row['name'],
                'role_type' => (string) ($row['role_type'] ?? ''),
                'is_primary' => (int) ($row['is_primary'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    /** @return array<int,string> */
    private function splitEmails(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[;,，；\s]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private function countryTerms(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $needle = mb_strtolower($term);
        $terms = [$term];
        foreach ($this->aliases() as $code => $items) {
            $fields = array_merge([(string) $code], $items);
            $hit = false;
            foreach ($fields as $alias) {
                if ($needle === mb_strtolower(trim((string) $alias))) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $terms = array_merge($terms, $fields);
            }
        }
        $terms = array_values(array_unique(array_filter(array_map(static function ($item) {
            return trim((string) $item);
        }, $terms))));
        return array_slice($terms, 0, 40);
    }

    /** @param array<int,string> $terms */
    private function termsAreExact(string $original, array $terms): bool
    {
        return count($terms) > 1 || preg_match('/^[A-Za-z]{2,3}$/', trim($original)) === 1;
    }

    private function countryLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '未填';
        }
        $needle = mb_strtolower($raw);
        foreach ($this->aliases() as $code => $items) {
            $fields = array_merge([(string) $code], $items);
            $matched = false;
            foreach ($fields as $field) {
                if ($needle === mb_strtolower(trim((string) $field))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            foreach ($fields as $field) {
                $field = trim((string) $field);
                if ($field !== '' && preg_match('/\p{Han}/u', $field)) {
                    return $field;
                }
            }
        }
        return $raw;
    }

    /** @return array<string,array<int,string>> */
    private function aliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }
        if (!function_exists('crm_customer_country_aliases')) {
            $file = dirname(__DIR__, 2) . '/crm_customer.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        $aliases = function_exists('crm_customer_country_aliases') ? crm_customer_country_aliases() : [];
        $this->aliases = is_array($aliases) ? $aliases : [];
        return $this->aliases;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tables)) {
            return $this->tables[$table];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $this->tables[$table] = (int) $stmt->fetchColumn() > 0;
        return $this->tables[$table];
    }

    public static function statusLabel(string $status, bool $doNotContact): string
    {
        if ($doNotContact && $status !== 'no_promotion') {
            return '禁止联系';
        }
        $map = [
            'not_promoted' => '未推广',
            'promoting' => '推广中',
            'no_promotion' => '不推广',
            'paused' => '已暂停',
            'promoted' => '已推广',
        ];
        return $map[$status] ?? ($status !== '' ? $status : '未推广');
    }

    public static function channelLabel(string $key): string
    {
        $map = [
            'email' => '邮件',
            'whatsapp' => 'WhatsApp',
            'wechat' => '微信',
            'wechat_group' => '微信群',
            'whatsapp_group' => 'WhatsApp群',
            'linkedin' => 'LinkedIn',
            'phone' => '电话',
            'edm' => 'EDM',
            'visit' => '拜访',
            'offline' => '线下',
            'no_promotion' => '不推广',
        ];
        return $map[$key] ?? $key;
    }

    public static function contactChannelStatusLabel(string $status): string
    {
        $map = [
            'active' => '启用',
            'stopped' => '停止',
            'no_contact' => '不联系',
        ];
        return $map[$status] ?? $status;
    }
}
