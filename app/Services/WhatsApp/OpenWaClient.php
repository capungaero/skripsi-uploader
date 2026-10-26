<?php

namespace App\Services\WhatsApp;

use App\Services\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to an OpenWA gateway (https://github.com/rmyndharis/OpenWA): creates the uploader's WhatsApp session,
 * shows its pairing QR, and sends text. The admin only scans the QR, like logging in to WhatsApp Web.
 */
class OpenWaClient
{
    /** Session states during which the page keeps polling quickly ('created' means not started: the admin must press Hubungkan). */
    public const CONNECTING = ['initializing', 'qr_ready', 'authenticating'];

    private const STATUS_LABELS = [
        'created' => 'Belum dimulai',
        'initializing' => 'Memulai…',
        'qr_ready' => 'Menunggu scan QR',
        'authenticating' => 'Menyambungkan…',
        'ready' => 'Tersambung',
        'disconnected' => 'Terputus',
        'action_required' => 'Perlu tindakan di HP',
        'failed' => 'Gagal',
    ];

    public function __construct(private Settings $settings) {}

    public function url(): string
    {
        return rtrim((string) ($this->settings->get('wa.openwa_url') ?: config('services.openwa.url')), '/');
    }

    private function key(): string
    {
        return (string) ($this->settings->get('wa.openwa_key') ?: config('services.openwa.key'));
    }

    public function isConfigured(): bool
    {
        return $this->url() !== '' && $this->key() !== '';
    }

    /** Linked at least once: the session exists (it may be offline right now). */
    public function hasSession(): bool
    {
        return $this->isConfigured() && filled($this->settings->get('wa.openwa_session'));
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ($status ?: '-');
    }

    /** Find the uploader's session by name or create it; remembers its id. */
    public function ensureSession(): string
    {
        $name = (string) config('services.openwa.session', 'uploader');

        $list = $this->check($this->http()->get($this->api('sessions')), 'daftar sesi')->json();
        foreach ((array) ($list['data'] ?? $list) as $session) {
            if (is_array($session) && ($session['name'] ?? null) === $name) {
                return $this->remember($session['id']);
            }
        }

        $created = $this->check($this->http()->post($this->api('sessions'), ['name' => $name]), 'buat sesi');

        return $this->remember($created->json('id'));
    }

    /** Current session record, or null when it does not exist (anymore). */
    public function session(): ?array
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            return null;
        }
        $response = $this->http()->get($this->api("sessions/{$id}"));
        if ($response->status() === 404) {
            $this->settings->set('wa.openwa_session', null); // deleted on the gateway: start over on the next connect
            $this->settings->flush();

            return null;
        }

        return $this->check($response, 'status sesi')->json();
    }

    /** Start the engine so it produces a QR (or reconnects with the stored login). */
    public function start(): void
    {
        $id = $this->ensureSession();
        $response = $this->http()->post($this->api("sessions/{$id}/start"));
        // 400 = already started or starting, which is exactly what we want.
        if ($response->failed() && $response->status() !== 400) {
            $this->check($response, 'mulai sesi');
        }
    }

    /** PNG data URL of the pairing QR, or null while there is none (not ready yet, or already linked). */
    public function qr(): ?string
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            return null;
        }
        $response = $this->http()->get($this->api("sessions/{$id}/qr"));
        if ($response->status() === 400 || $response->status() === 404) {
            return null;
        }

        $qr = (string) $this->check($response, 'ambil QR')->json('qrCode');

        return str_starts_with($qr, 'data:image/') ? $qr : null;
    }

    /** Unlink the phone and stop the session. */
    public function logout(): void
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            return;
        }
        $response = $this->http()->post($this->api("sessions/{$id}/logout"));
        // Not started means nothing to unlink.
        if ($response->failed() && $response->status() !== 400 && $response->status() !== 404) {
            $this->check($response, 'putuskan sesi');
        }
    }

    public function sendText(string $phone, string $message): Response
    {
        return $this->sendToChat(WhatsAppSender::normalizePhone($phone).'@c.us', $message);
    }

    /** Send to an existing chat id as given by the gateway (a number, or a privacy id when WhatsApp hides the number). */
    public function sendToChat(string $chatId, string $message): Response
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            throw new \RuntimeException('WhatsApp belum dihubungkan: scan QR di Pengaturan → WhatsApp.');
        }

        return $this->check($this->http()->post($this->api("sessions/{$id}/messages/send-text"), [
            'chatId' => $chatId,
            'text' => $message,
        ]), 'kirim pesan');
    }

    /** Phone number (digits) behind a contact id such as an @lid, or null when the engine cannot tell. */
    public function contactPhone(string $contactId): ?string
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            return null;
        }
        $response = $this->http()->get($this->api("sessions/{$id}/contacts/".rawurlencode($contactId).'/phone'));

        return $response->ok() ? ($response->json('phone') ?: null) : null;
    }

    /** Where OpenWA must deliver incoming messages. */
    public function webhookUrl(): string
    {
        return url('/api/wa/webhook');
    }

    /** The registered webhook that points at this app, or null. */
    public function webhook(): ?array
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            return null;
        }
        $list = $this->check($this->http()->get($this->api("sessions/{$id}/webhooks")), 'daftar webhook')->json();
        foreach ((array) ($list['data'] ?? $list) as $hook) {
            if (is_array($hook) && ($hook['url'] ?? null) === $this->webhookUrl()) {
                return $hook;
            }
        }

        return null;
    }

    /** (Re)create the webhook for incoming messages, signed with $secret. Returns the new webhook. */
    public function registerWebhook(string $secret): array
    {
        $id = $this->settings->get('wa.openwa_session');
        if (! $id) {
            throw new \RuntimeException('WhatsApp belum dihubungkan: scan QR di Pengaturan → WhatsApp.');
        }
        while ($old = $this->webhook()) {
            $this->check($this->http()->delete($this->api("sessions/{$id}/webhooks/{$old['id']}")), 'hapus webhook lama');
        }

        return $this->check($this->http()->post($this->api("sessions/{$id}/webhooks"), [
            'url' => $this->webhookUrl(),
            'events' => ['message.received'],
            'secret' => $secret,
        ]), 'daftarkan webhook')->json();
    }

    /** Ask OpenWA to POST a test event to the webhook: [reachable, statusCode|error]. */
    public function testWebhook(string $webhookId): array
    {
        $id = $this->settings->get('wa.openwa_session');
        $json = $this->check($this->http()->post($this->api("sessions/{$id}/webhooks/{$webhookId}/test")), 'tes webhook')->json();

        return [(bool) ($json['success'] ?? false), $json['statusCode'] ?? ($json['error'] ?? null)];
    }

    private function remember(string $id): string
    {
        $this->settings->set('wa.openwa_session', $id);

        return $id;
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->acceptJson()->withHeaders(['X-API-Key' => $this->key()]);
    }

    private function api(string $path): string
    {
        return $this->url().'/api/'.$path;
    }

    private function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new \RuntimeException(sprintf('OpenWA %s gagal (HTTP %d): %s', $what, $response->status(),
                mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300)));
        }

        return $response;
    }
}
