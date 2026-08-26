<?php

declare(strict_types=1);

namespace BankApi\Service;

use BankApi\HttpTransport;
use BankApi\Page;
use BankApi\Resource\BankingSummary;
use BankApi\Resource\Connection;
use BankApi\Resource\ConnectionsSummary;
use BankApi\Resource\PaymentIntent;
use BankApi\Resource\Transaction;
use BankApi\Resource\TransactionDetail;

final class BankingService
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function summary(?int $days = null): BankingSummary
    {
        return BankingSummary::fromArray(
            $this->transport->request('GET', '/banking/summary', $days === null ? [] : ['days' => $days]),
        );
    }

    /** @return list<Connection> */
    public function connections(): array
    {
        $data = $this->transport->request('GET', '/banking/connections');

        return array_map(Connection::fromArray(...), array_values($data['items'] ?? []));
    }

    public function connectionsSummary(?int $days = null): ConnectionsSummary
    {
        return ConnectionsSummary::fromArray(
            $this->transport->request('GET', '/banking/connections/summary', $days === null ? [] : ['days' => $days]),
        );
    }

    public function connection(string $connId): Connection
    {
        return Connection::fromArray($this->transport->request('GET', '/banking/connections/' . rawurlencode($connId)));
    }

    /** @return Page<Transaction> */
    public function transactions(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $direction = null,
        ?string $connectionId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $q = null,
        ?string $matchStatus = null,
    ): Page {
        $query = array_filter([
            'limit' => $limit,
            'direction' => $direction,
            'connection_id' => $connectionId,
            'from' => $from,
            'to' => $to,
            'q' => $q,
            'match_status' => $matchStatus,
            'cursor' => $cursor,
        ], static fn ($v) => $v !== null);

        $data = $this->transport->request('GET', '/banking/transactions', $query);

        return new Page(
            array_map(Transaction::fromArray(...), array_values($data['items'] ?? [])),
            (string) ($data['next_cursor'] ?? ''),
            fn (string $c): Page => $this->transactions($limit, $c, $direction, $connectionId, $from, $to, $q, $matchStatus),
        );
    }

    public function transaction(string $txId): TransactionDetail
    {
        return TransactionDetail::fromArray($this->transport->request('GET', '/banking/transactions/' . rawurlencode($txId)));
    }

    public function matchTransaction(string $txId, string $intentId): TransactionDetail
    {
        return TransactionDetail::fromArray(
            $this->transport->request('POST', '/banking/transactions/' . rawurlencode($txId) . '/match', [], ['intent_id' => $intentId]),
        );
    }

    /** @return Page<PaymentIntent> */
    public function paymentIntents(?string $status = null, ?int $limit = null, ?string $cursor = null): Page
    {
        $query = array_filter(['status' => $status, 'limit' => $limit, 'cursor' => $cursor], static fn ($v) => $v !== null);
        $data = $this->transport->request('GET', '/banking/payment-intents', $query);

        return new Page(
            array_map(PaymentIntent::fromArray(...), array_values($data['items'] ?? [])),
            (string) ($data['next_cursor'] ?? ''),
            fn (string $c): Page => $this->paymentIntents($status, $limit, $c),
        );
    }
}
