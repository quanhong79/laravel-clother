<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
class AppServiceProvider extends ServiceProvider
{
    public function register() {}

    public function boot(): void
    {
        Paginator::useBootstrap();

        View::composer(['layouts.store', 'store.*', '*'], function ($view) {
    $cartCount = 0;
    if (Auth::check()) {
        $cartCount = DB::table('cart_items')
            ->where('user_id', Auth::id())
            ->sum('quantity');
    }
    $view->with('cartCount', $cartCount);
});
    }
}
