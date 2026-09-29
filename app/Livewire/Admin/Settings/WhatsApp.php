<?php

namespace App\Livewire\Admin\Settings;

use App\Services\ActivityLogger;
use App\Services\Settings;
use App\Services\SubmissionNotifier;
use App\Services\WhatsApp\WhatsAppSender;
use Livewire\Attributes\Title;

#[Title('Notifikasi WhatsApp')]
class WhatsApp extends SettingsForm
{
    public string $testPhone = '';

    public ?string $testResult = null;

    protected function keys(): array
    {
        return array_merge(
            ['wa.enabled', 'wa.url', 'wa.method', 'wa.headers', 'wa.body_format', 'wa.body_template'],
            array_map(fn ($e) => 'wa.tpl.'.$e, array_keys(SubmissionNotifier::EVENTS)),
        );
    }

    public function save(Settings $settings): void
    {
        $json = function ($attr, $value, $fail) {
            if ($value !== null && $value !== '' && ! is_array(json_decode($value, true))) {
                $fail('Harus berupa objek JSON yang valid.');
            }
        };
        $this->persist($settings, [
            'form.wa__url' => ['nullable', 'url:https,http', 'max:500'],
            'form.wa__method' => ['required', 'in:POST,GET,PUT'],
            'form.wa__body_format' => ['required', 'in:json,form'],
            'form.wa__headers' => ['nullable', 'string', $json],
            'form.wa__body_template' => ['required', 'string', $json, function ($attr, $value, $fail) {
                if (! str_contains($value, '{phone}') || ! str_contains($value, '{message}')) {
                    $fail('Template body harus memuat {phone} dan {message}.');
                }
            }],
        ]);
    }

    public function sendTest(WhatsAppSender $sender): void
    {
        $this->validate(['testPhone' => ['required', 'regex:/^(\+?62|0)8[0-9]{7,12}$/']], ['testPhone.regex' => 'Nomor tidak valid.']);
        try {
            $response = $sender->send($this->testPhone, 'Tes notifikasi Skripsi Uploader Perpustakaan Unand.');
            $this->testResult = 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300);
        } catch (\Throwable $e) {
            $this->testResult = 'Gagal: '.mb_substr($e->getMessage(), 0, 300);
        }
        ActivityLogger::admin('wa.tested', null, ['phone' => $this->testPhone]);
    }

    public function render()
    {
        return view('livewire.admin.settings.whatsapp', ['events' => SubmissionNotifier::EVENTS]);
    }
}
