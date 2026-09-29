<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Thin wrapper around poppler-utils (pdftotext, pdftoppm).
 * pdftoppm is optional: without it blank-page detection falls back to text only and no images are sent to the AI.
 */
class PdfInspector
{
    public function inspect(string $path): PdfReport
    {
        $result = Process::timeout(180)->run([$this->bin('pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);

        if (! $result->successful()) {
            if ($this->isMissingBinary($result->exitCode(), $result->errorOutput())) {
                // Server problem, not the student's: let the job fail and retry instead of rejecting.
                throw new \RuntimeException('pdftotext (poppler-utils) is not installed or not on POPPLER_PATH.');
            }
            throw new InvalidPdfException($this->describeError($result->errorOutput()));
        }

        // pdftotext separates pages with form feeds and ends the last page with one too.
        $chunks = explode("\f", $result->output());
        if (count($chunks) > 1 && trim(end($chunks)) === '') {
            array_pop($chunks);
        }

        $pages = [];
        foreach ($chunks as $i => $text) {
            $pages[$i + 1] = $this->cleanText($text);
        }

        if ($pages === [] || (count($pages) === 1 && trim($pages[1]) === '' && $result->output() === '')) {
            throw new InvalidPdfException('File PDF tidak memiliki halaman yang dapat dibaca.');
        }

        return new PdfReport(count($pages), $pages);
    }

    /** Render one page to a JPEG (longest side $maxSide px) and return the bytes. */
    public function renderJpeg(string $path, int $page, int $maxSide = 1400): ?string
    {
        return $this->render($path, $page, ['-jpeg', '-jpegopt', 'quality=70', '-scale-to', (string) $maxSide], 'jpg');
    }

    /**
     * Whether a page is visually (almost) empty, using a tiny grayscale render.
     * Returns null when pdftoppm is unavailable.
     */
    public function isVisuallyBlank(string $path, int $page): ?bool
    {
        $pgm = $this->render($path, $page, ['-gray', '-r', '20'], 'pgm');

        return $pgm === null ? null : self::pgmIsBlank($pgm);
    }

    /** Parse a binary PGM (P5) and decide whether it is near-uniform white. */
    public static function pgmIsBlank(string $pgm, float $minMean = 245.0, float $maxStdDev = 6.0): bool
    {
        if (! preg_match('/^P5\s+(?:#[^\n]*\n\s*)*(\d+)\s+(\d+)\s+(\d+)\s/s', $pgm, $m, PREG_OFFSET_CAPTURE)) {
            throw new \InvalidArgumentException('Not a binary PGM image.');
        }

        $pixels = substr($pgm, strlen($m[0][0]));
        $count = strlen($pixels);
        if ($count === 0) {
            return true;
        }

        $sum = 0;
        $sumSq = 0;
        foreach (unpack('C*', $pixels) as $value) {
            $sum += $value;
            $sumSq += $value * $value;
        }
        $mean = $sum / $count;
        $std = sqrt(max(0, $sumSq / $count - $mean * $mean));

        return $mean >= $minMean && $std <= $maxStdDev;
    }

    private function render(string $path, int $page, array $options, string $ext): ?string
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdfpage_'.Str::random(12);
        $cmd = array_merge([$this->bin('pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-singlefile'],
            $options, [$path, $prefix]);

        try {
            $result = Process::timeout(60)->run($cmd);
            $file = $prefix.'.'.$ext;
            if (! $result->successful() || ! is_file($file)) {
                return null;
            }

            return file_get_contents($file);
        } catch (\Throwable) {
            return null; // binary missing
        } finally {
            @unlink($prefix.'.'.$ext);
        }
    }

    private function bin(string $name): string
    {
        $dir = (string) config('skripsi.poppler_path');

        return $dir === '' ? $name : rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$name;
    }

    private function cleanText(string $text): string
    {
        // Collapse the wide spacing produced by -layout while keeping line structure.
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text);

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text));
    }

    private function describeError(string $stderr): string
    {
        $lower = strtolower($stderr);

        return str_contains($lower, 'password') || str_contains($lower, 'encrypt')
            ? 'File PDF terkunci kata sandi/terenkripsi. Unggah PDF tanpa proteksi.'
            : 'File PDF rusak atau tidak dapat dibaca. Pastikan file dapat dibuka normal lalu unggah ulang.';
    }

    private function isMissingBinary(?int $exitCode, string $stderr): bool
    {
        $lower = strtolower($stderr);

        return $exitCode === 127 || str_contains($lower, 'not recognized') || str_contains($lower, 'command not found');
    }
}
