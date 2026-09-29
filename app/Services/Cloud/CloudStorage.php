<?php

namespace App\Services\Cloud;

interface CloudStorage
{
    /** Provider key: gdrive, onedrive or dropbox. */
    public function name(): string;

    public function isConnected(): bool;

    /**
     * Make sure the nested folder exists and return its provider reference (id or path).
     *
     * @param  string[]  $segments  e.g. ["Koleksi Skripsi", "Fakultas Hukum"]
     */
    public function ensureFolder(array $segments): string;

    /** Upload (or overwrite) a file inside the folder and return the provider file id. */
    public function upload(string $localPath, string $folderRef, string $fileName): string;

    /** Lock the file against edits. Returns false when the provider/account cannot do it. */
    public function setReadOnly(string $fileId): bool;

    public function createShareLink(string $fileId): ?string;

    public function downloadTo(string $fileId, string $localPath): void;

    /** Move the file to the provider's trash / recycle bin. */
    public function delete(string $fileId): void;

    public function authorizeUrl(string $redirectUri, string $state): string;

    /** Exchange an OAuth authorization code and store the refresh token. */
    public function exchangeCode(string $code, string $redirectUri): void;
}
