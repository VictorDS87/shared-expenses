<?php

namespace App\Http\Resources;

use App\Domain\Money\DecimalMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'description' => $this->description, 'supplier' => $this->supplier,
            'date' => $this->expense_date->format('Y-m-d'), 'currency' => $this->currency,
            'amount' => DecimalMoney::format($this->amount_minor), 'amount_minor' => $this->amount_minor,
            'brl_amount' => $this->brl_amount_minor === null ? null : DecimalMoney::format($this->brl_amount_minor),
            'brl_amount_minor' => $this->brl_amount_minor, 'status' => $this->status,
            'converted_at' => $this->converted_at,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($allocation) => [
                'unit_id' => $allocation->unit_id,
                'unit' => $allocation->unit->only('id', 'name', 'company', 'slug'),
                'percentage' => DecimalMoney::format($allocation->percentage_bps),
                'amount_minor' => $allocation->amount_minor,
                'brl_amount_minor' => $allocation->brl_amount_minor,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
