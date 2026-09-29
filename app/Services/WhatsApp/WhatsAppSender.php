<?php

namespace App\Services\WhatsApp;

use App\Services\Settings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gateway-agnostic sender: the admin configures URL, headers and a body template containing
 * {phone} and {message}, so Fonnte, Wablas, Woowa, WhatsApp Cloud API etc. work without code changes.
 */
class WhatsAppSender
{
    public function __construct(private Settings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->bool('wa.enabled') && filled($this->settings->get('wa.url'));
    }

    public function send(string $phone, string $message): Response
    {
        $url = (string) $this->settings->get('wa.url');
        $body = $this->renderBody(self::normalizePhone($phone), $message);
        $headers = json_decode((string) $this->settings->get('wa.headers'), true) ?: [];
        $method = strtoupper((string) $this->settings->get('wa.method')) ?: 'POST';
        $format = $this->settings->get('wa.body_format') === 'json' ? 'json' : 'form_params';

        $request = Http::timeout(30)->withHeaders($headers);
        $response = $method === 'GET'
            ? $request->get($url, $body)
            : $request->send($method, $url, [$format => $body]);

        if ($response->failed()) {
            throw new \RuntimeException('WhatsApp gateway HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
        }

        return $response;
    }

    /** Fill {phone}/{message} into the JSON body template; values are JSON-escaped so any text is safe. */
    public function renderBody(string $phone, string $message): array
    {
        $template = (string) $this->settings->get('wa.body_template');
        $escape = fn (string $v) => substr(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
        $json = str_replace(['{phone}', '{message}'], [$escape($phone), $escape($message)], $template);

        $body = json_decode($json, true);
        if (! is_array($body)) {
            throw new \InvalidArgumentException('Template body WhatsApp bukan JSON yang valid.');
        }

        return $body;
    }

    /** 0812..., +62812..., 62812..., 812... → 62812... */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return match (true) {
            str_starts_with($digits, '62') => $digits,
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => $digits,
        };
    }
}
