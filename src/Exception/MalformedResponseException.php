<?php

declare(strict_types=1);

namespace BankApi\Exception;

/**
 * A 2xx response whose body is not a JSON object/array (e.g. an HTML page
 * from a proxy or captive portal). Raised instead of silently returning
 * empty data; status carries the actual 2xx status code.
 */
class MalformedResponseException extends ApiException
{
    public function __construct(int $status, string $detail)
    {
        parent::__construct($status, 'Malformed response', $detail);
    }
}
