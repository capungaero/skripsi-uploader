<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    public const TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'faculty', 'program'];

    /** Fields the rest of the app depends on: they can be relabelled but never removed or made optional. */
    public const SYSTEM_KEYS = ['nim', 'nama', 'no_wa', 'fakultas', 'prodi', 'tahun_akademik', 'judul'];

    /** Keys stored in dedicated submission columns; everything else goes to submissions.extra. */
    public const COLUMN_KEYS = ['nim', 'nama', 'no_wa', 'judul'];

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
