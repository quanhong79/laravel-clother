<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class CheckoutController extends Controller
{
    /**
     * Trang chọn phương thức + tóm tắt giỏ.
     */
    public function index(Request $request)
    {
        // Đảm bảo có cart_key cho guest (không ảnh hưởng user đã login)
        $this->ensureGuestCartKey();

        $cart = $this->getUnifiedCart();
        if ($cart->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Giỏ hàng trống.');
        }

        $subTotal   = $this->subtotalFromUnified($cart);
        $shipping   = 0;
        $discount   = 0;
        $grandTotal = max(0, $subTotal - $discount + $shipping);

        return view('checkout.index', compact('cart', 'subTotal', 'shipping', 'discount', 'grandTotal'));
    }

    /**
     * Form chuyển khoản/VietQR nội bộ (nếu bạn muốn có trang riêng).
     */
    public function bankForm()
    {
        return view('checkout.bank');
    }

    /**
     * Thanh toán Chuyển khoản/VietQR nội bộ:
     * - Tạo đơn: payment_status = paid
     * - Ghi payments (nếu có bảng)
     * - Ghi order_items
     * - Xoá giỏ
     */
    public function bankPay(Request $request)
{
    if (!Schema::hasTable('orders')) {
        return back()->with('error', 'Thiếu bảng orders.');
    }

    // nếu bạn chỉ cần 3 trường của chuyển khoản:
    $data = $request->validate([
        'bank_code'    => 'required|string|max:50',
        'payer_name'   => 'required|string|max:120',
        'reference_no' => 'nullable|string|max:100',
        // 'note'       => 'nullable|string|max:1000', // orders hiện không có cột note
    ]);

    $this->ensureGuestCartKey();

    $cart = $this->getUnifiedCart();
    if ($cart->isEmpty()) {
        return redirect()->route('cart.index')->with('error', 'Giỏ hàng trống.');
    }

    $userId    = Auth::id();
    $subtotal  = $this->subtotalFromUnified($cart);
    $orderCode = $this->makeOrderCode();

    DB::transaction(function () use ($userId, $subtotal, $orderCode, $cart, $data) {
        $orderId = DB::table('orders')->insertGetId([
            'user_id'        => $userId,
            'code'           => $orderCode,
            'total'          => $subtotal,                  // DECIMAL(10,2)
            'total_amount'   => (int) round($subtotal),     // BIGINT UNSIGNED
            'status'         => 'pending',
            'payment_method' => 'BANK_MANUAL',
            'payment'        => 'BANK_MANUAL',
            'payment_status' => 'paid',                     // bạn muốn coi là đã trả
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Nếu bạn có bảng payments: lưu chi tiết chuyển khoản ở đó
        if (Schema::hasTable('payments')) {
            DB::table('payments')->insert([
                'order_id'     => $orderId,
                'method'       => 'BANK_MANUAL',
                'bank_code'    => $data['bank_code'],
                'payer_name'   => $data['payer_name'],
                'reference_no' => $data['reference_no'] ?? null,
                'amount'       => (int) round($subtotal),
                'status'       => 'submitted',
                'note'         => null,                      // orders schema chưa có note
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->insertOrderItems($orderId, $cart);
        $this->clearAllCartSources();
    });

    // Nếu CHƯA có route('checkout.thankyou'): đổi về orders.index
    return redirect()->route('orders.index')
        ->with('success', "Thanh toán thành công cho đơn {$orderCode}. Đơn đang chờ duyệt.");
}

    /**
     * Thanh toán khi nhận hàng (COD).
     * - Tạo đơn: payment_status = unpaid
     * - Ghi order_items
     * - Xoá giỏ
     */
    public function cod(Request $request)
{
    $this->ensureGuestCartKey();

    $cart = $this->getUnifiedCart();
    if ($cart->isEmpty()) {
        return back()->with('error', 'Giỏ hàng của bạn đang trống.');
    }

    $userId    = Auth::id();
    $subtotal  = $this->subtotalFromUnified($cart);          // số thực (decimal)
    $orderCode = $this->makeOrderCode();

    DB::transaction(function () use ($userId, $subtotal, $orderCode, $cart) {
        $orderId = DB::table('orders')->insertGetId([
            'user_id'        => $userId,
            'code'           => $orderCode,
            'total'          => $subtotal,                   // DECIMAL(10,2)
            'total_amount'   => (int) round($subtotal),      // BIGINT UNSIGNED (VNĐ)
            'status'         => 'pending',                   // theo schema
            'payment_method' => 'COD',
            'payment'        => 'COD',
            'payment_status' => 'pending',                   // schema default; có thể để 'unpaid' nếu bạn thích
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        foreach ($cart as $it) {
            DB::table('order_items')->insert([
                'order_id'   => $orderId,
                'product_id' => $it['product_id'],
                'quantity'   => $it['qty'],
                'price'      => $it['price'],                // DECIMAL(10,2)
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->clearAllCartSources();
    });

    return redirect()->route('orders.index')->with('success', 'Đặt hàng thành công!');
}


    /* ============================ Helpers ============================ */

    /**
     * Lấy giỏ hàng hợp nhất theo thứ tự ưu tiên:
     * 1) Bảng carts (user/guest)
     * 2) Bảng cart_items (user)
     * 3) session('cart')
     * Trả về Collection các item: product_id, qty, price, name, image, options
     */
    private function getUnifiedCart(): Collection
    {
        $userId  = Auth::id();
        $cartKey = Cookie::get('cart_key');

        // 1) carts (ưu tiên)
        if (Schema::hasTable('carts')) {
            $rows = DB::table('carts')
                ->join('products', 'products.id', '=', 'carts.product_id')
                ->when($userId,  fn($q) => $q->where('carts.user_id', $userId)->whereNull('carts.cart_key'))
                ->when(!$userId && $cartKey, fn($q) => $q->where('carts.cart_key', $cartKey)->whereNull('carts.user_id'))
                ->select(
                    'carts.product_id',
                    'carts.quantity',
                    'carts.price',
                    'carts.options',
                    'products.name',
                    'products.image',
                    'products.price as product_price'
                )
                ->get();

            if ($rows->isNotEmpty()) {
                return $rows->map(function ($r) {
                    return [
                        'product_id' => (int) $r->product_id,
                        'qty'        => (int) $r->quantity,
                        'price'      => (float) ($r->price ?? $r->product_price),
                        'name'       => $r->name ?? 'Sản phẩm',
                        'image'      => $r->image ?? null,
                        'options'    => $r->options ?? null,
                    ];
                });
            }
        }

        // 2) cart_items (nhiều code cũ dùng cho user login)
        if ($userId && Schema::hasTable('cart_items')) {
            $rows2 = DB::table('cart_items')
                ->join('products', 'cart_items.product_id', '=', 'products.id')
                ->select(
                    'cart_items.product_id',
                    'cart_items.quantity',
                    'products.price as product_price',
                    'products.name',
                    'products.image'
                )
                ->where('cart_items.user_id', $userId)
                ->get();

            if ($rows2->isNotEmpty()) {
                return $rows2->map(function ($r) {
                    return [
                        'product_id' => (int) $r->product_id,
                        'qty'        => (int) $r->quantity,
                        'price'      => (float) $r->product_price,
                        'name'       => $r->name ?? 'Sản phẩm',
                        'image'      => $r->image ?? null,
                        'options'    => null,
                    ];
                });
            }
        }

        // 3) session('cart') (giỏ dạng mảng)
        $sess = collect(session('cart', []));
        if ($sess->isNotEmpty()) {
            return $sess->map(function ($i) {
                return [
                    'product_id' => (int) ($i['product_id'] ?? $i['id'] ?? 0),
                    'qty'        => (int) ($i['qty'] ?? 1),
                    'price'      => (float) ($i['price'] ?? 0),
                    'name'       => $i['name'] ?? 'Sản phẩm',
                    'image'      => $i['image'] ?? null,
                    'options'    => $i['options'] ?? null,
                ];
            })->values();
        }

        return collect();
    }

    /**
     * Tính tổng tiền từ giỏ hợp nhất.
     */
    private function subtotalFromUnified(Collection $cart): float
    {
        return (float) $cart->sum(fn ($i) => (float) $i['price'] * (int) $i['qty']);
    }

    /**
     * Ghi order_items từ giỏ hợp nhất.
     */
    private function insertOrderItems(int $orderId, Collection $cart): void
    {
        if (!Schema::hasTable('order_items')) return;

        $batch = [];
        foreach ($cart as $it) {
            $batch[] = [
                'order_id'   => $orderId,
                'product_id' => $it['product_id'],
                'quantity'   => $it['qty'],
                'price'      => $it['price'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($batch)) {
            DB::table('order_items')->insert($batch);
        }
    }

    /**
     * Xoá giỏ từ mọi nguồn (carts, cart_items, session).
     */
    private function clearAllCartSources(): void
    {
        $userId  = Auth::id();
        $cartKey = Cookie::get('cart_key');

        if (Schema::hasTable('carts')) {
            DB::table('carts')
                ->when($userId,  fn($q) => $q->where('user_id', $userId)->whereNull('cart_key'))
                ->when(!$userId && $cartKey, fn($q) => $q->where('cart_key', $cartKey)->whereNull('user_id'))
                ->delete();
        }

        if ($userId && Schema::hasTable('cart_items')) {
            DB::table('cart_items')->where('user_id', $userId)->delete();
        }

        session()->forget('cart');
    }

    /**
     * Đảm bảo có cart_key cho guest.
     */
    private function ensureGuestCartKey(): void
    {
        if (!Auth::id() && !Cookie::get('cart_key')) {
            Cookie::queue('cart_key', (string) Str::uuid(), 60 * 24 * 30);
        }
    }

    /**
     * Sinh mã đơn.
     */
    private function makeOrderCode(): string
    {
        return 'OD' . now()->format('ymdHis') . Str::upper(Str::random(4));
    }
}
