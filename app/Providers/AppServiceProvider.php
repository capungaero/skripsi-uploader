<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::define('superadmin', fn (User $user) => $user->isSuperadmin());

        RateLimiter::for('nim-check', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('status', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('upload', fn (Request $r) => [
            Limit::perHour(5)->by('nim:'.$r->input('nim')),
            Limit::perHour(20)->by('ip:'.$r->ip()), // a lab / campus NAT shares one IP
        ]);
        RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
    }
}
