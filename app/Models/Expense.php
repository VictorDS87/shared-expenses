<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $fillable = [
        'description', 'supplier', 'expense_date', 'amount_minor', 'currency',
        'brl_amount_minor', 'status', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'amount_minor' => 'integer',
            'brl_amount_minor' => 'integer',
            'converted_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class)->orderBy('unit_id');
    }
}
