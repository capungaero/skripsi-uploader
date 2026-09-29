<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FormConfig extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort');
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function activate(): void
    {
        DB::transaction(function () {
            static::where('id', '!=', $this->id)->update(['is_active' => false]);
            $this->update(['is_active' => true]);
        });
    }

    public function cloneTo(string $academicYear): self
    {
        return DB::transaction(function () use ($academicYear) {
            $copy = static::create(['academic_year' => $academicYear, 'is_active' => false]);
            foreach ($this->fields as $field) {
                $copy->fields()->create($field->only([
                    'key', 'label', 'type', 'required', 'is_system', 'active', 'placeholder',
                    'help', 'options', 'pattern', 'max_length', 'sort',
                ]));
            }

            return $copy;
        });
    }
}
