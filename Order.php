<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders'; // bảng trong DB

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'address',
        'total', 'total_amount', 'status', 'payment_method',
    ];

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
public function payments(){ return $this->hasMany(Payment::class); }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
