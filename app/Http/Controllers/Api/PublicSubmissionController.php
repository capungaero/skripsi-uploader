<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckSubmissionPdf;
use App\Models\FormConfig;
use App\Models\FormField;
use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Dspace\DspaceClient;
use App\Services\FormSchema;
use App\Services\Staging;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PublicSubmissionController extends Controller
{
    public function __construct(private SubmissionService $submissions, private FormSchema $schema) {}

    public function form(): JsonResponse
    {
        return response()->json($this->schema->forClient(FormConfig::active()?->load('fields')));
    }

    public function checkNim(string $nim): JsonResponse
    {
        abort_unless(preg_match('/^[0-9A-Za-z.\-]{5,30}$/', $nim), 422, 'NIM tidak valid.');
        $state = $this->submissions->uploadState($nim);

        // Deliberately no name/title: the endpoint is public.
        return response()->json([
            'allowed' => $state['allowed'],
            'message' => $state['message'],
            'remaining' => $state['remaining'],
            'max_attempts' => $this->submissions->maxAttempts(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $config = FormConfig::active()?->load('fields');
        abort_unless($config, 503, 'Pengunggahan sedang ditutup.');

        $validated = Validator::make($request->all(), $this->schema->rules($config, $request->all()), [
            'file.max' => 'Ukuran file maksimal '.$this->schema->maxMb().' MB.',
            'file.mimes' => 'File harus berformat PDF.',
            'file.mimetypes' => 'File harus berformat PDF.',
        ], $this->schema->attributes($config))->validate();

        $file = $request->file('file');
        if (! $this->looksLikePdf($file->getRealPath())) {
            return response()->json(['message' => 'Isi file bukan PDF yang valid.', 'errors' => ['file' => ['Isi file bukan PDF yang valid.']]], 422);
        }

        $nim = trim($validated['nim']);
        $lock = Cache::lock('upload:'.$nim, 60);
        if (! $lock->get()) {
            return response()->json(['message' => 'Unggahan untuk NIM ini sedang diproses. Tunggu sebentar.'], 429);
        }

        try {
            $state = $this->submissions->uploadState($nim);
            if (! $state['allowed']) {
                return response()->json(['message' => $state['message'], 'errors' => ['nim' => [$state['message']]]], 422);
            }

            $size = $file->getSize();
            $hash = hash_file('sha256', $file->getRealPath());
            $original = mb_substr(Submission::safeFolderName($file->getClientOriginalName()), 0, 200) ?: 'skripsi.pdf';

            $submission = Submission::create([
                'public_token' => Str::random(40),
                'form_config_id' => $config->id,
                'academic_year' => $config->academic_year,
                'nim' => $nim,
                'nama' => trim($validated['nama']),
                'no_wa' => trim($validated['no_wa']),
                'judul' => trim(preg_replace('/\s+/u', ' ', $validated['judul'])),
                'faculty_id' => $validated['fakultas'],
                'study_program_id' => $validated['prodi'],
                'extra' => collect($validated)->except(array_merge(FormField::COLUMN_KEYS, ['file', 'fakultas', 'prodi']))
                    ->filter(fn ($v) => $v !== null && $v !== '')->all(),
                'status' => Submission::CHECKING,
                'original_filename' => $original,
                'file_size' => $size,
                'file_hash' => $hash,
                'staging_path' => Staging::store($file),
            ]);
        } finally {
            $lock->release();
        }

        ActivityLogger::student('submission.uploaded', $submission, [
            'file' => $original, 'size' => $size, 'sha256' => $hash, 'attempt' => $this->submissions->usedAttempts($nim) + 1,
        ]);
        CheckSubmissionPdf::dispatch($submission->id);

        return response()->json(['token' => $submission->public_token] + $this->statusPayload($submission), 201);
    }

    public function status(string $token): JsonResponse
    {
        $submission = Submission::where('public_token', $token)->firstOrFail();

        return response()->json($this->statusPayload($submission));
    }

    private function statusPayload(Submission $submission): array
    {
        $state = match ($submission->status) {
            Submission::CHECKING, Submission::AI_ERROR => 'checking',
            Submission::AI_ACCEPTED => 'accepted',
            Submission::APPROVED, Submission::DEPOSIT_FAILED => 'verified',
            Submission::DEPOSITED => 'deposited',
            default => 'rejected',
        };
        $check = $submission->checks()->whereNull('error')->first();
        $remaining = $this->submissions->remainingAttempts($submission->nim);

        return [
            'state' => $state,
            'status_label' => $submission->statusLabel(),
            'nim' => $submission->nim,
            'nama' => $submission->nama,
            'judul' => $submission->judul,
            'uploaded_at' => $submission->created_at->toIso8601String(),
            'updated_at' => $submission->updated_at->toIso8601String(),
            'reasons' => $state === 'rejected' ? ($submission->rejection_reasons ?? []) : [],
            'admin_note' => in_array($state, ['rejected', 'verified', 'deposited'], true) ? $submission->admin_note : null,
            'criteria' => $check ? collect($check->criteria)->map(fn ($c) => [
                'label' => $c['label'], 'score' => $c['score'], 'passed' => $c['passed'], 'reason' => $c['reason'],
            ])->all() : [],
            'remaining_attempts' => $remaining,
            'can_reupload' => $state === 'rejected' && $remaining > 0,
            'handle_url' => app(DspaceClient::class)->handleUrl($submission->dspace_handle),
        ];
    }

    private function looksLikePdf(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $head = fread($handle, 1024);
        fclose($handle);

        return str_contains((string) $head, '%PDF-');
    }
}
