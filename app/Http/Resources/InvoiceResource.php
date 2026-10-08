<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        $returns = $this->relationLoaded('returns')
            ? $this->returns->reject(fn ($return) => $return->workflow_status === 'cancelled')
            : $this->returns()
                ->where(fn ($query) => $query->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'cancelled'))
                ->get();
        $returnedAmount = (float) $returns->sum('total_amount');
        $refundedAmount = (float) $returns->sum('refunded_amount');
        $netAmount = $this->is_complimentary ? 0 : max(0, (float) $this->total_amount - $returnedAmount);
        $netPaid = $this->is_complimentary ? 0 : max(0, (float) $this->paid_amount - $refundedAmount);
        $remainingAmount = max(0, $netAmount - $netPaid);

        return [
            'id'               => $this->id,
            'invoice_number'   => $this->invoice_number,
            'status'           => $this->status,
            'branch' => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'name_ar' => $this->branch->name_ar,
                'phone' => $this->branch->phone,
                'address' => $this->branch->address,
                'address_ar' => $this->branch->address_ar,
            ] : null,

            'customer' => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->name,
            ],

            'salesRepresentative' => [
                'id'   => $this->salesRepresentative?->id,
                'name' => $this->salesRepresentative?->name,
            ],

            // ✅ إضافة الكاشير (الموظف اللي سجل الفاتورة)
            'cashier' => $this->relationLoaded('cashier') && $this->cashier ? [
                'id'          => $this->cashier->id,
                'name'        => $this->cashier->name,
                'name_ar'     => $this->cashier->name_ar,
                'employee_code' => $this->cashier->employee_code,
            ] : null,

            // ✅ إضافة الخزينة
            'treasury' => $this->treasury ? [
                'id'          => $this->treasury->id,
                'name'        => $this->treasury->name,
                'name_ar'     => $this->treasury->name_ar,
                'is_main'     => $this->treasury->is_main,
            ] : null,

            'amounts' => [
                'total'     => $netAmount,
                'paid'      => $netPaid,
                'remaining' => $remainingAmount,
                'extra_charge' => (float) ($this->extra_charge ?? 0),
                'overpaid' => max(0, (float) ($this->cash_received_amount ?? 0) - $netAmount),
            ],

            'total_amount' => $netAmount,
            'original_total_amount' => (float) $this->total_amount,
            'net_amount' => $netAmount,
            'returned_amount' => $returnedAmount,
            'refunded_amount' => $refundedAmount,
            'cash_received_amount' => (float) ($this->cash_received_amount ?? 0),
            'overpaid_amount' => max(0, (float) ($this->cash_received_amount ?? 0) - $netAmount),
            'change_amount' => max(0, (float) ($this->cash_received_amount ?? 0) - $netAmount),
            'return_status' => $returnedAmount <= 0 ? 'none' : ($returnedAmount >= (float) $this->total_amount ? 'full' : 'partial'),
            'journal_entry_id' => $this->journal_entry_id,
            'cogs_journal_entry_id' => $this->cogs_journal_entry_id,
            'commission_journal_entry_id' => $this->commission_journal_entry_id,
            'discount_percentage' => (float) $this->discount_percentage,
            'discount_amount' => (float) $this->discount_amount,
            'extra_charge' => (float) ($this->extra_charge ?? 0),
            'extra_charge_label' => 'بند الزيادات',
            'is_complimentary' => (bool) $this->is_complimentary,
            'commission_rate' => $this->commission_rate_snapshot !== null
                ? (float) $this->commission_rate_snapshot
                : (float) ($this->salesRepresentative?->commission_rate ?? 0),
            'commission_amount' => (float) $this->net_commission_amount,



            'items' => InvoiceItemResource::collection($this->items),

            'payments' => $this->is_complimentary ? [] : InvoicePaymentResource::collection($this->payments),

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
