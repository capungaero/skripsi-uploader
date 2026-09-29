<?php

namespace App\Livewire\Admin\Settings;

use App\Services\ActivityLogger;
use App\Services\Settings;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Base for settings pages: `$form` mirrors setting keys (dots replaced by "__").
 * Secret keys are write-only: they load empty and are only saved when a new value is typed.
 */
#[Layout('layouts.admin')]
abstract class SettingsForm extends Component
{
    public array $form = [];

    public ?string $flash = null;

    /** @return string[] setting keys edited on this page */
    abstract protected function keys(): array;

    public function mount(Settings $settings): void
    {
        foreach ($this->keys() as $key) {
            $this->form[self::field($key)] = in_array($key, Settings::SECRETS, true) ? '' : $settings->get($key);
        }
    }

    protected function persist(Settings $settings, array $rules = []): void
    {
        if ($rules) {
            $this->validate($rules);
        }

        $pairs = [];
        foreach ($this->keys() as $key) {
            $value = $this->form[self::field($key)] ?? null;
            if (in_array($key, Settings::SECRETS, true) && ($value === '' || $value === null)) {
                continue; // keep the stored secret
            }
            $pairs[$key] = is_string($value) ? trim($value) : $value;
        }
        $settings->setMany($pairs);
        ActivityLogger::admin('settings.updated', null, ['keys' => array_keys($pairs)]);

        foreach (Settings::SECRETS as $secret) {
            if (array_key_exists(self::field($secret), $this->form)) {
                $this->form[self::field($secret)] = '';
            }
        }
        $this->flash = 'Pengaturan disimpan.';
    }

    /** Whether a secret already has a stored value (for the "tersimpan" hint). */
    public function hasSecret(string $key): bool
    {
        return app(Settings::class)->has($key);
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
