<?php

namespace App\Services\Cloud;

/**
 * Dropbox API v2 (offline refresh token). Folders are addressed by path; files by "id:..." ids.
 * File locking only exists on Dropbox Business team accounts; elsewhere setReadOnly() returns false.
 */
class DropboxStorage extends OAuthStorage
{
    private const API = 'https://api.dropboxapi.com/2';

    private const CONTENT = 'https://content.dropboxapi.com/2';

    private const CHUNK = 8 * 1024 * 1024;

    public function name(): string
    {
        return 'dropbox';
    }

    protected function tokenUrl(): string
    {
        return 'https://api.dropboxapi.com/oauth2/token';
    }

    protected function clientKeys(): array
    {
        return ['dropbox.app_key', 'dropbox.app_secret'];
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => $this->settings->get('dropbox.app_key'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'state' => $state,
        ]);
    }

    public function ensureFolder(array $segments): string
    {
        return $this->resolveFolder($segments, '', function (string $parent, string $name) {
            $path = $parent.'/'.$name;
            $response = $this->http()->post(self::API.'/files/create_folder_v2', ['path' => $path, 'autorename' => false]);
            if ($response->status() === 409 && str_contains($response->body(), 'conflict')) {
                return $path; // already exists
            }
            $this->check($response, 'create folder');

            return $path;
        });
    }

    public function upload(string $localPath, string $folderRef, string $fileName): string
    {
        $size = filesize($localPath);
        $handle = fopen($localPath, 'rb');
        try {
            $sessionId = $this->check($this->contentCall('files/upload_session/start', ['close' => false],
                fread($handle, self::CHUNK)), 'start upload')->json('session_id');
            $offset = ftell($handle);

            while ($offset < $size) {
                $this->check($this->contentCall('files/upload_session/append_v2', [
                    'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                    'close' => false,
                ], fread($handle, self::CHUNK)), 'upload chunk');
                $offset = ftell($handle);
            }
        } finally {
            fclose($handle);
        }

        return (string) $this->check($this->contentCall('files/upload_session/finish', [
            'cursor' => ['session_id' => $sessionId, 'offset' => $size],
            'commit' => ['path' => $folderRef.'/'.$fileName, 'mode' => 'overwrite', 'autorename' => false, 'mute' => true],
        ], ''), 'finish upload')->json('id');
    }

    public function setReadOnly(string $fileId): bool
    {
        $response = $this->http()->post(self::API.'/files/lock_file_batch', ['entries' => [['path' => $fileId]]]);

        return $response->successful();
    }

    public function createShareLink(string $fileId): ?string
    {
        $response = $this->http()->post(self::API.'/sharing/create_shared_link_with_settings', [
            'path' => $fileId,
            'settings' => ['requested_visibility' => 'public', 'audience' => 'public', 'access' => 'viewer'],
        ]);

        if ($response->status() === 409 && str_contains($response->body(), 'shared_link_already_exists')) {
            return $this->check($this->http()->post(self::API.'/sharing/list_shared_links', [
                'path' => $fileId,
                'direct_only' => true,
            ]), 'list share links')->json('links.0.url');
        }

        return $this->check($response, 'create share link')->json('url');
    }

    public function downloadTo(string $fileId, string $localPath): void
    {
        $this->check($this->http()->sink($localPath)
            ->withHeaders(['Dropbox-API-Arg' => $this->apiArg(['path' => $fileId])])
            ->withBody('', 'application/octet-stream')
            ->post(self::CONTENT.'/files/download'), 'download');
    }

    public function delete(string $fileId): void
    {
        $response = $this->http()->post(self::API.'/files/delete_v2', ['path' => $fileId]);
        if (! ($response->status() === 409 && str_contains($response->body(), 'not_found'))) {
            $this->check($response, 'delete');
        }
    }

    private function contentCall(string $endpoint, array $arg, string $body)
    {
        return $this->http()
            ->withHeaders(['Dropbox-API-Arg' => $this->apiArg($arg)])
            ->withBody($body, 'application/octet-stream')
            ->post(self::CONTENT.'/'.$endpoint);
    }

    /** Dropbox-API-Arg must be ASCII: escape non-ASCII characters as \uXXXX. */
    private function apiArg(array $arg): string
    {
        return json_encode($arg, JSON_UNESCAPED_SLASHES);
    }
}
