<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 429. getRetryAfter() is the server's Retry-After in seconds, if sent. */
class RateLimitException extends PlateAPIException
{
    protected ?float $retryAfter;

    public function __construct(
        string $message = 'Rate limit exceeded',
        ?float $retryAfter = null,
        ?string $apiCode = null,
        ?string $requestId = null,
    ) {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, 429, null, $apiCode, $requestId);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
