<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\Faculty;
use App\Models\Submission;
use App\Services\Ai\AiClient;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Dspace\DspaceClient;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Support\Carbon;
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

    public function render(AiClient $ai, CloudStorageManager $cloud, WhatsAppSender $wa, DspaceClient $dspace)
    {
        $base = Submission::query()->when($this->year, fn ($q) => $q->where('academic_year', $this->year));
        $counts = (clone $base)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $counts[$s] ?? 0);

        $perFaculty = (clone $base)->select('faculty_id',
            DB::raw('COUNT(*) as total'),
            DB::raw("SUM(CASE WHEN status = 'ai_accepted' THEN 1 ELSE 0 END) as queue"),
            DB::raw("SUM(CASE WHEN status IN ('ai_rejected','admin_rejected') THEN 1 ELSE 0 END) as rejected"),
            DB::raw("SUM(CASE WHEN status IN ('approved','deposited','deposit_failed') THEN 1 ELSE 0 END) as approved"),
        )->groupBy('faculty_id')->get()->keyBy('faculty_id');

        $storage = $cloud->active();

        return view('livewire.admin.dashboard', [
            'total' => (int) $counts->sum(),
            'checking' => $sum([Submission::CHECKING]),
            'queueCount' => $sum([Submission::AI_ACCEPTED]),
            'approved' => $sum([Submission::APPROVED, Submission::DEPOSITED, Submission::DEPOSIT_FAILED]),
            'rejected' => $sum(Submission::REJECTED),
            'deposited' => $sum([Submission::DEPOSITED]),
            'errorCount' => $sum([Submission::AI_ERROR, Submission::DEPOSIT_FAILED]),
            'monthly' => $this->monthly(),
            'faculties' => Faculty::orderBy('sort')->get()
                ->sortByDesc(fn ($f) => $perFaculty[$f->id]->total ?? 0)->values(),
            'perFaculty' => $perFaculty,
            'years' => Submission::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'queue' => (clone $base)->with('faculty')->where('status', Submission::AI_ACCEPTED)->oldest()->limit(6)->get(),
            'activity' => ActivityLog::with('submission:id,nim,nama')->latest('id')->limit(6)->get(),
            'systems' => [
                ['AI pemeriksa', $ai->isConfigured(), 'sparkles'],
                ['Cloud '.($storage ? CloudStorageManager::PROVIDERS[$storage->name()] : ''), (bool) $storage?->isConnected(), 'cloud'],
                ['WhatsApp', $wa->isConfigured(), 'chat'],
                ['DSpace', $dspace->isConfigured(), 'library'],
            ],
        ]);
    }

    /** Upload counts for the last 12 months, oldest first. */
    private function monthly(): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(11);
        $expr = DB::getDriverName() === 'pgsql' ? "to_char(created_at, 'YYYY-MM')" : "strftime('%Y-%m', created_at)";
        $rows = Submission::where('created_at', '>=', $start)
            ->when($this->year, fn ($q) => $q->where('academic_year', $this->year))
            ->select(DB::raw("{$expr} as ym"), DB::raw('COUNT(*) as total'))
            ->groupBy('ym')->pluck('total', 'ym');

        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $series[] = ['label' => $month->translatedFormat('M'), 'full' => $month->translatedFormat('F Y'), 'total' => (int) ($rows[$month->format('Y-m')] ?? 0)];
        }

        return $series;
    }
}
