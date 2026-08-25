<?php

namespace App\Models;

use App\Enums\LogisticsStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_no', 'customer_id', 'status', 'currency', 'exchange_rate',
        'total_amount', 'paid_amount', 'shipping_address', 'tracking_no',
        'logistics_status', 'paid_at', 'shipped_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'logistics_status' => LogisticsStatus::class,
            'exchange_rate' => 'decimal:4',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'shipping_address' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('created_at');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
