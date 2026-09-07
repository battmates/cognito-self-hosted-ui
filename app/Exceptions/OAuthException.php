<?php

namespace App\Exceptions;

use RuntimeException;

class OAuthException extends RuntimeException
{
    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }
}
