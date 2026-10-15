<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardChartTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        // 15 Oct 2026, 10:30 WIB (03:30 UTC)
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:30:00', 'UTC'));
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@t.id', 'password' => 'password-123456', 'role' => 'superadmin', 'is_active' => true]));

        // all times below are UTC; in WIB they are +7h
        foreach ([
            '2026-10-15 03:10:00', // 15 Oct 10:10 WIB
            '2026-10-14 17:30:00', // 15 Oct 00:30 WIB (still "today" locally, yesterday in UTC)
            '2026-10-15 03:50:00', // 15 Oct 10:50 WIB
            '2026-10-12 05:00:00', // 12 Oct 12:00 WIB
            '2026-10-08 05:00:00', // before the 7-day window (window is 9-15 Oct)
        ] as $i => $at) {
            $this->stagedSubmission(['nim' => '30000000'.$i, 'created_at' => $at, 'updated_at' => $at, 'file_hash' => str_repeat((string) $i, 64)]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function points($t): array
    {
        return $t->viewData('series')['points'];
    }

    public function test_month_view_is_still_the_default_with_twelve_points(): void
    {
        $t = Livewire::test(Dashboard::class);

        $series = $t->viewData('series');
        $this->assertCount(12, $series['points']);
        $this->assertSame('Bulan terpilih', $series['unit']);
        $this->assertNull($series['caption']);
    }

    public function test_week_view_counts_uploads_per_local_day_over_seven_days(): void
    {
        $t = Livewire::test(Dashboard::class)->call('setRange', 'week');

        $points = $this->points($t);
        $this->assertCount(7, $points);
        $this->assertSame(['Hari terpilih'], [$t->viewData('series')['unit']]);
        $this->assertSame([0, 0, 0, 1, 0, 0, 3], array_column($points, 'total')); // 9..15 Oct; 12 Oct = 1, 15 Oct = 3 (incl. 00:30 WIB)
        $this->assertSame('9 Okt – 15 Okt 2026', $t->viewData('series')['caption']);
        $this->assertStringContainsString('15 Oktober 2026', $points[6]['full']);
    }

    public function test_hour_view_has_24_local_hours(): void
    {
        $t = Livewire::test(Dashboard::class)->call('setRange', 'day');

        $points = $this->points($t);
        $this->assertCount(24, $points);
        $this->assertSame('00', $points[0]['label']);
        $this->assertSame(1, $points[0]['total']);   // 00:30 WIB
        $this->assertSame(2, $points[10]['total']);  // 10:10 and 10:50 WIB
        $this->assertSame(3, array_sum(array_column($points, 'total')));
        $this->assertSame('Jam 10.00–10.59', $points[10]['full']);
    }

    public function test_stepping_back_moves_by_a_week_or_a_day_and_never_into_the_future(): void
    {
        $t = Livewire::test(Dashboard::class)->call('setRange', 'week')->call('step', 1)->assertSet('offset', 0);
        $t->call('step', -1)->assertSet('offset', -1);
        $series = $t->viewData('series');
        $this->assertSame('2 Okt – 8 Okt 2026', $series['caption']);
        $this->assertSame(1, array_sum(array_column($series['points'], 'total'))); // the 8 Oct upload
        $this->assertTrue($series['canForward']);

        $t->call('setRange', 'day')->assertSet('offset', 0)->call('step', -1)->assertSet('offset', -1);
        // yesterday (14 Oct local) has no uploads: the 17:30 UTC one belongs to 15 Oct in WIB
        $this->assertSame(0, array_sum(array_column($this->points($t), 'total')));
    }

    public function test_unknown_range_falls_back_to_month(): void
    {
        Livewire::test(Dashboard::class)->call('setRange', 'week')->call('setRange', 'bogus')->assertSet('range', 'month');
    }

    public function test_dashboard_renders_the_controls_for_each_range(): void
    {
        Livewire::test(Dashboard::class)->assertSee('Bulan')->assertSee('Minggu')->assertSee('Jam')
            ->call('setRange', 'week')->assertSee('9 Okt – 15 Okt 2026')->assertSee('Hari terpilih')
            ->call('setRange', 'day')->assertSee('Jam terpilih')->assertSee('Kamis, 15 Oktober 2026');
    }
}
