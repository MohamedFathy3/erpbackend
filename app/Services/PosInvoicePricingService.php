<?php

namespace App\Services;

class PosInvoicePricingService
{
    /**
     * Calculate invoice totals after line and invoice discounts.
     * Complimentary invoices always receive a full invoice-level discount.
     *
     * @param array<int, array{price: int|float|string, quantity: int|float|string, discount_percentage?: int|float|string}> $items
     * @return array{gross_total: float, item_discount_total: float, after_item_discounts: float, invoice_discount_percentage: float, invoice_discount_amount: float, total_discount_amount: float, effective_discount_percentage: float, net_total: float}
     */
    public function calculate(array $items, float $requestedInvoiceDiscountPercentage = 0, bool $isComplimentary = false, float $extraCharge = 0): array
    {
        $grossTotal = collect($items)->sum(fn (array $item): float =>
            (float) $item['price'] * (float) $item['quantity']
        );

        $itemDiscountTotal = collect($items)->sum(function (array $item): float {
            $gross = (float) $item['price'] * (float) $item['quantity'];
            return round($gross * (float) ($item['discount_percentage'] ?? 0) / 100, 2);
        });
        $itemDiscountTotal = round($itemDiscountTotal, 2);
        $afterItemDiscounts = max(0, round($grossTotal - $itemDiscountTotal, 2));
        $invoiceDiscountPercentage = $isComplimentary ? 100.0 : $requestedInvoiceDiscountPercentage;
        $invoiceDiscountAmount = round($afterItemDiscounts * $invoiceDiscountPercentage / 100, 2);
        $totalDiscountAmount = round($itemDiscountTotal + $invoiceDiscountAmount, 2);
        $effectiveDiscountPercentage = $grossTotal > 0
            ? round($totalDiscountAmount / $grossTotal * 100, 2)
            : 0.0;
        $netTotal = round(max(0.0, $afterItemDiscounts - $invoiceDiscountAmount) + max(0.0, $extraCharge), 2);

        return [
            'gross_total' => round($grossTotal, 2),
            'item_discount_total' => $itemDiscountTotal,
            'after_item_discounts' => $afterItemDiscounts,
            'invoice_discount_percentage' => $invoiceDiscountPercentage,
            'invoice_discount_amount' => $invoiceDiscountAmount,
            'total_discount_amount' => $totalDiscountAmount,
            'effective_discount_percentage' => $effectiveDiscountPercentage,
            'net_total' => $netTotal,
            'extra_charge' => round(max(0.0, $extraCharge), 2),
        ];
    }
}
