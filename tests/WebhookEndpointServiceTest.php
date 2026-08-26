<?php
declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\BankApi;
use BankApi\Resource\CreatedEndpoint;
use BankApi\Resource\Delivery;
use BankApi\Resource\WebhookEndpoint;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointServiceTest extends TestCase
{
    private MockClient $mock;
    private BankApi $client;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->client = new BankApi('bk_test', 'https://api.bankapi.vn', $this->mock);
    }

    private function respond(int $status, array $body): void
    {
        $this->mock->addResponse(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function testCreateReturnsSecretOnce(): void
    {
        $this->respond(201, ['id' => 'w1', 'url' => 'https://shop.vn/hook', 'secret' => 'whsec_abc']);

        $created = $this->client->webhookEndpoints()->create('https://shop.vn/hook', ['bank.credit'], 'đơn hàng');

        $req = $this->mock->getLastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/webhooks', $req->getUri()->getPath());
        self::assertSame(
            ['url' => 'https://shop.vn/hook', 'event_types' => ['bank.credit'], 'description' => 'đơn hàng'],
            json_decode((string) $req->getBody(), true),
        );
        self::assertInstanceOf(CreatedEndpoint::class, $created);
        self::assertSame('whsec_abc', $created->secret);
    }

    public function testAllMapsEndpoints(): void
    {
        $this->respond(200, ['items' => [
            ['id' => 'w1', 'url' => 'https://shop.vn/hook', 'event_types' => ['bank.credit'], 'active' => true, 'description' => '', 'failure_count' => 0],
        ], 'next_cursor' => '']);

        $page = $this->client->webhookEndpoints()->all(limit: 20);

        self::assertSame('limit=20', $this->mock->getLastRequest()->getUri()->getQuery());
        self::assertInstanceOf(WebhookEndpoint::class, $page->items[0]);
        self::assertTrue($page->items[0]->active);
    }

    public function testDeleteAndEnableHitCorrectPaths(): void
    {
        $this->respond(200, []);
        $this->client->webhookEndpoints()->delete('w1');
        self::assertSame('DELETE', $this->mock->getLastRequest()->getMethod());
        self::assertSame('/webhooks/w1', $this->mock->getLastRequest()->getUri()->getPath());

        $this->respond(200, []);
        $this->client->webhookEndpoints()->enable('w1');
        self::assertSame('POST', $this->mock->getLastRequest()->getMethod());
        self::assertSame('/webhooks/w1/enable', $this->mock->getLastRequest()->getUri()->getPath());
    }

    public function testDeliveriesMapsItems(): void
    {
        $this->respond(200, ['items' => [
            ['id' => 'd1', 'event_type' => 'bank.credit', 'attempt' => 2, 'status_code' => 500, 'error' => 'boom', 'created_at' => '2026-08-26T09:00:00Z'],
        ], 'next_cursor' => 'n1']);

        $page = $this->client->webhookEndpoints()->deliveries('w1');

        self::assertSame('/webhooks/w1/deliveries', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(Delivery::class, $page->items[0]);
        self::assertSame(500, $page->items[0]->statusCode);
        self::assertSame('n1', $page->nextCursor);
    }
}
