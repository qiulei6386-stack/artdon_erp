<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\QuoteRepository;

final class QuoteService
{
    private const TYPES = ['website_order', 'standard_product', 'custom_product'];
    private const EDIT_MODES = [
        'website_order' => 'locked',
        'standard_product' => 'semi_free',
        'custom_product' => 'free',
    ];

    private QuoteRepository $repository;
    private QuoteAmountCalculator $calculator;
    private QuoteNumberService $numberService;

    public function __construct(
        ?QuoteRepository $repository = null,
        ?QuoteAmountCalculator $calculator = null,
        ?QuoteNumberService $numberService = null
    ) {
        $this->repository = $repository ?? new QuoteRepository();
        $this->calculator = $calculator ?? new QuoteAmountCalculator();
        $this->numberService = $numberService ?? new QuoteNumberService();
    }

    public function saveDraft(array $input, int $actorUserId = 0): array
    {
        $quote = $this->normalizeQuote($input);
        if ((int)$quote['id'] === 0) {
            $quote['quote_no'] = $this->numberService->next();
        }
        $amounts = $this->calculator->calculate($quote['items'], $quote);
        return $this->repository->saveDraft($quote, $amounts, $actorUserId);
    }

    public function open(int $quoteId): ?array
    {
        return $this->repository->find($quoteId);
    }

    public function openLegacy(int $legacyId): ?array
    {
        return $this->repository->findLegacy($legacyId);
    }

