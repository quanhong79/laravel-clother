<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Category $category, Request $request)
    {
        $q    = trim((string) $request->get('q', ''));
        $sort = in_array($request->get('sort'), ['new','price_asc','price_desc'], true)
                  ? $request->get('sort')
                  : 'new';
        $min  = $request->get('min');
        $max  = $request->get('max');

        // Thu thập toàn bộ id con (nếu model có quan hệ children)
        $categoryIds = [$category->id];
        if (method_exists($category, 'children')) {
            $stack = $category->children()->get(['id']); // BFS/DFS tuỳ cách bạn thích
            while ($stack->isNotEmpty()) {
                $child = $stack->pop();
                $categoryIds[] = $child->id;
                if (method_exists($child, 'children')) {
                    $stack = $stack->merge($child->children()->get(['id']));
                }
            }
        }

        $query = Product::with(['category','images'])
            ->whereIn('category_id', $categoryIds);

        if ($q !== '')           $query->where('name', 'like', "%{$q}%");
        if (is_numeric($min))    $query->where('price', '>=', (float) $min);
        if (is_numeric($max))    $query->where('price', '<=', (float) $max);

        match ($sort) {
            'price_asc'  => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            default      => $query->latest('id'),
        };

        $products = $query->paginate(12)->appends($request->query());

        // Hiện bộ lọc trên trang danh mục
        return view('product.index', [
            'products'   => $products,
            'category'   => $category,
            'showFilter' => true,   // <-- BẬT filter trên category page
            'categories' => Category::orderBy('name')->get(['id','name','slug']), // (tuỳ chọn) nếu partial cần
        ]);
    }
}
