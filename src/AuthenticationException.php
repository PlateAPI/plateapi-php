<?php

declare(strict_types=1);

namespace PlateAPI;

class AuthenticationException extends PlateAPIException
{
    public function __construct()
    {
        parent::__construct('Invalid API key', 401);
    }
}
