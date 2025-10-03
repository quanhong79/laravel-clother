<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Xác định chủ sở hữu giỏ hàng (user hoặc guest).
     */
    private function currentOwner(): array
    {
        if (Auth::check()) {
            return ['type' => 'user', 'key' => Auth::id()];
        }
        return ['type' => 'guest', 'key' => Cookie::get('cart_key')];
    }

    /**
     * Danh sách đơn hàng.
     * - Admin thấy tất cả
     * - User chỉ thấy đơn của chính mình
     */
    public function index()
    {
        $user = Auth::user();

        if ($this->userIsAdmin($user)) {
            $orders = Order::query()
                ->with('user:id,name')
                ->orderByDesc('id')
                ->get();
        } else {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->get();
        }

        return view('orders.index', [
            'orders'  => $orders,
            'isAdmin' => $this->userIsAdmin($user),
            'badge'   => [
                'pending'    => 'badge bg-warning',
                'processing' => 'badge bg-primary',
                'confirmed'  => 'badge bg-success',
                'cancelled'  => 'badge bg-danger',
                'completed'  => 'badge bg-secondary',
            ],
        ]);
    }

    /**
     * Lưu đơn hàng từ giỏ hàng.
     */
    public function store(Request $request)
    {
        $owner = $this->currentOwner();

        $rows = DB::table('carts')
            ->when($owner['type'] === 'user', fn($q) => $q->where('user_id', $owner['key'])->whereNull('cart_key'))
            ->when($owner['type'] === 'guest', fn($q) => $q->where('cart_key', $owner['key'])->whereNull('user_id'))
            ->get();

        if ($rows->isEmpty()) {
            return back()->with('error', 'Giỏ hàng trống.');
        }

        DB::transaction(function () use ($rows, $request, $owner) {
            $subtotal = $rows->sum(fn($it) => (float) $it->price * (int) $it->quantity);

            $order = Order::create([
                'user_id'        => Auth::id(),
                'name'           => $request->name,
                'email'          => $request->email,
                'phone'          => $request->phone,
                'address'        => $request->address,
                'total'          => $subtotal,
                'total_amount'   => $subtotal,
                'status'         => 'processing',
                'payment_method' => $request->payment_method ?? 'COD',
            ]);

            foreach ($rows as $it) {
                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $it->product_id,
                    'price'          => $it->price,
                    'quantity'       => $it->quantity,
                    'selected_color' => $it->selected_color ?? null,
                    'selected_size'  => $it->selected_size ?? null,
                ]);
            }

            DB::table('carts')
                ->when($owner['type'] === 'user', fn($q) => $q->where('user_id', $owner['key'])->whereNull('cart_key'))
                ->when($owner['type'] === 'guest', fn($q) => $q->where('cart_key', $owner['key'])->whereNull('user_id'))
                ->delete();
        });

        return redirect()->route('orders.index')->with('success', 'Đặt hàng thành công!');
    }

    /**
     * Admin cập nhật trạng thái đơn hàng.
     * - Trừ tồn kho 1 lần khi chuyển sang "confirmed" lần đầu.
     */
    public function update(Request $request, Order $order)
    {
        $user = Auth::user();
        if (!$this->userIsAdmin($user)) {
            return redirect()->route('orders.index')->with('error', 'Bạn không có quyền.');
        }

        $data = $request->validate([
            'status' => 'required|in:processing,confirmed,cancelled,completed',
        ]);

        // Nếu lần đầu chuyển sang "confirmed" thì trừ tồn kho
        if ($order->status !== 'confirmed' && $data['status'] === 'confirmed') {
            $order->loadMissing('orderItems.product');

            foreach ($order->orderItems as $item) {
                $product = $item->product;
                if ($product) {
                    // Đảm bảo không âm kho
                    $newQty = max(0, (int)$product->quantity - (int)$item->quantity);
                    $product->update(['quantity' => $newQty]);
                }
            }
        }

        $order->update(['status' => $data['status']]);

        return redirect()->route('orders.index')
            ->with('success', "Đơn hàng #{$order->id} đã chuyển sang trạng thái {$data['status']}.");
    }

    /**
     * Xem chi tiết đơn hàng.
     */
    // app/Http/Controllers/OrderController.php
public function show($id)
{
    $order = \App\Models\Order::with([
        'user',                       // cần có quan hệ user() trong model Order
        'orderItems.product',         // để hiển thị tên SP
    ])->findOrFail($id);

    return view('orders.show', compact('order'));
}


    /**
     * Mua lại từ đơn hàng.
     */
    public function reorder($id)
    {
        $order  = Order::with(['orderItems.product'])->findOrFail($id);
        $userId = Auth::id();

        if ($order->user_id !== $userId) {
            return redirect()->route('orders.index')->with('error', 'Không có quyền.');
        }

        $cart = session('cart', []);

        foreach ($order->orderItems as $item) {
            $productId = $item->product_id;
            $qty       = (int) $item->quantity;
            $price     = (float) $item->price;

            if (isset($cart[$productId])) {
                $cart[$productId]['quantity'] += $qty;
            } else {
                $cart[$productId] = [
                    'quantity' => $qty,
                    'price'    => $price,
                    'name'     => $item->product->name ?? 'Sản phẩm',
                ];
            }
        }

        session(['cart' => $cart]);

        return redirect()->route('cart.index')->with('success', 'Đã thêm lại sản phẩm vào giỏ!');
    }

    /**
     * Xóa đơn hàng (Admin).
     */
    public function destroy(Order $order)
    {
        $user = Auth::user();
        if (!$this->userIsAdmin($user)) {
            return redirect()->route('orders.index')->with('error', 'Bạn không có quyền.');
        }

        $order->delete();
        return redirect()->back()->with('success', "Đơn hàng #{$order->id} đã bị xóa.");
    }

    /* =======================
     * Helpers
     * ======================= */
    private function userIsAdmin($user): bool
    {
        if (!$user) return false;
        $role = strtolower((string)($user->role ?? ''));
        if (in_array($role, ['admin', 'administrator'], true)) return true;
        if (property_exists($user, 'is_admin') && $user->is_admin) return true;
        if (($user->role_id ?? null) === 1) return true;
        return false;
    }
}
