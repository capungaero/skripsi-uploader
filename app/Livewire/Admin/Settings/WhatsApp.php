<?php

namespace App\Livewire\Admin\Settings;

use App\Services\ActivityLogger;
use App\Services\Settings;
use App\Services\SubmissionNotifier;
use App\Services\WhatsApp\LoginInbox;
use App\Services\WhatsApp\OpenWaClient;
use App\Services\WhatsApp\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppThrottle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;

/** Link the uploader's WhatsApp number by scanning a QR (OpenWA); a generic HTTP gateway stays available under "Lanjutan". */
#[Title('Notifikasi WhatsApp')]
class WhatsApp extends SettingsForm
{
    public string $testPhone = '';

    public ?string $testResult = null;

    /** @var array<string, mixed>|null OpenWA session record */
    public ?array $session = null;

    public ?string $qr = null;

    public ?string $error = null;

    protected function keys(): array
    {
        return array_merge(
            ['wa.enabled', 'wa.driver', 'wa.url', 'wa.method', 'wa.headers', 'wa.body_format', 'wa.body_template',
                'wa.interval_min', 'wa.interval_max', 'wa.max_per_hour', 'wa.max_per_day', 'wa.window_start', 'wa.window_end',
                'login.listen', 'login.enabled', 'login.wa_number', 'login.code_minutes', 'login.session_hours', 'wa.tpl.login_code', 'wa.tpl.login_help'],
            array_map(fn ($e) => 'wa.tpl.'.$e, array_keys(SubmissionNotifier::EVENTS)),
        );
    }

    public function mount(Settings $settings): void
    {
        parent::mount($settings);
        $this->form['wa__openwa_url'] = (string) $settings->get('wa.openwa_url');
        $this->form['wa__openwa_key'] = '';
        $this->refresh();
    }

    /** Re-read the session (and its QR while waiting for a scan). Also called by the page's polling. */
    public function refresh(): void
    {
        $client = app(OpenWaClient::class);
        $this->error = null;
        $this->qr = null;
        $this->session = null;
        if (! $client->hasSession()) {
            return;
        }

        try {
            $this->session = $client->session();
            if (($this->session['status'] ?? null) === 'qr_ready') {
                $this->qr = $client->qr();
            }
            // The number the page tells students to message: the linked number, unless the admin already set one.
            $settings = app(Settings::class);
            if (($this->session['status'] ?? null) === 'ready' && ! empty($this->session['phone']) && ! $settings->has('login.wa_number')) {
                $settings->set('login.wa_number', preg_replace('/\D+/', '', (string) $this->session['phone']));
                $this->form['login__wa_number'] = $settings->get('login.wa_number');
            }
        } catch (\Throwable $e) {
            $this->error = 'OpenWA tidak bisa dihubungi: '.mb_substr($e->getMessage(), 0, 250);
        }
    }

    public function saveConnection(Settings $settings): void
    {
        $this->validate(['form.wa__openwa_url' => ['required', 'url:http,https', 'max:300']],
            ['form.wa__openwa_url.required' => 'Isi alamat OpenWA.', 'form.wa__openwa_url.url' => 'Alamat harus diawali http:// atau https://.']);

        $pairs = ['wa.openwa_url' => rtrim(trim($this->form['wa__openwa_url']), '/')];
        if (trim((string) $this->form['wa__openwa_key']) !== '') {
            $pairs['wa.openwa_key'] = trim($this->form['wa__openwa_key']);
        }
        $settings->setMany($pairs);
        $this->form['wa__openwa_key'] = '';
        ActivityLogger::admin('settings.updated', null, ['keys' => array_keys($pairs)]);

        $this->flash = 'Sambungan OpenWA disimpan.';
        $this->refresh();
    }

