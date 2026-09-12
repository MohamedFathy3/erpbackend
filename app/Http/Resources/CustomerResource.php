<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_number' => $this->tax_number,
            'industry' => $this->industry,
            'credit_limit' => (float) ($this->credit_limit ?? 0),
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'point' => $this->point, // أي نقاط يدوية موجودة
            'active' => $this->active,
            'last_paid_amount' => $this->last_paid_amount,
            'total_purchases' => (float) $this->invoices()->sum('total_amount')
                + (float) $this->salesInvoices()->sum('net_total')
                - (float) $this->salesReturns()->sum('sales_invoice_returns.total_amount'),
            'outstanding_balance' => (float) $this->invoices()->sum('remaining_amount')
                + (float) $this->salesInvoices()->sum('net_total')
                - (float) $this->salesReturns()->sum('sales_invoice_returns.total_amount'),
            'created_at'      => $this->created_at?->format('Y-m-d H:i:s'),
            // 'total_invoices_amount' => $this->total_invoices_amount,
            // 'loyalty_points' => $this->loyalty_points, // النقاط المحسوبة تلقائياً
        ];
    }
}
