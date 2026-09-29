<?php

namespace App\Livewire\Admin;

use App\Jobs\PushToCloud;
use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Dspace\DspaceClient;
use App\Services\Staging;
use App\Services\SubmissionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Detail Unggahan')]
class SubmissionShow extends Component
{
    public Submission $submission;

    public string $note = '';

    public ?string $flash = null;

    public function mount(Submission $submission): void
    {
        $this->submission = $submission;
        $this->note = (string) $submission->admin_note;
    }

    public function approve(SubmissionService $service): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->run(fn () => $service->approve($this->submission, Auth::user(), $this->note ?: null), 'Disetujui. File dikunci dan diproses lebih lanjut.');
    }

    public function reject(SubmissionService $service): void
    {
        $this->validate(['note' => ['required', 'string', 'min:10', 'max:2000']], [
            'note.required' => 'Tulis alasan penolakan agar mahasiswa tahu apa yang harus diperbaiki.',
            'note.min' => 'Alasan penolakan minimal 10 karakter.',
        ]);
        $this->run(fn () => $service->reject($this->submission, Auth::user(), $this->note), 'Ditolak. Mahasiswa diberi notifikasi.');
    }

    public function deposit(SubmissionService $service): void
    {
        $this->run(fn () => $service->deposit($this->submission), 'Masuk antrean setor ke DSpace.');
    }

    public function recheck(SubmissionService $service): void
    {
        $this->run(fn () => $service->recheck($this->submission), 'Pengecekan AI dijalankan ulang.');
    }

    public function retryCloud(): void
    {
        abort_unless(Staging::exists($this->submission) && ! $this->submission->cloud_file_id, 422);
        ActivityLogger::admin('cloud.retry_requested', $this->submission);
        PushToCloud::dispatch($this->submission->id);
        $this->flash = 'Upload ke cloud dijadwalkan ulang.';
    }

    public function resetAttempts(SubmissionService $service): void
    {
        $count = $service->resetAttempts($this->submission->nim);
        $this->flash = "Jatah percobaan NIM {$this->submission->nim} direset ({$count} penolakan tidak dihitung lagi).";
    }

    private function run(callable $action, string $message): void
    {
        try {
            $action();
            $this->flash = $message;
        } catch (ValidationException $e) {
            $this->addError('status', $e->validator->errors()->first());
        }
        $this->submission->refresh();
    }

    public function render(SubmissionService $service, DspaceClient $dspace)
    {
        $this->submission->load(['faculty', 'studyProgram', 'verifier', 'checks', 'logs', 'formConfig.fields']);
        $labels = $this->submission->formConfig?->fields->pluck('label', 'key') ?? collect();

        return view('livewire.admin.submission-show', [
            's' => $this->submission,
            'extra' => collect($this->submission->extra ?? [])->mapWithKeys(fn ($v, $k) => [$labels[$k] ?? $k => $v]),
            'remaining' => $service->remainingAttempts($this->submission->nim),
            'maxAttempts' => $service->maxAttempts(),
            'history' => Submission::forNim($this->submission->nim)->where('id', '!=', $this->submission->id)->latest('id')->get(),
            'hasFile' => Staging::exists($this->submission) || $this->submission->cloud_file_id,
            'handleUrl' => $dspace->handleUrl($this->submission->dspace_handle),
        ]);
    }
}
