<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class WebsiteQuoteRepository
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function snapshotBySource(string $channel, string $orderNo, string $idempotencyKey): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cc_website_order_snapshots
             WHERE channel=? AND (external_order_no=? OR idempotency_key=?) LIMIT 1'
        );
        $statement->execute([$channel, $orderNo, $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function saveSnapshot(array $snapshot): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_website_order_snapshots
             (channel,external_order_no,idempotency_key,source_type,payload_json,payload_hash,customer_snapshot,
              contact_snapshot,items_snapshot,shipping_snapshot,attachment_snapshot,customer_note,placed_at,quote_id,
              import_status,is_test,imported_by_legacy_user_id,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'imported\',?,?,NOW(),NOW())'
        );
        $statement->execute([
            $snapshot['channel'], $snapshot['external_order_no'], $snapshot['idempotency_key'],
            $snapshot['source_type'], $snapshot['payload_json'], $snapshot['payload_hash'],
            $snapshot['customer_snapshot'], $snapshot['contact_snapshot'], $snapshot['items_snapshot'],
            $snapshot['shipping_snapshot'], $snapshot['attachment_snapshot'], $snapshot['customer_note'],
            $snapshot['placed_at'], $snapshot['quote_id'], $snapshot['is_test'],
            $snapshot['imported_by_legacy_user_id'] ?: null,
        ]);
        return (int)$this->connection->lastInsertId();
    }

    public function requestUnlock(
        int $quoteId,
        ?int $itemId,
        string $field,
        string $reason,
        mixed $before,
        mixed $after,
        array $actor
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_unlock_requests
             (quote_id,quote_item_id,field_code,reason,before_json,requested_after_json,approval_status,
              requested_by_legacy_user_id,requested_by_name,requested_at)
             VALUES (?,?,?,?,?,?,\'pending\',?,?,NOW())'
        );
        $statement->execute([
            $quoteId, $itemId, $field, $reason, $this->json($before), $this->json($after),
            $this->actorId($actor), $this->actorName($actor),
        ]);
        return (int)$this->connection->lastInsertId();
    }

    public function reviewUnlock(int $requestId, bool $approved, string $note, array $actor): void
    {
        $statement = $this->connection->prepare(
            'UPDATE cc_quote_unlock_requests SET approval_status=?,reviewed_by_legacy_user_id=?,
             reviewed_by_name=?,review_note=?,reviewed_at=NOW() WHERE id=? AND approval_status=\'pending\''
        );
        $statement->execute([
            $approved ? 'approved' : 'rejected', $this->actorId($actor), $this->actorName($actor),
            $note ?: null, $requestId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('解锁申请不存在或已处理。');
        }
    }

    public function consumeUnlock(int $quoteId, ?int $itemId, string $field): bool
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM cc_quote_unlock_requests
             WHERE quote_id=? AND (quote_item_id=? OR (quote_item_id IS NULL AND ? IS NULL))
               AND field_code=? AND approval_status=\'approved\' AND used_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$quoteId, $itemId, $itemId, $field]);
        $id = (int)$statement->fetchColumn();
        if ($id <= 0) {
            return false;
        }
        $this->connection->prepare('UPDATE cc_quote_unlock_requests SET used_at=NOW() WHERE id=?')->execute([$id]);
        return true;
    }

    public function unlockRequests(int $quoteId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cc_quote_unlock_requests WHERE quote_id=? ORDER BY id DESC'
        );
        $statement->execute([$quoteId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function actorId(array $actor): ?int
    {
        $id = (int)($actor['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function actorName(array $actor): ?string
    {
        $name = trim((string)($actor['display_name'] ?? $actor['username'] ?? ''));
        return $name === '' ? null : $name;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            ?: 'null';
    }
}
