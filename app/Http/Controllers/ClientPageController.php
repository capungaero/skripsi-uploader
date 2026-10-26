<?php

namespace App\Http\Controllers;

use App\Models\FormConfig;
use App\Services\FormSchema;
use App\Services\Settings;
use App\Services\StudentLogins;

class ClientPageController extends Controller
{
    public function __invoke(FormSchema $schema, StudentLogins $logins, Settings $settings)
    {
        // The schema is embedded in the page so the form renders without an extra request.
        $data = $schema->forClient(FormConfig::active()?->load('fields'));
        $number = preg_replace('/\D+/', '', (string) $settings->get('login.wa_number'));
        $data['login'] = [
            'required' => $logins->enabled(),
            'wa_number' => $number ?: null,
            'wa_link' => $number ? 'https://wa.me/'.$number.'?text=kliring_' : null,
            'code_minutes' => $logins->codeMinutes(),
        ];

        return view('client', ['schema' => $data]);
    }
}
