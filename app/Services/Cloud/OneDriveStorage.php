<?php

namespace App\Services\Cloud;

use Illuminate\Support\Facades\Http;

/**
 * OneDrive / OneDrive for Business through Microsoft Graph (delegated OAuth, Files.ReadWrite.All).
 * OneDrive has no per-file content lock for regular accounts, so setReadOnly() only strips edit links.
 */
class OneDriveStorage extends OAuthStorage
{
    private const API = 'https://graph.microsoft.com/v1.0/me/drive';

    private const SCOPE = 'offline_access Files.ReadWrite.All';

    private const CHUNK = 32 * 320 * 1024; // 10 MiB, must be a multiple of 320 KiB

    public function name(): string
    {
        return 'onedrive';
    }

    protected function tokenUrl(): string
    {
        return $this->authority().'/token';
    }

    protected function clientKeys(): array
    {
        return ['onedrive.client_id', 'onedrive.client_secret'];
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return $this->authority().'/authorize?'.http_build_query([
            'client_id' => $this->settings->get('onedrive.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    public function ensureFolder(array $segments): string
    {
        $rootId = $this->check($this->http()->get(self::API.'/root', ['$select' => 'id']), 'get root')->json('id');

        return $this->resolveFolder($segments, $rootId, function (string $parent, string $name) {
            $children = $this->check($this->http()->get(self::API."/items/{$parent}/children", [
                '$filter' => "name eq '".str_replace("'", "''", $name)."'",
                '$select' => 'id,name,folder',
            ]), 'list folder')->json('value', []);
            foreach ($children as $child) {
                if (isset($child['folder']) && mb_strtolower($child['name']) === mb_strtolower($name)) {
                    return $child['id'];
                }
            }

            return $this->check($this->http()->post(self::API."/items/{$parent}/children", [
                'name' => $name,
                'folder' => (object) [],
                '@microsoft.graph.conflictBehavior' => 'fail',
            ]), 'create folder')->json('id');
        });
    }

    public function upload(string $localPath, string $folderRef, string $fileName): string
    {
        $size = filesize($localPath);
        $session = $this->check($this->http()->post(
            self::API."/items/{$folderRef}:/".rawurlencode($fileName).':/createUploadSession',
            ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']],
        ), 'create upload session')->json('uploadUrl');

        $handle = fopen($localPath, 'rb');
        try {
            $offset = 0;
            while ($offset < $size) {
                $chunk = fread($handle, self::CHUNK);
                $end = $offset + strlen($chunk) - 1;
                // The pre-authenticated upload URL must NOT receive the Authorization header.
                $response = Http::timeout(180)
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                    ->withBody($chunk, 'application/octet-stream')
                    ->put($session);
                $this->check($response, 'upload chunk');
                $offset = $end + 1;
            }
        } finally {
            fclose($handle);
        }

        return (string) $response->json('id');
    }

    public function setReadOnly(string $fileId): bool
    {
        $permissions = $this->check($this->http()->get(self::API."/items/{$fileId}/permissions"), 'list permissions')
            ->json('value', []);
        foreach ($permissions as $permission) {
            if (in_array('write', $permission['roles'] ?? [], true) && ! in_array('owner', $permission['roles'] ?? [], true)) {
                $this->http()->delete(self::API."/items/{$fileId}/permissions/{$permission['id']}");
            }
        }

        return false; // edit access removed, but no true content lock exists on OneDrive
    }

    public function createShareLink(string $fileId): ?string
    {
        $response = $this->http()->post(self::API."/items/{$fileId}/createLink", ['type' => 'view', 'scope' => 'anonymous']);
        if ($response->status() === 403 || $response->status() === 400) { // tenant forbids anonymous links
            $response = $this->http()->post(self::API."/items/{$fileId}/createLink", ['type' => 'view', 'scope' => 'organization']);
        }

        return $this->check($response, 'create share link')->json('link.webUrl');
    }

    public function downloadTo(string $fileId, string $localPath): void
    {
        $this->check($this->http()->sink($localPath)->get(self::API."/items/{$fileId}/content"), 'download');
    }

    public function delete(string $fileId): void
    {
        $response = $this->http()->delete(self::API."/items/{$fileId}");
        if ($response->status() !== 404) {
            $this->check($response, 'delete');
        }
    }

    private function authority(): string
    {
        return 'https://login.microsoftonline.com/'.($this->settings->get('onedrive.tenant') ?: 'common').'/oauth2/v2.0';
    }
}
