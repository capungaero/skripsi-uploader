<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\Services\StudentLogins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Receives the OpenWA events of the library's WhatsApp number (signed with the secret set when the webhook was registered). */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, Settings $settings, StudentLogins $logins): JsonResponse
    {
        $secret = (string) $settings->get('login.webhook_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless($secret !== '' && hash_equals($expected, (string) $request->header('X-OpenWA-Signature')), 401, 'Tanda tangan tidak valid.');

        if ($request->json('event') !== 'message.received') {
            return response()->json(['ok' => true, 'ignored' => (string) $request->json('event')]);
        }

        // OpenWA delivers at least once: handle each message only once.
        $key = (string) ($request->header('X-OpenWA-Idempotency-Key') ?: $request->json('idempotencyKey'));
        if ($key !== '' && ! Cache::add('wa-webhook:'.sha1($key), 1, 86400)) {
            return response()->json(['ok' => true, 'ignored' => 'duplicate']);
        }

        return response()->json(['ok' => true, 'result' => $logins->handleInbound((array) $request->json('data'))]);
    }
}