    private function normalizeQuote(array $input): array
    {
        $type = trim((string)($input['quote_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('不支持的报价类型。');
        }
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USD')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('币种必须是三位字母代码。');
        }
        $items = $input['items'] ?? [];
        if (!is_array($items)) {
            throw new \InvalidArgumentException('报价明细格式无效。');
        }
        if ($type === 'website_order') {
            if (trim((string)($input['source_order_no'] ?? '')) === '') {
                throw new \InvalidArgumentException('网站订单报价必须保存来源订单号。');
            }
            if ($this->arrayValue($input['source_snapshot'] ?? []) === []) {
                throw new \InvalidArgumentException('网站订单报价必须保存来源订单快照。');
            }
        }
        $normalizedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('报价明细格式无效。');
            }
            $sourceSnapshot = $this->arrayValue($item['source_line_snapshot'] ?? []);
            $locked = $type === 'website_order' ? 1 : (int)!empty($item['locked']);
            if ($type === 'website_order' && $sourceSnapshot === []) {
                throw new \InvalidArgumentException('网站订单报价明细必须保存来源行快照。');
            }
            $normalizedItems[] = [
                'item_type' => $this->text($item['item_type'] ?? $type, 30),
                'legacy_product_id' => $this->nullableInt($item['legacy_product_id'] ?? null),
                'inventory_sku_id' => $this->nullableInt($item['inventory_sku_id'] ?? null),
                'description' => $this->requiredText($item['description'] ?? $item['product_name'] ?? '', 500, '产品描述'),
                'configuration_snapshot' => $this->arrayValue($item['configuration_snapshot'] ?? []),
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0,
                'unit_cost' => $item['unit_cost'] ?? $item['cost_amount'] ?? 0,
                'discount_rate' => $item['discount_rate'] ?? 0,
                'sort_order' => (int)($item['sort_order'] ?? $index),
                'product_source' => $this->text($item['product_source'] ?? 'manual', 40),
                'sku_code' => $this->nullableText($item['sku_code'] ?? null, 120),
                'model_no' => $this->nullableText($item['model_no'] ?? null, 190),
                'product_name' => $this->nullableText($item['product_name'] ?? null, 500),
                'image_path' => $this->nullableText($item['image_path'] ?? null, 500),
                'unit' => $this->text($item['unit'] ?? 'PCS', 40),
                'lead_time' => $this->nullableText($item['lead_time'] ?? null, 190),
                'customer_note' => $this->nullableText($item['customer_note'] ?? null, 1000),
                'internal_note' => $this->nullableText($item['internal_note'] ?? null, 1000),
                'locked' => $locked,
                'unlock_reason' => $this->nullableText($item['unlock_reason'] ?? null, 500),
                'source_line_snapshot' => $sourceSnapshot,
                'custom_fields' => $this->arrayValue($item['custom_fields'] ?? []),
                'reference_product_id' => $this->nullableInt($item['reference_product_id'] ?? null),
            ];
        }
        $customer = $this->arrayValue($input['customer_snapshot'] ?? []);
        return [
            'id' => max(0, (int)($input['id'] ?? 0)),
            'quote_no' => $this->text($input['quote_no'] ?? '', 80),
            'quote_type' => $type,
            'legacy_customer_id' => $this->nullableInt($input['legacy_customer_id'] ?? null),
            'customer_snapshot' => $customer,
            'currency' => $currency,
            'language' => $this->text($input['language'] ?? 'en', 10),
            'source_type' => $this->text($input['source_type'] ?? ($type === 'website_order' ? 'website_order' : 'manual'), 40),
            'source_order_no' => $this->nullableText($input['source_order_no'] ?? null, 120),
            'source_snapshot' => $this->arrayValue($input['source_snapshot'] ?? []),
            'edit_mode' => self::EDIT_MODES[$type],
            'contact_name' => $this->nullableText($input['contact_name'] ?? $customer['contact_name'] ?? null, 190),
            'contact_phone' => $this->nullableText($input['contact_phone'] ?? $customer['phone'] ?? null, 80),
            'contact_email' => $this->nullableText($input['contact_email'] ?? $customer['email'] ?? null, 190),
            'country' => $this->nullableText($input['country'] ?? $customer['country'] ?? null, 120),
            'exchange_rate' => $this->positiveNumber($input['exchange_rate'] ?? 1, '汇率'),
            'quote_date' => $this->date($input['quote_date'] ?? date('Y-m-d'), false),
            'valid_until' => $this->date($input['valid_until'] ?? null, true),
            'owner_legacy_user_id' => $this->nullableInt($input['owner_legacy_user_id'] ?? null),
            'owner_name' => $this->nullableText($input['owner_name'] ?? null, 190),
            'payment_terms' => $this->nullableText($input['payment_terms'] ?? null, 500),
            'trade_terms' => $this->nullableText($input['trade_terms'] ?? null, 190),
            'price_template_id' => $this->nullableInt($input['price_template_id'] ?? null),
            'project_ref' => $this->nullableText($input['project_ref'] ?? null, 190),
            'discount_amount' => $input['discount_amount'] ?? 0,
            'shipping_amount' => $input['shipping_amount'] ?? 0,
            'tax_amount' => $input['tax_amount'] ?? 0,
            'other_amount' => $input['other_amount'] ?? 0,
            'commission_amount' => $input['commission_amount'] ?? 0,
            'customer_note' => $this->nullableText($input['customer_note'] ?? null, 10000),
            'internal_note' => $this->nullableText($input['internal_note'] ?? null, 10000),
            'template_version' => $this->text($input['template_version'] ?? 'legacy_v1', 40),
            'request_id' => $this->nullableText($input['request_id'] ?? null, 36),
            'is_test' => (int)!empty($input['is_test']),
            'items' => $normalizedItems,
        ];
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function requiredText(mixed $value, int $length, string $label): string
    {
        $text = $this->text($value, $length);
        if ($text === '') {
            throw new \InvalidArgumentException($label . '不能为空。');
        }
        return $text;
    }

    private function text(mixed $value, int $length): string
    {
        $text = trim((string)$value);
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }

    private function nullableText(mixed $value, int $length): ?string
    {
        $text = $this->text($value ?? '', $length);
        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = (int)$value;
        return $integer > 0 ? $integer : null;
    }

    private function positiveNumber(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float)$value <= 0) {
            throw new \InvalidArgumentException($label . '必须大于 0。');
        }
        return round((float)$value, 8);
    }

    private function date(mixed $value, bool $nullable): ?string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '' && $nullable) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException('日期格式必须为 YYYY-MM-DD。');
        }
        return $text;
    }
}
