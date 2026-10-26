<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentLogin extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'used_at' => 'datetime',
            'session_expires_at' => 'datetime',
        ];
    }

    /** 62812… → 0812… (the format the upload form uses). */
    public function localPhone(): ?string
    {
        return $this->phone ? '0'.substr($this->phone, 2) : null;
    }
}
