<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $submissionId, public string $event, public string $message)
    {
        $this->onQueue('notify');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WhatsAppSender $sender): void
    {
        $submission = Submission::find($this->submissionId);
        if (! $submission || ! $sender->isConfigured()) {
            return;
        }

        // One message at a time, spaced out. A message that has to wait goes back to the queue as a fresh job
        // (so waiting never uses up the retries meant for real gateway errors).
        $throttle = app(WhatsAppThrottle::class);
        $lock = Cache::lock('wa:send', 120);
        if (! $lock->get()) {
            $this->defer(random_int(3, 8));

            return;
        }

        try {
            if (($wait = $throttle->waitSeconds()) > 0) {
                $this->defer($wait + $throttle->jitter());

                return;
            }

            $sender->send($submission->no_wa, $this->message);
            ActivityLogger::system('wa.sent', $submission, ['event' => $this->event]);
            $throttle->markSent();
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $e): void
    {
        $submission = Submission::find($this->submissionId);
        ActivityLogger::system('wa.failed', $submission, [
            'event' => $this->event,
            'error' => mb_substr($e?->getMessage() ?? '', 0, 500),
        ]);
    }

    private function defer(int $seconds): void
    {
        static::dispatch($this->submissionId, $this->event, $this->message)->delay(now()->addSeconds($seconds));
    }
}
