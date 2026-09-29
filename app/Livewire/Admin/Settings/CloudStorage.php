<?php

namespace App\Livewire\Admin\Settings;

use App\Http\Controllers\Admin\CloudOAuthController;
use App\Services\ActivityLogger;
use App\Services\Cloud\CloudStorageManager;
use App\Services\Settings;
use Livewire\Attributes\Title;

#[Title('Penyimpanan Cloud')]
class CloudStorage extends SettingsForm
{
    public ?string $testResult = null;

    protected function keys(): array
    {
        return [
            'cloud.provider', 'cloud.root_folder', 'cloud.share_link',
            'gdrive.client_id', 'gdrive.client_secret', 'gdrive.parent_id',
            'onedrive.client_id', 'onedrive.client_secret', 'onedrive.tenant',
            'dropbox.app_key', 'dropbox.app_secret',
        ];
    }

    public function mount(Settings $settings): void
    {
        parent::mount($settings);
        $this->flash = session('status');
        $this->testResult = session('error');
    }

    public function save(Settings $settings): void
    {
        $this->persist($settings, [
            'form.cloud__provider' => ['required', 'in:none,gdrive,onedrive,dropbox'],
            'form.cloud__root_folder' => ['required', 'string', 'max:100', 'not_regex:/[\\\\\/:*?"<>|]/'],
        ]);
    }

    public function testActive(CloudStorageManager $manager, Settings $settings): void
    {
        $storage = $manager->active();
        if (! $storage) {
            $this->testResult = 'Tidak ada provider aktif.';

            return;
        }
        try {
            $ref = $storage->ensureFolder([(string) $settings->get('cloud.root_folder')]);
            $this->testResult = 'Berhasil: folder utama siap ('.$ref.').';
        } catch (\Throwable $e) {
            $this->testResult = 'Gagal: '.mb_substr($e->getMessage(), 0, 400);
        }
        ActivityLogger::admin('cloud.tested', null, ['provider' => $storage->name(), 'ok' => str_starts_with($this->testResult, 'Berhasil')]);
    }

    public function disconnect(string $provider, Settings $settings): void
    {
        abort_unless(isset(CloudStorageManager::PROVIDERS[$provider]), 404);
        $settings->set($provider.'.refresh_token', null);
        ActivityLogger::admin('cloud.disconnected', null, ['provider' => $provider]);
        $this->flash = CloudStorageManager::PROVIDERS[$provider].' diputus.';
    }

    public function render(CloudStorageManager $manager)
    {
        $status = [];
        foreach (array_keys(CloudStorageManager::PROVIDERS) as $name) {
            $status[$name] = [
                'connected' => $manager->driver($name)->isConnected(),
                'callback' => app(CloudOAuthController::class)->callbackUrl($name),
            ];
        }

        return view('livewire.admin.settings.cloud-storage', [
            'providers' => CloudStorageManager::PROVIDERS,
            'status' => $status,
        ]);
    }
}
