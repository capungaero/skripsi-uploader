<?php

namespace App\Services\WhatsApp;

use App\Services\Settings;
use App\Services\StudentLogins;
use Illuminate\Support\Facades\Cache;

/**
 * Looks for new "kliring_NIM" messages in the library number's inbox by asking OpenWA for the incoming messages it
 * stored. Polling means OpenWA never has to reach this app, which keeps working behind the campus proxy.
 */
class LoginInbox
{
    /** Messages older than this are ignored: their code would be long expired anyway, and old chats must never be answered. */
    private const MAX_AGE_SECONDS = 600;

    public const LAST_POLL_KEY = 'wa-login:last_poll_at';

    public const LAST_ERROR_KEY = 'wa-login:last_error';

    public function __construct(private Settings $settings, private OpenWaClient $wa, private StudentLogins $logins) {}

    /** Whether the number should be answering at all. */
    public function active(): bool
    {
        return ($this->settings->bool('login.listen') || $this->logins->enabled()) && $this->wa->hasSession();
    }

    /** One look at the inbox. Returns how many messages were handled (answered or refused). */
    public function poll(): int
    {
        $since = max((int) $this->settings->get('login.poll_cursor'), (now()->getTimestamp() - self::MAX_AGE_SECONDS) * 1000);
        $handled = 0;
        $newest = $since;

        try {
            $messages = $this->wa->incomingTexts($since);
            Cache::forget(self::LAST_ERROR_KEY);
        } catch (\Throwable $e) {
            Cache::put(self::LAST_ERROR_KEY, mb_substr($e->getMessage(), 0, 250), 86400);

            return 0;
        } finally {
            Cache::put(self::LAST_POLL_KEY, now()->getTimestamp(), 86400);
        }

        usort($messages, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
        foreach ($messages as $message) {
            if ((int) ($message['timestamp'] ?? 0) * 1000 < $since) {
                continue; // too old (the gateway should not send these, but an old chat must never be answered)
            }
            $newest = max($newest, (int) ($message['timestamp'] ?? 0) * 1000);
            // The window includes the second of the last message, so the same message can come back: handle each once.
            if (! Cache::add('wa-login:seen:'.($message['id'] ?? sha1(json_encode($message))), 1, 2 * 86400)) {
                continue;
            }
            if ($this->logins->handleInbound($this->normalize($message)) !== 'ignored') {
                $handled++;
            }
        }
        if ($newest > $since) {
            $this->settings->set('login.poll_cursor', $newest);
        }

        return $handled;
    }

    /** A stored OpenWA message in the shape StudentLogins::handleInbound understands. */
    private function normalize(array $message): array
    {
        $chatId = (string) ($message['chatId'] ?? '');
        $chatId = preg_replace('/@s\.whatsapp\.net$/', '@c.us', $chatId);

        return [
            'chatId' => $chatId,
            'body' => (string) ($message['body'] ?? ''),
            'type' => (string) ($message['type'] ?? 'text'),
            'fromMe' => false,
            'isGroup' => str_ends_with($chatId, '@g.us'),
            'kind' => str_ends_with($chatId, '@c.us') || str_ends_with($chatId, '@lid') ? 'individual' : 'other',
            'senderPhone' => preg_match('/^\d{8,15}$/', (string) ($message['from'] ?? '')) ? $message['from'] : null,
        ];
    }
}
