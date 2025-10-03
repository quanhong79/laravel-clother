<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required','string','max:255'],
            'phone'       => ['nullable','string','max:50'],
            'address'     => ['nullable','string','max:255'],
            'city'        => ['nullable','string','max:100'],
            'state'       => ['nullable','string','max:100'],
            'postal_code' => ['nullable','string','max:20'],
        ]);

        $request->user()->update($data);

        return back()->with('success','Cập nhật thông tin thành công!');
    }
}
