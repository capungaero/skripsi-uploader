<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing installs: add the "Jenjang Studi" select to every form year and switch stored texts
 * from "skripsi" to "Karya Ilmiah" (the uploader now also takes tesis, disertasi and tugas akhir).
 */
return new class extends Migration
{
    private const WORDING = [
        '/(Unggah|Koleksi|Judul) Skripsi/' => '$1 Karya Ilmiah',
        '/Skripsi (Anda|sudah|tersimpan)/' => 'Karya Ilmiah $1',
        '/([Ff]ile|PDF|unggah|Pengunggahan|Isi) skripsi/' => '$1 Karya Ilmiah',
        // Texts reworded by an earlier run of this migration still have lower-case "karya ilmiah".
        '/karya ilmiah/i' => 'Karya Ilmiah',
    ];

    public function up(): void
    {
        foreach (DB::table('form_configs')->pluck('id') as $configId) {
            if (DB::table('form_fields')->where('form_config_id', $configId)->where('key', 'jenjang')->exists()) {
                continue;
            }
            $prodiSort = DB::table('form_fields')->where('form_config_id', $configId)->where('key', 'prodi')->value('sort');
            DB::table('form_fields')->insert([
                'form_config_id' => $configId,
                'key' => 'jenjang',
                'label' => 'Jenjang Studi',
                'type' => 'select',
                'required' => true,
                'is_system' => false,
                'active' => true,
                'options' => json_encode(['D3', 'S1', 'S2', 'S3', 'Profesi']),
                'sort' => $prodiSort !== null ? $prodiSort + 5
                    : (int) DB::table('form_fields')->where('form_config_id', $configId)->max('sort') + 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->reword('form_fields', 'label');
        $this->reword('ai_criteria', 'instruction');

        // Only plain-text settings an admin may have saved; secrets are encrypted and never contain this wording.
        $settings = DB::table('settings')->where('is_secret', false)
            ->where(fn ($q) => $q->where('key', 'like', 'appearance.%')->orWhere('key', 'like', 'wa.tpl.%')
                ->orWhere('key', 'cloud.root_folder'));
        $this->reword($settings, 'value');
        app(Settings::class)->flush();
    }

    public function down(): void
    {
        DB::table('form_fields')->where('key', 'jenjang')->delete();
    }

    private function reword($table, string $column): void
    {
        $query = is_string($table) ? DB::table($table) : $table;
        $query->where(fn ($q) => $q->where($column, 'like', '%kripsi%')->orWhere($column, 'like', '%arya ilmiah%'));
        foreach ($query->get(['id', $column]) as $row) {
            $text = preg_replace(array_keys(self::WORDING), array_values(self::WORDING), $row->$column);
            if ($text !== $row->$column) {
                DB::table(is_string($table) ? $table : 'settings')->where('id', $row->id)->update([$column => $text]);
            }
        }
    }
};
