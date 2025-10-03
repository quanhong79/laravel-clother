<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function __construct()
    {
        // Bắt buộc phải đăng nhập mới thao tác giỏ
        $this->middleware('auth');
    }

    /**
     * Hiển thị giỏ hàng
     */
    public function index()
{
    $userId = Auth::id();

    $items = DB::table('cart_items')
        ->join('products', 'cart_items.product_id', '=', 'products.id')
        ->select(
                'cart_items.id as db_id',
                'cart_items.quantity',
                'products.id as product_id',
                'products.name',
                'products.price',
                'products.image'
            )
        ->where('cart_items.user_id', $userId)
        ->get()
        ->map(function ($row) {
            return [
                'db_id'    => $row->db_id,
                'product_id' => $row->product_id ?? null,
                'name'     => $row->name,
                'price'    => (float) $row->price,
                'image'    => $row->image ? asset('storage/'.$row->image) : asset('images/placeholder.png'),
                'quantity' => (int) $row->quantity,
            ];
        })
        ->toArray();

    $subtotal = collect($items)->sum(fn($it) => $it['price'] * $it['quantity']);

    return view('cart.index', [
        'cart'     => $items,      // 👈 truyền đúng key
        'subtotal' => $subtotal,
        'discount' => 0,
        'shipping' => 0,
        'total'    => $subtotal,
    ]);
}
    /**
     * Thêm sản phẩm vào giỏ
     */
    public function add(Request $request, Product $product)
{
    $qty    = max(1, (int) $request->input('qty', 1));
    $color  = $request->input('color'); // có thể null
    $size   = $request->input('size');  // có thể null
    $userId = Auth::id();

    $item = DB::table('cart_items')
        ->where('user_id', $userId)
        ->where('product_id', $product->id)
        ->first();

    if ($item) {
        DB::table('cart_items')
            ->where('id', $item->id)
            ->update([
                'quantity'   => $item->quantity + $qty,
                'updated_at' => now(),
            ]);
    } else {
        DB::table('cart_items')->insert([
            'user_id'    => $userId,
            'product_id' => $product->id,
            'quantity'   => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $cartCount = DB::table('cart_items')
        ->where('user_id', $userId)
        ->sum('quantity');

    if ($request->expectsJson()) {
        return response()->json([
            'ok'         => true,
            'cart_count' => $cartCount,
            'message'    => 'Đã thêm vào giỏ hàng.'
        ]);
    }

    return back()->with('success', 'Đã thêm vào giỏ hàng.');
}

    /**
     * Cập nhật số lượng
     */
    public function update(Request $request, int $id)
{
    $qty = (int) $request->input('qty', 1);
    if ($qty <= 0) {
        return back()->with('error', 'Số lượng không hợp lệ.');
    }

    DB::table('cart_items')
        ->where('id', $id)
        ->where('user_id', Auth::id())
        ->update([
            'quantity'   => $qty,
            'updated_at' => now(),
        ]);

    return redirect()->route('cart.index')
        ->with('success', 'Đã cập nhật số lượng sản phẩm.');
}


    /**
     * Xóa 1 sản phẩm khỏi giỏ
     */
    public function remove(int $id)
    {
        DB::table('cart_items')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->delete();

        return back()->with('success', 'Đã xóa sản phẩm khỏi giỏ');
    }

    /**
     * Làm trống giỏ
     */
    public function clear()
    {
        DB::table('cart_items')
            ->where('user_id', Auth::id())
            ->delete();

        return back()->with('success', 'Đã làm trống giỏ hàng');
    }
}
