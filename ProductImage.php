<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $fillable = ['product_id','path','sort'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // URL đầy đủ cho ảnh
    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }
}