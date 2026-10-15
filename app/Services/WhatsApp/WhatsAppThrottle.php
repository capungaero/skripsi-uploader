<?php

namespace App\Services\WhatsApp;

use App\Models\ActivityLog;
use App\Services\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps outgoing WhatsApp traffic slow and human-like so the sending number is less likely to be banned:
 * a random pause between messages, a cap per hour and per 24 hours, and an optional daily sending window.
 * Messages over a limit are not dropped: the job asks how long to wait and puts itself back in the queue.
 */
class WhatsAppThrottle
{
    private const NEXT_KEY = 'wa:next_at';

    /** Defaults suggested to admins for a freshly linked number (the "Terapkan saran" button). */
    public const SUGGESTED = ['wa.interval_min' => 15, 'wa.interval_max' => 40, 'wa.max_per_hour' => 30, 'wa.max_per_day' => 200,
        'wa.window_start' => '07:00', 'wa.window_end' => '21:00'];

    public function __construct(private Settings $settings) {}

    /** @return array{min: int, max: int, hour: int, day: int, start: ?string, end: ?string} 0 means "no limit" */
    public function limits(): array
    {
        $min = max(0, (int) $this->settings->get('wa.interval_min'));

        return [
            'min' => $min,
            'max' => max($min, (int) $this->settings->get('wa.interval_max')),
            'hour' => max(0, (int) $this->settings->get('wa.max_per_hour')),
            'day' => max(0, (int) $this->settings->get('wa.max_per_day')),
            'start' => $this->time($this->settings->get('wa.window_start')),
            'end' => $this->time($this->settings->get('wa.window_end')),
        ];
    }

    /** Seconds the next message has to wait; 0 means it may be sent right now. */
    public function waitSeconds(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $l = $this->limits();
        $wait = (int) Cache::get(self::NEXT_KEY, 0) - $now->getTimestamp();
        $wait = max($wait, $this->windowWait($now, $l));

        foreach ([['hour', 3600], ['day', 86400]] as [$key, $span]) {
            if ($l[$key] > 0 && count($times = $this->recentSends($now, $span)) >= $l[$key]) {
                sort($times);
                // The oldest send that still counts must age out of the window first.
                $wait = max($wait, $times[count($times) - $l[$key]] + $span - $now->getTimestamp() + 1);
            }
        }

        return max(0, $wait);
    }

    /** Call after a message was accepted by the gateway: starts the pause before the next one. */
    public function markSent(): void
    {
        $l = $this->limits();
        Cache::put(self::NEXT_KEY, now()->getTimestamp() + random_int($l['min'], $l['max']), 86400);
    }

    /** Extra random spread so deferred jobs do not all wake up at the same second. */
    public function jitter(): int
    {
        return random_int(1, max(3, $this->limits()['max']));
    }

    /** @return array{hour: int, day: int, pending: int, next_in: int} */
    public function usage(): array
    {
        $now = now();

        return [
            'hour' => count($this->recentSends($now, 3600)),
            'day' => count($this->recentSends($now, 86400)),
            'pending' => DB::table('jobs')->where('queue', 'notify')->count(),
            'next_in' => max(0, (int) Cache::get(self::NEXT_KEY, 0) - $now->getTimestamp()),
        ];
    }

    /** @return int[] unix times of messages (real and test) sent within the last $span seconds */
    private function recentSends(CarbonInterface $now, int $span): array
    {
        return ActivityLog::whereIn('action', ['wa.sent', 'wa.tested'])
            ->where('created_at', '>', $now->copy()->subSeconds($span))
            ->pluck('created_at')->map(fn ($t) => $t->getTimestamp())->all();
    }

    private function windowWait(CarbonInterface $now, array $l): int
    {
        if (! $l['start'] || ! $l['end'] || $l['start'] === $l['end']) {
            return 0; // no window configured
        }
        $local = $now->copy()->setTimezone(config('skripsi.timezone'));
        $t = $local->format('H:i');
        $inside = $l['start'] < $l['end'] ? ($t >= $l['start'] && $t < $l['end']) : ($t >= $l['start'] || $t < $l['end']);
        if ($inside) {
            return 0;
        }
        $opens = $local->copy()->setTimeFromTimeString($l['start']);
        if ($opens <= $local) {
            $opens->addDay();
        }

        return max(0, $opens->getTimestamp() - $now->getTimestamp());
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }
}
