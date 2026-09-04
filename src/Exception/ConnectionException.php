<?php

declare(strict_types=1);

namespace BankApi\Exception;

/**
 * No HTTP response was received (DNS, connect, TLS, timeout). status is
 * always 0; the underlying PSR-18 exception is available via getPrevious().
 */
class ConnectionException extends ApiException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(0, 'Connection error', $detail, [], previous: $previous);
    }
}
