<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentLogin;
use App\Services\ActivityLogger;
use App\Services\StudentLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAuthController extends Controller
{
    public function __construct(private StudentLogins $logins) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Masukkan kode dari WhatsApp.']);

        $result = $this->logins->redeem($request->string('code'));
        if (! $result) {
            return response()->json(['message' => 'Kode salah atau sudah kedaluwarsa. Kirim "kliring_NIM" lagi ke WhatsApp perpustakaan untuk kode baru.'], 422);
        }
        ActivityLogger::system('login.success', null, ['nim' => $result['login']->nim]);

        return response()->json(['token' => $result['token']] + $this->payload($result['login']));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->attributes->get('student_login')));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->logins->revoke($request->attributes->get('student_login'));

        return response()->json(['ok' => true]);
    }

    private function payload(StudentLogin $login): array
    {
        return ['nim' => $login->nim, 'phone' => $login->localPhone(), 'expires_at' => $login->session_expires_at->toIso8601String()];
    }
}
