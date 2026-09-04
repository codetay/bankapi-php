<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testConstantsMatchTheRegistryCode(): void
    {
        self::assertSame('auth.refresh_in_flight', ErrorCode::AUTH_REFRESH_IN_FLIGHT);
        self::assertSame('idempotency.key_reused', ErrorCode::IDEMPOTENCY_KEY_REUSED);
    }

    public function testIsKnownAcceptsARegistryCode(): void
    {
        self::assertTrue(ErrorCode::isKnown('idempotency.in_progress'));
    }

    public function testIsKnownRejectsAnUnknownCode(): void
    {
        self::assertFalse(ErrorCode::isKnown('made.up_code'));
    }

    public function testIsKnownRejectsNull(): void
    {
        self::assertFalse(ErrorCode::isKnown(null));
    }
}
