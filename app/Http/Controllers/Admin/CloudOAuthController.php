<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Cloud\OAuthStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** "Hubungkan akun" button: OAuth authorization-code flow that stores a refresh token. */
class CloudOAuthController extends Controller
{
    public function __construct(private CloudStorageManager $manager) {}

    public function redirect(Request $request, string $provider)
    {
        abort_unless(isset(CloudStorageManager::PROVIDERS[$provider]), 404);

        $state = Str::random(40);
        $request->session()->put('oauth_state', [$provider, $state]);

        return redirect()->away($this->manager->driver($provider)->authorizeUrl($this->callbackUrl($provider), $state));
    }

    public function callback(Request $request, string $provider)
    {
        abort_unless(isset(CloudStorageManager::PROVIDERS[$provider]), 404);
        [$expectedProvider, $expectedState] = $request->session()->pull('oauth_state', [null, null]);
        abort_unless($expectedProvider === $provider && hash_equals((string) $expectedState, (string) $request->query('state')), 403);

        if ($request->filled('error')) {
            return redirect()->route('admin.settings.cloud')
                ->with('error', 'Otorisasi ditolak: '.$request->query('error_description', $request->query('error')));
        }

        try {
            $this->manager->driver($provider)->exchangeCode((string) $request->query('code'), $this->callbackUrl($provider));
        } catch (\Throwable $e) {
            return redirect()->route('admin.settings.cloud')->with('error', 'Gagal menghubungkan: '.$e->getMessage());
        }

        OAuthStorage::forgetFolders($provider); // a different account has different folder ids
        ActivityLogger::admin('cloud.connected', null, ['provider' => $provider]);

        return redirect()->route('admin.settings.cloud')
            ->with('status', CloudStorageManager::PROVIDERS[$provider].' berhasil terhubung.');
    }

    public function callbackUrl(string $provider): string
    {
        return route('admin.oauth.callback', $provider);
    }
}
