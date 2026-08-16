<?php

declare(strict_types=1);

namespace PlateAPI;

class QuotaExceededException extends PlateAPIException
{
    public function __construct()
    {
        parent::__construct('Monthly quota exceeded', 429);
    }
}
