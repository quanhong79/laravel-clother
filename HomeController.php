<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        // Lấy 4 sản phẩm bán chạy theo order_items.quantity
        $topIds = DB::table('order_items')
            ->select('product_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_id')
            ->orderByDesc('qty')
            ->limit(4)
            ->pluck('product_id')
            ->toArray();

        // Nếu chưa đủ 4 thì bù bằng sp mới nhất
        if (count($topIds) < 4) {
            $need = 4 - count($topIds);
            $more = Product::query()
                ->when(!empty($topIds), fn($q) => $q->whereNotIn('id', $topIds))
                ->latest('id')
                ->limit($need)
                ->pluck('id')
                ->toArray();
            $topIds = array_merge($topIds, $more);
        }

        // Lấy danh sách theo thứ tự $topIds
        $topSellers = Product::whereIn('id', $topIds)->get()
            ->sortBy(fn($p) => array_search($p->id, $topIds))
            ->values();

        // 4 sản phẩm khác (không trùng top)
        $otherProducts = Product::query()
            ->when(!empty($topIds), fn($q) => $q->whereNotIn('id', $topIds))
            ->latest('id')
            ->limit(4)
            ->get();

        // Alias để các view cũ (đang dùng $products) không vỡ
        $products  = $otherProducts;
        $brandText = 'Eddie'; // view của bạn có dùng biến này

        return view('welcome', compact('topSellers', 'otherProducts', 'products', 'brandText'));
    }
}
