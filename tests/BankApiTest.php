<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\BankApi;
use BankApi\Resource\CreatedEndpoint;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class BankApiTest extends TestCase
{
    /**
     * Builds the client with explicit collaborators: base-URL validation must
     * be testable without a PSR-18 implementation installed.
     */
    private static function client(string $baseUrl): BankApi
    {
        $factory = new Psr17Factory();

        return new BankApi('bk_live_x', $baseUrl, new MockClient(), $factory, $factory);
    }

    public function testPlainHttpBaseUrlIsRefused(): void
    {
        // The API key rides on every request; http would put it on the wire.
        $this->expectException(\InvalidArgumentException::class);
        self::client('http://api.bankapi.vn');
    }

    public function testLoopbackHttpIsAllowedForLocalDevelopment(): void
    {
        $this->expectNotToPerformAssertions();
        self::client('http://localhost:8080');
    }

    public function testBaseUrlWithoutHostIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::client('api.bankapi.vn');
    }

    public function testHttpsBaseUrlIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        self::client('https://api.bankapi.vn/');
    }

    public function testCreatedEndpointRedactsSecretWhenDumped(): void
    {
        $endpoint = CreatedEndpoint::fromArray(['id' => 'ep_1', 'url' => 'https://shop.vn/hook', 'secret' => 'whsec_supersecret']);
        $dump = print_r($endpoint, true);
        self::assertStringNotContainsString('whsec_supersecret', $dump);
        self::assertSame('whsec_supersecret', $endpoint->secret);
    }
}
