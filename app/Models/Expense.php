<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $fillable = [
        'description', 'supplier', 'expense_date', 'amount_minor', 'currency',
        'brl_amount_minor', 'status', 'exchange_rate', 'exchange_rate_date',
        'exchange_rate_source', 'conversion_error', 'converted_at', 'conversion_version',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount_minor' => 'integer',
            'brl_amount_minor' => 'integer',
            'exchange_rate' => 'decimal:8',
            'exchange_rate_date' => 'date:Y-m-d',
            'converted_at' => 'datetime',
            'conversion_version' => 'integer',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class)->orderBy('unit_id');
    }
}
