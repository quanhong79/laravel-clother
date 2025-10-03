<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Danh sách sản phẩm (Admin)
     */
    public function index(Request $request)
{
    $q          = trim((string) $request->get('q', ''));
    $categoryId = $request->get('category_id');

    $products = Product::query()
        ->with('category')
        ->withCount('images')
        ->when($q !== '', fn($qr) => $qr->where('name', 'like', "%{$q}%"))
        ->when(
            is_numeric($categoryId) && (int)$categoryId > 0,
            fn($qr) => $qr->where('category_id', (int)$categoryId)
        )
        ->latest('id')
        ->paginate(20)
        ->appends($request->query());

    $categories = Category::orderBy('name')->get(['id','name']);

    // Nếu đường dẫn bắt đầu bằng /admin → load view admin
    if ($request->is('admin/*')) {
        return view('admin.product.index', compact('products', 'categories'));
    }

    // Ngược lại → load view user
    return view('product.index', [
        'products'      => $products,
        'showFilter'    => true,
        'allCategories' => Category::orderBy('name')->get(['id','name','slug']),
    ]);
}


    /**
     * Form tạo sản phẩm (Admin)
     */
    public function create()
    {
        $parents = Category::with('children')
            ->whereNull('parent_id')
            ->get();

        return view('admin.product.create', compact('parents'));
    }

    /**
     * Lưu sản phẩm mới (Admin)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'quantity'    => 'required|integer|min:0',
            'category_id' => 'nullable|exists:categories,id',

            // Ảnh đại diện
            'image'       => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',

            // Biến thể
            'size_mode'   => 'required|in:none,apparel,shoes',
            'sizes'       => 'nullable|array',
            'sizes.*'     => 'string|max:10',
            'colors'      => 'nullable|array',
            'colors.*'    => 'string|max:50',

            // Gallery (tối đa 4)
            'gallery'     => 'nullable|array|max:4',
            'gallery.*'   => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Colors
        if (array_key_exists('colors', $data)) {
            $colors = array_map(fn($c) => trim((string)$c), $data['colors'] ?? []);
            $colors = array_values(array_unique(array_filter($colors, fn($c) => $c !== '')));
            $data['colors'] = $colors ?: null;
        }

        // Sizes theo mode
        $mode  = $data['size_mode'] ?? 'none';
        $sizes = array_map(fn($s) => trim((string)$s), $data['sizes'] ?? []);

        if ($mode === 'apparel') {
            $allowed = ['XS','S','M','L','XL','XXL','XXXL'];
            $sizes   = array_values(array_unique(array_filter($sizes, fn($s) => in_array(strtoupper($s), $allowed, true))));
            $sizes   = array_map('strtoupper', $sizes);
            $data['sizes'] = $sizes ?: null;
        } elseif ($mode === 'shoes') {
            $sizes = array_values(array_unique(array_filter($sizes, function ($s) {
                $n = (int)$s;
                return $n >= 30 && $n <= 50;
            })));
            $sizes = array_map(fn($s) => (string)((int)$s), $sizes);
            sort($sizes, SORT_NATURAL);
            $data['sizes'] = $sizes ?: null;
        } else {
            $data['sizes'] = null;
        }

        // Ảnh đại diện
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        // Gallery
        if ($request->hasFile('gallery')) {
            foreach (array_slice($request->file('gallery'), 0, 4) as $i => $file) {
                $path = $file->store('product_images', 'public');
                $product->images()->create(['path' => $path, 'sort' => $i]);
            }
        }

        return redirect()->route('admin.products.index')->with('success', 'Đã thêm sản phẩm.');
    }

    /**
     * Hiển thị chi tiết (Public)
     * (Có thể dùng từ admin.products.show để xem nhanh bản public)
     */
    public function show(Product $product)
{
    // nạp quan hệ cần thiết
    $product->load(['category', 'images']);

    // danh sách review đã duyệt + user, phân trang
    $reviews = \App\Models\Review::with('user:id,name')
        ->where('product_id', $product->id)
        ->where('status', 'approved') // đổi nếu bạn dùng trạng thái khác
        ->latest()
        ->paginate(8)
        ->appends(request()->query());

    // điểm trung bình
    $avgRating = \App\Models\Review::where('product_id', $product->id)
        ->where('status', 'approved')
        ->avg('rating');

    return view('product.show', compact('product', 'reviews', 'avgRating'));
}

    /**
     * Form chỉnh sửa (Admin)
     */
    public function edit(Product $product)
    {
        $parents = Category::with('children')
            ->whereNull('parent_id')
            ->get();

        return view('admin.product.edit', compact('product','parents'));
    }

    /**
     * Cập nhật (Admin)
     */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'quantity'    => 'required|integer|min:0',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',

            // Ảnh đại diện
            'image'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_image' => 'nullable|boolean',

            // Biến thể
            'size_mode'   => 'required|in:none,apparel,shoes',
            'sizes'       => 'nullable|array',
            'sizes.*'     => 'string|max:10',

            // Màu sắc
            'colors'      => 'nullable|array',
            'colors.*'    => 'string|max:50',

            // Gallery
            'gallery'            => 'nullable|array|max:4',
            'gallery.*'          => 'image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_image_ids'   => 'nullable|array',
            'remove_image_ids.*' => 'integer|exists:product_images,id',
        ]);

        // Xoá ảnh đại diện
        if ($request->boolean('remove_image') && $product->image) {
            if (Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = null;
        }

        // Up ảnh đại diện mới
        if ($request->hasFile('image')) {
            if ($product->image && Storage::disk('public')->exists($product->image)) {
                Storage::disk('public')->delete($product->image);
            }
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        // Colors
        if (array_key_exists('colors', $data)) {
            $colors = array_map(fn($c) => trim((string)$c), $data['colors'] ?? []);
            $colors = array_values(array_unique(array_filter($colors, fn($c) => $c !== '')));
            $data['colors'] = $colors ?: null;
        }

        // Sizes theo mode
        $mode  = $data['size_mode'] ?? $product->size_mode ?? 'none';
        $sizes = array_map(fn($s) => trim((string)$s), $data['sizes'] ?? []);

        if ($mode === 'apparel') {
            $allowed = ['XS','S','M','L','XL','XXL','XXXL'];
            $sizes   = array_values(array_unique(array_filter($sizes, fn($s) => in_array(strtoupper($s), $allowed, true))));
            $sizes   = array_map('strtoupper', $sizes);
            $data['sizes'] = $sizes ?: null;
        } elseif ($mode === 'shoes') {
            $sizes = array_values(array_unique(array_filter($sizes, function ($s) {
                $n = (int)$s;
                return $n >= 30 && $n <= 50;
            })));
            $sizes = array_map(fn($s) => (string)((int)$s), $sizes);
            sort($sizes, SORT_NATURAL);
            $data['sizes'] = $sizes ?: null;
        } else {
            $data['sizes'] = null;
        }

        // Cập nhật
        $product->update($data);

        // Xoá ảnh gallery chọn
        if (!empty($data['remove_image_ids'])) {
            $imgs = $product->images()
                ->whereIn('id', $data['remove_image_ids'])
                ->get();

            foreach ($imgs as $img) {
                if ($img->path && Storage::disk('public')->exists($img->path)) {
                    Storage::disk('public')->delete($img->path);
                }
                $img->delete();
            }
        }

        // Thêm ảnh gallery mới (tối đa 4 tổng)
        if ($request->hasFile('gallery')) {
            $current = $product->images()->count();
            $canAdd  = max(0, 4 - $current);

            foreach (array_slice($request->file('gallery'), 0, $canAdd) as $i => $file) {
                $path = $file->store('product_images', 'public');
                $product->images()->create([
                    'path' => $path,
                    'sort' => $current + $i,
                ]);
            }
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Cập nhật sản phẩm thành công!');
    }

    /**
     * Xoá sản phẩm (Admin)
     */
    public function destroy(Product $product)
    {
        if ($product->image && Storage::disk('public')->exists($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        foreach ($product->images as $img) {
            if ($img->path && Storage::disk('public')->exists($img->path)) {
                Storage::disk('public')->delete($img->path);
            }
            $img->delete();
        }

        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Đã xoá sản phẩm.');
    }

    /**
     * Danh sách public (lọc/sort + giữ query)
     */
    public function publicIndex(Request $request)
    {
        $q    = trim((string) $request->get('q', ''));
        $sort = $request->get('sort', 'new'); // new | price_asc | price_desc
        $min  = $request->get('min');
        $max  = $request->get('max');

        $products = Product::with(['category','images']);

        if ($q !== '') {
            $products->where('name', 'like', "%{$q}%");
        }
        if (is_numeric($min)) {
            $products->where('price', '>=', (float)$min);
        }
        if (is_numeric($max)) {
            $products->where('price', '<=', (float)$max);
        }

        match ($sort) {
            'price_asc'  => $products->orderBy('price', 'asc'),
            'price_desc' => $products->orderBy('price', 'desc'),
            default      => $products->latest('id'),
        };

        $products = $products->paginate(12)->appends($request->query());

        return view('product.index', compact('products'));
    }
    
}
