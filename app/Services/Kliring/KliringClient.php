<?php

namespace App\Services\Kliring;

use App\Services\Settings;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Drives the library clearance site (kliringlib, a Yii2 app) like its admin pages do.
 * Login needs a CAPTCHA typed by a person, so the session cookies are kept (encrypted) in settings
 * and reused by the queue until the site expires them.
 */
class KliringClient
{
    private const SESSION_KEY = 'kliring.session';

    private ?CookieJar $jar = null;

    public function __construct(private Settings $settings) {}

    public function isConfigured(): bool
    {
        return filled(config('services.kliring.url')) && filled(config('services.kliring.username'))
            && filled(config('services.kliring.password'));
    }

    public function hasSession(): bool
    {
        return $this->settings->has(self::SESSION_KEY);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.kliring.url'), '/');
    }

    /**
     * Step 1 of connecting: open the login page in a fresh session and fetch its CAPTCHA.
     *
     * @return array{state: array{cookies: array, csrf: string}, captcha: string} captcha is a data: URI
     */
    public function startLogin(): array
    {
        $jar = new CookieJar;
        $page = $this->check($this->http($jar)->get($this->url('/login')), 'buka halaman login');
        $xpath = self::xpath($page->body());

        $csrf = $xpath->evaluate("string(//input[@name='_csrf']/@value)");
        $src = $xpath->evaluate("string(//img[contains(@src, 'captcha')]/@src)");
        if ($csrf === '' || $src === '') {
            throw new KliringException('Form login kliring tidak dikenali (token/CAPTCHA tidak ditemukan).');
        }

        $image = $this->check($this->http($jar)->get($this->url($src)), 'ambil CAPTCHA');
        $type = explode(';', $image->header('Content-Type') ?: 'image/png')[0];

        return [
            'state' => ['cookies' => $jar->toArray(), 'csrf' => $csrf],
            'captcha' => 'data:'.$type.';base64,'.base64_encode($image->body()),
        ];
    }

    /** Step 2: submit the configured account with the CAPTCHA a person typed, then keep the session. */
    public function completeLogin(array $state, string $code): void
    {
        $jar = new CookieJar(false, $state['cookies'] ?? []);
        $response = $this->http($jar)->asForm()->post($this->url('/login'), [
            '_csrf' => $state['csrf'] ?? '',
            'LoginForm[username]' => config('services.kliring.username'),
            'LoginForm[password]' => config('services.kliring.password'),
            'LoginForm[verifyCode]' => trim($code),
            'login-button' => '',
        ]);

        // Yii redirects after a successful login and re-renders the form (200) with errors otherwise.
        if (! $response->redirect() || self::pointsToLogin($response->header('Location'))) {
            $errors = self::texts(self::xpath($response->body()), "//*[contains(@class, 'help-block-error') or contains(@class, 'alert')]");
            throw new KliringException('Login kliring gagal: '.($errors ?: 'periksa kode verifikasi lalu coba lagi.'));
        }

        $this->jar = $jar;
        $this->settings->set(self::SESSION_KEY, $jar->toArray());
    }

    public function disconnect(): void
    {
        $this->jar = null;
        $this->settings->set(self::SESSION_KEY, null);
    }

    /** Touch the site so its session does not idle out; forgets the session once it has expired. */
    public function ping(): bool
    {
        try {
            $this->get('/');

            return true;
        } catch (KliringSessionExpired) {
            $this->disconnect();

            return false;
        }
    }

    /**
     * The clearance request of a NIM: pending ones are under "Permohonan", finished ones under "Riwayat".
     *
     * @return array{param: string, free: bool}|null
     */
    public function findRequest(string $nim): ?array
    {
        $query = '?'.http_build_query(['KliringSearch' => ['klirMemberId' => $nim]]);

        foreach (['/kelola/kliring/index', '/kelola/kliring/riwayat-index'] as $path) {
            $xpath = self::xpath($this->get($path.$query)->body());
            foreach ($xpath->query('//table//tbody/tr') as $row) {
                // Join text nodes with spaces so "ID : <nim>" never runs into the next cell's text.
                $text = self::texts($xpath, './/text()', $row);
                // The search is a LIKE match, so compare the "ID : <nim>" line exactly.
                if (! preg_match('/ID\s*:\s*(\S+)/u', $text, $m) || $m[1] !== $nim) {
                    continue;
                }
                // Pending rows link to kliring/view, finished ones to kliring/riwayat-view.
                parse_str((string) parse_url($xpath->evaluate("string(.//a[contains(@href, 'view?param=')]/@href)", $row),
                    PHP_URL_QUERY), $params);
                if (filled($params['param'] ?? null)) {
                    return ['param' => $params['param'], 'free' => self::isFree($text)];
                }
            }
        }

        return null;
    }

