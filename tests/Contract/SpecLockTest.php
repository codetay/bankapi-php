<?php

declare(strict_types=1);

namespace BankApi\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Guards openapi.lock.json and the fixture it pins: the lock must be
 * well-formed and match the fixture's hash, and the fixture must be the
 * frozen BankAPI contract (not a stale pre-freeze spec). Refresh both with
 * `composer sync-spec` when GO-KIT changes its API.
 */
final class SpecLockTest extends TestCase
{
    private const LOCK_PATH = __DIR__ . '/../fixtures/openapi.lock.json';
    private const FIXTURE_PATH = __DIR__ . '/../fixtures/openapi.json';

    /** @return array{gokit_ref: string, sha256: string} */
    private static function lock(): array
    {
        return json_decode((string) file_get_contents(self::LOCK_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function fixtureRaw(): string
    {
        return (string) file_get_contents(self::FIXTURE_PATH);
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return json_decode(self::fixtureRaw(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testLockHasWellFormedRefAndHash(): void
    {
        $lock = self::lock();
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $lock['gokit_ref']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $lock['sha256']);
    }

    public function testLockMatchesTheFixtureItLocks(): void
    {
        self::assertSame(self::lock()['sha256'], hash('sha256', self::fixtureRaw()));
    }

    public function testFixturePinsTheFrozenBankApiContract(): void
    {
        $spec = self::spec();
        self::assertSame('BankAPI', $spec['info']['title']);
        self::assertStringEndsWith('/v1', $spec['servers'][0]['url']);
        self::assertCount(55, $spec['x-error-code-registry']);
    }
}
