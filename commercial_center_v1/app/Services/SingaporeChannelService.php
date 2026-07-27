<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Adapters\SingaporeChannelAdapter;
use Artdon\CommercialCenter\Repositories\QuoteRepository;
use PDO;
use Throwable;

final class SingaporeChannelService
{
    private const CHANNEL_CODE = 'singapore';

    private PDO $connection;
    private QuotePermissionService $permissions;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
        $this->permissions = new QuotePermissionService($this->connection);
    }

    public function dashboard(array $actor): array
    {
        $this->permissions->assert($actor, 'view');
        $channel = $this->channel(false);
        return [
            'adapter' => (new SingaporeChannelAdapter())->status(),
            'channel' => $channel ?? [
                'channel_code' => self::CHANNEL_CODE,
                'name' => '新加坡网站',
                'adapter_status' => 'not_configured',
                'status' => 'active',
            ],
            'stock_skus' => $this->all(
                "SELECT s.id,s.sku_code,s.legacy_product_id,s.actual_stock,s.sellable_stock,s.publishable,s.status,
                        n.model_no,n.product_name
                 FROM cc_inventory_skus s
                 LEFT JOIN naming_models n ON n.id=s.legacy_product_id
                 WHERE s.status='active' ORDER BY s.is_test,s.id DESC LIMIT 200"
            ),
            'packages' => $this->packages(),
            'outbox' => $this->all(
                "SELECT id,operation_type,entity_type,entity_id,status,attempts,max_attempts,
                        external_reference,last_error,is_test,created_at,updated_at,sent_at
                 FROM cc_channel_outbox WHERE channel_code=?
                 ORDER BY id DESC LIMIT 100",
                [self::CHANNEL_CODE]
            ),
            'counts' => [
                'draft_packages' => (int)$this->scalar(
                    "SELECT COUNT(*) FROM cc_channel_packages p
                     JOIN cc_channels c ON c.id=p.channel_id
                     WHERE c.channel_code=? AND p.status='draft'",
                    [self::CHANNEL_CODE]
                ),
                'pending' => (int)$this->scalar(
                    "SELECT COUNT(*) FROM cc_channel_outbox WHERE channel_code=? AND status='pending'",
                    [self::CHANNEL_CODE]
                ),
                'failed' => (int)$this->scalar(
                    "SELECT COUNT(*) FROM cc_channel_outbox WHERE channel_code=? AND status='failed'",
                    [self::CHANNEL_CODE]
                ),
            ],
        ];
    }

    public function savePackage(array $input, array $actor): array
    {
        $this->permissions->assert($actor, 'edit');
        $skuId = max(0, (int)($input['inventory_sku_id'] ?? 0));
        $sku = $this->one('SELECT * FROM cc_inventory_skus WHERE id=? AND status=\'active\'', [$skuId]);
        if ($sku === null) {
            throw new \InvalidArgumentException('请选择有效库存 SKU。');
        }
        $packageCode = $this->required($input['package_code'] ?? '', '公开套餐编码', 120);
        $title = $this->required($input['public_title'] ?? '', '公开标题', 190);
        $englishName = $this->required($input['english_name'] ?? '', '英文名称', 190);
        $currency = strtoupper(trim((string)($input['currency'] ?? 'SGD')));
        if ($currency !== 'SGD') {
            throw new \InvalidArgumentException('新加坡网站公开套餐币种必须为 SGD。');
        }
        $price = (float)($input['public_price'] ?? 0);
        if ($price <= 0) {
            throw new \InvalidArgumentException('公开售价必须大于 0。');
        }
        $moq = max(0.001, (float)($input['moq'] ?? 1));
        $lead = max(0, (int)($input['lead_time_days'] ?? 0));
        $allowOrder = !empty($input['allow_order']) ? 1 : 0;
        $inquiryOnly = $allowOrder ? 0 : 1;
        $parameters = is_array($input['public_parameters'] ?? null) ? $input['public_parameters'] : [];
        $now = date('Y-m-d H:i:s');
        $channel = $this->channel(true);
        $id = max(0, (int)($input['id'] ?? 0));
        if ($id > 0) {
            $statement = $this->connection->prepare(
                "UPDATE cc_channel_packages SET inventory_sku_id=?,package_code=?,public_title=?,english_name=?,
                 public_parameters=?,public_price=?,currency='SGD',moq=?,lead_time_days=?,allow_order=?,
                 inquiry_only=?,status='draft',updated_at=? WHERE id=? AND channel_id=?"
            );
            $statement->execute([
                $skuId, $packageCode, $title, $englishName, $this->json($parameters), $price, $moq, $lead,
                $allowOrder, $inquiryOnly, $now, $id, (int)$channel['id'],
            ]);
            if ($statement->rowCount() === 0 && $this->one('SELECT id FROM cc_channel_packages WHERE id=?', [$id]) === null) {
                throw new \RuntimeException('公开套餐不存在。');
            }
        } else {
            $statement = $this->connection->prepare(
                "INSERT INTO cc_channel_packages
                 (channel_id,inventory_sku_id,package_code,public_title,english_name,public_parameters,
                  public_price,currency,moq,lead_time_days,allow_order,inquiry_only,status,is_test,
                  created_by_legacy_user_id,created_at,updated_at)
                 VALUES (?,?,?,?,?,? ,?,'SGD',?,?,?,?,'draft',?,?,?,?)"
            );
            $statement->execute([
                (int)$channel['id'], $skuId, $packageCode, $title, $englishName, $this->json($parameters),
                $price, $moq, $lead, $allowOrder, $inquiryOnly, (int)!empty($input['is_test']),
                (int)($actor['id'] ?? 0) ?: null, $now, $now,
            ]);
            $id = (int)$this->connection->lastInsertId();
        }
        $this->connection->prepare('UPDATE cc_inventory_skus SET publishable=?,updated_at=? WHERE id=?')
            ->execute([!empty($input['publishable']) ? 1 : 0, $now, $skuId]);
        return $this->package($id) ?? throw new \RuntimeException('套餐保存后无法读取。');
    }

    public function queueProduct(int $packageId, array $actor): array
    {
        $this->permissions->assert($actor, 'send');
        $package = $this->package($packageId);
        if ($package === null) {
            throw new \RuntimeException('公开套餐不存在。');
        }
        $problems = [];
        if ((int)$package['publishable'] !== 1) $problems[] = '库存 SKU 未开启允许发布';
        if ((float)$package['public_price'] <= 0) $problems[] = '公开售价未填写';
        if (trim((string)$package['english_name']) === '') $problems[] = '英文名称未填写';
        if (trim((string)$package['public_title']) === '') $problems[] = '公开标题未填写';
        if ((float)$package['sellable_stock'] <= 0 && (int)$package['allow_order'] === 1) $problems[] = '允许下单但当前无可售库存';
        if ($problems !== []) {
            throw new \InvalidArgumentException('发布前检查未通过：' . implode('；', $problems) . '。');
        }
        $payload = [
            'schema_version' => '2026-07-27',
            'event_type' => 'product.upsert',
            'channel' => self::CHANNEL_CODE,
            'product' => [
                'package_id' => (int)$package['id'],
                'package_code' => (string)$package['package_code'],
                'sku' => (string)$package['sku_code'],
                'model' => (string)($package['model_no'] ?? ''),
                'title' => (string)$package['public_title'],
                'english_name' => (string)$package['english_name'],
                'parameters' => $this->decode((string)$package['public_parameters']),
                'price' => (float)$package['public_price'],
                'currency' => 'SGD',
                'moq' => (float)$package['moq'],
                'lead_time_days' => (int)$package['lead_time_days'],
                'allow_order' => (bool)$package['allow_order'],
                'inquiry_only' => (bool)$package['inquiry_only'],
                'sellable_stock' => (float)$package['sellable_stock'],
            ],
        ];
        $job = $this->enqueue('product_publish', 'channel_package', $packageId, $payload, $actor, (bool)$package['is_test']);
        $this->connection->prepare("UPDATE cc_channel_packages SET status='queued',updated_at=NOW() WHERE id=?")
            ->execute([$packageId]);
        return $job;
    }

    public function queueAssistedOrder(int $quoteId, array $actor): array
    {
        $this->permissions->assert($actor, 'send');
        $quote = (new QuoteRepository($this->connection))->find($quoteId);
        if ($quote === null || (string)$quote['quote_type'] !== 'stock_product') {
            throw new \RuntimeException('只有库存品报价可以建立代客网站订单。');
        }
        if ((string)($quote['sales_channel'] ?? '') !== 'singapore_web') {
            throw new \RuntimeException('该报价未选择新加坡网站渠道。');
        }
        if (!in_array((string)$quote['status'], ['approved', 'sent', 'customer_confirmed'], true)) {
            throw new \RuntimeException('报价审核通过后才能进入新加坡代客订单队列。');
        }
        $lines = [];
        foreach ($quote['items'] as $item) {
            $package = $this->one(
                "SELECT p.*,s.sku_code FROM cc_channel_packages p
                 JOIN cc_channels c ON c.id=p.channel_id AND c.channel_code=?
                 JOIN cc_inventory_skus s ON s.id=p.inventory_sku_id
                 WHERE p.inventory_sku_id=? AND p.allow_order=1 AND p.status IN ('simulated_ready','published')
                 ORDER BY p.id DESC LIMIT 1",
                [self::CHANNEL_CODE, (int)$item['inventory_sku_id']]
            );
            if ($package === null) {
                throw new \RuntimeException('报价中的 SKU 尚未完成新加坡模拟发布：' . (string)$item['sku_code']);
            }
            $lines[] = [
                'package_code' => (string)$package['package_code'],
                'sku' => (string)$package['sku_code'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'currency' => (string)$quote['currency'],
                'configuration_passport_hash' => $item['configuration_passport_hash'] ?? null,
            ];
        }
        $payload = [
            'schema_version' => '2026-07-27',
            'event_type' => 'assisted_order.create',
            'channel' => self::CHANNEL_CODE,
            'quote_id' => (int)$quote['id'],
            'quote_no' => (string)$quote['quote_no'],
            'customer' => [
                'legacy_customer_id' => (int)$quote['legacy_customer_id'],
                'name' => (string)($quote['customer_snapshot']['customer_name']
                    ?? $quote['customer_snapshot']['customer_name_en'] ?? ''),
                'contact_name' => (string)($quote['contact_name'] ?? ''),
                'phone' => (string)($quote['contact_phone'] ?? ''),
                'email' => (string)($quote['contact_email'] ?? ''),
                'country' => (string)($quote['country'] ?? ''),
            ],
            'currency' => (string)$quote['currency'],
            'total_amount' => (float)$quote['total_amount'],
            'lines' => $lines,
        ];
        $job = $this->enqueue('assisted_order', 'quote', $quoteId, $payload, $actor, (bool)$quote['is_test']);
        $this->connection->prepare(
            "UPDATE cc_quote_channel_context SET push_status='queued',last_outbox_id=?,updated_at=NOW() WHERE quote_id=?"
        )->execute([(int)$job['id'], $quoteId]);
        return $job;
    }

    public function simulate(int $outboxId, array $actor): array
    {
        $this->permissions->assert($actor, 'send');
        $job = $this->one(
            'SELECT * FROM cc_channel_outbox WHERE id=? AND channel_code=? FOR UPDATE',
            [$outboxId, self::CHANNEL_CODE]
        );
        if ($job === null) {
            throw new \RuntimeException('待发送记录不存在。');
        }
        if (!in_array((string)$job['status'], ['pending', 'failed'], true)) {
            return $this->decodeJob($job);
        }
        $payload = $this->decode((string)$job['payload_json']);
        if ($payload === [] || !isset($payload['schema_version'], $payload['event_type'])) {
            throw new \RuntimeException('待发送数据结构不完整。');
        }
        $reference = 'SIM-SG-' . date('Ymd') . '-' . str_pad((string)$outboxId, 6, '0', STR_PAD_LEFT);
        $response = [
            'mode' => 'simulation',
            'accepted' => true,
            'external_reference' => $reference,
            'message' => '接口未配置，本次只验证数据契约，未连接新加坡网站。',
        ];
        $this->connection->prepare(
            "UPDATE cc_channel_outbox SET status='simulated',attempts=attempts+1,external_reference=?,
             response_json=?,last_error=NULL,updated_at=NOW(),sent_at=NOW() WHERE id=?"
        )->execute([$reference, $this->json($response), $outboxId]);
        if ((string)$job['operation_type'] === 'product_publish') {
            $this->connection->prepare("UPDATE cc_channel_packages SET status='simulated_ready',updated_at=NOW() WHERE id=?")
                ->execute([(int)$job['entity_id']]);
        } elseif ((string)$job['operation_type'] === 'assisted_order') {
            $this->connection->prepare(
                "UPDATE cc_quote_channel_context SET push_status='simulated',external_order_id=?,updated_at=NOW() WHERE quote_id=?"
            )->execute([$reference, (int)$job['entity_id']]);
        }
        $this->saveEntityLink(
            (string)$job['entity_type'],
            (int)$job['entity_id'],
            $reference,
            'simulated',
            (string)$job['payload_hash']
        );
        return $this->decodeJob($this->one('SELECT * FROM cc_channel_outbox WHERE id=?', [$outboxId]) ?? []);
    }

    public function retry(int $outboxId, array $actor): array
    {
        $this->permissions->assert($actor, 'send');
        $statement = $this->connection->prepare(
            "UPDATE cc_channel_outbox SET status='pending',available_at=NOW(),last_error=NULL,updated_at=NOW()
             WHERE id=? AND channel_code=? AND status='failed' AND attempts<max_attempts"
        );
        $statement->execute([$outboxId, self::CHANNEL_CODE]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('只有未超过重试上限的失败记录可以重试。');
        }
        return $this->decodeJob($this->one('SELECT * FROM cc_channel_outbox WHERE id=?', [$outboxId]) ?? []);
    }

    private function enqueue(
        string $operation,
        string $entityType,
        int $entityId,
        array $payload,
        array $actor,
        bool $isTest
    ): array {
        $json = $this->json($payload);
        $hash = hash('sha256', $json);
        $key = self::CHANNEL_CODE . ':' . $operation . ':' . $entityType . ':' . $entityId . ':' . $hash;
        $existing = $this->one('SELECT * FROM cc_channel_outbox WHERE idempotency_key=?', [$key]);
        if ($existing !== null) {
            return $this->decodeJob($existing);
        }
        $now = date('Y-m-d H:i:s');
        $statement = $this->connection->prepare(
            "INSERT INTO cc_channel_outbox
             (channel_code,operation_type,entity_type,entity_id,idempotency_key,payload_json,payload_hash,
              status,attempts,max_attempts,available_at,is_test,created_by_legacy_user_id,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,'pending',0,5,?,?,?,?,?)"
        );
        $statement->execute([
            self::CHANNEL_CODE, $operation, $entityType, $entityId, $key, $json, $hash,
            $now, $isTest ? 1 : 0, (int)($actor['id'] ?? 0) ?: null, $now, $now,
        ]);
        return $this->decodeJob(
            $this->one('SELECT * FROM cc_channel_outbox WHERE id=?', [(int)$this->connection->lastInsertId()]) ?? []
        );
    }

    private function packages(): array
    {
        return $this->all(
            "SELECT p.*,s.sku_code,s.sellable_stock,s.publishable,n.model_no,n.product_name
             FROM cc_channel_packages p
             JOIN cc_channels c ON c.id=p.channel_id AND c.channel_code=?
             JOIN cc_inventory_skus s ON s.id=p.inventory_sku_id
             LEFT JOIN naming_models n ON n.id=s.legacy_product_id
             ORDER BY p.id DESC LIMIT 200",
            [self::CHANNEL_CODE]
        );
    }

    private function package(int $id): ?array
    {
        return $this->one(
            "SELECT p.*,s.sku_code,s.sellable_stock,s.publishable,n.model_no,n.product_name
             FROM cc_channel_packages p
             JOIN cc_channels c ON c.id=p.channel_id AND c.channel_code=?
             JOIN cc_inventory_skus s ON s.id=p.inventory_sku_id
             LEFT JOIN naming_models n ON n.id=s.legacy_product_id
             WHERE p.id=? LIMIT 1",
            [self::CHANNEL_CODE, $id]
        );
    }

    private function channel(bool $create): ?array
    {
        $row = $this->one('SELECT * FROM cc_channels WHERE channel_code=? LIMIT 1', [self::CHANNEL_CODE]);
        if ($row !== null || !$create) return $row;
        $now = date('Y-m-d H:i:s');
        $this->connection->prepare(
            "INSERT INTO cc_channels(channel_code,name,adapter_status,status,created_at,updated_at)
             VALUES (?,'新加坡网站','not_configured','active',?,?)"
        )->execute([self::CHANNEL_CODE, $now, $now]);
        return $this->one('SELECT * FROM cc_channels WHERE channel_code=? LIMIT 1', [self::CHANNEL_CODE]);
    }

    private function saveEntityLink(
        string $entityType,
        int $entityId,
        string $externalId,
        string $status,
        string $hash
    ): void {
        $now = date('Y-m-d H:i:s');
        $this->connection->prepare(
            "INSERT INTO cc_channel_entity_links
             (channel_code,entity_type,entity_id,external_id,sync_status,last_payload_hash,last_synced_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE external_id=VALUES(external_id),sync_status=VALUES(sync_status),
              last_payload_hash=VALUES(last_payload_hash),last_synced_at=VALUES(last_synced_at),updated_at=VALUES(updated_at)"
        )->execute([self::CHANNEL_CODE, $entityType, $entityId, $externalId, $status, $hash, $now, $now, $now]);
    }

    private function decodeJob(array $job): array
    {
        if ($job === []) return [];
        $job['payload'] = $this->decode((string)($job['payload_json'] ?? '{}'));
        $job['response'] = $this->decode((string)($job['response_json'] ?? '{}'));
        unset($job['payload_json'], $job['response_json']);
        return $job;
    }

    private function required(mixed $value, string $label, int $length): string
    {
        $text = trim((string)$value);
        if ($text === '') throw new \InvalidArgumentException($label . '不能为空。');
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) throw new \RuntimeException('渠道数据无法序列化。');
        return $json;
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    private function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    private function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function all(string $sql, array $parameters = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
