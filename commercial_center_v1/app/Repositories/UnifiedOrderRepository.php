<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Repositories;
use PDO;
final class UnifiedOrderRepository
{
    public function list(int $limit = 50): array
    {
        $statement=db()->prepare('SELECT id,order_no,order_source,sales_channel,external_order_no,customer_name,total_amount,currency,payment_status,stock_status,packaging_status,shipment_status,expected_ship_at,internal_status FROM cc_orders ORDER BY id DESC LIMIT '.max(1,min(100,$limit)));
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function counts(): array
    {
        $row=db()->query("SELECT COUNT(*) total,SUM(order_source='singapore_web') singapore,SUM(internal_status='pending_review') pending_review,SUM(internal_status='sync_failed') sync_failed FROM cc_orders")->fetch(PDO::FETCH_ASSOC);
        return array_map('intval',$row?:['total'=>0,'singapore'=>0,'pending_review'=>0,'sync_failed'=>0]);
    }
}
