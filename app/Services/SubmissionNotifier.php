<?php

namespace App\Services;

use App\Jobs\SendWhatsApp;
use App\Models\Submission;
use App\Services\Dspace\DspaceClient;

/** Renders the admin-editable WhatsApp templates and queues them. */
class SubmissionNotifier
{
    public const EVENTS = [
        'ai_rejected' => 'Ditolak AI',
        'ai_accepted' => 'Diterima AI (antrean verifikasi)',
        'admin_approved' => 'Disetujui admin',
        'admin_rejected' => 'Ditolak admin',
        'deposited' => 'Tersimpan di DSpace',
    ];

    public function __construct(private Settings $settings, private SubmissionService $submissions) {}

    public function notify(Submission $submission, string $event): void
    {
        $template = (string) $this->settings->get('wa.tpl.'.$event);
        if (trim($template) === '' || ! $this->settings->bool('wa.enabled')) {
            return;
        }

        SendWhatsApp::dispatch($submission->id, $event, $this->render($template, $submission));
    }

    public function render(string $template, Submission $submission): string
    {
        $reasons = $submission->rejection_reasons ?? [];

        return strtr($template, [
            '{nama}' => $submission->nama,
            '{nim}' => $submission->nim,
            '{judul}' => $submission->judul,
            '{alasan}' => $reasons ? '• '.implode("\n• ", $reasons) : '-',
            '{catatan}' => $submission->admin_note ?: '-',
            '{sisa_percobaan}' => (string) $this->submissions->remainingAttempts($submission->nim),
            '{link}' => url('/?status='.$submission->public_token),
            '{handle}' => app(DspaceClient::class)->handleUrl($submission->dspace_handle) ?? '-',
        ]);
    }
}
