<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /** Actions that say nothing new in a "latest activity" feed. */
    public const NOISY = ['submission.file_viewed', 'submission.exported', 'ai.check_started', 'auth.login', 'auth.logout',
        'cloud.tested', 'dspace.tested', 'wa.tested', 'ai.connection_tested'];

    private const LABELS = [
        'submission.uploaded' => 'Unggahan baru',
        'ai.accepted' => 'Lolos pengecekan AI',
        'ai.rejected' => 'Ditolak AI',
        'ai.error' => 'Error pengecekan AI',
        'ai.recheck_requested' => 'Cek ulang AI diminta',
        'submission.approved' => 'Disetujui admin',
        'submission.rejected' => 'Ditolak admin',
        'cloud.uploaded' => 'File disimpan ke penyimpanan',
        'cloud.finalized' => 'File dikunci',
        'cloud.removed' => 'File dihapus dari penyimpanan',
        'cloud.skipped' => 'Penyimpanan belum siap',
        'cloud.retry_requested' => 'Kirim ulang ke penyimpanan',
        'dspace.deposit_requested' => 'Setor DSpace diminta',
        'dspace.item_created' => 'Item DSpace dibuat',
        'dspace.deposited' => 'Tersimpan di DSpace',
        'dspace.failed' => 'Gagal setor DSpace',
        'kliring.approved' => 'Disetujui di kliring',
        'kliring.failed' => 'Gagal di kliring',
        'kliring.waiting_login' => 'Kliring menunggu sesi',
        'kliring.waiting_request' => 'Menunggu permohonan kliring',
        'kliring.connected' => 'Kliring tersambung',
        'kliring.disconnected' => 'Kliring diputus',
        'kliring.session_expired' => 'Sesi kliring berakhir',
        'kliring.retry_requested' => 'Ulang approve kliring',
        'wa.sent' => 'WhatsApp terkirim',
        'wa.failed' => 'WhatsApp gagal',
        'attempts.reset' => 'Jatah percobaan direset',
        'submission.restored' => 'Dipulihkan ke antrean verifikasi',
        'review.kept' => 'PDF tertolak disimpan untuk evaluasi',
        'review.deleted' => 'PDF evaluasi dihapus',
        'auth.failed' => 'Login admin gagal',
    ];

    public function label(): string
    {
        return self::LABELS[$this->action]
            ?? (str_starts_with($this->action, 'settings.') ? 'Pengaturan diubah' : $this->action);
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
