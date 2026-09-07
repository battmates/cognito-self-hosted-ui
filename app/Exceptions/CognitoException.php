<?php

namespace App\Exceptions;

use RuntimeException;

class CognitoException extends RuntimeException
{
    public function __construct(public readonly string $awsType, string $message)
    {
        parent::__construct($message);
    }
}
