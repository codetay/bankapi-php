<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\Exception\ApiException;
use BankApi\Exception\AuthenticationException;
use BankApi\Exception\NotFoundException;
use BankApi\Exception\PermissionException;
use BankApi\Exception\RateLimitException;
use BankApi\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testMapsStatusToSubclass(): void
    {
        self::assertInstanceOf(AuthenticationException::class, ApiException::fromResponse(401, []));
        self::assertInstanceOf(PermissionException::class, ApiException::fromResponse(403, []));
        self::assertInstanceOf(NotFoundException::class, ApiException::fromResponse(404, []));
        self::assertInstanceOf(ValidationException::class, ApiException::fromResponse(400, []));
        self::assertInstanceOf(ValidationException::class, ApiException::fromResponse(422, []));
        self::assertInstanceOf(RateLimitException::class, ApiException::fromResponse(429, []));
        self::assertSame(ApiException::class, ApiException::fromResponse(500, [])::class);
    }

    public function testCarriesProblemFields(): void
    {
        $e = ApiException::fromResponse(404, ['title' => 'Not Found', 'detail' => 'no such transaction']);
        self::assertSame(404, $e->status);
        self::assertSame('Not Found', $e->title);
        self::assertSame('no such transaction', $e->detail);
        self::assertStringContainsString('no such transaction', $e->getMessage());
    }

    public function testRateLimitReadsRetryAfter(): void
    {
        $e = ApiException::fromResponse(429, [], ['Retry-After' => ['7']]);
        self::assertInstanceOf(RateLimitException::class, $e);
        self::assertSame(7, $e->retryAfter);

        $none = ApiException::fromResponse(429, []);
        self::assertInstanceOf(RateLimitException::class, $none);
        self::assertNull($none->retryAfter);
    }

    public function testErrorCodeStripsRegistryPrefix(): void
    {
        $e = ApiException::fromResponse(409, ['type' => 'urn:bankapi:error:idempotency.in_progress']);

        self::assertSame('idempotency.in_progress', $e->errorCode);
        self::assertSame('idempotency.in_progress', $e->errorCode());
    }

    public function testErrorCodeIsNullForAnUnknownTypePrefix(): void
    {
        $e = ApiException::fromResponse(400, ['type' => 'https://example.com/errors/x']);

        self::assertNull($e->errorCode);
        self::assertNull($e->errorCode());
    }

    public function testErrorCodeIsNullWhenTypeIsAbsent(): void
    {
        $e = ApiException::fromResponse(400, []);

        self::assertNull($e->errorCode());
    }

    public function test422IdempotencyKeyReusedMapsToValidationExceptionWithErrorCode(): void
    {
        $e = ApiException::fromResponse(422, ['type' => 'urn:bankapi:error:idempotency.key_reused']);

        self::assertInstanceOf(ValidationException::class, $e);
        self::assertSame('idempotency.key_reused', $e->errorCode());
    }

    public function testReplayedIsTrueFromTheHeaderCaseInsensitively(): void
    {
        $lower = ApiException::fromResponse(409, [], ['idempotent-replayed' => ['true']]);
        self::assertTrue($lower->replayed);

        $mixed = ApiException::fromResponse(409, [], ['Idempotent-Replayed' => ['True']]);
        self::assertTrue($mixed->replayed);
    }

    public function testReplayedIsFalseWhenHeaderAbsentOrNotTrue(): void
    {
        self::assertFalse(ApiException::fromResponse(409, [])->replayed);
        self::assertFalse(ApiException::fromResponse(409, [], ['Idempotent-Replayed' => ['false']])->replayed);
    }

    public function testGetCodeStaysZeroAndDoesNotBecomeTheHttpStatus(): void
    {
        $e = ApiException::fromResponse(404, []);

        self::assertSame(0, $e->getCode());
        self::assertSame(404, $e->status);
    }
}
