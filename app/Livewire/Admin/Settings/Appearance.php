<?php

namespace App\Livewire\Admin\Settings;

use App\Services\ActivityLogger;
use App\Services\Settings;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

#[Title('Tampilan Halaman Mahasiswa')]
class Appearance extends SettingsForm
{
    use WithFileUploads;

    public $banner;

    protected function keys(): array
    {
        return ['appearance.title', 'appearance.description', 'appearance.instructions', 'upload.max_mb', 'upload.max_attempts'];
    }

    public function save(Settings $settings): void
    {
        $this->persist($settings, [
            'form.appearance__title' => ['required', 'string', 'max:200'],
            'form.appearance__description' => ['nullable', 'string', 'max:1000'],
            'form.appearance__instructions' => ['nullable', 'string', 'max:5000'],
            'form.upload__max_mb' => ['required', 'integer', 'min:1', 'max:200'],
            'form.upload__max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        if ($this->banner) {
            $old = $settings->get('appearance.banner_path');
            $path = $this->banner->store('banner', 'public');
            $settings->set('appearance.banner_path', $path);
            if ($old) {
                Storage::disk('public')->delete($old);
            }
            $this->banner = null;
            ActivityLogger::admin('settings.banner_updated');
        }
    }

    public function removeBanner(Settings $settings): void
    {
        if ($old = $settings->get('appearance.banner_path')) {
            Storage::disk('public')->delete($old);
        }
        $settings->set('appearance.banner_path', null);
        ActivityLogger::admin('settings.banner_removed');
    }

    public function render(Settings $settings)
    {
        $banner = $settings->get('appearance.banner_path');

        return view('livewire.admin.settings.appearance', [
            'bannerUrl' => $banner ? Storage::disk('public')->url($banner) : null,
        ]);
    }
}
