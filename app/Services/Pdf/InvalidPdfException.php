<?php

namespace App\Services\Pdf;

/** The uploaded file cannot be read as a PDF; the message is shown to the student. */
class InvalidPdfException extends \RuntimeException {}
