<?php

namespace App\Services\Cloud;

use App\Models\Submission;

/**
 * "Provider" that keeps files on this server instead of a cloud account.
 * Files live under config('skripsi.local_dir') with the same folder layout as the cloud providers
 * and are never reachable from the web: admins open them through the authenticated file route.
 * File ids are paths relative to the storage root.
 */
class LocalStorage implements CloudStorage
{
    private const TRASH = '_trash';

    public function name(): string
    {
        return 'local';
    }

    public function isConnected(): bool
    {
        $root = $this->root();
        if (! is_dir($root)) {
            @mkdir($root, 0770, true);
        }

        return is_dir($root) && is_writable($root);
    }

    public function ensureFolder(array $segments): string
    {
        $relative = implode('/', array_map(fn ($s) => Submission::safeFolderName((string) $s) ?: '_', $segments));
        $path = $this->resolve($relative);
        if (! is_dir($path) && ! @mkdir($path, 0770, true) && ! is_dir($path)) {
            throw new CloudException("Folder lokal tidak bisa dibuat: {$relative}");
        }

        return $relative;
    }

    public function upload(string $localPath, string $folderRef, string $fileName): string
    {
        $relative = $folderRef.'/'.Submission::safeFolderName($fileName);
        $target = $this->resolve($relative);
        $partial = $target.'.part';

        if (! @copy($localPath, $partial)) {
            throw new CloudException("File tidak bisa disalin ke penyimpanan lokal: {$relative}");
        }
        if (is_file($target)) {
            @chmod($target, 0660); // a re-upload replaces a file that was locked read-only
        }
        if (! @rename($partial, $target)) {
            @unlink($partial);
            throw new CloudException("File tidak bisa disimpan di penyimpanan lokal: {$relative}");
        }

        return $relative;
    }

    public function setReadOnly(string $fileId): bool
    {
        return @chmod($this->existing($fileId), 0440);
    }

    /** Local files are opened from the dashboard (authenticated), so there is no external link. */
    public function fileUrl(string $fileId): ?string
    {
        return null;
    }

    public function createShareLink(string $fileId): ?string
    {
        return null; // local files are never public
    }

    public function downloadTo(string $fileId, string $localPath): void
    {
        if (! @copy($this->existing($fileId), $localPath)) {
            throw new CloudException("File lokal tidak bisa dibaca: {$fileId}");
        }
    }

    /** Move to the trash folder instead of deleting, like the cloud providers do. */
    public function delete(string $fileId): void
    {
        $path = $this->resolve($fileId);
        if (! is_file($path)) {
            return;
        }

        $trash = $this->resolve(self::TRASH);
        if (! is_dir($trash)) {
            @mkdir($trash, 0770, true);
        }
        @chmod($path, 0660);
        if (! @rename($path, $trash.DIRECTORY_SEPARATOR.date('Ymd_His').'_'.basename($path))) {
            throw new CloudException("File lokal tidak bisa dipindah ke sampah: {$fileId}");
        }
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        throw new CloudException('Penyimpanan lokal tidak memakai otorisasi akun.');
    }

    public function exchangeCode(string $code, string $redirectUri): void
    {
        throw new CloudException('Penyimpanan lokal tidak memakai otorisasi akun.');
    }

    public function root(): string
    {
        return rtrim((string) config('skripsi.local_dir'), '/\\');
    }

    private function existing(string $fileId): string
    {
        $path = $this->resolve($fileId);
        if (! is_file($path)) {
            throw new CloudException("File tidak ditemukan di penyimpanan lokal: {$fileId}");
        }

        return $path;
    }

    /** Absolute path for a relative id; refuses anything that could leave the storage root. */
    private function resolve(string $relative): string
    {
        $parts = explode('/', str_replace('\\', '/', $relative));
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.' || str_contains($part, "\0")) {
                throw new CloudException('Path penyimpanan lokal tidak valid.');
            }
        }

        return $this->root().DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, array_filter($parts, 'strlen'));
    }
}
