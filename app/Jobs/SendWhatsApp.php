<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        $sender->send($submission->no_wa, $this->message);
        ActivityLogger::system('wa.sent', $submission, ['event' => $this->event]);
    }

    public function failed(?\Throwable $e): void
    {
        $submission = Submission::find($this->submissionId);
        ActivityLogger::system('wa.failed', $submission, [
            'event' => $this->event,
            'error' => mb_substr($e?->getMessage() ?? '', 0, 500),
        ]);
    }
}
