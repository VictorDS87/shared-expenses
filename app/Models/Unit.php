<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = ['name', 'company', 'slug'];

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
