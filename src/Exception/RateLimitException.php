<?php

declare(strict_types=1);

namespace BankApi\Exception;

class RateLimitException extends ApiException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        int $status,
        string $title,
        string $detail,
        array $body = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($status, $title, $detail, $body);
    }
}
