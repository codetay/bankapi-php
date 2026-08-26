<?php
declare(strict_types=1);

namespace BankApi\Exception;

class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $body = [],
    ) {
        parent::__construct(sprintf('[%d] %s: %s', $status, $title, $detail));
    }

    /**
     * Build the right exception subclass from an RFC 7807 problem response.
     *
     * @param array<string, mixed>       $problem
     * @param array<string, list<string>> $headers PSR-7 shaped headers (name => values)
     */
    public static function fromResponse(int $status, array $problem, array $headers = []): self
    {
        $title = is_string($problem['title'] ?? null) ? $problem['title'] : 'API error';
        $detail = is_string($problem['detail'] ?? null) ? $problem['detail'] : '';

        return match (true) {
            $status === 401 => new AuthenticationException($status, $title, $detail, $problem),
            $status === 403 => new PermissionException($status, $title, $detail, $problem),
            $status === 404 => new NotFoundException($status, $title, $detail, $problem),
            $status === 400, $status === 422 => new ValidationException($status, $title, $detail, $problem),
            $status === 429 => new RateLimitException($status, $title, $detail, $problem, self::retryAfter($headers)),
            default => new self($status, $title, $detail, $problem),
        };
    }

    /** @param array<string, list<string>> $headers */
    private static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) === 'retry-after' && isset($values[0]) && is_numeric($values[0])) {
                return (int) $values[0];
            }
        }

        return null;
    }
}