    /** Create/start the session; the QR appears on the next poll. */
    public function connect(): void
    {
        try {
            app(OpenWaClient::class)->start();
            ActivityLogger::admin('wa.connect_requested');
        } catch (\Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 300);

            return;
        }
        $this->refresh();
    }

    public function disconnect(): void
    {
        try {
            app(OpenWaClient::class)->logout();
            ActivityLogger::admin('wa.disconnected');
            $this->flash = 'Nomor WhatsApp diputus.';
        } catch (\Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 300);
        }
        $this->refresh();
    }

    public function save(Settings $settings): void
    {
        $number = trim((string) ($this->form['login__wa_number'] ?? ''));
        $this->form['login__wa_number'] = match (true) {
            $number === '' => null,
            preg_match('/\d/', $number) !== 1 => $number, // no digits at all: let validation reject it
            default => WhatsAppSender::normalizePhone($number),
        };

        $json = function ($attr, $value, $fail) {
            if ($value !== null && $value !== '' && ! is_array(json_decode($value, true))) {
                $fail('Harus berupa objek JSON yang valid.');
            }
        };
        $custom = ($this->form['wa__driver'] ?? 'openwa') === 'custom';
        $this->persist($settings, [
            'form.wa__driver' => ['required', 'in:openwa,custom'],
            'form.login__wa_number' => ['nullable', 'regex:/^62[0-9]{8,13}$/'],
            'form.login__code_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'form.login__session_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'form.wa__interval_min' => ['required', 'integer', 'min:0', 'max:3600'],
            'form.wa__interval_max' => ['required', 'integer', 'gte:form.wa__interval_min', 'max:3600'],
            'form.wa__max_per_hour' => ['required', 'integer', 'min:0', 'max:5000'],
            'form.wa__max_per_day' => ['required', 'integer', 'min:0', 'max:50000'],
            'form.wa__window_start' => ['nullable', 'date_format:H:i', 'required_with:form.wa__window_end'],
            'form.wa__window_end' => ['nullable', 'date_format:H:i', 'required_with:form.wa__window_start'],
            'form.wa__url' => ['nullable', 'url:https,http', 'max:500'],
            'form.wa__method' => ['required', 'in:POST,GET,PUT'],
            'form.wa__body_format' => ['required', 'in:json,form'],
            'form.wa__headers' => ['nullable', 'string', $json],
            'form.wa__body_template' => [$custom ? 'required' : 'nullable', 'string', $json, function ($attr, $value, $fail) use ($custom) {
                if ($custom && (! str_contains((string) $value, '{phone}') || ! str_contains((string) $value, '{message}'))) {
                    $fail('Template body harus memuat {phone} dan {message}.');
                }
            }],
        ]);
        $this->refresh();
    }

    /** Fill the limit fields with the suggested safe values (still needs Simpan). */
    public function applySuggested(): void
    {
        foreach (WhatsAppThrottle::SUGGESTED as $key => $value) {
            $this->form[self::field($key)] = $value;
        }
    }

    public function sendTest(WhatsAppSender $sender): void
    {
        $this->validate(['testPhone' => ['required', 'regex:/^(\+?62|0)8[0-9]{7,12}$/']], ['testPhone.regex' => 'Nomor tidak valid.']);
        if ($sender->usesOpenWa() && ($this->session['status'] ?? null) !== 'ready') {
            $this->testResult = 'Gagal: nomor WhatsApp belum tersambung. Scan QR dulu.';

            return;
        }
        try {
            $response = $sender->send($this->testPhone, 'Tes notifikasi Karya Ilmiah Uploader Perpustakaan Unand.');
            $this->testResult = 'Terkirim (HTTP '.$response->status().').';
        } catch (\Throwable $e) {
            $this->testResult = 'Gagal: '.mb_substr($e->getMessage(), 0, 300);
        }
        ActivityLogger::admin('wa.tested', null, ['phone' => '***'.substr($this->testPhone, -3)]);
    }

    public function render(OpenWaClient $client, WhatsAppThrottle $throttle)
    {
        $status = $this->session['status'] ?? null;

        return view('livewire.admin.settings.whatsapp', [
            'events' => SubmissionNotifier::EVENTS,
            'configured' => $client->isConfigured(),
            'status' => $status,
            'statusLabel' => OpenWaClient::statusLabel($status),
            'connecting' => in_array($status, OpenWaClient::CONNECTING, true),
            'hasSession' => $client->hasSession(),
            'usage' => $throttle->usage(),
            'timezone' => config('skripsi.timezone'),
            'inbox' => [
                'last' => ($t = Cache::get(LoginInbox::LAST_POLL_KEY)) ? Carbon::createFromTimestamp($t) : null,
                'error' => Cache::get(LoginInbox::LAST_ERROR_KEY),
            ],
        ]);
    }
}
