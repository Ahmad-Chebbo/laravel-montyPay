<?php

namespace AhmadChebbo\LaravelMontypay\Exceptions;

use Exception;
use Throwable;

class MontyPayException extends Exception
{
    /** Per-field errors returned by the API (`errors[]`), if any. */
    public array $errors = [];

    public function __construct($message = "", $code = 0, ?Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, (int) $code, $previous);

        $this->errors = $errors;
    }
}
