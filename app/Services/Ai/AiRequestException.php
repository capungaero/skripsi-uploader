<?php

namespace App\Services\Ai;

/** Transport/format problem with the AI provider. Never the student's fault, so it is retried. */
class AiRequestException extends \RuntimeException {}
