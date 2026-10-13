<?php

namespace Database\Seeders;

use App\Models\FormConfig;
use App\Models\FormField;
use Illuminate\Database\Seeder;

class FormConfigSeeder extends Seeder
{
    public function run(): void
    {
        if (FormConfig::exists()) {
            return;
        }

        $config = FormConfig::create(['academic_year' => '2026/2027', 'is_active' => true]);

        $fields = [
            ['nim', 'NIM', 'text', true, true, ['pattern' => '^[0-9]{8,12}$', 'max_length' => 12, 'placeholder' => 'Contoh: 2011012345']],
            ['nama', 'Nama Lengkap', 'text', true, true, ['max_length' => 150]],
            ['email', 'Akun email student (xxx@student.unand.ac.id)', 'email', true, false, ['pattern' => FormField::STUDENT_EMAIL_PATTERN, 'placeholder' => 'xxx@student.unand.ac.id', 'max_length' => 150]],
            ['no_wa', 'Nomor WhatsApp', 'tel', true, true, ['pattern' => '^(\+?62|0)8[0-9]{7,12}$', 'placeholder' => '08xxxxxxxxxx', 'help' => 'Notifikasi hasil pengecekan dikirim ke nomor ini.']],
            ['fakultas', 'Fakultas', 'faculty', true, true, []],
            ['prodi', 'Program Studi', 'text', true, true, ['max_length' => 150, 'placeholder' => 'Ketik nama program studi']],
            ['jenjang', 'Jenjang Studi', 'select', true, false, ['options' => FormField::JENJANG]],
            ['judul', 'Judul Karya Ilmiah', 'textarea', true, true, ['max_length' => 500]],
            ['pembimbing', 'Dosen Pembimbing', 'text', false, false, ['help' => 'Pisahkan dengan titik koma (;) jika lebih dari satu.', 'max_length' => 300]],
            ['abstrak', 'Abstrak', 'textarea', false, false, ['max_length' => 5000]],
        ];

        foreach ($fields as $i => [$key, $label, $type, $required, $system, $extra]) {
            $config->fields()->create([
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'is_system' => $system,
                'sort' => ($i + 1) * 10,
            ] + $extra);
        }
    }
}
