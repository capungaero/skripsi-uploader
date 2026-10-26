<?php

namespace App\Services;

use App\Jobs\SendLoginReply;
use App\Models\StudentLogin;
use App\Services\WhatsApp\OpenWaClient;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Students log in by messaging the library's WhatsApp number first ("kliring_<NIM>"); the system answers with a one-time
 * code. Because the student always writes first, the number only ever replies to people who contacted it.
 */
class StudentLogins
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L

    private const REQUESTS_PER_HOUR = 5;

    public function __construct(private Settings $settings, private SubmissionService $submissions) {}

    /** Whether uploading requires a login (off until the admin has tested the WhatsApp flow). */
    public function enabled(): bool
    {
        return $this->settings->bool('login.enabled');
    }

    public function codeMinutes(): int
    {
        return max(1, (int) $this->settings->get('login.code_minutes'));
    }

    public function sessionHours(): int
    {
        return max(1, (int) $this->settings->get('login.session_hours'));
    }

    /**
     * What an incoming message asks for: ['nim' => …] for "kliring_<NIM>", ['help' => true] for a "kliring…" message
     * that is malformed, null for everything else (those messages are never answered).
     */
    public static function parse(string $body): ?array
    {
        $body = trim($body);
        if (! preg_match('/^kliring(?![a-z])/i', $body)) {
            return null;
        }
        if (preg_match('/^kliring[\s_\-:]*([0-9A-Za-z.\-]{5,30})$/i', $body, $m)) {
            return ['nim' => strtoupper($m[1])];
        }

        return ['help' => true];
    }

    /** Process one incoming WhatsApp message (data of an OpenWA message.received event). Returns what happened. */
    public function handleInbound(array $message): string
    {
        if (($message['fromMe'] ?? false) || ($message['isGroup'] ?? false)
            || (isset($message['kind']) && $message['kind'] !== 'individual')
            || (isset($message['type']) && $message['type'] !== 'text' && $message['type'] !== 'chat')) {
            return 'ignored';
        }
        $parsed = self::parse((string) ($message['body'] ?? ''));
        $chatId = (string) ($message['chatId'] ?? $message['from'] ?? '');
        if (! $parsed || $chatId === '') {
            return 'ignored';
        }

        // WhatsApp usually reports a privacy id (@lid) as the chat; replies go to the real number, which is the reliable address.
        $phone = $this->senderPhone($message, $chatId);
        $target = $phone ? $phone.'@c.us' : $chatId;
        $key = 'wa-login:'.($phone ?? $chatId);
        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_HOUR)) {
            // Tell them once per hour, then stay silent so a flood of messages never turns into a flood of replies.
            if (Cache::add('wa-login-limited:'.($phone ?? $chatId), 1, 3600)) {
                $this->reply($target, "Anda sudah meminta kode beberapa kali. Gunakan kode terakhir yang dikirim, atau coba lagi dalam 1 jam.\n\n— Perpustakaan Universitas Andalas");
            }

            return 'limited';
        }
        RateLimiter::hit($key, 3600);

        if (isset($parsed['help'])) {
            $this->reply($target, (string) $this->settings->get('wa.tpl.login_help'));

            return 'help';
        }

        $nim = $parsed['nim'];
        $state = $this->submissions->uploadState($nim);
        if (! $state['allowed']) {
            $this->reply($target, "Halo, NIM {$nim} belum bisa mengunggah.\n\n{$state['message']}\n\n— Perpustakaan Universitas Andalas");
            ActivityLogger::system('login.refused', null, ['nim' => $nim]);

            return 'refused';
        }

        $code = $this->issue($nim, $phone);
        $this->reply($target, strtr((string) $this->settings->get('wa.tpl.login_code'), [
            '{nim}' => $nim,
            '{kode}' => $this->format($code),
            '{menit}' => (string) $this->codeMinutes(),
            '{link}' => url('/?kode='.$this->format($code)),
        ]), $nim);
        ActivityLogger::system('login.code_issued', null, ['nim' => $nim, 'phone' => $phone ? '***'.substr($phone, -3) : null]);

        return 'issued';
    }

    /** Create a fresh code for the NIM and invalidate the earlier unused codes of the same number. */
    public function issue(string $nim, ?string $phone): string
    {
        if ($phone) {
            StudentLogin::where('phone', $phone)->whereNull('used_at')->where('code_expires_at', '>', now())
                ->update(['code_expires_at' => now()]);
        }
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $hash = self::hash($code);
        } while (StudentLogin::where('code_hash', $hash)->exists());

        StudentLogin::create([
            'nim' => $nim, 'phone' => $phone, 'code_hash' => $hash,
            'code_expires_at' => now()->addMinutes($this->codeMinutes()),
        ]);

        return $code;
    }

    /**
     * Trade a valid, unused code for a browser session. Returns null for a wrong, used or expired code.
     *
     * @return array{token: string, login: StudentLogin}|null
     */
    public function redeem(string $input): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($code) !== 8) {
            return null;
        }

        return DB::transaction(function () use ($code) {
            $login = StudentLogin::where('code_hash', self::hash($code))->whereNull('used_at')
                ->where('code_expires_at', '>', now())->lockForUpdate()->first();
            if (! $login) {
                return null;
            }
            $token = Str::random(48);
            $login->update([
                'used_at' => now(),
                'session_hash' => self::hash($token),
                'session_expires_at' => now()->addHours($this->sessionHours()),
            ]);

            return ['token' => $token, 'login' => $login];
        });
    }

    public function authenticate(?string $token): ?StudentLogin
    {
        if (! $token || strlen($token) !== 48) {
            return null;
        }

        return StudentLogin::where('session_hash', self::hash($token))->where('session_expires_at', '>', now())->first();
    }

    public function revoke(StudentLogin $login): void
    {
        $login->update(['session_expires_at' => now()]);
    }

    /** Drop rows that can no longer be used. */
    public function purge(): int
    {
        return StudentLogin::where('code_expires_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNull('session_expires_at')->orWhere('session_expires_at', '<', now()->subDay()))
            ->delete();
    }

    public function format(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private function reply(string $chatId, string $message, ?string $nim = null): void
    {
        if (trim($message) !== '') {
            SendLoginReply::dispatch($chatId, $message, $nim);
        }
    }

    /** The sender's real number: given by the gateway, derived from the chat id, or looked up for privacy ids (@lid). */
    private function senderPhone(array $message, string $chatId): ?string
    {
        $raw = $message['senderPhone'] ?? null;
        if (! $raw && str_ends_with($chatId, '@c.us')) {
            $raw = strtok($chatId, '@');
        }
        if (! $raw && str_ends_with($chatId, '@lid')) {
            try {
                $raw = app(OpenWaClient::class)->contactPhone($chatId);
            } catch (\Throwable) {
                $raw = null;
            }
        }
        $digits = preg_replace('/\D+/', '', (string) $raw);

        return preg_match('/^628[0-9]{7,12}$/', WhatsAppSender::normalizePhone($digits)) ? WhatsAppSender::normalizePhone($digits) : null;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
