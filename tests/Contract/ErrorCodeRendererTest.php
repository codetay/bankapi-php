<?php

declare(strict_types=1);

namespace BankApi\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 4) . '/bin/lib/error-codes.php';

/**
 * bin/lib/error-codes.php renders each registry code to a PHP constant name
 * by uppercasing it and merging "." and "-" into "_" — two distinct codes
 * could collide on the same name. Guard that a future registry addition
 * which collides fails generation loudly instead of silently overwriting a
 * constant declaration.
 */
final class ErrorCodeRendererTest extends TestCase
{
    public function testRejectsTwoRegistryCodesThatRenderTheSameConstantName(): void
    {
        $spec = [
            'x-error-code-registry' => [
                ['code' => 'foo.bar'],
                ['code' => 'foo-bar'],
            ],
        ];

        try {
            \BankApi\Codegen\renderErrorCode($spec);
            self::fail('expected a RuntimeException for the colliding constant name');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('foo.bar', $e->getMessage());
            self::assertStringContainsString('foo-bar', $e->getMessage());
            self::assertStringContainsString('FOO_BAR', $e->getMessage());
        }
    }
}
