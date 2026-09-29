<?php

namespace App\Livewire\Admin\Settings;

use App\Services\ActivityLogger;
use App\Services\Dspace\DspaceClient;
use App\Services\Settings;
use Livewire\Attributes\Title;

#[Title('DSpace')]
class Dspace extends SettingsForm
{
    /** "form_key = dc.field" per line */
    public string $mapText = '';

    /** "dc.field = value" per line */
    public string $fixedText = '';

    public ?string $testResult = null;

    protected function keys(): array
    {
        return ['dspace.enabled', 'dspace.base_url', 'dspace.email', 'dspace.password', 'dspace.auto_deposit'];
    }

    public function mount(Settings $settings): void
    {
        parent::mount($settings);
        $this->mapText = self::toLines((array) $settings->get('dspace.metadata_map'));
        $this->fixedText = self::toLines((array) $settings->get('dspace.fixed_metadata'));
    }

    public function save(Settings $settings): void
    {
        $this->persist($settings, [
            'form.dspace__base_url' => ['nullable', 'url:https,http', 'max:300', 'regex:~/server/?$~'],
            'form.dspace__email' => ['nullable', 'email'],
            'mapText' => ['nullable', 'string', 'max:5000'],
            'fixedText' => ['nullable', 'string', 'max:5000'],
        ]);
        $settings->setMany([
            'dspace.metadata_map' => self::fromLines($this->mapText),
            'dspace.fixed_metadata' => self::fromLines($this->fixedText),
        ]);
    }

    public function testLogin(DspaceClient $client): void
    {
        try {
            $client->login();
            $this->testResult = 'Berhasil login ke DSpace REST API.';
        } catch (\Throwable $e) {
            $this->testResult = 'Gagal: '.mb_substr($e->getMessage(), 0, 400);
        }
        ActivityLogger::admin('dspace.tested', null, ['ok' => str_starts_with($this->testResult, 'Berhasil')]);
    }

    public static function toLines(array $pairs): string
    {
        return collect($pairs)->map(fn ($v, $k) => "{$k} = {$v}")->implode("\n");
    }

    public static function fromLines(string $text): array
    {
        $pairs = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if ($key !== '') {
                    $pairs[$key] = $value;
                }
            }
        }

        return $pairs;
    }

    public function render()
    {
        return view('livewire.admin.settings.dspace');
    }
}
