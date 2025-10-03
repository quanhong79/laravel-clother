<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        return view('settings.index', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'     => ['required','string','max:255'],
            'phone'    => ['nullable','string','max:30'],
            'address'  => ['nullable','string','max:255'],
            'district' => ['nullable','string','max:100'],
            'city'     => ['nullable','string','max:100'],
            'country'  => ['nullable','string','max:50'],
        ]);

        $user->update($data);

        return redirect()->route('settings.index', ['#tab-profile'])->with('success', 'Đã lưu thông tin hồ sơ.');

    }

    public function updateNotifications(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'notify_email' => ['nullable','boolean'],
            'notify_sms'   => ['nullable','boolean'],
        ]);
        $user->update([
            'notify_email' => (bool)($data['notify_email'] ?? false),
            'notify_sms'   => (bool)($data['notify_sms'] ?? false),
        ]);
        return back()->with('success','Đã lưu cài đặt thông báo.');
    }

    public function updateLanguage(Request $request)
{
    $request->validate([
        'language' => 'required|in:vi,en',
    ]);

    $user = $request->user();
    if ($user) {
        $user->language = $request->language;   // cột language bạn đã thêm
        $user->save();
    }

    // cũng lưu session cho khách/guest (hoặc khi vừa đổi xong)
    $request->session()->put('locale', $request->language);

    app()->setLocale($request->language);

    return back()->with('success', __('settings.language_updated'));
}

    public function updatePayment(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'default_payment_method' => ['required', Rule::in(['COD','VNPAY','CARD'])],
        ]);
        $user->update($data);
        return back()->with('success','Đã lưu phương thức thanh toán mặc định.');
    }

    // (Tuỳ ý) đổi mật khẩu ở trang này luôn
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password'      => ['required','current_password'],
            'password'              => ['required','confirmed','min:6'],
        ]);

        $request->user()->update([
            'password' => bcrypt($request->password),
        ]);

        return back()->with('success','Đã đổi mật khẩu.');
    }
}
