<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\BankApi;
use BankApi\Resource\Connection;
use BankApi\Resource\PaymentIntent;
use BankApi\Resource\Transaction;
use BankApi\Resource\TransactionDetail;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class BankingServiceTest extends TestCase
{
    private MockClient $mock;
    private BankApi $client;

    protected function setUp(): void
    {
        $this->mock = new MockClient();
        $this->client = new BankApi('bk_test', 'https://api.bankapi.vn', $this->mock);
    }

    private function respond(array $body): void
    {
        $this->mock->addResponse(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function testTransactionsBuildsQueryAndMapsItems(): void
    {
        $this->respond(['items' => [
            ['id' => 't1', 'amount' => 150000, 'direction' => 'credit', 'bank_ref' => 'FT1', 'connection_id' => 'c1',
             'description' => 'cafe', 'match_status' => 'unmatched', 'matched_intent_id' => '', 'transaction_date' => '2026-08-26T09:00:00Z',
             'created_at' => '2026-08-26T09:00:01Z', 'field_added_next_year' => 'ignored'],
        ], 'next_cursor' => 'abc']);

        $page = $this->client->banking()->transactions(limit: 10, direction: 'credit', matchStatus: 'unmatched');

        $req = $this->mock->getLastRequest();
        self::assertSame('/v1/banking/transactions', $req->getUri()->getPath());
        self::assertSame('limit=10&direction=credit&match_status=unmatched', $req->getUri()->getQuery());
        self::assertCount(1, $page->items);
        self::assertInstanceOf(Transaction::class, $page->items[0]);
        self::assertSame(150000, $page->items[0]->amount);
        self::assertSame('abc', $page->nextCursor);
    }

    public function testAutoPagingFetchesNextPageWithSameFilters(): void
    {
        $this->respond(['items' => [['id' => 't1', 'amount' => 1, 'direction' => 'credit']], 'next_cursor' => 'c2']);
        $this->respond(['items' => [['id' => 't2', 'amount' => 2, 'direction' => 'credit']], 'next_cursor' => '']);

        $ids = [];
        foreach ($this->client->banking()->transactions(direction: 'credit')->autoPaging() as $tx) {
            $ids[] = $tx->id;
        }

        self::assertSame(['t1', 't2'], $ids);
        $second = $this->mock->getRequests()[1];
        self::assertSame('direction=credit&cursor=c2', $second->getUri()->getQuery());
    }

    public function testConnectionsReturnsPlainList(): void
    {
        $this->respond(['items' => [['id' => 'c1', 'bank_code' => 'ocb', 'label' => 'OCB chính', 'status' => 'active',
            'account_type' => 'checking', 'created_at' => '2026-01-01T00:00:00Z', 'verified_at' => '2026-01-01T00:00:00Z']]]);

        $conns = $this->client->banking()->connections();

        self::assertIsArray($conns);
        self::assertInstanceOf(Connection::class, $conns[0]);
        self::assertSame('ocb', $conns[0]->bankCode);
        self::assertNull($conns[0]->balance);
    }

    public function testMatchTransactionPostsIntentId(): void
    {
        $this->respond(['id' => 't1', 'amount' => 5, 'direction' => 'credit', 'match_status' => 'matched', 'matched_intent_id' => 'i9']);

        $tx = $this->client->banking()->matchTransaction('t1', 'i9');

        $req = $this->mock->getLastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/banking/transactions/t1/match', $req->getUri()->getPath());
        self::assertSame('{"intent_id":"i9"}', (string) $req->getBody());
        self::assertInstanceOf(TransactionDetail::class, $tx);
        self::assertSame('matched', $tx->matchStatus);
    }

    public function testSummaryPassesDays(): void
    {
        $this->respond(['count' => 3, 'credit_total' => 100, 'debit_total' => 50, 'credit_matched_total' => 80,
            'from' => '2026-08-19', 'to' => '2026-08-26',
            'match_counts' => ['matched' => 1, 'unmatched' => 2, 'requires_review' => 0]]);

        $s = $this->client->banking()->summary(7);

        self::assertSame('days=7', $this->mock->getLastRequest()->getUri()->getQuery());
        self::assertSame(3, $s->count);
        self::assertSame(1, $s->matchCounts['matched']);
    }

    public function testCreatePaymentIntentPostsSnakeCaseBodyWithAGeneratedKey(): void
    {
        $this->respond(['id' => 'i1', 'code' => 'PN-1', 'expected_amount' => 150000, 'status' => 'pending',
            'matched_transaction_id' => '', 'expires_at' => '2026-09-05T00:00:00Z']);

        $intent = $this->client->banking()->createPaymentIntent('PN-1', 150000);

        $req = $this->mock->getLastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/banking/payment-intents', $req->getUri()->getPath());
        self::assertSame(['code' => 'PN-1', 'expected_amount' => 150000], json_decode((string) $req->getBody(), true));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $req->getHeaderLine('Idempotency-Key'));
        self::assertInstanceOf(PaymentIntent::class, $intent);
        self::assertSame('i1', $intent->id);
    }

    public function testCreatePaymentIntentPassesThroughExpiresInSecsAndACustomKey(): void
    {
        $this->respond(['id' => 'i1', 'code' => 'PN-1', 'expected_amount' => 150000, 'status' => 'pending',
            'matched_transaction_id' => '', 'expires_at' => '2026-09-05T00:00:00Z']);

        $this->client->banking()->createPaymentIntent('PN-1', 150000, 900, 'order-1042-attempt-1');

        $req = $this->mock->getLastRequest();
        self::assertSame(
            ['code' => 'PN-1', 'expected_amount' => 150000, 'expires_in_secs' => 900],
            json_decode((string) $req->getBody(), true),
        );
        self::assertSame('order-1042-attempt-1', $req->getHeaderLine('Idempotency-Key'));
    }

    public function testPaymentIntentGetsByIdAndMapsTheResponse(): void
    {
        $this->respond(['id' => 'i1', 'code' => 'PN-1', 'expected_amount' => 150000, 'status' => 'matched',
            'matched_transaction_id' => 't1', 'expires_at' => '2026-09-05T00:00:00Z']);

        $intent = $this->client->banking()->paymentIntent('i1');

        self::assertSame('GET', $this->mock->getLastRequest()->getMethod());
        self::assertSame('/v1/banking/payment-intents/i1', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(PaymentIntent::class, $intent);
        self::assertSame('matched', $intent->status);
    }
}
