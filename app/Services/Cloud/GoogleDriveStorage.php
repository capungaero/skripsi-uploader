<?php

namespace App\Services\Cloud;

/**
 * Google Drive v3 via OAuth (scope: drive). Works with My Drive or, when gdrive.parent_id
 * points to a Shared Drive folder, with Shared Drives (recommended for Google Workspace).
 */
class GoogleDriveStorage extends OAuthStorage
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private const CHUNK = 8 * 1024 * 1024; // multiple of 256 KiB

    public function name(): string
    {
        return 'gdrive';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function clientKeys(): array
    {
        return ['gdrive.client_id', 'gdrive.client_secret'];
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->settings->get('gdrive.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function ensureFolder(array $segments): string
    {
        $root = $this->settings->get('gdrive.parent_id') ?: 'root';

        return $this->resolveFolder($segments, $root, function (string $parent, string $name) {
            $existing = $this->findChild($parent, $name, self::FOLDER_MIME);
            if ($existing) {
                return $existing;
            }

            return $this->check($this->http()->post(self::API.'/files?supportsAllDrives=true', [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parent],
            ]), 'create folder')->json('id');
        });
    }

    public function upload(string $localPath, string $folderRef, string $fileName): string
    {
        $size = filesize($localPath);
        $existing = $this->findChild($folderRef, $fileName);

        $start = $existing
            ? $this->http()->withHeaders($this->uploadHeaders($size))
                ->patch(self::UPLOAD."/{$existing}?uploadType=resumable&supportsAllDrives=true", (object) [])
            : $this->http()->withHeaders($this->uploadHeaders($size))
                ->post(self::UPLOAD.'?uploadType=resumable&supportsAllDrives=true', [
                    'name' => $fileName,
                    'parents' => [$folderRef],
                    'mimeType' => 'application/pdf',
                ]);
        $sessionUrl = $this->check($start, 'start upload')->header('Location');
        if (! $sessionUrl) {
            throw new CloudException('Google Drive did not return an upload session URL.');
        }

        $handle = fopen($localPath, 'rb');
        try {
            $offset = 0;
            do {
                $chunk = fread($handle, self::CHUNK);
                $end = $offset + strlen($chunk) - 1;
                $response = $this->http()
                    ->withoutRedirecting() // Drive answers 308 "Resume Incomplete" between chunks
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                    ->withBody($chunk, 'application/pdf')
                    ->put($sessionUrl);
                if ($response->status() !== 308) {
                    $this->check($response, 'upload chunk');
                }
                $offset = $end + 1;
            } while ($response->status() === 308 && $offset < $size);
        } finally {
            fclose($handle);
        }

        return $existing ?: (string) $response->json('id');
    }

    public function setReadOnly(string $fileId): bool
    {
        $this->check($this->http()->patch(self::API."/files/{$fileId}?supportsAllDrives=true", [
            'contentRestrictions' => [['readOnly' => true, 'reason' => 'Disetujui UPT Perpustakaan Universitas Andalas']],
        ]), 'set read-only');

        return true;
    }

    public function createShareLink(string $fileId): ?string
    {
        $this->check($this->http()->post(self::API."/files/{$fileId}/permissions?supportsAllDrives=true", [
            'role' => 'reader',
            'type' => 'anyone',
        ]), 'create share link');

        return $this->check($this->http()->get(self::API."/files/{$fileId}", [
            'fields' => 'webViewLink',
            'supportsAllDrives' => 'true',
        ]), 'get share link')->json('webViewLink');
    }

    public function downloadTo(string $fileId, string $localPath): void
    {
        $this->check($this->http()->sink($localPath)->get(self::API."/files/{$fileId}", [
            'alt' => 'media',
            'supportsAllDrives' => 'true',
        ]), 'download');
    }

    public function delete(string $fileId): void
    {
        $this->check($this->http()->patch(self::API."/files/{$fileId}?supportsAllDrives=true", [
            'trashed' => true,
        ]), 'trash file');
    }

    private function findChild(string $parent, string $name, ?string $mime = null): ?string
    {
        $q = sprintf("name = '%s' and '%s' in parents and trashed = false", addcslashes($name, "'\\"), $parent);
        if ($mime) {
            $q .= " and mimeType = '{$mime}'";
        }

        return $this->check($this->http()->get(self::API.'/files', [
            'q' => $q,
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]), 'search')->json('files.0.id');
    }

    private function uploadHeaders(int $size): array
    {
        return ['X-Upload-Content-Type' => 'application/pdf', 'X-Upload-Content-Length' => (string) $size];
    }
}
