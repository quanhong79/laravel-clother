<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PromotionController extends Controller
{
    // Hiển thị danh sách khuyến mãi (admin)
    public function index(Request $request)
    {
        // Bộ lọc UI: ?status=active|expired|all
        $status = $request->query('status', 'all');

        $query = Promotion::with('product');

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'expired') {
            $now = now();
            $query->where('end_date', '<', $now);
        }

        $promotions = $query->orderByDesc('id')->paginate(15);

        return view('promotions.index', compact('promotions', 'status'));
    }

    // Form tạo (tùy ý tách riêng create), ở đây ta cũng chuẩn bị dữ liệu
    public function create()
    {
        $products = Product::orderBy('name')->get(['id', 'name', 'price']); // điều chỉnh cột phù hợp schema của bạn
        return view('promotions.create', compact('products'));
    }

    // Lưu khuyến mãi mới (admin)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id'          => ['required', 'exists:products,id'],
            'discount_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'start_date'          => ['required', 'date', 'after_or_equal:now'],
            'end_date'            => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'product_id.exists' => 'Sản phẩm không tồn tại.',
            'discount_percentage.max' => 'Phần trăm giảm phải từ 0 đến 100.',
            'start_date.after_or_equal' => 'Ngày bắt đầu không được trước hiện tại.',
            'end_date.after_or_equal'   => 'Ngày kết thúc phải >= ngày bắt đầu.',
        ]);

        Promotion::create($validated);

        return redirect()
            ->route('promotions.index')
            ->with('success', 'Tạo khuyến mãi thành công.');
    }

    // (Không bắt buộc đề bài, nhưng để có nút Xóa trong UI)
    public function destroy(Promotion $promotion)
    {
        $promotion->delete();
        return back()->with('success', 'Đã xóa khuyến mãi.');
    }

    /**
     * Áp dụng khuyến mãi cho giỏ hàng (session('cart')).
     * Kỳ vọng session('cart') là mảng các item:
     * [
     *   product_id => [
     *       'name' => '...',
     *       'price' => 123.45,
     *       'quantity' => 2,
     *       ... (có thể có image, ...),
     *       // sẽ thêm 'discounted_price' sau khi áp dụng
     *   ],
     *   ...
     * ]
     */
    public function applyPromotion(Request $request)
    {
        // Yêu cầu đăng nhập (đã bảo vệ bằng middleware)
        $cart = session('cart', []);

        if (empty($cart)) {
            return back()->with('error', 'Giỏ hàng trống, không có gì để áp dụng khuyến mãi.');
        }

        $now = now();
        $appliedCount = 0;

        foreach ($cart as $productId => &$item) {
            // Nếu schema cart của bạn không dùng key = product_id,
            // thì thay $productId bằng $item['product_id'].
            $product = Product::find($productId);
            if (!$product) {
                continue;
            }

            // Lấy khuyến mãi hiệu lực tốt nhất
            $best = $product->promotions()
                ->where('start_date', '<=', $now)
                ->where('end_date', '>=', $now)
                ->orderByDesc('discount_percentage')
                ->first();

            if ($best) {
                $original = (float)($item['price'] ?? 0);
                $rate     = max(0, min(100, (float)$best->discount_percentage)) / 100;
                $discounted = round($original * (1 - $rate), 2);

                $item['discounted_price'] = $discounted;
                $item['promotion_percent'] = (float)$best->discount_percentage;
                $appliedCount++;
            } else {
                // Không có KM hợp lệ, xóa trường discounted (nếu có từ lần trước)
                unset($item['discounted_price'], $item['promotion_percent']);
            }
        }
        unset($item); // good practice khi dùng tham chiếu

        session(['cart' => $cart]);

        if ($appliedCount > 0) {
            return back()->with('success', "Đã áp dụng khuyến mãi cho {$appliedCount} sản phẩm trong giỏ.");
        }
        return back()->with('warning', 'Không tìm thấy khuyến mãi hợp lệ cho các sản phẩm trong giỏ.');
    }
}
