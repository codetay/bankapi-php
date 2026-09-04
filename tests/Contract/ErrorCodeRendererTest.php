<?php

declare(strict_types=1);

namespace BankApi\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * bin/lib/error-codes.php renders each registry code to a PHP constant name
 * by uppercasing it and merging "." and "-" into "_" — two distinct codes
 * could collide on the same name. Guard that a future registry addition
 * which collides fails generation loudly instead of silently overwriting a
 * constant declaration.
 *
 * bin/ is a workspace-level tool, not part of the published package: after
 * `git subtree split`, packages/core is checked out on its own without it,
 * so this test skips instead of fataling when the renderer isn't there.
 */
final class ErrorCodeRendererTest extends TestCase
{
    protected function setUp(): void
    {
        $path = dirname(__DIR__, 4) . '/bin/lib/error-codes.php';
        if (!is_file($path)) {
            $this->markTestSkipped('bin/lib/error-codes.php lives in the workspace repo, not the split package.');
        }

        require_once $path;
    }

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
