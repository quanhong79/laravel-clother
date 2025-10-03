<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    /**
     * Schema đồng bộ:
     * - size_mode: 'none' | 'apparel' | 'shoes'
     * - sizes: array (string)      // ví dụ ['S','M','L'] hoặc ['38','39','40']
     * - colors: array (string)     // ví dụ ['Đen','Trắng']
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'quantity',
        'category_id',
        'image',
        'size_mode',
        'sizes',
        'colors',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'quantity'    => 'integer',
        'category_id' => 'integer',
        'sizes'       => 'array',
        'colors'      => 'array',
    ];

    /* ----------------- Relationships ----------------- */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort')->orderBy('id');
    }
    public function firstImage()
{
    return $this->hasOne(\App\Models\ProductImage::class)->orderBy('sort');
}

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews()
    {
        return $this->hasMany(Review::class)->where('status', 'approved');
    }

    /* ----------------- Computed / Helpers ----------------- */
    public function getImageUrlAttribute(): string
    {
        return $this->image
            ? asset('storage/'.$this->image)
            : asset('images/no-image.png');
    }

    public function averageRating()
    {
        return $this->approvedReviews()->avg('rating') ?? 0;
    }

    /* ----------------- Mutators (chuẩn hoá dữ liệu) ----------------- */

    public function setSizeModeAttribute($value): void
    {
        $val = in_array($value, ['none','apparel','shoes'], true) ? $value : 'none';
        $this->attributes['size_mode'] = $val;

        // Nếu chuyển về 'none' thì xoá sizes
        if ($val === 'none') {
            $this->attributes['sizes'] = null;
        }
    }

    public function setSizesAttribute($value): void
    {
        // Cho phép null/array; luôn lưu mảng string đã chuẩn hoá
        $arr = is_array($value) ? $value : [];

        // Trim + loại rỗng + unique
        $arr = array_values(array_unique(array_filter(array_map(function ($v) {
            return trim((string)$v);
        }, $arr), fn($v) => $v !== '')));

        // Chuẩn theo mode hiện tại
        $mode = $this->size_mode ?? $this->attributes['size_mode'] ?? 'none';

        if ($mode === 'apparel') {
            // Giữ các size áo quần thông dụng, upper-case
            $allowed = ['XS','S','M','L','XL','XXL','XXXL'];
            $arr = array_values(array_filter(array_map('strtoupper', $arr), function ($s) use ($allowed) {
                return in_array($s, $allowed, true);
            }));
        } elseif ($mode === 'shoes') {
            // Chỉ nhận số 30..50, lưu dạng string
            $arr = array_values(array_filter(array_map(function ($s) {
                $n = (int)$s;
                return ($n >= 30 && $n <= 50) ? (string)$n : null;
            }, $arr)));
            sort($arr, SORT_NATURAL);
        } else { // none
            $arr = [];
        }

        $this->attributes['sizes'] = !empty($arr) ? json_encode($arr, JSON_UNESCAPED_UNICODE) : null;
    }

    public function setColorsAttribute($value): void
    {
        $arr = is_array($value) ? $value : [];
        // Trim + loại rỗng + unique (giữ nguyên hoa/thường, dấu tiếng Việt)
        $arr = array_values(array_unique(array_filter(array_map(function ($v) {
            return trim((string)$v);
        }, $arr), fn($v) => $v !== '')));

        $this->attributes['colors'] = !empty($arr) ? json_encode($arr, JSON_UNESCAPED_UNICODE) : null;
    }
}
