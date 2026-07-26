<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;
use Throwable;

final class QuoteRepository
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function saveDraft(array $quote, array $amounts, int $actorUserId = 0): array
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $now = date('Y-m-d H:i:s');
            $quoteId = (int)($quote['id'] ?? 0);
            if ($quoteId > 0) {
                $locked = $this->one('SELECT * FROM cc_quotes WHERE id=? FOR UPDATE', [$quoteId]);
                if ($locked === null) {
                    throw new \RuntimeException('报价不存在。');
                }
                if ((string)$locked['status'] !== 'draft') {
                    throw new \RuntimeException('当前状态不允许编辑。');
                }
                $quote['quote_no'] = (string)$locked['quote_no'];
                $versionNo = (int)$locked['current_version'] + 1;
                $statement = $this->connection->prepare(
                    'UPDATE cc_quotes SET legacy_customer_id=?,customer_snapshot=?,quote_type=?,currency=?,language=?,
                     current_version=?,total_amount=?,total_cost=?,is_test=?,updated_at=? WHERE id=?'
                );
                $statement->execute([
                    $quote['legacy_customer_id'], $this->json($quote['customer_snapshot']), $quote['quote_type'],
                    $quote['currency'], $quote['language'], $versionNo, $amounts['total_amount'],
                    $amounts['total_cost'], $quote['is_test'], $now, $quoteId,
                ]);
            } else {
                $versionNo = 1;
                $statement = $this->connection->prepare(
                    'INSERT INTO cc_quotes
                     (quote_no,legacy_customer_id,customer_snapshot,quote_type,currency,language,current_version,status,
                      total_amount,total_cost,is_test,created_by_legacy_user_id,created_at,updated_at)
                     VALUES (?,?,?,?,?,?,?,\'draft\',?,?,?,?,?,?)'
                );
                $statement->execute([
                    $quote['quote_no'], $quote['legacy_customer_id'], $this->json($quote['customer_snapshot']),
                    $quote['quote_type'], $quote['currency'], $quote['language'], $versionNo,
                    $amounts['total_amount'], $amounts['total_cost'], $quote['is_test'],
                    $actorUserId ?: null, $now, $now,
                ]);
                $quoteId = (int)$this->connection->lastInsertId();
            }

            $this->saveDetails($quoteId, $quote, $amounts, $now);
            $versionId = $this->saveVersion($quoteId, $versionNo, $quote, $amounts, $actorUserId, $now);
            foreach ($amounts['items'] as $item) {
                $this->saveItem($versionId, $item, $now);
            }

            $snapshot = $this->snapshotPayload($quoteId, $versionId, $versionNo, $quote, $amounts);
            $snapshotJson = $this->json($snapshot);
            $snapshotStatement = $this->connection->prepare(
                'INSERT INTO cc_quote_snapshots
                 (quote_id,quote_version_id,snapshot_type,snapshot_json,snapshot_hash,created_by_legacy_user_id,created_at)
                 VALUES (?,?,\'draft\',?,?,?,?)'
            );
            $snapshotStatement->execute([
                $quoteId, $versionId, $snapshotJson, hash('sha256', $snapshotJson), $actorUserId ?: null, $now,
            ]);

            $log = $this->connection->prepare(
                'INSERT INTO cc_quotation_logs
                 (quote_id,action_code,status,actor_user_id,message,request_id,created_at)
                 VALUES (?,\'save_draft\',\'success\',?,?,?,?)'
            );
            $log->execute([
                $quoteId, $actorUserId ?: null, 'Saved quotation version ' . $versionNo,
                $quote['request_id'] ?: null, $now,
            ]);

            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $this->find($quoteId) ?? throw new \RuntimeException('报价保存后无法重新读取。');
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }

    public function find(int $quoteId): ?array
    {
        $quote = $this->one(
            'SELECT q.*,d.source_type,d.source_order_no,d.source_snapshot,d.edit_mode,d.contact_name,d.contact_phone,
                    d.contact_email,d.country,d.exchange_rate_snapshot,d.quote_date,d.valid_until,
                    d.owner_legacy_user_id,d.owner_name,d.payment_terms,d.trade_terms,d.price_template_id,d.project_ref,
                    d.subtotal_amount,d.discount_amount,d.shipping_amount,d.tax_amount,d.other_amount,d.commission_amount,
                    d.gross_profit,d.gross_margin,d.customer_note,d.internal_note
             FROM cc_quotes q LEFT JOIN cc_quote_details d ON d.quote_id=q.id WHERE q.id=?',
            [$quoteId]
        );
        if ($quote === null) {
            return null;
        }
        $version = $this->one(
            'SELECT * FROM cc_quote_versions WHERE quote_id=? AND version_no=?',
            [$quoteId, $quote['current_version']]
        );
        $items = [];
        if ($version !== null) {
            $statement = $this->connection->prepare(
                'SELECT i.*,d.product_source,d.sku_code,d.model_no,d.product_name,d.image_path,d.unit,d.lead_time,
                        d.customer_note,d.internal_note,d.locked,d.unlock_reason,d.source_line_snapshot,
                        d.custom_fields_json,d.reference_product_id
                 FROM cc_quote_items i
                 LEFT JOIN cc_quote_item_details d ON d.quote_item_id=i.id
                 WHERE i.quote_version_id=? ORDER BY i.sort_order,i.id'
            );
            $statement->execute([(int)$version['id']]);
            $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->decodeQuote($quote, $version, $items);
    }

    public function findLegacy(int $legacyId): ?array
    {
        $row = $this->one('SELECT * FROM quote_orders WHERE id=?', [$legacyId]);
        if ($row === null) {
            return null;
        }
        $items = $this->decode((string)($row['items_json'] ?? '[]'), []);
        if ($items === []) {
            $product = $this->decode((string)($row['product_json'] ?? '{}'), []);
            if ($product !== []) {
                $product['quantity'] = (float)($row['qty'] ?? 0);
                $product['unit_price'] = (float)($row['price'] ?? 0);
                $product['line_amount'] = (float)($row['amount'] ?? 0);
                $items = [$product];
            }
        }
        return [
            'storage' => 'legacy',
            'legacy_id' => (int)$row['id'],
            'quote_no' => (string)$row['quote_no'],
            'quote_type' => 'legacy',
            'status' => (string)($row['quote_status'] ?: $row['status'] ?: 'draft'),
            'currency' => (string)$row['currency'],
            'customer_snapshot' => $this->decode((string)($row['customer_json'] ?? '{}'), []),
            'items' => is_array($items) ? $items : [],
            'total_amount' => (float)$row['amount'],
            'approved_snapshot' => $this->decode((string)($row['approved_snapshot_json'] ?? '{}'), []),
            'raw' => $row,
        ];
    }

    private function saveDetails(int $quoteId, array $quote, array $amounts, string $now): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_details
             (quote_id,source_type,source_order_no,source_snapshot,edit_mode,contact_name,contact_phone,contact_email,
              country,exchange_rate_snapshot,quote_date,valid_until,owner_legacy_user_id,owner_name,payment_terms,
              trade_terms,price_template_id,project_ref,subtotal_amount,discount_amount,shipping_amount,tax_amount,
              other_amount,commission_amount,gross_profit,gross_margin,customer_note,internal_note,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE source_type=VALUES(source_type),source_order_no=VALUES(source_order_no),
              source_snapshot=VALUES(source_snapshot),edit_mode=VALUES(edit_mode),contact_name=VALUES(contact_name),
              contact_phone=VALUES(contact_phone),contact_email=VALUES(contact_email),country=VALUES(country),
              exchange_rate_snapshot=VALUES(exchange_rate_snapshot),quote_date=VALUES(quote_date),
              valid_until=VALUES(valid_until),owner_legacy_user_id=VALUES(owner_legacy_user_id),
              owner_name=VALUES(owner_name),payment_terms=VALUES(payment_terms),trade_terms=VALUES(trade_terms),
              price_template_id=VALUES(price_template_id),project_ref=VALUES(project_ref),
              subtotal_amount=VALUES(subtotal_amount),discount_amount=VALUES(discount_amount),
              shipping_amount=VALUES(shipping_amount),tax_amount=VALUES(tax_amount),other_amount=VALUES(other_amount),
              commission_amount=VALUES(commission_amount),gross_profit=VALUES(gross_profit),
              gross_margin=VALUES(gross_margin),customer_note=VALUES(customer_note),
              internal_note=VALUES(internal_note),updated_at=VALUES(updated_at)'
        );
        $statement->execute([
            $quoteId, $quote['source_type'], $quote['source_order_no'], $this->nullableJson($quote['source_snapshot']),
            $quote['edit_mode'], $quote['contact_name'], $quote['contact_phone'], $quote['contact_email'],
            $quote['country'], $quote['exchange_rate'], $quote['quote_date'], $quote['valid_until'],
            $quote['owner_legacy_user_id'], $quote['owner_name'], $quote['payment_terms'], $quote['trade_terms'],
            $quote['price_template_id'], $quote['project_ref'], $amounts['subtotal_amount'],
            $amounts['discount_amount'], $amounts['shipping_amount'], $amounts['tax_amount'],
            $amounts['other_amount'], $amounts['commission_amount'], $amounts['gross_profit'],
            $amounts['gross_margin'], $quote['customer_note'], $quote['internal_note'], $now, $now,
        ]);
    }

    private function saveVersion(
        int $quoteId,
        int $versionNo,
        array $quote,
        array $amounts,
        int $actorUserId,
        string $now
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_versions
             (quote_id,version_no,customer_snapshot,terms_snapshot,pricing_snapshot,cost_snapshot,exchange_rate,
              template_version,status,created_by_legacy_user_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,\'draft\',?,?)'
        );
        $statement->execute([
            $quoteId, $versionNo, $this->json($quote['customer_snapshot']),
            $this->json(['payment_terms' => $quote['payment_terms'], 'trade_terms' => $quote['trade_terms']]),
            $this->json($this->pricingSnapshot($amounts)),
            $this->json(['total_cost' => $amounts['total_cost'], 'gross_profit' => $amounts['gross_profit']]),
            $quote['exchange_rate'], $quote['template_version'], $actorUserId ?: null, $now,
        ]);
        return (int)$this->connection->lastInsertId();
    }

    private function saveItem(int $versionId, array $item, string $now): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cc_quote_items
             (quote_version_id,item_type,legacy_product_id,inventory_sku_id,description,configuration_snapshot,
              quantity,unit_price,cost_amount,discount_rate,line_amount,sort_order,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $versionId, $item['item_type'], $item['legacy_product_id'], $item['inventory_sku_id'],
            $item['description'], $this->nullableJson($item['configuration_snapshot']), $item['quantity'],
            $item['unit_price'], $item['line_cost'], $item['discount_rate'], $item['line_amount'],
            $item['sort_order'], $now, $now,
        ]);
        $itemId = (int)$this->connection->lastInsertId();
        $detail = $this->connection->prepare(
            'INSERT INTO cc_quote_item_details
             (quote_item_id,product_source,sku_code,model_no,product_name,image_path,unit,lead_time,customer_note,
              internal_note,locked,unlock_reason,source_line_snapshot,custom_fields_json,reference_product_id,
              created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $detail->execute([
            $itemId, $item['product_source'], $item['sku_code'], $item['model_no'], $item['product_name'],
            $item['image_path'], $item['unit'], $item['lead_time'], $item['customer_note'],
            $item['internal_note'], $item['locked'], $item['unlock_reason'],
            $this->nullableJson($item['source_line_snapshot']), $this->nullableJson($item['custom_fields']),
            $item['reference_product_id'], $now, $now,
        ]);
        $product = $this->json([
            'source' => $item['product_source'], 'legacy_product_id' => $item['legacy_product_id'],
            'inventory_sku_id' => $item['inventory_sku_id'], 'sku_code' => $item['sku_code'],
            'model_no' => $item['model_no'], 'product_name' => $item['product_name'],
            'description' => $item['description'], 'image_path' => $item['image_path'],
        ]);
        $price = $this->json([
            'quantity' => $item['quantity'], 'unit' => $item['unit'], 'unit_price' => $item['unit_price'],
            'discount_rate' => $item['discount_rate'], 'line_amount' => $item['line_amount'],
        ]);
        $cost = $this->json(['unit_cost' => $item['unit_cost'], 'line_cost' => $item['line_cost']]);
        $configuration = $this->nullableJson($item['configuration_snapshot']);
        $hash = hash('sha256', $product . '|' . ($configuration ?? '') . '|' . $price . '|' . $cost);
        $snapshot = $this->connection->prepare(
            'INSERT INTO cc_quote_item_snapshots
             (quote_item_id,product_snapshot,configuration_snapshot,price_snapshot,cost_snapshot,snapshot_hash,created_at)
             VALUES (?,?,?,?,?,?,?)'
        );
        $snapshot->execute([$itemId, $product, $configuration, $price, $cost, $hash, $now]);
    }

    private function decodeQuote(array $quote, ?array $version, array $items): array
    {
        $quote['storage'] = 'cc';
        $quote['id'] = (int)$quote['id'];
        $quote['current_version'] = (int)$quote['current_version'];
        $quote['customer_snapshot'] = $this->decode((string)($quote['customer_snapshot'] ?? '{}'), []);
        $quote['source_snapshot'] = $this->decode((string)($quote['source_snapshot'] ?? '{}'), []);
        $quote['version'] = $version;
        foreach ($items as &$item) {
            $item['configuration_snapshot'] = $this->decode((string)($item['configuration_snapshot'] ?? '{}'), []);
            $item['source_line_snapshot'] = $this->decode((string)($item['source_line_snapshot'] ?? '{}'), []);
            $item['custom_fields'] = $this->decode((string)($item['custom_fields_json'] ?? '{}'), []);
        }
        unset($item);
        $quote['items'] = $items;
        return $quote;
    }

    private function snapshotPayload(
        int $quoteId,
        int $versionId,
        int $versionNo,
        array $quote,
        array $amounts
    ): array {
        return [
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'version_no' => $versionNo,
            'quote_no' => $quote['quote_no'],
            'quote_type' => $quote['quote_type'],
            'source_type' => $quote['source_type'],
            'source_order_no' => $quote['source_order_no'],
            'customer' => $quote['customer_snapshot'],
            'currency' => $quote['currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'terms' => ['payment' => $quote['payment_terms'], 'trade' => $quote['trade_terms']],
            'items' => $amounts['items'],
            'amounts' => $this->pricingSnapshot($amounts),
        ];
    }

    private function pricingSnapshot(array $amounts): array
    {
        return array_diff_key($amounts, ['items' => true]);
    }

    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \InvalidArgumentException('报价数据无法序列化。');
        }
        return $json;
    }

    private function nullableJson(mixed $value): ?string
    {
        return $value === null || $value === [] || $value === '' ? null : $this->json($value);
    }

    private function decode(string $json, array $fallback): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}
