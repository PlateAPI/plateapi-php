<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 400: invalid plate, state, cascade value, vehicle ID or changes feed parameter. */
class BadRequestException extends PlateAPIException
{
    public function __construct(string $message = 'Bad request', ?string $apiCode = null, ?string $requestId = null)
    {
        parent::__construct($message, 400, null, $apiCode, $requestId);
    }
}
