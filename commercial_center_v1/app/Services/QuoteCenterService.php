<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use PDO;

final class QuoteCenterService
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function overview(array $actor, int $limit = 100): array
    {
        $userId = max(0, (int)($actor['id'] ?? $actor['user_id'] ?? 0));
        $isAdmin = !empty($actor['is_super_admin']);
        $where = ['1=1'];
        $parameters = [];
        if (!$isAdmin && $userId > 0) {
            $where[] = 'q.created_by_legacy_user_id=?';
            $parameters[] = $userId;
        }
        $statement = $this->connection->prepare(
            "SELECT q.id,q.quote_no,q.quote_type,q.currency,q.status,q.total_amount,q.current_version,
                    q.customer_snapshot,q.updated_at,d.source_type,d.contact_name,d.country,d.owner_name,
                    x.sales_channel,x.fulfillment_mode,x.push_status,
                    (SELECT COUNT(*) FROM cc_quote_items i
                     JOIN cc_quote_versions v ON v.id=i.quote_version_id
                     WHERE v.quote_id=q.id AND v.version_no=q.current_version) item_count
             FROM cc_quotes q
             LEFT JOIN cc_quote_details d ON d.quote_id=q.id
             LEFT JOIN cc_quote_channel_context x ON x.quote_id=q.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY q.updated_at DESC,q.id DESC LIMIT " . max(1, min(200, $limit))
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $counts = [
            'total' => 0, 'draft' => 0, 'pending_approval' => 0,
            'sent' => 0, 'pending_customer' => 0, 'converted' => 0,
        ];
        foreach ($rows as &$row) {
            $customer = json_decode((string)($row['customer_snapshot'] ?? '{}'), true);
            $row['customer_name'] = is_array($customer)
                ? (string)($customer['customer_name'] ?? $customer['customer_name_en'] ?? '')
                : '';
            unset($row['customer_snapshot']);
            $row['type_label'] = self::typeLabel((string)$row['quote_type']);
            $row['source_label'] = self::sourceLabel((string)($row['source_type'] ?? ''), (string)($row['sales_channel'] ?? ''));
            $row['edit_url'] = self::editUrl($row);
            $counts['total']++;
            if (isset($counts[(string)$row['status']])) $counts[(string)$row['status']]++;
            if ((string)$row['status'] === 'sent') $counts['pending_customer']++;
        }
        unset($row);
        return ['rows' => $rows, 'counts' => $counts];
    }

    public static function typeLabel(string $type): string
    {
        return [
            'stock_product' => '库存品报价单',
            'standard_product' => '标准品报价单',
            'custom_product' => '定制品报价单',
            'website_order' => '网站回流订单报价',
        ][$type] ?? $type;
    }

    private static function sourceLabel(string $source, string $channel): string
    {
        if ($source === 'website_import' || $source === 'website_order') return '新加坡网站回流';
        if ($channel === 'singapore_web') return '新加坡网站';
        if ($source === 'sales_proxy') return '业务员代客';
        return '广州商务';
    }

    private static function editUrl(array $row): string
    {
        $id = (int)$row['id'];
        return match ((string)$row['quote_type']) {
            'stock_product' => "?page=quote_center&quote_mode=stock&quote_id={$id}",
            'custom_product' => "?page=quote_center&quote_mode=custom&editor=1&quote_id={$id}",
            'website_order' => "?page=quote_center&quote_mode=website&quote_id={$id}",
            default => "?page=quote_center&quote_mode=standard&quote_id={$id}",
        };
    }
}
