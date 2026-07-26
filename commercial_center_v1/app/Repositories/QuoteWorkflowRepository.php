<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class QuoteWorkflowRepository
{
    private PDO $connection;
    private QuoteRepository $quotes;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
        $this->quotes = new QuoteRepository($this->connection);
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function lock(int $quoteId): array
    {
        $statement = $this->connection->prepare('SELECT * FROM cc_quotes WHERE id=? FOR UPDATE');
        $statement->execute([$quoteId]);
        $quote = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($quote)) {
            throw new \RuntimeException('报价不存在。');
        }
        return $quote;
    }

    public function cloneCurrentVersion(int $quoteId, int $actorId): int
    {
        $quote = $this->lock($quoteId);
        $oldVersion = $this->versionRow($quoteId, (int)$quote['current_version']);
        $newVersionNo = (int)$quote['current_version'] + 1;
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_versions
             (quote_id,version_no,customer_snapshot,terms_snapshot,pricing_snapshot,cost_snapshot,exchange_rate,
              template_version,status,created_by_legacy_user_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $quoteId, $newVersionNo, $oldVersion['customer_snapshot'], $oldVersion['terms_snapshot'],
            $oldVersion['pricing_snapshot'], $oldVersion['cost_snapshot'], $oldVersion['exchange_rate'],
            $oldVersion['template_version'], $quote['status'], $actorId ?: null, date('Y-m-d H:i:s'),
        ]);
        $newVersionId = (int)$this->connection->lastInsertId();
        $items = $this->itemsForVersion((int)$oldVersion['id']);
        foreach ($items as $item) {
            $oldItemId = (int)$item['id'];
            $insert = $this->connection->prepare(
                'INSERT INTO cc_quote_items
                 (quote_version_id,item_type,legacy_product_id,inventory_sku_id,description,configuration_snapshot,
                  quantity,unit_price,cost_amount,discount_rate,line_amount,sort_order,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $now = date('Y-m-d H:i:s');
            $insert->execute([
                $newVersionId, $item['item_type'], $item['legacy_product_id'], $item['inventory_sku_id'],
                $item['description'], $item['configuration_snapshot'], $item['quantity'], $item['unit_price'],
                $item['cost_amount'], $item['discount_rate'], $item['line_amount'], $item['sort_order'], $now, $now,
            ]);
            $newItemId = (int)$this->connection->lastInsertId();
            $this->copyItemDetail($oldItemId, $newItemId);
            $this->copyItemSnapshot($oldItemId, $newItemId);
        }
        $update = $this->connection->prepare('UPDATE cc_quotes SET current_version=?,updated_at=NOW() WHERE id=?');
        $update->execute([$newVersionNo, $quoteId]);
        return $newVersionId;
    }

    public function createSnapshot(int $quoteId, string $type, int $actorId): array
    {
        $quote = $this->quotes->find($quoteId);
        if ($quote === null || !is_array($quote['version'] ?? null)) {
            throw new \RuntimeException('报价快照来源不存在。');
        }
        $json = $this->json($quote);
        $hash = hash('sha256', $json);
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_snapshots
             (quote_id,quote_version_id,snapshot_type,snapshot_json,snapshot_hash,created_by_legacy_user_id,created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        $statement->execute([
            $quoteId, (int)$quote['version']['id'], $type, $json, $hash, $actorId ?: null,
        ]);
        return ['hash' => $hash, 'snapshot' => $quote, 'version_id' => (int)$quote['version']['id']];
    }

    public function updateStatus(int $quoteId, int $versionId, string $status): void
    {
        $this->connection->prepare('UPDATE cc_quotes SET status=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, $quoteId]);
        $this->connection->prepare('UPDATE cc_quote_versions SET status=? WHERE id=?')
            ->execute([$status, $versionId]);
    }

    public function updateQuoteStatus(int $quoteId, string $status): void
    {
        $this->connection->prepare('UPDATE cc_quotes SET status=?,updated_at=NOW() WHERE id=?')
            ->execute([$status, $quoteId]);
    }

    public function stateHistory(
        int $quoteId,
        int $versionId,
        string $from,
        string $to,
        string $reason,
        array $actor
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_state_history
             (quote_id,quote_version_id,from_status,to_status,reason,actor_legacy_user_id,actor_name,created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        );
        $statement->execute([
            $quoteId, $versionId, $from, $to, $reason ?: null, $this->actorId($actor), $this->actorName($actor),
        ]);
    }

    public function approval(
        int $quoteId,
        int $versionId,
        string $action,
        string $status,
        string $reason,
        array $actor,
        ?string $beforeHash,
        ?string $afterHash
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_approvals
             (quote_id,quote_version_id,action_code,approval_status,reason,actor_legacy_user_id,actor_name,
              before_snapshot_hash,after_snapshot_hash,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())'
        );
        $statement->execute([
            $quoteId, $versionId, $action, $status, $reason ?: null, $this->actorId($actor),
            $this->actorName($actor), $beforeHash, $afterHash,
        ]);
    }

    public function audit(
        array $quote,
        string $action,
        string $reason,
        array $actor,
        mixed $before,
        mixed $after,
        array $request
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_audit_logs
             (quote_id,quote_no,quote_type,object_type,object_id,action_code,actor_legacy_user_id,actor_name,reason,
              before_json,after_json,ip_hash,user_agent_hash,request_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $statement->execute([
            (int)$quote['id'], (string)$quote['quote_no'], (string)$quote['quote_type'], 'quotation',
            (string)$quote['id'], $action, $this->actorId($actor), $this->actorName($actor), $reason ?: null,
            $this->json($before), $this->json($after),
            $this->hashNullable($request['ip'] ?? null), $this->hashNullable($request['user_agent'] ?? null),
            $request['request_id'] ?? null,
        ]);
    }

    public function history(int $quoteId): array
    {
        $versions = $this->all(
            'SELECT * FROM cc_quote_versions WHERE quote_id=? ORDER BY version_no DESC',
            [$quoteId]
        );
        foreach ($versions as &$version) {
            $version['items'] = $this->itemsForVersion((int)$version['id']);
        }
        unset($version);
        return $versions;
    }

    private function versionRow(int $quoteId, int $versionNo): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cc_quote_versions WHERE quote_id=? AND version_no=?'
        );
        $statement->execute([$quoteId, $versionNo]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('报价版本不存在。');
        }
        return $row;
    }

    private function itemsForVersion(int $versionId): array
    {
        return $this->all(
            'SELECT i.*,d.product_source,d.sku_code,d.model_no,d.product_name,d.image_path,d.unit,d.lead_time,
                    d.customer_note,d.internal_note,d.locked,d.unlock_reason,d.source_line_snapshot,
                    d.custom_fields_json,d.reference_product_id
             FROM cc_quote_items i LEFT JOIN cc_quote_item_details d ON d.quote_item_id=i.id
             WHERE i.quote_version_id=? ORDER BY i.sort_order,i.id',
            [$versionId]
        );
    }

    private function copyItemDetail(int $oldId, int $newId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_item_details
             (quote_item_id,product_source,sku_code,model_no,product_name,image_path,unit,lead_time,customer_note,
              internal_note,locked,unlock_reason,source_line_snapshot,custom_fields_json,reference_product_id,
              created_at,updated_at)
             SELECT ?,product_source,sku_code,model_no,product_name,image_path,unit,lead_time,customer_note,
                    internal_note,locked,unlock_reason,source_line_snapshot,custom_fields_json,reference_product_id,
                    NOW(),NOW()
             FROM cc_quote_item_details WHERE quote_item_id=?'
        );
        $statement->execute([$newId, $oldId]);
    }

    private function copyItemSnapshot(int $oldId, int $newId): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_item_snapshots
             (quote_item_id,product_snapshot,configuration_snapshot,price_snapshot,cost_snapshot,snapshot_hash,created_at)
             SELECT ?,product_snapshot,configuration_snapshot,price_snapshot,cost_snapshot,snapshot_hash,NOW()
             FROM cc_quote_item_snapshots WHERE quote_item_id=?'
        );
        $statement->execute([$newId, $oldId]);
    }

    private function all(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function actorId(array $actor): ?int
    {
        $id = (int)($actor['id'] ?? $actor['user_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function actorName(array $actor): ?string
    {
        $name = trim((string)($actor['display_name'] ?? $actor['username'] ?? ''));
        return $name === '' ? null : $name;
    }

    private function hashNullable(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : hash('sha256', $text);
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('审计数据无法序列化。');
        }
        return $json;
    }
}
