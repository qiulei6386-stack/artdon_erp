<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\LegacyCatalogReadRepository;
use Artdon\CommercialCenter\Repositories\StandardQuoteRepository;

final class StandardQuoteService
{
    private StandardQuoteRepository $repository;
    private ConfigurationEngineService $configuration;
    private QuoteWorkflowService $workflow;
    private QuotePermissionService $permissions;

    public function __construct(
        ?StandardQuoteRepository $repository = null,
        ?ConfigurationEngineService $configuration = null,
        ?QuoteWorkflowService $workflow = null,
        ?QuotePermissionService $permissions = null
    ) {
        $this->repository = $repository ?? new StandardQuoteRepository();
        $this->configuration = $configuration ?? new ConfigurationEngineService();
        $this->workflow = $workflow ?? new QuoteWorkflowService();
        $this->permissions = $permissions ?? new QuotePermissionService();
    }

    public function bootstrap(int $userId, int $customerId = 0, string $search = ''): array
    {
        return [
            'customers' => $this->repository->customers($search),
            'configuration' => $this->configuration->catalog($userId, $customerId ?: null),
            'commission_reminders' => $this->repository->commissionReminders($customerId),
        ];
    }

    public function prepareItem(array $input, int $userId, int $customerId): array
    {
        $quantity = max(0.001, (float)($input['quantity'] ?? 1));
        $configurationInput = is_array($input['configuration_request'] ?? null)
            ? $input['configuration_request']
            : $input;
        $configurationInput['customer_id'] = $customerId;
        $configuration = $this->configuration->evaluate($configurationInput, $userId);
        if (($configuration['status'] ?? '') === 'blocked') {
            throw new \InvalidArgumentException('产品配置不合法，不能加入报价。');
        }
        $product = $configuration['product'];
        $model = (string)$product['code'];
        $customer = $this->repository->customer($customerId);
        if ($customer === null) {
            throw new \InvalidArgumentException('CRM 客户不存在或已删除。');
        }
        $price = $this->resolvePrice($model, $quantity, (string)($customer['level'] ?? ''), $configuration);
        $manualPrice = $input['unit_price'] ?? null;
        if ($manualPrice !== null && is_numeric($manualPrice) && (float)$manualPrice >= 0) {
            $price['unit_price'] = round((float)$manualPrice, 4);
            $price['source'] = 'manual_override';
        }
        $commission = $this->commission(
            $customerId,
            $model,
            (string)($configurationInput['category'] ?? ''),
            $quantity * (float)$price['unit_price']
        );
        $warnings = [];
        if ((float)$price['unit_price'] <= 0) {
            $warnings[] = '产品暂无有效价格，提交审核前必须核价。';
        }
        if ($quantity < (float)$price['moq']) {
            $warnings[] = '数量低于 MOQ ' . $price['moq'] . '，需要审批。';
        }
        foreach ($configuration['messages'] ?? [] as $message) {
            $warnings[] = (string)($message['message'] ?? '');
        }
        return [
            'item_type' => 'standard_product',
            'legacy_product_id' => (int)$product['legacy_product_id'],
            'inventory_sku_id' => (int)$product['inventory_sku_id'] ?: null,
            'product_source' => 'naming_models',
            'sku_code' => $product['type'] === 'stock' ? (string)$product['code'] : null,
            'model_no' => $model,
            'product_name' => (string)$product['name'],
            'description' => (string)$product['name'],
            'configuration_snapshot' => [
                'request' => $configurationInput,
                'result' => $configuration,
                'passport_hash' => $configuration['passport_hash'],
            ],
            'quantity' => $quantity,
            'unit' => (string)($input['unit'] ?? 'PCS'),
            'unit_price' => $price['unit_price'],
            'unit_cost' => $price['unit_cost'],
            'discount_rate' => $input['discount_rate'] ?? 0,
            'lead_time' => $price['lead_time_days'] . ' 天',
            'customer_note' => $input['customer_note'] ?? null,
            'internal_note' => $input['internal_note'] ?? null,
            'custom_fields' => [
                'price_source' => $price['source'],
                'price_policy_id' => $price['policy_id'],
                'moq' => $price['moq'],
                'commission' => $commission,
                'warnings' => array_values(array_filter($warnings)),
            ],
            'pricing' => $price,
            'commission' => $commission,
            'warnings' => array_values(array_filter($warnings)),
        ];
    }

