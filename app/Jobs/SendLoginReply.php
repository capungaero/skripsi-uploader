<?php

namespace App\Jobs;

use App\Services\ActivityLogger;
use App\Services\WhatsApp\OpenWaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Answers a student who messaged the library number first. Replies skip the hourly/daily caps of the notification
 * lane (the student started the conversation) but are still spaced a few seconds apart.
 */
class SendLoginReply implements ShouldQueue
{
    use Queueable;

    private const NEXT_KEY = 'wa:reply_next_at';

    public int $tries = 3;

    public function __construct(public string $chatId, public string $message, public ?string $nim = null)
    {
        $this->onQueue('notify');
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(OpenWaClient $wa): void
    {
        if (! $wa->hasSession()) {
            return;
        }

        $lock = Cache::lock('wa:reply', 60);
        if (! $lock->get()) {
            $this->defer(random_int(2, 5));

            return;
        }

        try {
            if (($wait = (int) Cache::get(self::NEXT_KEY, 0) - now()->getTimestamp()) > 0) {
                $this->defer($wait + random_int(0, 2));

                return;
            }

            $wa->sendToChat($this->chatId, $this->message);
            Cache::put(self::NEXT_KEY, now()->getTimestamp() + random_int(2, 5), 3600);
            ActivityLogger::system('login.reply_sent', null, ['nim' => $this->nim]);
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $e): void
    {
        ActivityLogger::system('login.reply_failed', null, ['nim' => $this->nim, 'error' => mb_substr($e?->getMessage() ?? '', 0, 300)]);
    }

    private function defer(int $seconds): void
    {
        static::dispatch($this->chatId, $this->message, $this->nim)->delay(now()->addSeconds($seconds));
    }
}
