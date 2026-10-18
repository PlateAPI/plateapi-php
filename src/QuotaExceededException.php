<?php

declare(strict_types=1);

namespace PlateAPI;

/** HTTP 429 because the monthly lookup quota is used up. Also a RateLimitException. */
class QuotaExceededException extends RateLimitException
{
    public function __construct(
        string $message = 'Monthly quota exceeded',
        ?float $retryAfter = null,
        ?string $requestId = null,
    ) {
        parent::__construct($message, $retryAfter, 'quota_exceeded', $requestId);
    }
}
