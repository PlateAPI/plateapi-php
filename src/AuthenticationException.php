<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 401: missing, invalid or deactivated API key, or email not verified. */
class AuthenticationException extends PlateAPIException
{
    public function __construct(string $message = 'Invalid API key', ?string $apiCode = null, ?string $requestId = null)
    {
        parent::__construct($message, 401, null, $apiCode, $requestId);
    }
}
