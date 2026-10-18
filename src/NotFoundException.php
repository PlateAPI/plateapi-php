<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 404: no vehicle with that vehicle_id. */
class NotFoundException extends PlateAPIException
{
    public function __construct(string $message = 'Not found', ?string $apiCode = 'not_found', ?string $requestId = null)
    {
        parent::__construct($message, 404, null, $apiCode, $requestId);
    }
}
