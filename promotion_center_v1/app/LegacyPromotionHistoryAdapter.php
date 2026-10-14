<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

/**
 * 只读 CRM 推广任务和旧独立中心活动。本模块不更新这些表。
 */
class LegacyPromotionHistoryAdapter
{
    /** @var \PDO */
    private $pdo;

    /** @var PmAccess */
    private $access;

    public function __construct(\PDO $pdo, PmAccess $access)
    {
        $this->pdo = $pdo;
        $this->access = $access;
    }

    /** @return array{rows:array<int,array<string,mixed>>,readonly:bool,note:string} */
    public function list(): array
    {
        $scope = $this->access->scope('promotion');
        $this->access->scope('customer');
        if (($scope['scope_type'] ?? '') === 'none' || $this->access->userId() <= 0) {
            return ['rows' => [], 'readonly' => true, 'note' => 'CRM/旧中心历史只读，不能在本模块编辑。'];
        }
        $rows = [];
        if ($this->tableExists('crm_marketing_tasks')) {
            foreach ($this->crmTasks($scope) as $row) {
                $rows[] = $row;
            }
        }
        if ($this->tableExists('promo_campaigns')) {
            foreach ($this->legacyCampaigns($scope) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, static function ($a, $b) {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        return [
            'rows' => array_slice($rows, 0, 100),
            'readonly' => true,
            'note' => '这些是 CRM/旧中心历史，只读展示，不能在本模块编辑或发送。',
        ];
    }

    /** @param array{scope_type:string,scope_value:string} $scope @return array<int,array<string,mixed>> */
    private function crmTasks(array $scope): array
    {
        $columns = $this->columns('crm_marketing_tasks');
        if (!isset($columns['id'], $columns['task_name'])) {
            return [];
        }
        [$where, $params] = $this->creatorWhere($scope, 't', $columns);
        $status = isset($columns['task_status']) ? 't.task_status' : "''";
        $created = isset($columns['created_at']) ? 't.created_at' : 'NULL';
        $count = isset($columns['customer_count']) ? 't.customer_count' : 'NULL';
        $sql = "SELECT t.id, t.task_name AS name, {$status} AS status_code, {$created} AS created_at, {$count} AS customer_count,
                COALESCE(NULLIF(u.real_name, ''), u.username) AS creator_name
            FROM crm_marketing_tasks t
            LEFT JOIN crm_users u ON u.id = t.created_by
            WHERE {$where}
            ORDER BY t.id DESC
            LIMIT 80";
        if (!isset($columns['created_by'])) {
            $sql = "SELECT t.id, t.task_name AS name, {$status} AS status_code, {$created} AS created_at, {$count} AS customer_count, '' AS creator_name
                FROM crm_marketing_tasks t WHERE {$where} ORDER BY t.id DESC LIMIT 80";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = (string) ($row['status_code'] ?? '');
            $out[] = [
                'source' => 'crm',
                'source_label' => 'CRM 历史',
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'status_code' => $code,
                'status_label' => self::crmStatusLabel($code),
                'creator_name' => (string) ($row['creator_name'] ?? ''),
                'customer_count' => $row['customer_count'] !== null ? (int) $row['customer_count'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'editable' => false,
            ];
        }
        return $out;
    }

    /** @param array{scope_type:string,scope_value:string} $scope @return array<int,array<string,mixed>> */
    private function legacyCampaigns(array $scope): array
    {
        $columns = $this->columns('promo_campaigns');
        $nameColumn = null;
        foreach (['name', 'title', 'campaign_name', 'subject'] as $candidate) {
            if (isset($columns[$candidate])) {
                $nameColumn = $candidate;
                break;
            }
        }
        if ($nameColumn === null || !isset($columns['id'])) {
            return [];
        }
        [$where, $params] = $this->creatorWhere($scope, 'c', $columns);
        $status = isset($columns['status']) ? 'c.status' : "''";
        $created = isset($columns['created_at']) ? 'c.created_at' : 'NULL';
        $join = isset($columns['created_by']) ? 'LEFT JOIN crm_users u ON u.id = c.created_by' : '';
        $creator = isset($columns['created_by']) ? "COALESCE(NULLIF(u.real_name, ''), u.username)" : "''";
        $sql = "SELECT c.id, c.`{$nameColumn}` AS name, {$status} AS status_code, {$created} AS created_at, {$creator} AS creator_name
            FROM promo_campaigns c {$join}
            WHERE {$where}
            ORDER BY c.id DESC
            LIMIT 80";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = (string) ($row['status_code'] ?? '');
            $out[] = [
                'source' => 'legacy',
                'source_label' => '旧中心历史',
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'status_code' => $code,
                'status_label' => $code !== '' ? $code : '未知',
                'creator_name' => (string) ($row['creator_name'] ?? ''),
                'customer_count' => null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'editable' => false,
            ];
        }
        return $out;
    }

    /**
     * @param array{scope_type:string,scope_value:string} $scope
     * @param array<string,bool> $columns
     * @return array{0:string,1:array<int,mixed>}
     */
    private function creatorWhere(array $scope, string $alias, array $columns): array
    {
        $type = (string) ($scope['scope_type'] ?? 'self');
        if (!isset($columns['created_by'])) {
            return $type === 'all' || $this->access->isSuperAdmin() ? ['1=1', []] : ['1=0', []];
        }
        if ($type === 'all' || $this->access->isSuperAdmin()) {
            return ['1=1', []];
        }
        if ($type === 'department' || $type === 'team') {
            $department = trim((string) ($scope['scope_value'] ?? ''));
            if ($department === '') {
                $department = $this->access->departmentName();
            }
            if ($department === '' || !$this->tableExists('crm_departments')) {
                return ["{$alias}.created_by = ?", [$this->access->userId()]];
            }
            return ["EXISTS (SELECT 1 FROM crm_users cu LEFT JOIN crm_departments cd ON cd.id = cu.department_id WHERE cu.id = {$alias}.created_by AND cd.name = ?)", [$department]];
        }
        return ["{$alias}.created_by = ?", [$this->access->userId()]];
    }

    /** @return array<string,bool> */
    private function columns(string $table): array
    {
        $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $column) {
            $out[(string) $column] = true;
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function crmStatusLabel(string $status): string
    {
        $map = [
            'draft' => '草稿（历史）',
            'pending' => '已排期',
            'scheduled' => '已排期',
            'running' => '发送中',
            'manual_pending' => '发送中（人工待办未完成）',
            'paused' => '已暂停',
            'completed' => '已完成',
            'partial_failed' => '已完成',
            'failed' => '已完成',
            'cancelled' => '已取消',
        ];
        return $map[$status] ?? ($status !== '' ? $status : '未知');
    }
}
