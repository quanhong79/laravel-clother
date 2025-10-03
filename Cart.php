<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'carts';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'cart_key',
        'product_id',
        'selected_color',
        'selected_size',
        'quantity',
        'price',
        'options', // nếu DB có cột JSON 'options'
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'product_id' => 'integer',
        'quantity'   => 'integer',
        // Mở dòng dưới nếu bạn có cột JSON 'options'
        // 'options'     => 'array',
    ];

    /** Quan hệ */
    public function user()    { return $this->belongsTo(User::class); }
    public function product() { return $this->belongsTo(Product::class); }

    /**
     * Scope: ràng buộc chủ sở hữu giỏ (user hoặc guest)
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @param string|int $ownerKey  user_id (khi login) hoặc cart_key (khi guest)
     * @param bool $isUser          true nếu là user đăng nhập
     */
    public function scopeForOwner($q, $ownerKey, bool $isUser)
    {
        if ($isUser) {
            $q->where('user_id', (int) $ownerKey)
              ->whereNull('cart_key');
        } else {
            $q->where('cart_key', (string) $ownerKey)
              ->whereNull('user_id');
        }
        return $q;
    }

    /**
     * Scope: gộp item theo biến thể (color/size) + chủ sở hữu + sản phẩm
     * NULL-safe và ép string để so sánh nhất quán.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @param string|int $ownerKey
     * @param bool $isUser
     * @param int $productId
     * @param string|null $color
     * @param string|null $size
     */
    public function scopeForVariant($q, $ownerKey, bool $isUser, int $productId, $color = null, $size = null)
    {
        $q->where('product_id', (int) $productId)
          ->forOwner($ownerKey, $isUser);

        $color = ($color !== null && $color !== '') ? (string) $color : null;
        $size  = ($size  !== null && $size  !== '') ? (string) $size  : null;

        if ($color !== null) { $q->where('selected_color', $color); }
        else                 { $q->whereNull('selected_color'); }

        if ($size !== null)  { $q->where('selected_size', $size); }
        else                 { $q->whereNull('selected_size'); }

        return $q;
    }

    /** Mutators: bảo đảm lưu dưới dạng string hoặc null */
    public function setSelectedColorAttribute($value)
    {
        $this->attributes['selected_color'] =
            ($value !== null && $value !== '') ? (string) $value : null;
    }

    public function setSelectedSizeAttribute($value)
    {
        $this->attributes['selected_size'] =
            ($value !== null && $value !== '') ? (string) $value : null;
    }
}
