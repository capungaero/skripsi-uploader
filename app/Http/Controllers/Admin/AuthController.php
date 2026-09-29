<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = strtolower($credentials['email']);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            ActivityLogger::system('auth.failed', null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        $request->session()->regenerate();
        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLogger::admin('auth.login');

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        ActivityLogger::admin('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
