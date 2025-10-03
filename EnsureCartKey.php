<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class EnsureCartKey {
  public function handle($req, Closure $next) {
    if (!$req->cookie('cart_key')) {
      Cookie::queue(cookie('cart_key', Str::uuid()->toString(), 60*24*365)); // 1 năm
    }
    return $next($req);
  }
}