<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionCheck extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['criteria' => 'array', 'passed' => 'boolean'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
