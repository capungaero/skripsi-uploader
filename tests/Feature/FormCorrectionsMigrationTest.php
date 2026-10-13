<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FormConfig;
use App\Models\FormField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormCorrectionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_existing_form_is_corrected_in_every_year(): void
    {
        // The form as it was before the corrections: pick-list programme, year field, email after WhatsApp.
        $old = FormConfig::active()->replicate(['academic_year'])->fill(['academic_year' => '2025/2026', 'is_active' => false]);
        $old->save();
        foreach (FormConfig::active()->fields as $field) {
            $old->fields()->create($field->only(['key', 'label', 'type', 'required', 'is_system', 'sort', 'options', 'pattern', 'placeholder', 'max_length']));
        }
        foreach (FormConfig::all() as $config) {
            $config->fields()->where('key', 'prodi')->update(['type' => 'program']);
            $config->fields()->where('key', 'jenjang')->update(['options' => ['D3', 'S1', 'S2', 'S3', 'Profesi']]);
            $config->fields()->where('key', 'email')->update(['label' => 'Email', 'pattern' => null, 'sort' => 45]);
            $config->fields()->create(['key' => 'tahun_akademik', 'label' => 'Tahun Akademik Lulus', 'type' => 'select', 'required' => true, 'is_system' => true, 'sort' => 70]);
        }
        Faculty::where('code', 'SPS')->delete();

        $migration = require database_path('migrations/2026_10_13_000200_apply_form_corrections.php');
        $migration->up();
        $migration->up(); // idempotent

        foreach (FormConfig::all() as $config) {
            $fields = $config->fields()->get()->keyBy('key');
            $this->assertArrayNotHasKey('tahun_akademik', $fields->all());
            $this->assertSame('text', $fields['prodi']->type);
            $this->assertSame(FormField::JENJANG, $fields['jenjang']->options);
            $this->assertContains('Spesialis', $fields['jenjang']->options);
            $this->assertSame('Akun email student (xxx@student.unand.ac.id)', $fields['email']->label);
            $this->assertSame(FormField::STUDENT_EMAIL_PATTERN, $fields['email']->pattern);
            $this->assertLessThan($fields['no_wa']->sort, $fields['email']->sort);
        }
        $this->assertSame(1, Faculty::where('name', 'Sekolah Pascasarjana')->count());
    }
}
