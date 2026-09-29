<?php

namespace App\Http\Controllers;

use App\Models\FormConfig;
use App\Services\FormSchema;

class ClientPageController extends Controller
{
    public function __invoke(FormSchema $schema)
    {
        // The schema is embedded in the page so the form renders without an extra request.
        return view('client', ['schema' => $schema->forClient(FormConfig::active()?->load('fields'))]);
    }
}
