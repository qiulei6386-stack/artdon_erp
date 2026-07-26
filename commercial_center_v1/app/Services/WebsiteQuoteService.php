<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\QuoteRepository;
use Artdon\CommercialCenter\Repositories\QuoteWorkflowRepository;
use Artdon\CommercialCenter\Repositories\StandardQuoteRepository;
use Artdon\CommercialCenter\Repositories\WebsiteQuoteRepository;
use Throwable;

final class WebsiteQuoteService
{
    private WebsiteQuoteRepository $website;
    private QuoteRepository $quotes;
    private QuoteService $quoteService;
    private QuoteWorkflowService $workflow;
    private QuoteWorkflowRepository $workflowRepository;
    private QuotePermissionService $permissions;
    private StandardQuoteRepository $customers;

    public function __construct(
        ?WebsiteQuoteRepository $website = null,
        ?QuoteRepository $quotes = null,
        ?QuoteService $quoteService = null,
        ?QuoteWorkflowService $workflow = null,
        ?QuoteWorkflowRepository $workflowRepository = null,
        ?QuotePermissionService $permissions = null,
        ?StandardQuoteRepository $customers = null
    ) {
        $this->website = $website ?? new WebsiteQuoteRepository();
        $this->quotes = $quotes ?? new QuoteRepository($this->website->connection());
        $this->quoteService = $quoteService ?? new QuoteService($this->quotes);
        $this->workflowRepository = $workflowRepository ?? new QuoteWorkflowRepository($this->website->connection());
        $this->permissions = $permissions ?? new QuotePermissionService($this->website->connection());
        $this->workflow = $workflow ?? new QuoteWorkflowService(
            $this->workflowRepository,
            $this->quotes,
            $this->quoteService,
            $this->permissions
        );
        $this->customers = $customers ?? new StandardQuoteRepository($this->website->connection());
    }

