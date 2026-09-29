<?php

namespace Tests;

use App\Models\AiCriterion;
use App\Models\Faculty;
use App\Models\Submission;
use App\Services\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingDir = storage_path('framework/testing/staging_'.Str::random(8));
        config(['skripsi.staging_dir' => $this->stagingDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingDir);
        parent::tearDown();
    }

    protected function settings(array $pairs = []): Settings
    {
        $settings = app(Settings::class);
        if ($pairs) {
            $settings->setMany($pairs);
        }

        return $settings;
    }

    protected function fakePdf(string $name = 'skripsi.pdf', int $kilobytes = 0): UploadedFile
    {
        $content = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        if ($kilobytes > 0) {
            $content .= str_repeat('0', $kilobytes * 1024);
        }

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** Valid request payload for the seeded form. */
    protected function uploadPayload(array $overrides = []): array
    {
        $faculty = Faculty::with('programs')->where('code', 'FFARMASI')->first();

        return array_merge([
            'nim' => '2011012345',
            'nama' => 'Siti Aminah',
            'no_wa' => '081234567890',
            'email' => 'siti@student.unand.ac.id',
            'fakultas' => $faculty->id,
            'prodi' => $faculty->programs->first()->id,
            'tahun_akademik' => '2026/2027',
            'judul' => 'Uji Aktivitas Antioksidan Ekstrak Daun Gambir',
            'pembimbing' => 'Dr. A; Dr. B',
            'file' => $this->fakePdf(),
        ], $overrides);
    }

    /** A submission sitting in staging, as if just uploaded. */
    protected function stagedSubmission(array $attributes = []): Submission
    {
        if (! is_dir($this->stagingDir)) {
            mkdir($this->stagingDir, 0777, true);
        }
        $name = 'test_'.Str::random(8).'.pdf';
        file_put_contents($this->stagingDir.DIRECTORY_SEPARATOR.$name, "%PDF-1.4\n%%EOF\n");
        $faculty = Faculty::with('programs')->where('code', 'FFARMASI')->first();

        return Submission::create(array_merge([
            'public_token' => Str::random(40),
            'academic_year' => '2026/2027',
            'nim' => '2011012345',
            'nama' => 'Siti Aminah',
            'no_wa' => '081234567890',
            'faculty_id' => $faculty->id,
            'study_program_id' => $faculty->programs->first()->id,
            'judul' => 'Uji Aktivitas Antioksidan Ekstrak Daun Gambir',
            'extra' => ['pembimbing' => 'Dr. A; Dr. B', 'tahun_akademik' => '2026/2027'],
            'status' => Submission::CHECKING,
            'original_filename' => 'skripsi.pdf',
            'file_size' => 16,
            'file_hash' => str_repeat('a', 64),
            'staging_path' => $name,
        ], $attributes));
    }

    /** pdftotext output: pages separated by form feeds. */
    protected function pdfText(): string
    {
        return implode("\f", [
            "UJI AKTIVITAS ANTIOKSIDAN\nSKRIPSI\nSiti Aminah\n2011012345",
            "LEMBAR PENGESAHAN\nDisetujui oleh Pembimbing\nDr. A",
            "DAFTAR ISI\nBAB I PENDAHULUAN ..... 1\nBAB V KESIMPULAN ..... 40",
            "BAB I\nPENDAHULUAN\n1.1 Latar Belakang",
            "BAB II\nTINJAUAN PUSTAKA",
            "BAB V\nKESIMPULAN DAN SARAN",
            "DAFTAR PUSTAKA\nAhmad, 2020.",
        ])."\f";
    }

    protected function aiAnswer(array $scores): array
    {
        $criteria = [];
        foreach (AiCriterion::where('active', true)->pluck('key') as $key) {
            $criteria[] = ['key' => $key, 'score' => $scores[$key] ?? 90, 'reason' => "Alasan {$key}"];
        }

        return ['choices' => [['message' => ['content' => json_encode(['criteria' => $criteria, 'summary' => 'ok'])]]]];
    }
}
