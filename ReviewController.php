<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct()
    {
        // User phải đăng nhập để gửi/cập nhật review
        $this->middleware('auth')->only(['store']);
        // Trang/quyền admin bạn đã cấu hình ở routes (middleware admin trong group /admin)
    }

    /**
     * User gửi/cập nhật review cho 1 sản phẩm.
     * Route: POST /product/{product}/reviews  (auth)
     */
    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'rating'  => ['required','integer','min:1','max:5'],
            // chấp nhận cả content/comment; cuối cùng lưu vào cột `comment`
            'comment' => ['nullable','string','max:1000'],
            'content' => ['nullable','string','max:1000'],
        ]);

        $text = $data['comment'] ?? $data['content'] ?? null;
        if (!$text) {
            return back()->with('error', 'Vui lòng nhập nội dung đánh giá.')->withInput();
        }

        $userId = $request->user()->id;

        // Mỗi user 1 review/1 sản phẩm
        $review = Review::firstOrNew([
            'product_id' => $product->id,
            'user_id'    => $userId,
        ]);

        $review->fill([
            'rating'  => (int) $data['rating'],
            'comment' => $text, // <-- lưu vào cột comment
            // nếu đã approved thì khi sửa quay về pending để admin duyệt lại
            'status'  => $review->exists
                ? ($review->status === 'approved' ? 'pending' : $review->status)
                : 'pending',
        ]);

        $review->save();

        return back()->with(
            'success',
            $review->wasRecentlyCreated
                ? 'Bình luận của bạn đã gửi và đang chờ duyệt.'
                : 'Đã cập nhật đánh giá, vui lòng chờ duyệt lại.'
        );
    }

    /**
     * Admin: danh sách reviews với bộ lọc.
     * Route: GET /admin/reviews  (auth+admin)
     */
    public function index(Request $request)
    {
        $q         = trim((string) $request->get('q', ''));
        $status    = $request->get('status'); // approved|pending|hidden
        $minRating = $request->get('min_rating');
        $productQ  = trim((string) $request->get('product', ''));

        $reviews = Review::with(['user:id,name', 'product:id,name'])
            ->when($q !== '', function ($qr) use ($q) {
                $qr->where(function ($w) use ($q) {
                    $w->where('comment', 'like', "%{$q}%")  // <-- tìm theo comment
                      ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$q}%"))
                      ->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$q}%"));
                });
            })
            ->when(in_array($status, ['approved','pending','hidden'], true), fn($qr) => $qr->where('status', $status))
            ->when(is_numeric($minRating), fn($qr) => $qr->where('rating', '>=', (int) $minRating))
            ->when($productQ !== '', fn($qr) => $qr->whereHas('product', fn($p) => $p->where('name', 'like', "%{$productQ}%")))
            ->latest()
            ->paginate(20)
            ->appends($request->query());

        return view('admin.reviews.index', compact('reviews'));
    }

    /**
     * Admin: duyệt review → approved
     * Route: PATCH /admin/reviews/{review}/approve  (auth+admin)
     */
    public function approve(Review $review)
    {
        $review->update(['status' => 'approved']);
        return back()->with('success', "Đã duyệt đánh giá #{$review->id}");
    }

    /**
     * Admin: ẩn review → hidden
     * Route: PATCH /admin/reviews/{review}/hide  (auth+admin)
     */
    public function hide(Review $review)
    {
        $review->update(['status' => 'hidden']);
        return back()->with('success', "Đã ẩn đánh giá #{$review->id}");
    }
}
