<?php

use App\Models\FormField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Form corrections for existing installs, applied to every form year:
 * jenjang gains "Spesialis"; programme becomes free text; the student mailbox moves before the WhatsApp number;
 * "tahun akademik lulus" is dropped. Also adds the "Sekolah Pascasarjana" faculty.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('form_configs')->pluck('id') as $configId) {
            $fields = DB::table('form_fields')->where('form_config_id', $configId);

            (clone $fields)->where('key', 'jenjang')->update(['options' => json_encode(FormField::JENJANG)]);
            (clone $fields)->where('key', 'prodi')->update([
                'type' => 'text', 'max_length' => 150, 'placeholder' => 'Ketik nama program studi',
            ]);
            (clone $fields)->where('key', 'tahun_akademik')->delete();

            $waSort = (clone $fields)->where('key', 'no_wa')->value('sort');
            (clone $fields)->where('key', 'email')->update([
                'label' => 'Akun email student (xxx@student.unand.ac.id)',
                'placeholder' => 'xxx@student.unand.ac.id',
                'pattern' => FormField::STUDENT_EMAIL_PATTERN,
                'required' => true,
            ] + ($waSort !== null ? ['sort' => $waSort - 5] : []));
        }

        if (! DB::table('faculties')->where('name', 'Sekolah Pascasarjana')->exists()) {
            DB::table('faculties')->insert([
                'code' => 'SPS', 'name' => 'Sekolah Pascasarjana', 'sort' => (int) DB::table('faculties')->max('sort') + 1,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversible: the removed field and the retyped programme field are not restored.
    }
};
