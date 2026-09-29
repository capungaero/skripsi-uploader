<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Log out accounts that a superadmin has deactivated. */
class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('admin.login')->withErrors(['email' => 'Akun tidak aktif.']);
        }

        return $next($request);
    }
}
