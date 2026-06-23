<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function showRequest()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        // ตอบกลับเป็นกลางเสมอ (ไม่บอกว่าอีเมลมีในระบบไหม — กัน enumeration)
        return back()->with('status', __($status));
    }
}
