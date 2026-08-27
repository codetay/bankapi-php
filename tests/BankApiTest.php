<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\BankApi;
use BankApi\Resource\CreatedEndpoint;
use PHPUnit\Framework\TestCase;

final class BankApiTest extends TestCase
{
    public function testPlainHttpBaseUrlIsRefused(): void
    {
        // The API key rides on every request; http would put it on the wire.
        $this->expectException(\InvalidArgumentException::class);
        new BankApi('bk_live_x', 'http://api.bankapi.vn');
    }

    public function testLoopbackHttpIsAllowedForLocalDevelopment(): void
    {
        $this->expectNotToPerformAssertions();
        new BankApi('bk_test_x', 'http://localhost:8080');
    }

    public function testBaseUrlWithoutHostIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BankApi('bk_live_x', 'api.bankapi.vn');
    }

    public function testHttpsBaseUrlIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        new BankApi('bk_live_x', 'https://api.bankapi.vn/');
    }

    public function testCreatedEndpointRedactsSecretWhenDumped(): void
    {
        $endpoint = CreatedEndpoint::fromArray(['id' => 'ep_1', 'url' => 'https://shop.vn/hook', 'secret' => 'whsec_supersecret']);
        $dump = print_r($endpoint, true);
        self::assertStringNotContainsString('whsec_supersecret', $dump);
        self::assertSame('whsec_supersecret', $endpoint->secret);
    }
}
