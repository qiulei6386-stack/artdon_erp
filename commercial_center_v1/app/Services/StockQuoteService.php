<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\StandardQuoteRepository;
use PDO;

final class StockQuoteService
{
    private PDO $connection;
    private StandardQuoteRepository $catalog;
    private ConfigurationEngineService $configuration;
    private QuoteWorkflowService $workflow;
    private QuotePermissionService $permissions;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
        $this->catalog = new StandardQuoteRepository($this->connection);
        $this->configuration = new ConfigurationEngineService();
        $this->workflow = new QuoteWorkflowService();
        $this->permissions = new QuotePermissionService($this->connection);
    }

    public function bootstrap(int $userId, int $customerId = 0): array
    {
        $configuration = $this->configuration->catalog($userId, $customerId ?: null);
        return [
            'customers' => $this->catalog->customers('', 100),
            'stock_skus' => array_values(array_map(function (array $sku): array {
                $package = $this->singaporePackage((int)$sku['id']);
                $sku['singapore_package'] = $package;
                $sku['can_sell_singapore'] = $package !== null
                    && (int)$package['allow_order'] === 1
                    && in_array((string)$package['status'], ['simulated_ready', 'published'], true);
                return $sku;
            }, $configuration['stock_skus'] ?? [])),
            'channels' => [
                ['code' => 'guangzhou_direct', 'name' => '广州直接销售', 'status' => 'available'],
                ['code' => 'singapore_web', 'name' => '新加坡网站代客下单', 'status' => 'simulation_only'],
            ],
        ];
    }

    public function prepareItem(array $input, int $userId, int $customerId, string $salesChannel): array
    {
        if ($this->catalog->customer($customerId) === null) {
            throw new \InvalidArgumentException('请选择有效 CRM 客户。');
        }
        $quantity = max(0.001, (float)($input['quantity'] ?? 1));
        $request = is_array($input['configuration_request'] ?? null)
            ? $input['configuration_request']
            : $input;
        $request['customer_id'] = $customerId;
        $configuration = $this->configuration->evaluate($request, $userId);
        if (($configuration['product']['type'] ?? '') !== 'stock') {
            throw new \InvalidArgumentException('库存品报价只能选择库存 SKU。');
        }
        if (($configuration['status'] ?? '') === 'blocked') {
            throw new \InvalidArgumentException('库存 SKU 配置快照无效。');
        }
        $skuId = (int)$configuration['product']['inventory_sku_id'];
        $sku = $this->stockSku($skuId);
        if ($sku === null || (string)$sku['status'] !== 'active') {
            throw new \RuntimeException('库存 SKU 不存在或已停用。');
        }
        if ($quantity > (float)$sku['sellable_stock']) {
            throw new \InvalidArgumentException(
                '可销售库存不足：当前可售 ' . $this->number((float)$sku['sellable_stock']) . '。'
            );
        }
        $package = $this->singaporePackage($skuId);
        if ($salesChannel === 'singapore_web') {
            if ((int)$sku['publishable'] !== 1) {
                throw new \InvalidArgumentException('该库存 SKU 尚未允许发布到新加坡网站。');
            }
            if ($package === null || (int)$package['allow_order'] !== 1
                || !in_array((string)$package['status'], ['simulated_ready', 'published'], true)) {
                throw new \InvalidArgumentException('请先在“新加坡发布”完成套餐检查与模拟发布。');
            }
        }
        $price = $package !== null && $salesChannel === 'singapore_web'
            ? (float)$package['public_price']
            : 0.0;
        if (isset($input['unit_price']) && is_numeric($input['unit_price']) && (float)$input['unit_price'] >= 0) {
            $price = (float)$input['unit_price'];
        }
        $warnings = [];
        if ($price <= 0) {
            $warnings[] = '当前 SKU 没有有效销售价，请在保存前录入单价。';
        }
        if ((float)$sku['sellable_stock'] - $quantity <= (float)$sku['safety_stock']) {
            $warnings[] = '本次数量接近安全库存，请确认是否需要补货。';
        }
        $product = $configuration['product'];
        return [
            'item_type' => 'stock_product',
            'legacy_product_id' => (int)$product['legacy_product_id'] ?: null,
            'inventory_sku_id' => $skuId,
            'product_source' => 'inventory_sku',
            'sku_code' => (string)$sku['sku_code'],
            'model_no' => (string)($product['code'] ?? $sku['sku_code']),
            'product_name' => (string)($product['name'] ?? $sku['sku_code']),
            'description' => (string)($product['name'] ?? $sku['sku_code']),
            'configuration_snapshot' => [
                'request' => $request,
                'result' => $configuration,
                'passport_hash' => $configuration['passport_hash'],
                'stock_at_quote' => [
                    'actual_stock' => (float)$sku['actual_stock'],
                    'reserved_stock' => (float)$sku['reserved_stock'],
                    'safety_stock' => (float)$sku['safety_stock'],
                    'sellable_stock' => (float)$sku['sellable_stock'],
                    'captured_at' => date(DATE_ATOM),
                ],
            ],
            'configuration_level' => 'locked',
            'adaptation_product_id' => (int)($configuration['adaptation']['product_id'] ?? 0) ?: null,
            'adaptation_version_no' => (int)($configuration['adaptation']['approved_version'] ?? 0) ?: null,
            'configuration_passport_hash' => (string)$configuration['passport_hash'],
            'adaptation_snapshot' => $configuration['adaptation'] ?? [],
            'quantity' => $quantity,
            'unit' => (string)($input['unit'] ?? 'PCS'),
            'unit_price' => round($price, 4),
            'unit_cost' => 0,
            'discount_rate' => $input['discount_rate'] ?? 0,
            'lead_time' => '库存现货',
            'locked' => 1,
            'customer_note' => $input['customer_note'] ?? null,
            'internal_note' => $input['internal_note'] ?? null,
            'custom_fields' => [
                'sales_channel' => $salesChannel,
                'sellable_stock_at_quote' => (float)$sku['sellable_stock'],
                'channel_package_id' => $package === null ? null : (int)$package['id'],
                'warnings' => $warnings,
            ],
            'warnings' => $warnings,
        ];
    }

    public function save(array $payload, array $actor): array
    {
        $customerId = (int)($payload['customer_id'] ?? 0);
        $customer = $this->catalog->customer($customerId);
        if ($customer === null) {
            throw new \InvalidArgumentException('请选择有效 CRM 客户。');
        }
        $salesChannel = (string)($payload['sales_channel'] ?? 'guangzhou_direct');
        if (!in_array($salesChannel, ['guangzhou_direct', 'singapore_web'], true)) {
            throw new \InvalidArgumentException('销售渠道无效。');
        }
        $items = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $rawItem) {
            if (is_array($rawItem)) {
                if (array_key_exists('unit_price', $rawItem)) {
                    $this->permissions->assert($actor, 'edit_price');
                }
                $items[] = $this->prepareItem($rawItem, (int)($actor['id'] ?? 0), $customerId, $salesChannel);
            }
        }
        if ($items === []) {
            throw new \InvalidArgumentException('库存品报价至少需要一个库存 SKU。');
        }
        $input = [
            'id' => max(0, (int)($payload['id'] ?? 0)),
            'quote_type' => 'stock_product',
            'legacy_customer_id' => $customerId,
            'customer_snapshot' => $customer,
            'currency' => $payload['currency'] ?? ($salesChannel === 'singapore_web' ? 'SGD' : 'USD'),
            'language' => $payload['language'] ?? 'en',
            'source_type' => $salesChannel === 'singapore_web' ? 'sales_proxy' : 'manual',
            'sales_channel' => $salesChannel,
            'fulfillment_mode' => 'inventory',
            'contact_name' => $payload['contact_name'] ?? $customer['contact_name'],
            'contact_phone' => $customer['contact_phone'] ?: $customer['phone'],
            'contact_email' => $customer['contact_email'] ?: $customer['email'],
            'country' => $payload['country'] ?? $customer['country'],
            'quote_date' => $payload['quote_date'] ?? date('Y-m-d'),
            'valid_until' => $payload['valid_until'] ?? null,
            'owner_legacy_user_id' => (int)($actor['id'] ?? 0),
            'owner_name' => $actor['display_name'] ?? $actor['username'] ?? '',
            'payment_terms' => $payload['payment_terms'] ?? '',
            'trade_terms' => $payload['trade_terms'] ?? '',
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'shipping_amount' => $payload['shipping_amount'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'customer_note' => $payload['customer_note'] ?? '',
            'internal_note' => $payload['internal_note'] ?? '',
            'items' => $items,
            'is_test' => !empty($payload['is_test']),
        ];
        return $input['id'] > 0
            ? $this->workflow->editDraft($input, $actor)
            : $this->workflow->createDraft($input, $actor);
    }

    public function open(int $quoteId, array $actor): ?array
    {
        $quote = $this->workflow->open($quoteId, $actor);
        if ($quote !== null && (string)$quote['quote_type'] !== 'stock_product') {
            throw new \RuntimeException('当前报价不是库存品报价。');
        }
        return $quote;
    }

    public function submit(int $quoteId, array $actor): array
    {
        return $this->workflow->transition($quoteId, 'pending_approval', $actor, '库存品报价提交审核');
    }

    private function stockSku(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM cc_inventory_skus WHERE id=? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function singaporePackage(int $skuId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT p.* FROM cc_channel_packages p
             JOIN cc_channels c ON c.id=p.channel_id
             WHERE c.channel_code='singapore' AND p.inventory_sku_id=?
             ORDER BY p.id DESC LIMIT 1"
        );
        $statement->execute([$skuId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
