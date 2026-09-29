<?php

namespace Database\Seeders;

use App\Models\AiCriterion;
use Illuminate\Database\Seeder;

class AiCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            [
                'key' => 'tanda_tangan_pembimbing',
                'label' => 'Tanda tangan dosen pembimbing',
                'instruction' => 'Periksa gambar halaman pengesahan/persetujuan. Harus ada tanda tangan (basah hasil pindai atau tanda tangan digital/QR) pada kolom dosen pembimbing, bukan hanya nama yang diketik. Skor rendah jika kolom tanda tangan kosong.',
                'page_keywords' => ['PENGESAHAN', 'PERSETUJUAN', 'PEMBIMBING', 'DISETUJUI'],
                'needs_image' => true,
                'min_score' => 70,
            ],
            [
                'key' => 'daftar_isi',
                'label' => 'Halaman daftar isi',
                'instruction' => 'Dokumen harus memiliki halaman DAFTAR ISI yang mencantumkan bab-bab beserta nomor halaman.',
                'page_keywords' => ['DAFTAR ISI'],
                'needs_image' => false,
                'min_score' => 70,
            ],
            [
                'key' => 'halaman_pengesahan',
                'label' => 'Halaman pengesahan',
                'instruction' => 'Dokumen harus memiliki halaman pengesahan (lembar pengesahan) yang memuat judul, nama mahasiswa, NIM, dan nama dosen pembimbing/penguji.',
                'page_keywords' => ['LEMBAR PENGESAHAN', 'HALAMAN PENGESAHAN', 'PENGESAHAN'],
                'needs_image' => true,
                'min_score' => 70,
            ],
            [
                'key' => 'struktur_bab',
                'label' => 'Struktur bab lengkap',
                'instruction' => 'Isi skripsi harus lengkap dari BAB I (Pendahuluan) sampai bab penutup (Kesimpulan dan Saran), serta memiliki DAFTAR PUSTAKA. Sebutkan bab yang tidak ditemukan.',
                'page_keywords' => ['BAB I', 'BAB II', 'BAB III', 'BAB IV', 'BAB V', 'BAB VI', 'KESIMPULAN', 'DAFTAR PUSTAKA'],
                'needs_image' => false,
                'min_score' => 70,
            ],
            [
                'key' => 'halaman_utuh',
                'label' => 'Tidak ada halaman kosong atau teks terpotong',
                'instruction' => 'Gunakan data halaman kosong dan gambar halaman contoh. Skor rendah jika ada halaman kosong di tengah isi (bukan pemisah yang disengaja), halaman terpotong, atau teks keluar dari tepi halaman. Sebutkan nomor halamannya.',
                'page_keywords' => [],
                'needs_image' => true,
                'min_score' => 60,
            ],
        ];

        foreach ($criteria as $i => $data) {
            AiCriterion::updateOrCreate(['key' => $data['key']], $data + ['sort' => ($i + 1) * 10]);
        }
    }
}
