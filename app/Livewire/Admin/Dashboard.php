<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\Faculty;
use App\Models\Submission;
use App\Services\Ai\AiClient;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Dspace\DspaceClient;
use App\Services\Kliring\KliringClient;
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

    /** Overview chart: month (12 months), week (7 days) or day (24 hours). */
    #[Url]
    public string $range = 'month';

    /** How many weeks (week view) or days (day view) back from today; 0 = the latest. */
    #[Url]
    public int $offset = 0;

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['month', 'week', 'day'], true) ? $range : 'month';
        $this->offset = 0;
    }

    /** -1 = one step back in time, +1 = forward (never past today). */
    public function step(int $direction): void
    {
        $this->offset = max(-520, min(0, $this->offset + ($direction <=> 0)));
    }

    public function render(AiClient $ai, CloudStorageManager $cloud, WhatsAppSender $wa, DspaceClient $dspace, KliringClient $kliring)
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
            'series' => $this->series(),
            'faculties' => Faculty::orderBy('sort')->get()
                ->sortByDesc(fn ($f) => $perFaculty[$f->id]->total ?? 0)->values(),
            'perFaculty' => $perFaculty,
            'years' => Submission::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'queue' => (clone $base)->with('faculty')->where('status', Submission::AI_ACCEPTED)->latest('id')->limit(6)->get(),
            'activity' => ActivityLog::with('submission:id,nim,nama')->whereNotIn('action', ActivityLog::NOISY)->latest('id')->limit(8)->get(),
            'systems' => [
                ['AI pemeriksa', $ai->isConfigured(), 'sparkles'],
                [$storage?->name() === 'local' ? 'Penyimpanan server lokal' : 'Cloud '.($storage ? CloudStorageManager::PROVIDERS[$storage->name()] : ''), (bool) $storage?->isConnected(), 'cloud'],
                ['WhatsApp', $wa->isConfigured(), 'chat'],
                ['DSpace', $dspace->isConfigured(), 'library'],
                ['Kliring', $kliring->isConfigured() && $kliring->hasSession(), 'check'],
            ],
        ]);
    }

    /**
     * Data of the overview chart.
     *
     * @return array{points: array<int, array{label: string, full: string, total: int}>, unit: string, caption: ?string, canForward: bool}
     */
    private function series(): array
    {
        return match ($this->range) {
            'week' => $this->weekly(),
            'day' => $this->hourly(),
            default => ['points' => $this->monthly(), 'unit' => 'Bulan terpilih', 'caption' => null, 'canForward' => false],
        };
    }

    /** Uploads per day over seven days (local time), the last day being today shifted back by whole weeks. */
    private function weekly(): array
    {
        $tz = config('skripsi.timezone');
        $last = Carbon::now($tz)->startOfDay()->addWeeks($this->offset);
        $first = $last->copy()->subDays(6);
        $counts = $this->bucketCounts($first->copy(), $last->copy()->endOfDay(), fn (Carbon $t) => $t->format('Y-m-d'));

        $points = [];
        for ($day = $first->copy(); $day <= $last; $day->addDay()) {
            $points[] = ['label' => $day->translatedFormat('D'), 'full' => $day->translatedFormat('l, j F Y'), 'total' => $counts[$day->format('Y-m-d')] ?? 0];
        }

        return ['points' => $points, 'unit' => 'Hari terpilih', 'caption' => $first->translatedFormat('j M').' – '.$last->translatedFormat('j M Y'), 'canForward' => $this->offset < 0];
    }

    /** Uploads per hour over one local day. */
    private function hourly(): array
    {
        $tz = config('skripsi.timezone');
        $day = Carbon::now($tz)->startOfDay()->addDays($this->offset);
        $counts = $this->bucketCounts($day->copy(), $day->copy()->endOfDay(), fn (Carbon $t) => (int) $t->format('G'));

        $points = [];
        for ($h = 0; $h < 24; $h++) {
            $points[] = ['label' => sprintf('%02d', $h), 'full' => sprintf('Jam %02d.00–%02d.59', $h, $h), 'total' => $counts[$h] ?? 0];
        }

        return ['points' => $points, 'unit' => 'Jam terpilih', 'caption' => $day->translatedFormat('l, j F Y'), 'canForward' => $this->offset < 0];
    }

    /**
     * Count uploads between two local-time instants, grouped by $key (applied to the local time).
     *
     * @return array<int|string, int>
     */
    private function bucketCounts(Carbon $from, Carbon $to, \Closure $key): array
    {
        $tz = config('skripsi.timezone');
        $counts = [];
        $times = Submission::query()
            ->when($this->year, fn ($q) => $q->where('academic_year', $this->year))
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->pluck('created_at');
        foreach ($times as $t) {
            $k = $key(Carbon::parse($t)->setTimezone($tz));
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        return $counts;
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