    public function import(array $payload, array $actor, string $sourceType = 'website_import'): array
    {
        $this->permissions->assert($actor, 'create');
        if (!in_array($sourceType, ['website_import', 'sales_proxy'], true)) {
            throw new \InvalidArgumentException('网站报价来源无效。');
        }
        $normalized = $this->normalizePayload($payload, $sourceType);
        $existing = $this->website->snapshotBySource(
            $normalized['channel'],
            $normalized['external_order_no'],
            $normalized['idempotency_key']
        );
        if ($existing !== null) {
            return [
                'duplicate' => true,
                'snapshot_id' => (int)$existing['id'],
                'quote' => (int)$existing['quote_id'] > 0 ? $this->quotes->find((int)$existing['quote_id']) : null,
            ];
        }

        $connection = $this->website->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }
        try {
            $quoteInput = $this->quoteInput($normalized, $actor);
            $quote = $this->workflow->createDraft($quoteInput, $actor);
            $snapshotId = $this->website->saveSnapshot([
                'channel' => $normalized['channel'],
                'external_order_no' => $normalized['external_order_no'],
                'idempotency_key' => $normalized['idempotency_key'],
                'source_type' => $sourceType,
                'payload_json' => $this->json($normalized),
                'payload_hash' => hash('sha256', $this->json($normalized)),
                'customer_snapshot' => $this->json($normalized['customer']),
                'contact_snapshot' => $this->json($normalized['contact']),
                'items_snapshot' => $this->json($normalized['items']),
                'shipping_snapshot' => $this->json($normalized['shipping']),
                'attachment_snapshot' => $this->json($normalized['attachments']),
                'customer_note' => $normalized['customer_note'],
                'placed_at' => $normalized['placed_at'],
                'quote_id' => (int)$quote['id'],
                'is_test' => $normalized['is_test'],
                'imported_by_legacy_user_id' => (int)($actor['id'] ?? 0),
            ]);
            $submitted = $this->workflow->transition(
                (int)$quote['id'],
                'pending_approval',
                $actor,
                $sourceType === 'sales_proxy' ? '业务员代客户建立网站订单' : '新加坡网站待审核订单导入'
            );
            if ($ownsTransaction) {
                $connection->commit();
            }
            return ['duplicate' => false, 'snapshot_id' => $snapshotId, 'quote' => $submitted];
        } catch (Throwable $error) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }

    public function bootstrap(int $userId): array
    {
        return [
            'customers' => $this->customers->customers('', 100),
            'configuration' => (new ConfigurationEngineService())->catalog($userId),
            'channel' => [
                'code' => 'singapore_web',
                'live_api_status' => 'not_configured',
                'import_mode' => 'authenticated_payload',
            ],
        ];
    }

    public function review(int $quoteId, array $changes, array $actor, string $reason): array
    {
        $this->permissions->assert($actor, 'approve');
        $this->permissions->assert($actor, 'edit_price');
        $before = $this->quotes->find($quoteId) ?? throw new \RuntimeException('网站报价不存在。');
        if ((string)$before['quote_type'] !== 'website_order' || (string)$before['status'] !== 'pending_approval') {
            throw new \RuntimeException('当前报价不是待审核网站订单。');
        }
        $inputItems = is_array($changes['items'] ?? null) ? $changes['items'] : [];
        $items = [];
        foreach ($before['items'] as $index => $item) {
            $change = is_array($inputItems[$index] ?? null) ? $inputItems[$index] : [];
            $locked = [
                'model_no' => $item['model_no'],
                'sku_code' => $item['sku_code'],
                'configuration_snapshot' => $item['configuration_snapshot'],
                'quantity' => $item['quantity'],
                'source_line_snapshot' => $item['source_line_snapshot'],
            ];
            foreach ($locked as $field => $value) {
                if (array_key_exists($field, $change) && $change[$field] != $value) {
                    if (!$this->website->consumeUnlock($quoteId, (int)$item['id'], $field)) {
                        throw new \RuntimeException('锁定字段未获批准，不能修改：' . $field);
                    }
                    $locked[$field] = $change[$field];
                }
            }
            $items[] = [
                'item_type' => 'website_order',
                'legacy_product_id' => $item['legacy_product_id'],
                'inventory_sku_id' => $item['inventory_sku_id'],
                'description' => $item['description'],
                'product_source' => $item['product_source'],
                'sku_code' => $locked['sku_code'],
                'model_no' => $locked['model_no'],
                'product_name' => $item['product_name'],
                'image_path' => $item['image_path'],
                'configuration_snapshot' => $locked['configuration_snapshot'],
                'source_line_snapshot' => $locked['source_line_snapshot'],
                'quantity' => $locked['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $change['unit_price'] ?? $item['unit_price'],
                'unit_cost' => ((float)$item['cost_amount'] / max(0.001, (float)$item['quantity'])),
                'discount_rate' => $change['discount_rate'] ?? $item['discount_rate'],
                'lead_time' => $change['lead_time'] ?? $item['lead_time'],
                'customer_note' => $item['customer_note'],
                'internal_note' => $change['internal_note'] ?? $item['internal_note'],
                'locked' => 1,
                'unlock_reason' => $reason,
                'custom_fields' => $item['custom_fields'],
            ];
        }
        $customer = $before['customer_snapshot'];
        $saved = $this->quoteService->saveApprovalReview([
            'id' => $quoteId,
            'quote_type' => 'website_order',
            'legacy_customer_id' => $before['legacy_customer_id'],
            'customer_snapshot' => $customer,
            'currency' => $before['currency'],
            'language' => $before['language'],
            'source_type' => $before['source_type'],
            'source_order_no' => $before['source_order_no'],
            'source_snapshot' => $before['source_snapshot'],
            'contact_name' => $before['contact_name'],
            'contact_phone' => $before['contact_phone'],
            'contact_email' => $before['contact_email'],
            'country' => $before['country'],
            'exchange_rate' => $before['exchange_rate_snapshot'],
            'quote_date' => $before['quote_date'],
            'valid_until' => $before['valid_until'],
            'owner_legacy_user_id' => $before['owner_legacy_user_id'],
            'owner_name' => $before['owner_name'],
            'payment_terms' => $changes['payment_terms'] ?? $before['payment_terms'],
            'trade_terms' => $changes['trade_terms'] ?? $before['trade_terms'],
            'discount_amount' => $changes['discount_amount'] ?? $before['discount_amount'],
            'shipping_amount' => $changes['shipping_amount'] ?? $before['shipping_amount'],
            'tax_amount' => $changes['tax_amount'] ?? $before['tax_amount'],
            'other_amount' => $changes['other_amount'] ?? $before['other_amount'],
            'commission_amount' => $before['commission_amount'],
            'customer_note' => $before['customer_note'],
            'internal_note' => $changes['internal_note'] ?? $before['internal_note'],
            'items' => $items,
            'is_test' => $before['is_test'],
        ], (int)($actor['id'] ?? 0));
        $this->workflowRepository->audit($saved, 'website_review_adjust', $reason, $actor, $before, $saved, []);
        return $saved;
    }

    public function requestUnlock(
        int $quoteId,
        int $itemId,
        string $field,
        mixed $requestedValue,
        string $reason,
        array $actor
    ): int {
        $this->permissions->assert($actor, 'edit');
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('解锁申请必须填写原因。');
        }
        $allowed = ['model_no', 'sku_code', 'configuration_snapshot', 'quantity', 'source_line_snapshot'];
        if (!in_array($field, $allowed, true)) {
            throw new \InvalidArgumentException('该字段不属于网站订单锁定字段。');
        }
        $quote = $this->quotes->find($quoteId) ?? throw new \RuntimeException('报价不存在。');
        $item = null;
        foreach ($quote['items'] as $candidate) {
            if ((int)$candidate['id'] === $itemId) {
                $item = $candidate;
                break;
            }
        }
        if ($item === null) {
            throw new \RuntimeException('报价明细不存在。');
        }
        $id = $this->website->requestUnlock(
            $quoteId,
            $itemId,
            $field,
            $reason,
            $item[$field] ?? null,
            $requestedValue,
            $actor
        );
        $this->workflowRepository->audit(
            $quote,
            'request_unlock',
            $reason,
            $actor,
            [$field => $item[$field] ?? null],
            [$field => $requestedValue, 'request_id' => $id],
            []
        );
        return $id;
    }

    public function reviewUnlock(int $requestId, bool $approved, string $note, array $actor): void
    {
        $this->permissions->assert($actor, 'edit_locked');
        $this->website->reviewUnlock($requestId, $approved, $note, $actor);
    }

    public function approve(int $quoteId, array $actor, string $reason = ''): array
    {
        return $this->workflow->transition($quoteId, 'approved', $actor, $reason);
    }

    public function reject(int $quoteId, array $actor, string $reason): array
    {
        return $this->workflow->transition($quoteId, 'rejected', $actor, $reason);
    }

    public function open(int $quoteId, array $actor): array
    {
        $quote = $this->workflow->open($quoteId, $actor) ?? throw new \RuntimeException('报价不存在。');
        $quote['unlock_requests'] = $this->website->unlockRequests($quoteId);
        return $quote;
    }

    private function normalizePayload(array $payload, string $sourceType): array
    {
        $channel = trim((string)($payload['channel'] ?? 'singapore_web'));
        $orderNo = trim((string)($payload['external_order_no'] ?? ''));
        if ($orderNo === '') {
            throw new \InvalidArgumentException('网站订单号不能为空。');
        }
        $customerId = (int)($payload['customer_id'] ?? 0);
        $customer = $this->customers->customer($customerId);
        if ($customer === null) {
            throw new \InvalidArgumentException('请选择有效 CRM 客户。');
        }
        $items = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
        if ($items === []) {
            throw new \InvalidArgumentException('网站订单至少需要一条产品明细。');
        }
        $normalizedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = (float)($item['quantity'] ?? 0);
            $price = (float)($item['website_unit_price'] ?? $item['unit_price'] ?? 0);
            if ($quantity <= 0 || $price < 0) {
                throw new \InvalidArgumentException('网站订单数量或价格无效。');
            }
            $normalizedItems[] = [
                'line_no' => $item['line_no'] ?? $index + 1,
                'legacy_product_id' => (int)($item['legacy_product_id'] ?? 0) ?: null,
                'inventory_sku_id' => (int)($item['inventory_sku_id'] ?? 0) ?: null,
                'sku_code' => trim((string)($item['sku_code'] ?? '')),
                'model_no' => trim((string)($item['model_no'] ?? '')),
                'product_name' => trim((string)($item['product_name'] ?? '')),
                'configuration' => is_array($item['configuration'] ?? null) ? $item['configuration'] : [],
                'quantity' => $quantity,
                'website_unit_price' => $price,
                'lead_time' => trim((string)($item['lead_time'] ?? '')),
                'customer_requirement' => trim((string)($item['customer_requirement'] ?? '')),
            ];
        }
        $idempotency = trim((string)($payload['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = hash('sha256', $channel . '|' . $orderNo);
        }
        return [
            'channel' => $channel,
            'external_order_no' => $orderNo,
            'idempotency_key' => $idempotency,
            'source_type' => $sourceType,
            'customer' => $customer,
            'contact' => [
                'id' => $customer['contact_id'],
                'name' => $payload['contact_name'] ?? $customer['contact_name'],
                'email' => $customer['contact_email'] ?: $customer['email'],
                'phone' => $customer['contact_phone'] ?: $customer['phone'],
            ],
            'items' => $normalizedItems,
            'currency' => strtoupper((string)($payload['currency'] ?? 'USD')),
            'shipping' => is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [],
            'shipping_amount' => max(0, (float)($payload['shipping_amount'] ?? 0)),
            'payment_terms' => trim((string)($payload['payment_terms'] ?? '')),
            'trade_terms' => trim((string)($payload['trade_terms'] ?? '')),
            'customer_note' => trim((string)($payload['customer_note'] ?? '')),
            'attachments' => is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [],
            'placed_at' => $this->dateTime($payload['placed_at'] ?? null),
            'is_test' => (int)!empty($payload['is_test']),
        ];
    }

    private function quoteInput(array $source, array $actor): array
    {
        $items = [];
        foreach ($source['items'] as $item) {
            $items[] = [
                'item_type' => 'website_order',
                'legacy_product_id' => $item['legacy_product_id'],
                'inventory_sku_id' => $item['inventory_sku_id'],
                'product_source' => $source['source_type'] === 'sales_proxy' ? 'website_catalog' : 'singapore_web',
                'sku_code' => $item['sku_code'],
                'model_no' => $item['model_no'],
                'product_name' => $item['product_name'],
                'description' => $item['product_name'] ?: $item['model_no'] ?: $item['sku_code'],
                'configuration_snapshot' => $item['configuration'],
                'quantity' => $item['quantity'],
                'unit' => 'PCS',
                'unit_price' => $item['website_unit_price'],
                'unit_cost' => 0,
                'discount_rate' => 0,
                'lead_time' => $item['lead_time'],
                'customer_note' => $item['customer_requirement'],
                'locked' => 1,
                'source_line_snapshot' => $item,
            ];
        }
        return [
            'quote_type' => 'website_order',
            'legacy_customer_id' => (int)$source['customer']['id'],
            'customer_snapshot' => $source['customer'],
            'currency' => $source['currency'],
            'source_type' => $source['source_type'],
            'source_order_no' => $source['external_order_no'],
            'source_snapshot' => $source,
            'contact_name' => $source['contact']['name'],
            'contact_phone' => $source['contact']['phone'],
            'contact_email' => $source['contact']['email'],
            'country' => $source['customer']['country'],
            'exchange_rate' => 1,
            'quote_date' => date('Y-m-d'),
            'owner_legacy_user_id' => (int)($actor['id'] ?? 0),
            'owner_name' => $actor['display_name'] ?? $actor['username'] ?? '',
            'payment_terms' => $source['payment_terms'],
            'trade_terms' => $source['trade_terms'],
            'shipping_amount' => $source['shipping_amount'],
            'customer_note' => $source['customer_note'],
            'items' => $items,
            'is_test' => $source['is_test'],
        ];
    }

    private function dateTime(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        $timestamp = strtotime($text);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('网站下单时间无效。');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            ?: 'null';
    }
}
