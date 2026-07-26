<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class StandardQuoteRepository
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function customers(string $search = '', int $limit = 50): array
    {
        $where = ['c.deleted_at IS NULL'];
        $parameters = [];
        if (trim($search) !== '') {
            $where[] = '(c.customer_code LIKE ? OR c.customer_name LIKE ? OR c.customer_name_en LIKE ?)';
            $term = '%' . trim($search) . '%';
            $parameters = [$term, $term, $term];
        }
        $statement = $this->connection->prepare(
            'SELECT c.id,c.customer_code,c.customer_name,c.customer_name_en,c.country,c.level,c.owner_user_id,
                    c.email,c.phone,c.risk_level,c.remark,
                    ct.id AS contact_id,ct.name AS contact_name,ct.email AS contact_email,ct.phone AS contact_phone
             FROM crm_customers c
             LEFT JOIN crm_contacts ct ON ct.customer_id=c.id AND ct.deleted_at IS NULL AND ct.is_primary=1
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.updated_at DESC,c.id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function customer(int $customerId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT c.id,c.customer_code,c.customer_name,c.customer_name_en,c.country,c.level,c.owner_user_id,
                    c.email,c.phone,c.risk_level,c.remark,
                    ct.id AS contact_id,ct.name AS contact_name,ct.email AS contact_email,ct.phone AS contact_phone
             FROM crm_customers c
             LEFT JOIN crm_contacts ct ON ct.customer_id=c.id AND ct.deleted_at IS NULL AND ct.is_primary=1
             WHERE c.id=? AND c.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute([$customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function pricePolicy(string $model): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM quote_price_policies
             WHERE status IN ('active','enabled','启用','可报价') AND product_model=?
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([$model]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function tierPrice(int $policyId, float $quantity): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM quote_price_tiers
             WHERE policy_id=? AND min_qty<=? ORDER BY min_qty DESC,sort_order DESC,id DESC LIMIT 1'
        );
        $statement->execute([$policyId, $quantity]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function customerLevelMultiplier(string $level): ?float
    {
        if (trim($level) === '') {
            return null;
        }
        $statement = $this->connection->prepare(
            'SELECT multiplier FROM quote_price_levels WHERE is_active=1 AND name=? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([trim($level)]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (float)$value;
    }

    public function commissionRules(int $customerId, string $model, string $category): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM quote_commission_rules
             WHERE is_active=1 AND
               (apply_scope IN (\'all\',\'global\',\'\') OR customer_id=? OR product_model=? OR category=?)
             ORDER BY (customer_id=?) DESC,(product_model=?) DESC,(category=?) DESC,id DESC'
        );
        $statement->execute([$customerId, $model, $category, $customerId, $model, $category]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function commissionReminders(int $customerId): array
    {
        $statement = $this->connection->prepare(
            'SELECT scene,trigger_condition,remind_level,action_mode,require_reason,note
             FROM quote_commission_reminder_rules
             WHERE is_active=1 AND (customer_scope IN (\'all\',\'global\',\'\') OR customer_id=?)
             ORDER BY sort_order,id'
        );
        $statement->execute([$customerId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
