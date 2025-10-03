<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'amount', 'payment_date', 'payment_method'];

    public function order()
    {
        return $this->belongsTo(Orders::class);
    }
}
