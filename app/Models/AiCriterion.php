<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCriterion extends Model
{
    protected $table = 'ai_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'page_keywords' => 'array',
            'needs_image' => 'boolean',
            'required' => 'boolean',
            'active' => 'boolean',
            'min_score' => 'integer',
        ];
    }
}
