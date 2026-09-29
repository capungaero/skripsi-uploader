<?php

namespace Database\Seeders;

use App\Models\Faculty;
use Illuminate\Database\Seeder;

/**
 * Starting list of Universitas Andalas faculties and S1 programmes.
 * Admins must verify it and fill in the DSpace collection UUID per faculty on the dashboard.
 */
class FacultySeeder extends Seeder
{
    private const DATA = [
        'FH' => ['Fakultas Hukum', ['Ilmu Hukum']],
        'FAPERTA' => ['Fakultas Pertanian', ['Agroteknologi', 'Agribisnis', 'Ilmu Tanah', 'Proteksi Tanaman', 'Penyuluhan Pertanian']],
        'FK' => ['Fakultas Kedokteran', ['Pendidikan Dokter', 'Psikologi', 'Kebidanan']],
        'FMIPA' => ['Fakultas Matematika dan Ilmu Pengetahuan Alam', ['Matematika', 'Fisika', 'Kimia', 'Biologi']],
        'FEB' => ['Fakultas Ekonomi dan Bisnis', ['Ekonomi Pembangunan', 'Manajemen', 'Akuntansi', 'Ekonomi Islam']],
        'FAPET' => ['Fakultas Peternakan', ['Peternakan']],
        'FIB' => ['Fakultas Ilmu Budaya', ['Sastra Indonesia', 'Sastra Inggris', 'Sastra Minangkabau', 'Sastra Jepang', 'Ilmu Sejarah']],
        'FISIP' => ['Fakultas Ilmu Sosial dan Ilmu Politik', ['Sosiologi', 'Antropologi Sosial', 'Ilmu Politik', 'Administrasi Publik', 'Ilmu Hubungan Internasional', 'Ilmu Komunikasi']],
        'FT' => ['Fakultas Teknik', ['Teknik Mesin', 'Teknik Sipil', 'Teknik Industri', 'Teknik Elektro', 'Teknik Lingkungan']],
        'FFARMASI' => ['Fakultas Farmasi', ['Farmasi']],
        'FATETA' => ['Fakultas Teknologi Pertanian', ['Teknik Pertanian dan Biosistem', 'Teknologi Hasil Pertanian', 'Teknologi Industri Pertanian']],
        'FKM' => ['Fakultas Kesehatan Masyarakat', ['Kesehatan Masyarakat', 'Gizi']],
        'FKEP' => ['Fakultas Keperawatan', ['Keperawatan']],
        'FKG' => ['Fakultas Kedokteran Gigi', ['Pendidikan Dokter Gigi']],
        'FTI' => ['Fakultas Teknologi Informasi', ['Sistem Informasi', 'Teknik Komputer', 'Informatika']],
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::DATA as $code => [$name, $programs]) {
            $faculty = Faculty::updateOrCreate(['code' => $code], ['name' => $name, 'sort' => ++$sort]);
            foreach ($programs as $program) {
                $faculty->programs()->firstOrCreate(['name' => $program], ['level' => 'S1']);
            }
        }
    }
}
