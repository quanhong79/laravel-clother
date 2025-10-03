<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class Promotion extends Model
{
    protected $fillable = [
        'product_id',
        'discount_percentage',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
        'discount_percentage' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Scope lấy khuyến mãi còn hiệu lực
    public function scopeActive(Builder $query): Builder
    {
        $now = now();
        return $query->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now);
    }

    // Thuộc tính tiện dụng
    public function getIsActiveAttribute(): bool
    {
        $now = now();
        return $this->start_date <= $now && $this->end_date >= $now;
    }

    // Tính giá sau giảm cho 1 đơn giá bất kỳ
    public function applyToPrice(float $originalPrice): float
    {
        $rate = max(0, min(100, (float)$this->discount_percentage)) / 100;
        return round($originalPrice * (1 - $rate), 2);
    }
}
