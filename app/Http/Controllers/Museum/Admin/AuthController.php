<?php

namespace App\Http\Controllers\Museum\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show()
    {
        return view('museum.admin.login');
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($cred, $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'اطلاعات ورود نادرست است.'])->onlyInput('email');
        }
        $r->session()->regenerate();

        return redirect()->intended(route('museum.admin.dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('museum.home');
    }
}
