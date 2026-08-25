<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'currency', 'rate_to_cny',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_cny' => 'decimal:4',
        ];
    }
}
