<?php

declare(strict_types=1);

namespace PlateAPI;

class RateLimitException extends PlateAPIException
{
    protected ?float $retryAfter;

    public function __construct(string $message = 'Rate limit exceeded', ?float $retryAfter = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, 429);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
