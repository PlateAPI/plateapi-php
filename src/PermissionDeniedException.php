<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 403: IP not on the key's allowlist, account banned, or the vehicle database needs Starter or above. */
class PermissionDeniedException extends PlateAPIException
{
    public function __construct(string $message = 'Permission denied', ?string $apiCode = null, ?string $requestId = null)
    {
        parent::__construct($message, 403, null, $apiCode, $requestId);
    }
}
