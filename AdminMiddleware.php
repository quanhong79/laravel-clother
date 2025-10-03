<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Chưa đăng nhập -> mời đăng nhập
        if (!$user) {
            return redirect()
                ->route('login')
                ->with('error', 'Vui lòng đăng nhập để vào khu vực quản trị.');
        }

        // Xác định quyền admin linh hoạt
        $isAdmin =
            ($user->role ?? null) === 'admin'      // có cột role = 'admin'
            || (bool) ($user->is_admin ?? false)   // hoặc có cột is_admin = 1
            || (method_exists($user, 'isAdmin') && $user->isAdmin()); // hoặc có method isAdmin()

        if (!$isAdmin) {
            // Đã đăng nhập nhưng không đủ quyền -> trả đúng mã 403
            abort(403, 'Bạn không có quyền truy cập khu vực quản trị.');
            // Nếu muốn redirect thay vì 403:
            // return redirect()->route('welcome')->with('error', 'Bạn không có quyền truy cập.');
        }

        return $next($request);
    }
}
