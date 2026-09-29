<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Dspace\DspaceClient;
use App\Services\SubmissionQuery;
use Illuminate\Http\Request;

/** CSV export (Excel-compatible, UTF-8 BOM) of the currently filtered submission list. */
class ExportController extends Controller
{
    public function __invoke(Request $request, DspaceClient $dspace)
    {
        $filters = $request->only(['search', 'status', 'faculty', 'year']);
        $query = SubmissionQuery::filtered($filters)->with(['faculty', 'studyProgram', 'verifier']);
        ActivityLogger::admin('submission.exported', null, ['filters' => $filters]);

        $columns = ['NIM', 'Nama', 'Fakultas', 'Program Studi', 'Tahun Akademik', 'Judul', 'No. WA', 'Status',
            'Skor AI', 'Alasan Penolakan', 'Catatan Admin', 'Diverifikasi oleh', 'Tanggal Unggah', 'Path Cloud',
            'Link Share', 'Handle DSpace'];

        return response()->streamDownload(function () use ($query, $columns, $dspace) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            $query->orderBy('id')->chunk(500, function ($rows) use ($out, $dspace) {
                foreach ($rows as $s) {
                    /** @var Submission $s */
                    fputcsv($out, array_map([self::class, 'cell'], [
                        $s->nim, $s->nama, $s->faculty?->name, $s->studyProgram?->name,
                        $s->extra['tahun_akademik'] ?? $s->academic_year, $s->judul, $s->no_wa,
                        $s->adminStatusLabel(), $s->ai_score, implode(' | ', $s->rejection_reasons ?? []),
                        $s->admin_note, $s->verifier?->name, $s->created_at->format('Y-m-d H:i'),
                        $s->cloud_path, $s->share_url, $dspace->handleUrl($s->dspace_handle),
                    ]));
                }
            });
            fclose($out);
        }, 'skripsi_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
