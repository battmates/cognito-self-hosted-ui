<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderCredentialsException extends RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct('Incorrect credentials.');
    }
}
