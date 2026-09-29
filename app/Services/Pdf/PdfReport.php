<?php

namespace App\Services\Pdf;

final class PdfReport
{
    /**
     * @param  array<int, string>  $pages  1-based page number => extracted text
     */
    public function __construct(
        public readonly int $pageCount,
        public readonly array $pages,
    ) {}

    /** Pages with (almost) no extractable text: blank pages, or scans without a text layer. */
    public function textlessPages(int $minChars = 15): array
    {
        return array_keys(array_filter($this->pages, fn ($t) => mb_strlen(trim($t)) < $minChars));
    }

    /** True when most pages have no text layer (scanned document). */
    public function isScanned(): bool
    {
        return $this->pageCount > 0 && count($this->textlessPages()) / $this->pageCount > 0.5;
    }

    /**
     * Pages whose text contains one of the keywords, earliest first.
     *
     * @param  string[]  $keywords
     * @return int[]
     */
    public function findPages(array $keywords, int $limit = 3): array
    {
        $found = [];
        foreach ($this->pages as $no => $text) {
            $upper = mb_strtoupper(preg_replace('/\s+/u', ' ', $text));
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && self::containsWord($upper, mb_strtoupper($keyword))) {
                    $found[] = $no;
                    break;
                }
            }
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /** Chapter headings ("BAB I PENDAHULUAN") with the page they start on. */
    public function chapterHeadings(): array
    {
        $headings = [];
        foreach ($this->pages as $no => $text) {
            if (preg_match_all('/^\s*(BAB\s+[IVXL0-9]+\b[^\n]{0,80})/mu', $text, $m)) {
                foreach ($m[1] as $heading) {
                    $headings[] = ['page' => $no, 'heading' => trim(preg_replace('/\s+/u', ' ', $heading))];
                }
            }
        }

        return $headings;
    }

    /** "BAB I" must not match "BAB II", so require a non-letter after the keyword. */
    private static function containsWord(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(?<![A-Z])'.preg_quote($needle, '/').'(?![A-Z])/u', $haystack);
    }
}