    /** Mark the "karya ilmiah" requirement valid when needed, then accept ("Terima Permohonan") the request. */
    public function approve(string $param): void
    {
        $view = '/kelola/kliring/view?param='.urlencode($param);
        $xpath = self::xpath($this->get($view)->body());
        if (self::isFree(self::requestStatus($xpath))) {
            return;
        }
        $csrf = $xpath->evaluate("string(//meta[@name='csrf-token']/@content)");

        foreach (self::requirements($xpath) as $item) {
            if (str_contains(mb_strtolower($item['label']), 'karya ilmiah') && $item['valid'] !== 'Ya') {
                // Same payload as the page's btnSimpan(): the serialized form travels inside "data".
                $response = $this->post($view.'&itemid='.urlencode($item['id']), [
                    'btn-simpan' => 1,
                    'data' => http_build_query(['_csrf' => $csrf, 'KliringSyarat' => ['klirIsValid' => 'Ya']]),
                ], $csrf);
                if ($response->json('status') !== 'success') {
                    throw new KliringException('Validasi syarat Karya Ilmiah ditolak kliring: '.mb_substr($response->body(), 0, 300));
                }
            }
        }

        $this->post($view, ['btn-terima' => 1], $csrf);

        $status = self::requestStatus(self::xpath($this->get($view)->body()));
        if (! self::isFree($status)) {
            throw new KliringException("Terima Permohonan sudah dikirim, tetapi status kliring masih \"{$status}\".");
        }
    }

    private function get(string $path): Response
    {
        return $this->guard($this->http($this->jar())->get($this->url($path)), 'GET '.strtok($path, '?'));
    }

    private function post(string $path, array $data, string $csrf): Response
    {
        return $this->guard($this->http($this->jar())->asForm()
            ->withHeaders(['X-CSRF-Token' => $csrf, 'X-Requested-With' => 'XMLHttpRequest'])
            ->post($this->url($path), $data), 'POST '.strtok($path, '?'));
    }

    private function jar(): CookieJar
    {
        if (! $this->jar) {
            $cookies = $this->settings->get(self::SESSION_KEY);
            if (! $cookies) {
                throw new KliringSessionExpired('Sesi kliring belum disambungkan.');
            }
            $this->jar = new CookieJar(false, $cookies);
        }

        return $this->jar;
    }

    /** Detect a dead session, and store rotated cookies so the next run keeps the same session. */
    private function guard(Response $response, string $what): Response
    {
        if (($response->redirect() && self::pointsToLogin($response->header('Location')))
            || str_contains($response->body(), 'LoginForm[password]')) {
            throw new KliringSessionExpired('Sesi login kliring sudah berakhir.');
        }
        $this->check($response, $what);

        $cookies = $this->jar->toArray();
        if ($cookies !== $this->settings->get(self::SESSION_KEY)) {
            $this->settings->set(self::SESSION_KEY, $cookies);
        }

        return $response;
    }

    private function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new KliringException(sprintf('Kliring %s gagal (HTTP %d).', $what, $response->status()), $response->status());
        }

        return $response;
    }

    private function http(CookieJar $jar): PendingRequest
    {
        return Http::timeout(60)->withoutRedirecting()->withOptions(['cookies' => $jar])
            ->withUserAgent('SkripsiUploader/1.0 (Perpustakaan Universitas Andalas)');
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->baseUrl().'/'.ltrim($path, '/');
    }

    private static function pointsToLogin(string $location): bool
    {
        return (bool) preg_match('~/login/?$~', (string) parse_url($location, PHP_URL_PATH));
    }

    private static function isFree(string $status): bool
    {
        return stripos($status, 'bebas') !== false && stripos($status, 'belum') === false;
    }

    /** "Status : Belum Bebas" on the request detail page. */
    private static function requestStatus(\DOMXPath $xpath): string
    {
        return self::text($xpath->query("//tr[td[1][normalize-space()='Status']]/td[last()]")->item(0));
    }

    /** @return array<int, array{id: string, label: string, valid: string}> rows: No | Item | Catatan | Valid | Aksi */
    private static function requirements(\DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query("//a[starts-with(@onclick, 'dialogValidasi')]") as $link) {
            $row = $xpath->query('ancestor::tr[1]', $link)->item(0);
            if ($row && preg_match("/dialogValidasi\('([^']+)'\)/", $link->getAttribute('onclick'), $m)) {
                $cells = $xpath->query('td', $row);
                $items[] = ['id' => $m[1], 'label' => self::text($cells->item(1)), 'valid' => self::text($cells->item(3))];
            }
        }

        return $items;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    private static function text(?\DOMNode $node): string
    {
        return $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent)) : '';
    }

    private static function texts(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $texts = [];
        foreach ($xpath->query($query, $context) as $node) {
            if (($text = self::text($node)) !== '') {
                $texts[] = $text;
            }
        }

        return implode(' ', array_unique($texts));
    }
}
