<?php

declare(strict_types=1);

namespace PlateAPI;

class ServerException extends PlateAPIException
{
    protected ?float $retryAfter;

    public function __construct(string $message = 'Server error', int $statusCode = 500, ?float $retryAfter = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct($message, $statusCode);
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }
}
