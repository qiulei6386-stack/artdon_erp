<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class LegacyOperationsReadRepository
{
    private PDO $connection;

    public function __construct()
    {
        if (!function_exists('db')) {
            throw new \RuntimeException('Legacy database connection is unavailable.');
        }
        $this->connection = db();
    }

    public function dispatchQueue(array $user, int $limit = 12): array
    {
        $scope = $this->userScope($user, 't.assigned_to', 't.created_by');
        return $this->selectAll(
            "SELECT t.id,t.task_no,t.title,t.project,t.priority,t.status,t.assigned_to,t.task_date,t.due_at,t.progress,t.linked_system,t.linked_title,
                    creator.real_name AS creator_name,assignee.real_name AS assignee_name
             FROM dispatch_next_tasks t
             LEFT JOIN crm_users creator ON creator.id=t.created_by
             LEFT JOIN crm_users assignee ON assignee.id=t.assigned_to
             WHERE t.is_deleted=0 AND t.status NOT IN ('done','cancelled') {$scope['sql']}
             ORDER BY (t.due_at IS NULL),t.due_at ASC,t.id DESC LIMIT " . $this->limit($limit),
            $scope['params']
        );
    }

    public function quoteDeliveryQueue(array $user, int $limit = 10): array
    {
        $scope = $this->nameScope($user, 'q.user_name');
        return $this->selectAll(
            "SELECT q.id,q.quote_no,q.customer_name,q.amount,q.currency,q.approval_status,q.quote_status,q.submitted_at,q.updated_at,
                    CASE
                      WHEN q.approval_status='pending' THEN '待审批'
                      WHEN q.approval_status='approved' AND COALESCE(q.converted_order_id,0)=0 THEN '待客户确认/转订单'
                      WHEN q.approval_status='rejected' THEN '需修改'
                      ELSE COALESCE(NULLIF(q.quote_status,''),'报价处理中')
                    END AS next_action
             FROM quote_orders q
             WHERE (COALESCE(q.approval_status,'pending')<>'approved'
                OR COALESCE(q.converted_order_id,0)=0)
                {$scope['sql']}
             ORDER BY COALESCE(q.updated_at,q.created_at) DESC,q.id DESC LIMIT " . $this->limit($limit),
            $scope['params']
        );
    }

    public function orders(array $user, int $limit = 10): array
    {
        $scope = $this->nameScope($user, 'o.user_name');
        return $this->selectAll(
            "SELECT o.id,o.order_no,o.quote_no,o.customer_name,o.qty,o.amount,o.currency,o.status,o.shipment_status,o.payment_status,
                    o.paid_amount,o.balance_amount,o.order_date,o.created_at,
                    COALESCE(SUM(i.shipped_qty),0) AS shipped_qty
             FROM quote_sales_orders o
             LEFT JOIN quote_sales_order_items i ON i.order_id=o.id
             WHERE 1=1 {$scope['sql']}
             GROUP BY o.id
             ORDER BY COALESCE(o.order_date,o.created_at) DESC,o.id DESC LIMIT " . $this->limit($limit),
            $scope['params']
        );
    }

    public function exceptions(array $user): array
    {
        $dispatchScope = $this->userScope($user, 't.assigned_to', 't.created_by');
        $quoteScope = $this->nameScope($user, 'q.user_name');
        $orderScope = $this->nameScope($user, 'o.user_name');
        $checks = [
            [
                'key' => 'overdue_dispatch',
                'label' => '逾期派工',
                'severity' => 'high',
                'target' => '../dispatch_next.php',
                'sql' => "SELECT COUNT(*) AS total FROM dispatch_next_tasks t WHERE t.is_deleted=0 AND t.status NOT IN ('done','cancelled') AND t.due_at<NOW() {$dispatchScope['sql']}",
                'params' => $dispatchScope['params'],
            ],
            [
                'key' => 'rejected_quotes',
                'label' => '被退回报价',
                'severity' => 'medium',
                'target' => '../quotation.php',
                'sql' => "SELECT COUNT(*) AS total FROM quote_orders q WHERE q.approval_status='rejected' {$quoteScope['sql']}",
                'params' => $quoteScope['params'],
            ],
            [
                'key' => 'order_balance',
                'label' => '存在未结余额订单',
                'severity' => 'medium',
                'target' => '../quotation.php',
                'sql' => "SELECT COUNT(*) AS total FROM quote_sales_orders o WHERE COALESCE(o.balance_amount,0)>0 {$orderScope['sql']}",
                'params' => $orderScope['params'],
            ],
            [
                'key' => 'missing_shipments',
                'label' => '尚无出货批次订单',
                'severity' => 'low',
                'target' => '../quotation.php',
                'sql' => "SELECT COUNT(*) AS total FROM quote_sales_orders o WHERE NOT EXISTS (SELECT 1 FROM quote_shipments s WHERE s.order_id=o.id) {$orderScope['sql']}",
                'params' => $orderScope['params'],
            ],
        ];
        foreach ($checks as &$check) {
            $row = $this->selectOne($check['sql'], $check['params']);
            $check['count'] = (int)($row['total'] ?? 0);
            unset($check['sql'], $check['params']);
        }
        unset($check);
        return $checks;
    }

    public function recentActivity(array $user, int $limit = 8): array
    {
        $scope = $this->nameScope($user, 'l.user_name');
        return $this->selectAll(
            "SELECT l.id,l.created_at,l.event,l.action,l.quote_no,l.customer_name,l.summary
             FROM quote_logs l
             WHERE 1=1 {$scope['sql']}
             ORDER BY l.created_at DESC,l.id DESC LIMIT " . $this->limit($limit),
            $scope['params']
        );
    }

    private function userScope(array $user, string $primaryField, string $secondaryField): array
    {
        if (!empty($user['is_super_admin'])) {
            return ['sql' => '', 'params' => []];
        }
        return [
            'sql' => " AND ({$primaryField}=? OR {$secondaryField}=?)",
            'params' => [(int)$user['id'], (int)$user['id']],
        ];
    }

    private function nameScope(array $user, string $field): array
    {
        if (!empty($user['is_super_admin'])) {
            return ['sql' => '', 'params' => []];
        }
        $names = array_values(array_unique(array_filter([
            (string)($user['username'] ?? ''),
            (string)($user['display_name'] ?? ''),
        ])));
        if ($names === []) {
            return ['sql' => ' AND 1=0', 'params' => []];
        }
        return [
            'sql' => ' AND ' . $field . ' IN (' . implode(',', array_fill(0, count($names), '?')) . ')',
            'params' => $names,
        ];
    }

    private function selectAll(string $sql, array $parameters = []): array
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new \LogicException('Operations repository only permits SELECT.');
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectOne(string $sql, array $parameters = []): ?array
    {
        $rows = $this->selectAll($sql, $parameters);
        return $rows[0] ?? null;
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
