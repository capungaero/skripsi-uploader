<?php

namespace Tests\Unit;

use App\Models\Submission;
use App\Services\Pdf\PdfInspector;
use App\Services\Pdf\PdfReport;
use App\Services\WhatsApp\WhatsAppSender;
use PHPUnit\Framework\TestCase;

class PdfReportTest extends TestCase
{
    private function report(): PdfReport
    {
        return new PdfReport(5, [
            1 => 'JUDUL SKRIPSI UJI AKTIVITAS',
            2 => 'LEMBAR PENGESAHAN disetujui pembimbing',
            3 => '',
            4 => "BAB II\nTINJAUAN PUSTAKA",
            5 => "BAB I\nPENDAHULUAN",
        ]);
    }

    public function test_bab_i_does_not_match_bab_ii(): void
    {
        $this->assertSame([5], $this->report()->findPages(['BAB I']));
        $this->assertSame([4], $this->report()->findPages(['BAB II']));
    }

    public function test_find_pages_is_case_insensitive_and_limited(): void
    {
        $this->assertSame([2], $this->report()->findPages(['pengesahan']));
        $this->assertSame([4], $this->report()->findPages(['BAB'], 1));
    }

    public function test_chapter_headings_and_textless_pages(): void
    {
        $headings = $this->report()->chapterHeadings();

        $this->assertSame([4, 5], array_column($headings, 'page'));
        $this->assertSame([3], $this->report()->textlessPages());
        $this->assertFalse($this->report()->isScanned());
        $this->assertTrue((new PdfReport(3, [1 => '', 2 => '', 3 => 'x']))->isScanned());
    }

    public function test_pgm_blank_detection(): void
    {
        $white = "P5\n4 4\n255\n".str_repeat(chr(255), 16);
        $text = "P5\n# comment\n4 4\n255\n".str_repeat(chr(255), 8).str_repeat(chr(0), 8);

        $this->assertTrue(PdfInspector::pgmIsBlank($white));
        $this->assertFalse(PdfInspector::pgmIsBlank($text));
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('6281234567890', WhatsAppSender::normalizePhone('0812-3456-7890'));
        $this->assertSame('6281234567890', WhatsAppSender::normalizePhone('+62 812 3456 7890'));
        $this->assertSame('6281234567890', WhatsAppSender::normalizePhone('81234567890'));
    }

    public function test_safe_file_names(): void
    {
        $this->assertSame('Siti_Aminah', Submission::safeName('  Siti   Aminah '));
        $this->assertSame('AhmadYani', Submission::safeName('Ahmad/Yani?'));
        $this->assertSame('Fakultas Hukum', Submission::safeFolderName('Fakultas  Hukum:'));
    }
}
