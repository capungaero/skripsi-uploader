<?php

namespace App\Livewire\Admin;

use App\Models\Faculty;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Url]
    public string $year = '';

    public function render()
    {
        $base = Submission::query()->when($this->year, fn ($q) => $q->where('academic_year', $this->year));
        $counts = (clone $base)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $sum = fn (array $statuses) => collect($statuses)->sum(fn ($s) => $counts[$s] ?? 0);

        $stats = [
            ['Total unggahan', $counts->sum(), 'text-slate-800', null],
            ['Sedang dicek AI', $sum([Submission::CHECKING]), 'text-amber-600', Submission::CHECKING],
            ['Antrean verifikasi', $sum([Submission::AI_ACCEPTED]), 'text-blue-700', 'queue'],
            ['Diterima (disetujui)', $sum([Submission::APPROVED, Submission::DEPOSITED, Submission::DEPOSIT_FAILED]), 'text-brand-700', Submission::APPROVED],
            ['Ditolak', $sum(Submission::REJECTED), 'text-red-600', 'rejected'],
            ['Tersimpan di DSpace', $sum([Submission::DEPOSITED]), 'text-brand-800', Submission::DEPOSITED],
            ['Error AI / DSpace', $sum([Submission::AI_ERROR, Submission::DEPOSIT_FAILED]), 'text-orange-600', Submission::AI_ERROR],
        ];

        $perFaculty = (clone $base)->select('faculty_id',
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN status = 'ai_accepted' THEN 1 ELSE 0 END) as queue"),
            DB::raw("SUM(CASE WHEN status IN ('ai_rejected','admin_rejected') THEN 1 ELSE 0 END) as rejected"),
            DB::raw("SUM(CASE WHEN status IN ('approved','deposited','deposit_failed') THEN 1 ELSE 0 END) as approved"),
        )->groupBy('faculty_id')->get()->keyBy('faculty_id');

        return view('livewire.admin.dashboard', [
            'stats' => $stats,
            'faculties' => Faculty::orderBy('sort')->get(),
            'perFaculty' => $perFaculty,
            'years' => Submission::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'queue' => (clone $base)->with('faculty')->where('status', Submission::AI_ACCEPTED)->oldest()->limit(8)->get(),
        ]);
    }
}
