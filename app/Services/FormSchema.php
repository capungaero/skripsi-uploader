<?php

namespace App\Services;

use App\Models\Faculty;
use App\Models\FormConfig;
use App\Models\FormField;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Turns the active form configuration into a client schema and matching server-side validation rules. */
class FormSchema
{
    public function __construct(private Settings $settings) {}

    public function forClient(?FormConfig $config): array
    {
        $banner = $this->settings->get('appearance.banner_path');

        return [
            'appearance' => [
                'title' => $this->settings->get('appearance.title'),
                'description' => $this->settings->get('appearance.description'),
                'instructions' => $this->settings->get('appearance.instructions'),
                'banner_url' => $banner ? Storage::disk('public')->url($banner) : null,
            ],
            'open' => $config !== null,
            'academic_year' => $config?->academic_year,
            'max_mb' => $this->maxMb(),
            'max_attempts' => max(1, $this->settings->int('upload.max_attempts')),
            'fields' => $config ? $config->fields->where('active', true)->values()->map(fn (FormField $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'type' => $f->type,
                'required' => $f->required,
                'placeholder' => $f->placeholder,
                'help' => $f->help,
                'options' => $f->options ?? [],
                'pattern' => $f->pattern,
                'max_length' => $f->max_length,
            ])->all() : [],
            'faculties' => Faculty::where('is_active', true)->orderBy('sort')->with(['programs' => fn ($q) => $q->where('is_active', true)])
                ->get()->map(fn (Faculty $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'programs' => $f->programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all(),
                ])->all(),
        ];
    }

    public function maxMb(): int
    {
        return max(1, $this->settings->int('upload.max_mb') ?: 50);
    }

    /** @return array<string, array> */
    public function rules(FormConfig $config, array $input): array
    {
        $rules = [
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.($this->maxMb() * 1024)],
        ];

        foreach ($config->fields->where('active', true) as $field) {
            $rules[$field->key] = $this->fieldRules($field, $input);
        }

        return $rules;
    }

    public function attributes(FormConfig $config): array
    {
        return $config->fields->pluck('label', 'key')->all() + ['file' => 'File PDF'];
    }

    private function fieldRules(FormField $field, array $input): array
    {
        $rules = [$field->required || $field->is_system ? 'required' : 'nullable'];

        switch ($field->type) {
            case 'email':
                $rules[] = 'email:rfc';
                break;
            case 'number':
                $rules[] = 'numeric';
                break;
            case 'select':
                $rules[] = Rule::in($field->options ?? []);
                break;
            case 'faculty':
                $rules[] = 'integer';
                $rules[] = Rule::exists('faculties', 'id')->where('is_active', true);
                break;
            case 'program':
                $rules[] = 'integer';
                $rules[] = Rule::exists('study_programs', 'id')->where('is_active', true)
                    ->where('faculty_id', (int) ($input['fakultas'] ?? 0));
                break;
            default:
                $rules[] = 'string';
        }

        if (in_array($field->type, ['text', 'email', 'tel', 'textarea'], true)) {
            $rules[] = 'max:'.($field->max_length ?: ($field->type === 'textarea' ? 5000 : 255));
        }
        if ($field->pattern) {
            $rules[] = 'regex:/'.str_replace('/', '\/', $field->pattern).'/u';
        }

        return $rules;
    }
}
