<?php

namespace App\Services\Cloud;

use App\Models\CloudFolder;
use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Shared OAuth refresh-token handling and folder-id caching for the cloud adapters. */
abstract class OAuthStorage implements CloudStorage
{
    public function __construct(protected Settings $settings) {}

    /** Token endpoint URL. */
    abstract protected function tokenUrl(): string;

    /** Settings keys: [client id, client secret]. */
    abstract protected function clientKeys(): array;

    public function isConnected(): bool
    {
        [$id, $secret] = $this->clientKeys();

        return $this->settings->has($id) && $this->settings->has($secret)
            && $this->settings->has($this->name().'.refresh_token');
    }

    public function exchangeCode(string $code, string $redirectUri): void
    {
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (empty($data['refresh_token'])) {
            throw new CloudException('Provider did not return a refresh token. Revoke the app access and connect again.');
        }

        $this->settings->set($this->name().'.refresh_token', $data['refresh_token']);
        Cache::forget($this->tokenCacheKey());
    }

    protected function accessToken(): string
    {
        if (! $this->isConnected()) {
            throw new CloudException('Cloud storage '.$this->name().' is not connected.');
        }

        return Cache::remember($this->tokenCacheKey(), now()->addMinutes(45), function () {
            $data = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->settings->get($this->name().'.refresh_token'),
            ]);
            if (! empty($data['refresh_token'])) { // Microsoft rotates refresh tokens
                $this->settings->set($this->name().'.refresh_token', $data['refresh_token']);
            }

            return $data['access_token'];
        });
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(120)->retry(2, 1000,
            fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()),
            throw: false);
    }

    protected function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new CloudException(sprintf('%s %s failed (HTTP %d): %s',
                $this->name(), $what, $response->status(), mb_substr($response->body(), 0, 400)), $response->status());
        }

        return $response;
    }

    /**
     * Walk the folder path, creating missing folders, caching every level in cloud_folders.
     *
     * @param  callable(string $parentRef, string $name): string  $findOrCreate
     */
    protected function resolveFolder(array $segments, string $rootRef, callable $findOrCreate): string
    {
        $parent = $rootRef;
        $path = '';
        foreach ($segments as $segment) {
            $path .= '/'.$segment;
            $cached = CloudFolder::where('provider', $this->name())->where('path', $path)->value('remote_id');
            if ($cached) {
                $parent = $cached;

                continue;
            }
            $parent = $findOrCreate($parent, $segment);
            CloudFolder::updateOrCreate(['provider' => $this->name(), 'path' => $path], ['remote_id' => $parent]);
        }

        return $parent;
    }

    public static function forgetFolders(string $provider): void
    {
        CloudFolder::where('provider', $provider)->delete();
    }

    private function tokenRequest(array $params): array
    {
        [$idKey, $secretKey] = $this->clientKeys();
        $response = Http::asForm()->timeout(30)->post($this->tokenUrl(), $params + [
            'client_id' => $this->settings->get($idKey),
            'client_secret' => $this->settings->get($secretKey),
        ]);

        return $this->check($response, 'token request')->json();
    }

    private function tokenCacheKey(): string
    {
        return 'cloud_token_'.$this->name().'_'.md5((string) $this->settings->get($this->name().'.refresh_token'));
    }
}
