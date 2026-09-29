<?php

namespace App\Livewire\Admin\Settings;

use App\Models\FormConfig;
use App\Models\FormField;
use App\Services\ActivityLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Field Form Unggah')]
class FormFields extends Component
{
    public ?int $configId = null;

    /** @var array<int, array> field id => editable props */
    public array $fields = [];

    public array $new = ['key' => '', 'label' => '', 'type' => 'text'];

    public string $cloneYear = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->configId = FormConfig::active()?->id ?? FormConfig::latest('id')->value('id');
        $this->loadFields();
    }

    public function updatedConfigId(): void
    {
        $this->loadFields();
    }

    private function loadFields(): void
    {
        $this->fields = [];
        $config = $this->configId ? FormConfig::with('fields')->find($this->configId) : null;
        foreach ($config?->fields ?? [] as $f) {
            $this->fields[$f->id] = [
                'key' => $f->key, 'is_system' => $f->is_system, 'type' => $f->type,
                'label' => $f->label, 'required' => $f->required, 'active' => $f->active,
                'placeholder' => $f->placeholder, 'help' => $f->help, 'pattern' => $f->pattern,
                'max_length' => $f->max_length, 'sort' => $f->sort,
                'options' => implode("\n", $f->options ?? []),
            ];
        }
    }

    public function save(): void
    {
        $this->validate([
            'fields.*.label' => ['required', 'string', 'max:150'],
            'fields.*.sort' => ['required', 'integer', 'min:0'],
            'fields.*.max_length' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'fields.*.pattern' => ['nullable', 'string', 'max:200', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && @preg_match('/'.str_replace('/', '\/', $value).'/u', '') === false) {
                    $fail('Pola regex tidak valid.');
                }
            }],
        ]);

        foreach ($this->fields as $id => $data) {
            $field = FormField::where('form_config_id', $this->configId)->findOrFail($id);
            $options = array_values(array_filter(array_map('trim', explode("\n", (string) $data['options']))));
            $field->update([
                'label' => $data['label'],
                // System fields feed other features (WhatsApp, DSpace, folders) and stay required.
                'required' => $field->is_system ? true : (bool) $data['required'],
                'active' => $field->is_system ? true : (bool) $data['active'],
                'placeholder' => $data['placeholder'] ?: null,
                'help' => $data['help'] ?: null,
                'pattern' => $data['pattern'] ?: null,
                'max_length' => $data['max_length'] ?: null,
                'sort' => (int) $data['sort'],
                'options' => $field->type === 'select' ? $options : null,
            ]);
        }
        ActivityLogger::admin('settings.form_fields_updated', null, ['form_config_id' => $this->configId]);
        $this->loadFields();
        $this->flash = 'Field form disimpan.';
    }

    public function addField(): void
    {
        $this->validate([
            'new.key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,48}$/', Rule::notIn(FormField::SYSTEM_KEYS),
                Rule::unique('form_fields', 'key')->where('form_config_id', $this->configId)],
            'new.label' => ['required', 'string', 'max:150'],
            'new.type' => ['required', Rule::in(['text', 'email', 'tel', 'number', 'textarea', 'select'])],
        ], ['new.key.regex' => 'Key huruf kecil, angka, dan garis bawah; diawali huruf.']);

        FormField::create($this->new + [
            'form_config_id' => $this->configId,
            'required' => false,
            'sort' => (int) (FormField::where('form_config_id', $this->configId)->max('sort') + 10),
        ]);
        ActivityLogger::admin('settings.form_field_added', null, $this->new);
        $this->new = ['key' => '', 'label' => '', 'type' => 'text'];
        $this->loadFields();
    }

    public function deleteField(int $id): void
    {
        $field = FormField::where('form_config_id', $this->configId)->findOrFail($id);
        abort_if($field->is_system, 422, 'Field sistem tidak bisa dihapus.');
        $field->delete();
        ActivityLogger::admin('settings.form_field_deleted', null, ['key' => $field->key]);
        $this->loadFields();
    }

    public function cloneConfig(): void
    {
        $this->validate(['cloneYear' => ['required', 'regex:/^\d{4}\/\d{4}$/', 'unique:form_configs,academic_year']],
            ['cloneYear.regex' => 'Format tahun akademik: 2027/2028.']);

        $copy = FormConfig::with('fields')->findOrFail($this->configId)->cloneTo($this->cloneYear);
        ActivityLogger::admin('settings.form_cloned', null, ['from' => $this->configId, 'year' => $this->cloneYear]);
        $this->configId = $copy->id;
        $this->cloneYear = '';
        $this->loadFields();
        $this->flash = "Konfigurasi {$copy->academic_year} dibuat (belum aktif).";
    }

    public function activate(): void
    {
        $config = FormConfig::findOrFail($this->configId);
        $config->activate();
        ActivityLogger::admin('settings.form_activated', null, ['year' => $config->academic_year]);
        $this->flash = "Form {$config->academic_year} sekarang aktif untuk mahasiswa.";
    }

    public function render()
    {
        return view('livewire.admin.settings.form-fields', [
            'configs' => FormConfig::orderByDesc('academic_year')->get(),
            'current' => $this->configId ? FormConfig::find($this->configId) : null,
        ]);
    }
}
