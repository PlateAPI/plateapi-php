<?php

declare(strict_types=1);

namespace PlateAPI;

/**
 * Base class for every error thrown by the SDK.
 *
 * getStatusCode() is the HTTP status (0 for connection failures), getApiCode() the API's
 * machine code when it sent one (not_found, lookup_failed, unavailable, quota_exceeded) and
 * getRequestId() the request ID from the body or the X-Request-ID header.
 */
class PlateAPIException extends \RuntimeException
{
    protected int $statusCode;
    protected ?string $apiCode;
    protected ?string $requestId;

    public function __construct(
        string $message,
        int $statusCode = 0,
        ?\Throwable $previous = null,
        ?string $apiCode = null,
        ?string $requestId = null,
    ) {
        $this->statusCode = $statusCode;
        $this->apiCode = $apiCode;
        $this->requestId = $requestId;
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getApiCode(): ?string
    {
        return $this->apiCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
