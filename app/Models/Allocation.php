<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Allocation extends Model
{
    protected $fillable = ['unit_id', 'percentage_bps', 'amount_minor', 'brl_amount_minor'];

    protected function casts(): array
    {
        return ['percentage_bps' => 'integer', 'amount_minor' => 'integer', 'brl_amount_minor' => 'integer'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
