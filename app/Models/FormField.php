<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    public const TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'faculty', 'program'];

    /** Fields the rest of the app depends on: they can be relabelled but never removed or made optional. */
    public const SYSTEM_KEYS = ['nim', 'nama', 'no_wa', 'fakultas', 'prodi', 'judul'];

    /** Keys stored in dedicated submission columns; everything else goes to submissions.extra. */
    public const COLUMN_KEYS = ['nim', 'nama', 'no_wa', 'judul'];

    /** Student mailbox: xxx@student.unand.ac.id */
    public const STUDENT_EMAIL_PATTERN = '^[A-Za-z0-9._%+\-]+@student\.unand\.ac\.id$';

    /** Options of the "jenjang" (level of study) select. */
    public const JENJANG = ['D3', 'S1', 'S2', 'S3', 'Profesi', 'Spesialis'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'options' => 'array',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(FormConfig::class, 'form_config_id');
    }
}
