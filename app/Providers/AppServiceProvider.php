<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, so long-running queue workers pick up settings changed on the dashboard between jobs.
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        // Timestamps are stored in UTC; staff read them in local time (WIB).
        Carbon::macro('wib', fn (string $format = 'd/m/Y H:i') => $this->copy()->timezone(config('skripsi.timezone'))->format($format));

        // Behind a reverse proxy that mounts the app under a subpath (e.g. /skripsi_upload) and
        // strips that prefix before forwarding, the request itself looks like it's at "/" — so
        // url()/asset() must be forced to APP_URL instead of being inferred from the request.
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);
        }

        Gate::define('superadmin', fn (User $user) => $user->isSuperadmin());

        RateLimiter::for('nim-check', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('status', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('upload', fn (Request $r) => array_filter([
            Limit::perHour(5)->by('nim:'.$r->input('nim')),
            Limit::perHour(20)->by('ip:'.$r->ip()), // a lab / campus NAT shares one IP
            $r->bearerToken() ? Limit::perHour(8)->by('session:'.sha1($r->bearerToken())) : null, // a logged-in student cannot dodge the NIM limit by editing the form
        ]));
        // 8-character codes: slow guessing down per IP (a campus NAT shares one IP, hence the generous minute limit).
        RateLimiter::for('student-login', fn (Request $r) => [Limit::perMinute(15)->by($r->ip()), Limit::perHour(100)->by('login:'.$r->ip())]);
        RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
    }
}
