<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfilePasswordController extends Controller
{
    public function edit()
    {
        return view('profile.password');
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password'      => ['required','current_password'],
            'password'              => ['required','string','min:8','confirmed'],
        ],[
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
        ]);

        $user = $request->user();
        $user->password = Hash::make($request->password);
        $user->save();

        return back()->with('success','Đã đổi mật khẩu thành công!');
    }
}
