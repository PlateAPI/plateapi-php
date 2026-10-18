<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 5xx after retries. getApiCode() is lookup_failed or unavailable when the API sent one. */
class ServerException extends PlateAPIException
{
    protected ?float $retryAfter;

    public function __construct(
        string $message = 'Server error',
        int $statusCode = 500,
        ?float $retryAfter = null,
        ?string $apiCode = null,
        ?string $requestId = null,
    ) {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, $statusCode, null, $apiCode, $requestId);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
