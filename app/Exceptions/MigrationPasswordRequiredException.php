<?php

namespace App\Exceptions;

use RuntimeException;

class MigrationPasswordRequiredException extends RuntimeException
{
    public function __construct(public readonly array $identity = [])
    {
        parent::__construct('Create a stronger password to finish setting up single sign-on.');
    }
}
