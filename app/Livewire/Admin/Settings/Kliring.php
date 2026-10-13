<?php

namespace App\Livewire\Admin\Settings;

use App\Jobs\ApproveInKliring;
use App\Models\Submission;
use App\Services\ActivityLogger;
use App\Services\Kliring\KliringClient;
use App\Services\Kliring\KliringException;
use App\Services\Kliring\KliringSessionExpired;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Connect the kliringlib session: the site wants a CAPTCHA, so a person types it here once per session. */
#[Layout('layouts.admin')]
#[Title('Kliring')]
class Kliring extends Component
{
    public ?bool $connected = null;

    public ?string $captcha = null;

    public string $code = '';

    public string $nim = '';

    public ?string $lookup = null;

    public ?string $flash = null;

    public ?string $failure = null;

    public function mount(KliringClient $kliring): void
    {
        $this->refreshConnection($kliring);
    }

    public function connect(KliringClient $kliring): void
    {
        $this->failure = null;
        try {
            $login = $kliring->startLogin();
            // The half-open login session stays on the server; only the CAPTCHA image goes to the browser.
            Cache::put($this->stateKey(), $login['state'], now()->addMinutes(10));
            $this->captcha = $login['captcha'];
            $this->code = '';
        } catch (\Throwable $e) {
            $this->failure = 'Tidak bisa membuka halaman login kliring: '.mb_substr($e->getMessage(), 0, 300);
        }
    }

    public function login(KliringClient $kliring): void
    {
        $this->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Ketik kode verifikasi pada gambar.']);
        $state = Cache::pull($this->stateKey());
        if (! $state) {
            $this->failure = 'Kode verifikasi kedaluwarsa. Gambar baru sudah dimuat.';
            $this->connect($kliring);

            return;
        }

        try {
            $kliring->completeLogin($state, $this->code);
        } catch (KliringException $e) {
            ActivityLogger::admin('kliring.login_failed', null, ['error' => $e->getMessage()]);
            $this->connect($kliring); // a used CAPTCHA cannot be retried
            $this->failure = $e->getMessage();

            return;
        }

        ActivityLogger::admin('kliring.connected');
        $this->connected = true;
        $this->captcha = null;
        $this->failure = null;
        $this->flash = 'Tersambung ke kliring. '.ApproveInKliring::dispatchWaiting().' unggahan yang menunggu diproses ulang.';
    }

    public function disconnect(KliringClient $kliring): void
    {
        $kliring->disconnect();
        ActivityLogger::admin('kliring.disconnected');
        $this->connected = false;
        $this->flash = 'Sesi kliring diputus.';
    }

    public function processWaiting(): void
    {
        $this->flash = ApproveInKliring::dispatchWaiting().' unggahan dimasukkan ke antrean kliring.';
    }

    /** Read-only lookup, useful to confirm the integration sees a student before relying on it. */
    public function checkNim(KliringClient $kliring): void
    {
        $this->validate(['nim' => ['required', 'regex:/^[0-9A-Za-z.\-]{5,30}$/']]);
        try {
            $request = $kliring->findRequest(trim($this->nim));
            $this->lookup = match (true) {
                $request === null => 'Tidak ada permohonan kliring untuk NIM ini.',
                $request['free'] => 'Permohonan ditemukan: sudah Bebas.',
                default => 'Permohonan ditemukan: Belum Bebas (akan diterima otomatis setelah Karya Ilmiahnya di-approve).',
            };
        } catch (KliringSessionExpired) {
            $kliring->disconnect();
            $this->connected = false;
            $this->lookup = 'Sesi kliring sudah berakhir. Sambungkan ulang.';
        } catch (KliringException $e) {
            $this->lookup = 'Gagal: '.$e->getMessage();
        }
    }

    private function refreshConnection(KliringClient $kliring): void
    {
        if (! $kliring->isConfigured() || ! $kliring->hasSession()) {
            $this->connected = false;

            return;
        }
        try {
            $this->connected = $kliring->ping();
        } catch (\Throwable $e) {
            $this->connected = null; // unknown: the site did not answer
            $this->failure = 'Kliring tidak bisa dihubungi: '.mb_substr($e->getMessage(), 0, 300);
        }
    }

    private function stateKey(): string
    {
        return 'kliring:login:'.Auth::id();
    }

    public function render(KliringClient $kliring)
    {
        return view('livewire.admin.settings.kliring', [
            'configured' => $kliring->isConfigured(),
            'baseUrl' => $kliring->baseUrl(),
            'username' => config('services.kliring.username'),
            'counts' => Submission::whereNotNull('kliring_status')->select('kliring_status', DB::raw('COUNT(*) as total'))
                ->groupBy('kliring_status')->pluck('total', 'kliring_status'),
            'problems' => Submission::whereIn('kliring_status', ['failed', 'waiting_request'])->latest('verified_at')->limit(10)->get(),
        ]);
    }
}