    public function save(array $payload, array $actor): array
    {
        $customerId = (int)($payload['customer_id'] ?? 0);
        $customer = $this->repository->customer($customerId);
        if ($customer === null) {
            throw new \InvalidArgumentException('请选择有效 CRM 客户。');
        }
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($rawItems as $rawItem) {
            if (is_array($rawItem) && array_key_exists('unit_price', $rawItem)) {
                $this->permissions->assert($actor, 'edit_price');
                break;
            }
        }
        $items = [];
        $commission = 0.0;
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $prepared = $this->prepareItem($rawItem, (int)($actor['id'] ?? 0), $customerId);
            $commission += (float)($prepared['commission']['estimated_amount'] ?? 0);
            $items[] = $prepared;
        }
        $input = [
            'id' => (int)($payload['id'] ?? 0),
            'quote_type' => 'standard_product',
            'legacy_customer_id' => $customerId,
            'customer_snapshot' => $customer,
            'currency' => strtoupper((string)($payload['currency'] ?? 'USD')),
            'language' => (string)($payload['language'] ?? 'en'),
            'source_type' => 'manual',
            'contact_name' => $payload['contact_name'] ?? $customer['contact_name'],
            'contact_phone' => $customer['contact_phone'] ?: $customer['phone'],
            'contact_email' => $customer['contact_email'] ?: $customer['email'],
            'country' => $payload['country'] ?? $customer['country'],
            'exchange_rate' => $payload['exchange_rate'] ?? 1,
            'quote_date' => $payload['quote_date'] ?? date('Y-m-d'),
            'valid_until' => $payload['valid_until'] ?? null,
            'owner_legacy_user_id' => (int)($actor['id'] ?? 0),
            'owner_name' => $actor['display_name'] ?? $actor['username'] ?? '',
            'payment_terms' => $payload['payment_terms'] ?? null,
            'trade_terms' => $payload['trade_terms'] ?? null,
            'price_template_id' => $payload['price_template_id'] ?? null,
            'project_ref' => $payload['project_ref'] ?? null,
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'shipping_amount' => $payload['shipping_amount'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'other_amount' => $payload['other_amount'] ?? 0,
            'commission_amount' => round($commission, 4),
            'customer_note' => $payload['customer_note'] ?? null,
            'internal_note' => $payload['internal_note'] ?? null,
            'items' => $items,
            'is_test' => (int)!empty($payload['is_test']),
            'request_context' => $payload['request_context'] ?? [],
        ];
        return (int)$input['id'] > 0
            ? $this->workflow->editDraft($input, $actor)
            : $this->workflow->createDraft($input, $actor);
    }

    public function open(int $quoteId, array $actor): ?array
    {
        return $this->workflow->open($quoteId, $actor);
    }

    public function submit(int $quoteId, array $actor, string $reason = ''): array
    {
        return $this->workflow->transition($quoteId, 'pending_approval', $actor, $reason);
    }

    private function resolvePrice(string $model, float $quantity, string $customerLevel, array $configuration): array
    {
        $policy = $this->repository->pricePolicy($model);
        $unitCost = (float)($configuration['pricing']['cost'] ?? 0);
        $unitPrice = (float)($configuration['pricing']['suggested_price'] ?? 0);
        $source = 'configuration_suggested';
        $moq = (float)($configuration['moq'] ?? 1);
        $lead = (int)($configuration['lead_time_days'] ?? 0);
        $policyId = 0;
        if ($policy !== null) {
            $policyId = (int)$policy['id'];
            $unitCost = (float)($policy['base_cost'] ?: $policy['bom_cost_rmb'] ?: $unitCost);
            $unitPrice = (float)($policy['base_price'] ?: $policy['estimated_sale_price_rmb'] ?: $unitPrice);
            $source = 'standard_price';
            $moq = max($moq, (float)$policy['moq']);
            $lead = max($lead, (int)$policy['lead_time']);
            $multiplier = $this->repository->customerLevelMultiplier($customerLevel);
            if ($multiplier !== null && $multiplier > 0) {
                $unitPrice *= $multiplier;
                $source = 'customer_level';
            } else {
                $tier = $this->repository->tierPrice($policyId, $quantity);
                if ($tier !== null) {
                    $unitPrice = (float)($tier['final_price'] ?: $tier['manual_price'] ?: $tier['auto_price']);
                    $source = 'quantity_tier';
                }
            }
        }
        return [
            'policy_id' => $policyId,
            'source' => $source,
            'unit_price' => round(max(0, $unitPrice), 4),
            'unit_cost' => round(max(0, $unitCost), 4),
            'currency' => (string)($policy['currency'] ?? $configuration['pricing']['currency'] ?? 'USD'),
            'moq' => $moq,
            'lead_time_days' => $lead,
        ];
    }

    private function commission(int $customerId, string $model, string $category, float $lineAmount): array
    {
        $rules = $this->repository->commissionRules($customerId, $model, $category);
        $rule = $rules[0] ?? null;
        if (!is_array($rule)) {
            return ['required' => false, 'estimated_amount' => 0, 'rule_id' => null];
        }
        $value = (float)$rule['commission_value'];
        $amount = (string)$rule['commission_mode'] === 'fixed' ? $value : $lineAmount * $value / 100;
        return [
            'required' => true,
            'estimated_amount' => round(max(0, $amount), 4),
            'rule_id' => (int)$rule['id'],
            'rule_name' => (string)$rule['rule_name'],
            'settle_node' => (string)$rule['settle_node'],
        ];
    }
}
