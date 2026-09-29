<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    public const CHECKING = 'checking';

    public const AI_REJECTED = 'ai_rejected';

    public const AI_ACCEPTED = 'ai_accepted';

    public const AI_ERROR = 'ai_error';

    public const APPROVED = 'approved';

    public const ADMIN_REJECTED = 'admin_rejected';

    public const DEPOSITED = 'deposited';

    public const DEPOSIT_FAILED = 'deposit_failed';

    public const REJECTED = [self::AI_REJECTED, self::ADMIN_REJECTED];

    /** Statuses that block a new upload for the same NIM + year. */
    public const ACTIVE = [self::CHECKING, self::AI_ERROR, self::AI_ACCEPTED, self::APPROVED,
        self::DEPOSITED, self::DEPOSIT_FAILED];

    public const LABELS = [
        self::CHECKING => 'Sedang dicek',
        self::AI_ERROR => 'Sedang dicek',
        self::AI_REJECTED => 'Ditolak',
        self::AI_ACCEPTED => 'Diterima – antrean verifikasi',
        self::APPROVED => 'Terverifikasi',
        self::ADMIN_REJECTED => 'Ditolak verifikator',
        self::DEPOSITED => 'Tersimpan di repositori',
        self::DEPOSIT_FAILED => 'Terverifikasi',
    ];

    public const ADMIN_LABELS = [
        self::CHECKING => 'Sedang dicek AI',
        self::AI_ERROR => 'Error AI',
        self::AI_REJECTED => 'Ditolak AI',
        self::AI_ACCEPTED => 'Antrean verifikasi',
        self::APPROVED => 'Disetujui',
        self::ADMIN_REJECTED => 'Ditolak admin',
        self::DEPOSITED => 'Di DSpace',
        self::DEPOSIT_FAILED => 'Gagal setor DSpace',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['staging_path'];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
            'rejection_reasons' => 'array',
            'counts_as_attempt' => 'boolean',
            'cloud_locked' => 'boolean',
            'verified_at' => 'datetime',
            'deposited_at' => 'datetime',
        ];
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class);
    }

    public function formConfig(): BelongsTo
    {
        return $this->belongsTo(FormConfig::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(SubmissionCheck::class)->latest('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('id');
    }

    /** A NIM submits one thesis ever, so duplicate and attempt checks are not scoped by year. */
    public function scopeForNim(Builder $query, string $nim): Builder
    {
        return $query->where('nim', $nim);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function adminStatusLabel(): string
    {
        return self::ADMIN_LABELS[$this->status] ?? $this->status;
    }

    /** "{NIM}_{Nama}.pdf" */
    public function cloudFileName(): string
    {
        return self::safeName($this->nim).'_'.self::safeName($this->nama).'.pdf';
    }

    /** Strip characters that Drive/OneDrive/Dropbox reject and collapse whitespace to "_". */
    public static function safeName(string $value): string
    {
        return mb_substr(trim(preg_replace('~\s+~u', '_', self::safeFolderName($value)), '._'), 0, 120);
    }

    /** Same character rules as safeName() but keeps single spaces, e.g. "Fakultas Hukum". */
    public static function safeFolderName(string $value): string
    {
        $value = preg_replace('~[\\\\/:*?"<>|#%{}\~&\x00-\x1F]+~u', '', $value);

        return mb_substr(trim(preg_replace('~\s+~u', ' ', $value), ' .'), 0, 120);
    }
}
