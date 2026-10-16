<?php

namespace App\Support;

use RuntimeException;

/** Erro com código entendido pela interface (errMsg da página). */
class StoreError extends RuntimeException
{
    public function __construct(public string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
