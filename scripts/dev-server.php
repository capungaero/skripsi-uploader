<?php

// Router for `php -S` when the server cannot be started from public/ (e.g. to pass -d upload limits):
// php -d upload_max_filesize=60M -d post_max_size=64M -S 127.0.0.1:8000 -t public scripts/dev-server.php
chdir(__DIR__.'/../public');

require __DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
