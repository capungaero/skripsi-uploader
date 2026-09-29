<?php

namespace App\Services\Cloud;

use App\Services\Settings;

class CloudStorageManager
{
    public const PROVIDERS = [
        'gdrive' => 'Google Drive',
        'onedrive' => 'OneDrive',
        'dropbox' => 'Dropbox',
    ];

    public function __construct(private Settings $settings) {}

    /** The provider selected on the dashboard, or null when cloud storage is disabled. */
    public function active(): ?CloudStorage
    {
        $name = $this->settings->get('cloud.provider');

        return isset(self::PROVIDERS[$name]) ? $this->driver($name) : null;
    }

    public function driver(string $name): CloudStorage
    {
        return match ($name) {
            'gdrive' => new GoogleDriveStorage($this->settings),
            'onedrive' => new OneDriveStorage($this->settings),
            'dropbox' => new DropboxStorage($this->settings),
            default => throw new CloudException("Unknown cloud provider {$name}."),
        };
    }
}
