<?php

namespace App\Http\Middleware;

use App\Services\StudentLogins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the student session from the "Authorization: Bearer" header. The session is required when student login
 * is switched on, or always with the "always" parameter (the session endpoints themselves).
 */
class EnsureStudentLogin
{
    public function __construct(private StudentLogins $logins) {}

    public function handle(Request $request, Closure $next, string $mode = 'auto'): Response
    {
        $login = $this->logins->authenticate($request->bearerToken());
        if ($login) {
            $request->attributes->set('student_login', $login);
        } elseif ($mode === 'always' || $this->logins->enabled()) {
            return response()->json(['message' => 'Silakan masuk dulu lewat WhatsApp.', 'login_required' => true], 401);
        }

        return $next($request);
    }
}
