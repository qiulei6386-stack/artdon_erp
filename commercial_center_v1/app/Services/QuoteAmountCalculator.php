<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

final class QuoteAmountCalculator
{
    public function calculate(array $items, array $charges = []): array
    {
        $subtotal = 0.0;
        $totalCost = 0.0;
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('报价明细格式无效。');
            }
            $quantity = $this->nonNegative($item['quantity'] ?? 0, '数量');
            if ($quantity <= 0) {
                throw new \InvalidArgumentException('报价明细数量必须大于 0。');
            }
            $unitPrice = $this->nonNegative($item['unit_price'] ?? 0, '单价');
            $discountRate = $this->rate($item['discount_rate'] ?? 0);
            $unitCost = $this->nonNegative($item['unit_cost'] ?? $item['cost_amount'] ?? 0, '成本');
            $lineAmount = round($quantity * $unitPrice * (1 - $discountRate), 4);
            $lineCost = round($quantity * $unitCost, 4);
            $subtotal += $lineAmount;
            $totalCost += $lineCost;
            $item['quantity'] = $quantity;
            $item['unit_price'] = $unitPrice;
            $item['discount_rate'] = $discountRate;
            $item['unit_cost'] = $unitCost;
            $item['line_amount'] = $lineAmount;
            $item['line_cost'] = $lineCost;
            $item['sort_order'] = (int)($item['sort_order'] ?? $index);
            $normalized[] = $item;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('报价至少需要一条明细。');
        }

        $discount = $this->nonNegative($charges['discount_amount'] ?? 0, '整单折扣');
        $shipping = $this->nonNegative($charges['shipping_amount'] ?? 0, '运费');
        $tax = $this->nonNegative($charges['tax_amount'] ?? 0, '税费');
        $other = $this->nonNegative($charges['other_amount'] ?? 0, '其他费用');
        $commission = $this->nonNegative($charges['commission_amount'] ?? 0, '佣金');
        $total = round(max(0, $subtotal - $discount + $shipping + $tax + $other), 4);
        $grossProfit = round($total - $totalCost - $commission, 4);
        $grossMargin = $total > 0 ? round($grossProfit / $total, 4) : 0.0;

        return [
            'items' => $normalized,
            'subtotal_amount' => round($subtotal, 4),
            'discount_amount' => $discount,
            'shipping_amount' => $shipping,
            'tax_amount' => $tax,
            'other_amount' => $other,
            'commission_amount' => $commission,
            'total_amount' => $total,
            'total_cost' => round($totalCost, 4),
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
        ];
    }

    private function nonNegative(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new \InvalidArgumentException($label . '必须是非负数。');
        }
        return round((float)$value, 4);
    }

    private function rate(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('折扣率格式无效。');
        }
        $rate = (float)$value;
        if ($rate > 1 && $rate <= 100) {
            $rate /= 100;
        }
        if ($rate < 0 || $rate > 1) {
            throw new \InvalidArgumentException('折扣率必须在 0 到 1 之间。');
        }
        return round($rate, 4);
    }
}
