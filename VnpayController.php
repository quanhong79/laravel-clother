<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class VnpayController extends Controller
{
    // Khởi tạo giao dịch VNPay
    public function create(Request $request)
    {
        if (!Schema::hasTable('carts')) {
            return back()->with('error', 'Cart table not found.');
        }

        $userId  = Auth::id();
        $cartKey = Cookie::get('cart_key');

        $rows = DB::table('carts')
            ->join('products','products.id','=','carts.product_id')
            ->when($userId,  fn($q)=>$q->where('carts.user_id',$userId)->whereNull('carts.cart_key'))
            ->when(!$userId, fn($q)=>$q->where('carts.cart_key',$cartKey)->whereNull('carts.user_id'))
            ->select('carts.*','products.name','products.price as product_price')
            ->get();

        if ($rows->isEmpty()) {
            return back()->with('error','Giỏ hàng trống.');
        }

        $subtotal = 0;
        foreach ($rows as $r) {
            $unit = (float)($r->price ?? $r->product_price);
            $subtotal += $unit * (int)$r->quantity;
        }

        // Tạo đơn trước: status pending, payment_status pending
        $orderCode = 'OD' . now()->format('ymdHis') . Str::upper(Str::random(4));
        $orderId = null;

        DB::transaction(function () use (&$orderId, $orderCode, $userId, $cartKey, $subtotal) {
            $orderId = DB::table('orders')->insertGetId([
                'user_id'        => $userId,
                'cart_key'       => $userId ? null : $cartKey,
                'total_amount'   => $subtotal,
                'payment_method' => 'VNPAY',
                'payment_status' => 'pending', // chờ VNPay callback
                'status'         => 'pending', // vẫn chờ admin duyệt sau khi thanh toán ok
                'code'           => $orderCode,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });

        // Build VNPay URL
        $vnp_TmnCode   = env('VNPAY_TMN_CODE');
        $vnp_HashSecret= env('VNPAY_HASH_SECRET');
        $vnp_Url       = rtrim(env('VNPAY_URL'), '/');
        $vnp_ReturnUrl = env('VNPAY_RETURN_URL');

        $vnp_TxnRef    = $orderCode;
        $vnp_OrderInfo = 'Thanh toan don hang ' . $orderCode;
        $vnp_OrderType = 'other';
        $vnp_Amount    = (int) round($subtotal * 100); // theo VNPay: VND x 100
        $vnp_Locale    = 'vn';
        $vnp_IpAddr    = request()->ip();

        $inputData = [
            'vnp_Version'    => '2.1.0',
            'vnp_Command'    => 'pay',
            'vnp_TmnCode'    => $vnp_TmnCode,
            'vnp_Amount'     => $vnp_Amount,
            'vnp_CurrCode'   => 'VND',
            'vnp_TxnRef'     => $vnp_TxnRef,
            'vnp_OrderInfo'  => $vnp_OrderInfo,
            'vnp_OrderType'  => $vnp_OrderType,
            'vnp_Locale'     => $vnp_Locale,
            'vnp_ReturnUrl'  => $vnp_ReturnUrl,
            'vnp_IpAddr'     => $vnp_IpAddr,
            'vnp_CreateDate' => now()->format('YmdHis'),
        ];

        ksort($inputData);
        $query = [];
        $hashData = [];
        foreach ($inputData as $key => $value) {
            $query[]    = urlencode($key) . '=' . urlencode($value);
            $hashData[] = $key . '=' . $value;
        }
        $vnp_Url = $vnp_Url . '?' . implode('&', $query);
        $secureHash = hash_hmac('sha512', implode('&', $hashData), $vnp_HashSecret);
        $vnp_Url .= '&vnp_SecureHash=' . $secureHash;

        // Điều hướng sang VNPay
        return redirect()->away($vnp_Url);
    }

    // VNPay trả về
    public function return(Request $request)
    {
        $vnp_HashSecret= env('VNPAY_HASH_SECRET');

        $vnp_SecureHash = $request->get('vnp_SecureHash');
        $data = $request->except('vnp_SecureHash', 'vnp_SecureHashType');

        // verify hash
        ksort($data);
        $hashData = [];
        foreach ($data as $key => $value) {
            $hashData[] = $key . '=' . $value;
        }
        $secureHash = hash_hmac('sha512', implode('&', $hashData), $vnp_HashSecret);

        $isValid = hash_equals($secureHash, (string)$vnp_SecureHash);
        $orderCode = $request->get('vnp_TxnRef');
        $responseCode = $request->get('vnp_ResponseCode');     // '00' = thành công
        $transactionStatus = $request->get('vnp_TransactionStatus');

        // Tìm đơn theo code
        $order = DB::table('orders')->where('code', $orderCode)->first();
        if (!$order) {
            return redirect()->route('cart.index')->with('error','Không tìm thấy đơn hàng.');
        }

        // Lưu log giao dịch
        $orderId = $order->id;
        DB::table('vnpay_transactions')->insert([
            'order_id'               => $orderId,
            'vnp_TxnRef'             => $orderCode,
            'vnp_Amount'             => (int) $request->get('vnp_Amount'),
            'vnp_BankCode'           => $request->get('vnp_BankCode'),
            'vnp_BankTranNo'         => $request->get('vnp_BankTranNo'),
            'vnp_CardType'           => $request->get('vnp_CardType'),
            'vnp_OrderInfo'          => $request->get('vnp_OrderInfo'),
            'vnp_PayDate'            => $request->get('vnp_PayDate'),
            'vnp_ResponseCode'       => $responseCode,
            'vnp_TransactionNo'      => $request->get('vnp_TransactionNo'),
            'vnp_TransactionStatus'  => $transactionStatus,
            'vnp_SecureHash'         => $request->get('vnp_SecureHash'),
            'raw_payload'            => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        // Cập nhật trạng thái thanh toán
        if ($isValid && $responseCode === '00') {
            // Thanh toán thành công
            DB::table('orders')->where('id', $orderId)->update([
                'payment_status' => 'paid',    // đã thanh toán
                'updated_at'     => now(),
            ]);

            // Sau khi thanh toán OK: xoá giỏ của chủ sở hữu đơn
            DB::table('carts')
                ->when($order->user_id,  fn($q)=>$q->where('user_id', $order->user_id)->whereNull('cart_key'))
                ->when(!$order->user_id && $order->cart_key, fn($q)=>$q->where('cart_key', $order->cart_key)->whereNull('user_id'))
                ->delete();

            // Lưu ý: đơn vẫn status=pending -> chờ admin duyệt
            return redirect()->route('cart.index')
                ->with('success', "Thanh toán thành công cho đơn {$order->code}. Đơn hàng đang chờ duyệt.");
        } else {
            // Thất bại hoặc sai chữ ký
            DB::table('orders')->where('id', $orderId)->update([
                'payment_status' => $isValid ? 'failed' : 'failed',
                'updated_at'     => now(),
            ]);
            return redirect()->route('cart.index')
                ->with('error', 'Thanh toán không thành công hoặc không hợp lệ. Vui lòng thử lại.');
        }
    }
}
