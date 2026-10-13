<?php

namespace Tests\Feature;

use App\Models\AiCriterion;
use App\Models\FormConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KaryaIlmiahMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_existing_install_gets_jenjang_field_and_new_wording(): void
    {
        // State of an install made before the change.
        $config = FormConfig::active();
        $config->fields()->where('key', 'jenjang')->delete();
        $config->fields()->where('key', 'judul')->update(['label' => 'Judul Skripsi']);
        $this->settings(['cloud.root_folder' => 'Koleksi Skripsi', 'appearance.title' => 'Unggah Skripsi — Unand',
            'wa.tpl.deposited' => 'Skripsi Anda sudah tersimpan.',
            'wa.tpl.ai_accepted' => 'File karya ilmiah Anda diterima.']); // reworded by an earlier version
        AiCriterion::where('key', 'struktur_bab')->update(['instruction' => 'Isi skripsi harus lengkap.']);

        $migration = require database_path('migrations/2026_10_12_000100_add_jenjang_and_karya_ilmiah_wording.php');
        $migration->up();
        $migration->up(); // idempotent

        $jenjang = $config->fields()->where('key', 'jenjang')->sole();
        $this->assertSame(['D3', 'S1', 'S2', 'S3', 'Profesi'], $jenjang->options); // as of that migration
        $this->assertSame($config->fields()->where('key', 'prodi')->value('sort') + 5, $jenjang->sort);
        $this->assertSame('Judul Karya Ilmiah', $config->fields()->where('key', 'judul')->value('label'));

        $settings = $this->settings();
        $this->assertSame('Koleksi Karya Ilmiah', $settings->get('cloud.root_folder'));
        $this->assertSame('Unggah Karya Ilmiah — Unand', $settings->get('appearance.title'));
        $this->assertSame('Karya Ilmiah Anda sudah tersimpan.', $settings->get('wa.tpl.deposited'));
        $this->assertSame('File Karya Ilmiah Anda diterima.', $settings->get('wa.tpl.ai_accepted'));
        $this->assertSame('Isi Karya Ilmiah harus lengkap.', AiCriterion::where('key', 'struktur_bab')->value('instruction'));
    }
}
