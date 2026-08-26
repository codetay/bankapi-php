<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\Exception\ConnectionException;
use BankApi\Exception\MalformedResponseException;
use BankApi\Exception\NotFoundException;
use BankApi\Exception\RateLimitException;
use BankApi\HttpTransport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HttpTransportTest extends TestCase
{
    private MockClient $mock;

    private function transport(int $maxRetries = 2): HttpTransport
    {
        $this->mock = new MockClient();
        $f = new Psr17Factory();

        return new HttpTransport('bk_test_key', 'https://api.bankapi.vn', $this->mock, $f, $f, $maxRetries, static function (int $ms): void {
        });
    }

    private function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testSendsApiKeyAndQuery(): void
    {
        $t = $this->transport();
        $this->mock->addResponse($this->json(200, ['ok' => true]));

        $out = $t->request('GET', '/banking/transactions', ['limit' => 5, 'q' => 'cafe sáng']);

        $req = $this->mock->getLastRequest();
        self::assertSame('bk_test_key', $req->getHeaderLine('X-API-Key'));
        self::assertSame('GET', $req->getMethod());
        self::assertSame('/banking/transactions', $req->getUri()->getPath());
        self::assertSame('limit=5&q=' . rawurlencode('cafe sáng'), $req->getUri()->getQuery());
        self::assertSame(['ok' => true], $out);
    }

    public function testPostSendsJsonBody(): void
    {
        $t = $this->transport();
        $this->mock->addResponse($this->json(200, []));

        $t->request('POST', '/banking/transactions/abc/match', [], ['intent_id' => 'xyz']);

        $req = $this->mock->getLastRequest();
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('{"intent_id":"xyz"}', (string) $req->getBody());
    }

    public function testMapsProblemToException(): void
    {
        $t = $this->transport();
        $this->mock->addResponse($this->json(404, ['title' => 'Not Found', 'detail' => 'gone'], ['Content-Type' => 'application/problem+json']));

        $this->expectException(NotFoundException::class);
        $t->request('GET', '/banking/transactions/nope');
    }

    public function testRetriesGetOn5xxThenSucceeds(): void
    {
        $t = $this->transport();
        $this->mock->addResponse($this->json(500, ['title' => 'boom']));
        $this->mock->addResponse($this->json(200, ['ok' => true]));

        self::assertSame(['ok' => true], $t->request('GET', '/banking/summary'));
        self::assertCount(2, $this->mock->getRequests());
    }

    public function testGetExhaustsRetriesThenThrows(): void
    {
        $t = $this->transport(2);
        $this->mock->addResponse($this->json(429, ['title' => 'slow down'], ['Retry-After' => '3']));
        $this->mock->addResponse($this->json(429, ['title' => 'slow down']));
        $this->mock->addResponse($this->json(429, ['title' => 'slow down']));

        try {
            $t->request('GET', '/banking/summary');
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertCount(3, $this->mock->getRequests()); // 1 + 2 retries
        }
    }

    public function testNeverRetriesPost(): void
    {
        $t = $this->transport();
        $this->mock->addResponse($this->json(500, ['title' => 'boom']));

        try {
            $t->request('POST', '/webhooks', [], ['url' => 'https://x.vn']);
            self::fail('expected ApiException');
        } catch (\BankApi\Exception\ApiException) {
            self::assertCount(1, $this->mock->getRequests());
        }
    }

    public function testEmptyBodyBecomesEmptyArray(): void
    {
        $t = $this->transport();
        $this->mock->addResponse(new Response(204));

        self::assertSame([], $t->request('DELETE', '/webhooks/abc'));
    }

    private static function networkException(string $message): \Psr\Http\Client\ClientExceptionInterface
    {
        return new class ($message) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
        };
    }

    public function testNetworkErrorOnPostWrappedWithoutRetry(): void
    {
        $t = $this->transport();
        $cause = self::networkException('dns failure');
        $this->mock->addException($cause);

        try {
            $t->request('POST', '/webhooks', [], ['url' => 'https://x.vn']);
            self::fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame(0, $e->status);
            self::assertSame($cause, $e->getPrevious());
            self::assertStringContainsString('dns failure', $e->detail);
            self::assertCount(1, $this->mock->getRequests());
        }
    }

    public function testNetworkErrorOnGetExhaustsRetriesThenWraps(): void
    {
        $t = $this->transport(2);
        $this->mock->addException(self::networkException('timeout 1'));
        $this->mock->addException(self::networkException('timeout 2'));
        $this->mock->addException(self::networkException('timeout 3'));

        try {
            $t->request('GET', '/banking/summary');
            self::fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertCount(3, $this->mock->getRequests()); // 1 + 2 retries
        }
    }

    public function testMalformedJsonOn2xxThrows(): void
    {
        $t = $this->transport();
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'text/html'], '<html>captive portal</html>'));

        try {
            $t->request('GET', '/banking/summary');
            self::fail('expected MalformedResponseException');
        } catch (MalformedResponseException $e) {
            self::assertSame(200, $e->status);
        }
    }

    public function testNonArrayJsonOn2xxThrows(): void
    {
        $t = $this->transport();
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], '"ok"'));

        $this->expectException(MalformedResponseException::class);
        $t->request('GET', '/banking/summary');
    }

    public function testMalformedErrorBodyStillMapsByStatus(): void
    {
        $t = $this->transport();
        $this->mock->addResponse(new Response(500, ['Content-Type' => 'text/html'], '<html>oops</html>'));

        try {
            $t->request('POST', '/webhooks', [], ['url' => 'https://x.vn']);
            self::fail('expected ApiException');
        } catch (MalformedResponseException) {
            self::fail('error path must stay lenient, not throw MalformedResponseException');
        } catch (\BankApi\Exception\ApiException $e) {
            self::assertSame(500, $e->status);
        }
    }
}
