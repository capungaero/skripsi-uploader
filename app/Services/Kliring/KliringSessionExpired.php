<?php

namespace App\Services\Kliring;

/** The stored login session is gone; an admin must reconnect (CAPTCHA) from the dashboard. */
class KliringSessionExpired extends KliringException {}
