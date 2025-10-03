<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class SetLocale
{
    public function handle($request, Closure $next)
    {
        // Ưu tiên ngôn ngữ trong session (ví dụ khi guest đổi)
        $lang = $request->session()->get('locale');

        // Nếu đã đăng nhập, ưu tiên ngôn ngữ trong users.language
        if (Auth::check()) {
            $userLang = Auth::user()->language ?? null;
            if ($userLang) {
                $lang = $userLang;
            }
        }

        // Fallback
        if (!in_array($lang, ['vi','en'])) {
            $lang = config('app.locale', 'vi');
        }

        App::setLocale($lang);

        return $next($request);
    }
}
