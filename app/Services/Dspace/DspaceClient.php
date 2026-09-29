<?php

namespace App\Services\Dspace;

use App\Services\Settings;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DSpace 7/8/9 Server REST API client (base URL ends in /server).
 * Authentication: CSRF token (DSPACE-XSRF-TOKEN) + password login → JWT bearer.
 */
class DspaceClient
{
    private CookieJar $cookies;

    private ?string $csrf = null;

    private ?string $jwt = null;

    public function __construct(private Settings $settings)
    {
        $this->cookies = new CookieJar;
    }

    public function isConfigured(): bool
    {
        return $this->settings->bool('dspace.enabled') && filled($this->settings->get('dspace.base_url'))
            && filled($this->settings->get('dspace.email')) && filled($this->settings->get('dspace.password'));
    }

    public function login(): void
    {
        $this->remember($this->request()->get($this->api('security/csrf')));
        if (! $this->csrf) { // pre-7.6 servers hand the token out on any request
            $this->remember($this->request()->get($this->api('authn/status')));
        }

        $response = $this->remember($this->request()->asForm()->post($this->api('authn/login'), [
            'user' => $this->settings->get('dspace.email'),
            'password' => $this->settings->get('dspace.password'),
        ]));
        $this->check($response, 'login');

        $this->jwt = trim(str_ireplace('Bearer', '', (string) $response->header('Authorization')));
        if ($this->jwt === '') {
            throw new DspaceException('DSpace login returned no Authorization token.');
        }
    }

    /** Create an archived (published) item directly in the collection. Returns ['uuid' => ..., 'handle' => ...]. */
    public function createItem(string $collectionUuid, string $name, array $metadata): array
    {
        $response = $this->check($this->remember($this->authed()->post(
            $this->api('core/items').'?owningCollection='.urlencode($collectionUuid),
            [
                'name' => $name,
                'inArchive' => true,
                'discoverable' => true,
                'withdrawn' => false,
                'type' => 'item',
                'metadata' => $metadata,
            ],
        )), 'create item');

        return ['uuid' => $response->json('uuid') ?? $response->json('id'), 'handle' => $response->json('handle')];
    }

    public function getItem(string $uuid): array
    {
        return $this->check($this->remember($this->authed()->get($this->api("core/items/{$uuid}"))), 'get item')->json();
    }

    /** Existing ORIGINAL bundle uuid and its bitstream count, or null. */
    public function findBundle(string $itemUuid, string $bundleName = 'ORIGINAL'): ?array
    {
        $bundles = $this->check($this->remember($this->authed()->get($this->api("core/items/{$itemUuid}/bundles"))),
            'list bundles')->json('_embedded.bundles', []);

        foreach ($bundles as $bundle) {
            if (($bundle['name'] ?? '') === $bundleName) {
                $bitstreams = $this->check($this->remember($this->authed()->get(
                    $this->api("core/bundles/{$bundle['uuid']}/bitstreams"))), 'list bitstreams');

                return ['uuid' => $bundle['uuid'], 'bitstreams' => (int) $bitstreams->json('page.totalElements', 0)];
            }
        }

        return null;
    }

    public function createBundle(string $itemUuid, string $bundleName = 'ORIGINAL'): string
    {
        return $this->check($this->remember($this->authed()->post($this->api("core/items/{$itemUuid}/bundles"), [
            'name' => $bundleName,
            'metadata' => (object) [],
        ])), 'create bundle')->json('uuid');
    }

    public function uploadBitstream(string $bundleUuid, string $localPath, string $fileName): string
    {
        $properties = json_encode([
            'name' => $fileName,
            'metadata' => ['dc.title' => [['value' => $fileName, 'language' => null]]],
            'bundleName' => 'ORIGINAL',
        ]);

        $handle = fopen($localPath, 'rb');
        try {
            $response = $this->authed(json: false)->timeout(600)
                ->attach('file', $handle, $fileName, ['Content-Type' => 'application/pdf'])
                ->attach('properties', $properties, null, ['Content-Type' => 'application/json'])
                ->post($this->api("core/bundles/{$bundleUuid}/bitstreams"));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->check($this->remember($response), 'upload bitstream')->json('uuid');
    }

    /** Public handle URL for display, e.g. https://repo.unand.ac.id/handle/123456789/42 */
    public function handleUrl(?string $handle): ?string
    {
        if (! $handle) {
            return null;
        }

        return preg_replace('~/server/?$~', '', $this->base()).'/handle/'.$handle;
    }

    private function authed(bool $json = true): PendingRequest
    {
        if (! $this->jwt) {
            $this->login();
        }
        $request = $this->request()->withToken($this->jwt);

        // Multipart uploads must not carry the JSON content type.
        return $json ? $request->asJson() : $request;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(120)->acceptJson()->withOptions(['cookies' => $this->cookies]);

        return $this->csrf ? $request->withHeaders(['X-XSRF-TOKEN' => $this->csrf]) : $request;
    }

    /** DSpace rotates the CSRF token; always keep the newest one. */
    private function remember(Response $response): Response
    {
        $token = $response->header('DSPACE-XSRF-TOKEN');
        if ($token !== '') {
            $this->csrf = $token;
        }

        return $response;
    }

    private function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new DspaceException(sprintf('DSpace %s failed (HTTP %d): %s',
                $what, $response->status(), mb_substr($response->body(), 0, 500)), $response->status());
        }

        return $response;
    }

    private function base(): string
    {
        return rtrim((string) $this->settings->get('dspace.base_url'), '/');
    }

    private function api(string $path): string
    {
        return $this->base().'/api/'.ltrim($path, '/');
    }
}
