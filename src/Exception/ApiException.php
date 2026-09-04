<?php

declare(strict_types=1);

namespace BankApi\Exception;

class ApiException extends \RuntimeException
{
    private const ERROR_CODE_PREFIX = 'urn:bankapi:error:';

    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $body = [],
        public readonly ?string $errorCode = null,
        public readonly bool $replayed = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('[%d] %s: %s', $status, $title, $detail), 0, $previous);
    }

    /**
     * problem.type with the urn:bankapi:error: prefix stripped; null for any
     * other prefix. A dedicated accessor avoids confusing this with the
     * inherited int-typed Exception::getCode(), which this class does not
     * override.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
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
        $errorCode = self::extractErrorCode($problem);
        $replayed = self::isReplayed($headers);

        return match (true) {
            $status === 401 => new AuthenticationException($status, $title, $detail, $problem, $errorCode, $replayed),
            $status === 403 => new PermissionException($status, $title, $detail, $problem, $errorCode, $replayed),
            $status === 404 => new NotFoundException($status, $title, $detail, $problem, $errorCode, $replayed),
            $status === 400, $status === 422 => new ValidationException($status, $title, $detail, $problem, $errorCode, $replayed),
            $status === 429 => new RateLimitException($status, $title, $detail, $problem, self::retryAfter($headers), $errorCode, $replayed),
            default => new self($status, $title, $detail, $problem, $errorCode, $replayed),
        };
    }

    /** @param array<string, mixed> $problem */
    private static function extractErrorCode(array $problem): ?string
    {
        $type = $problem['type'] ?? null;
        if (!is_string($type) || !str_starts_with($type, self::ERROR_CODE_PREFIX)) {
            return null;
        }

        return substr($type, strlen(self::ERROR_CODE_PREFIX));
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

    /** @param array<string, list<string>> $headers */
    private static function isReplayed(array $headers): bool
    {
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) === 'idempotent-replayed' && isset($values[0])) {
                return strtolower(trim((string) $values[0])) === 'true';
            }
        }

        return false;
    }
}
