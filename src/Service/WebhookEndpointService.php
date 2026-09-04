<?php

declare(strict_types=1);

namespace BankApi\Service;

use BankApi\HttpTransport;
use BankApi\Page;
use BankApi\Resource\CreatedEndpoint;
use BankApi\Resource\Delivery;
use BankApi\Resource\WebhookEndpoint;

final class WebhookEndpointService
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * x-idempotent: retried with the same key, this replays the first response
     * instead of creating a second endpoint. A key is generated when omitted.
     * The returned secret is shown only here — store it before it is lost.
     *
     * @param list<string> $eventTypes
     */
    public function create(string $url, array $eventTypes, string $description = '', ?string $idempotencyKey = null): CreatedEndpoint
    {
        return CreatedEndpoint::fromArray($this->transport->request('POST', '/webhooks', [], [
            'url' => $url,
            'event_types' => $eventTypes,
            'description' => $description,
        ], $idempotencyKey ?? bin2hex(random_bytes(16))));
    }

    /** @return Page<WebhookEndpoint> */
    public function all(?int $limit = null, ?string $cursor = null): Page
    {
        $query = array_filter(['limit' => $limit, 'cursor' => $cursor], static fn ($v) => $v !== null);
        $data = $this->transport->request('GET', '/webhooks', $query);

        return new Page(
            array_map(WebhookEndpoint::fromArray(...), array_values($data['items'] ?? [])),
            (string) ($data['next_cursor'] ?? ''),
            fn (string $c): Page => $this->all($limit, $c),
        );
    }

    public function get(string $id): WebhookEndpoint
    {
        return WebhookEndpoint::fromArray($this->transport->request('GET', '/webhooks/' . rawurlencode($id)));
    }

    public function delete(string $id): void
    {
        $this->transport->request('DELETE', '/webhooks/' . rawurlencode($id));
    }

    public function enable(string $id): void
    {
        $this->transport->request('POST', '/webhooks/' . rawurlencode($id) . '/enable');
    }

    /** @return Page<Delivery> */
    public function deliveries(string $id, ?int $limit = null, ?string $cursor = null): Page
    {
        $query = array_filter(['limit' => $limit, 'cursor' => $cursor], static fn ($v) => $v !== null);
        $data = $this->transport->request('GET', '/webhooks/' . rawurlencode($id) . '/deliveries', $query);

        return new Page(
            array_map(Delivery::fromArray(...), array_values($data['items'] ?? [])),
            (string) ($data['next_cursor'] ?? ''),
            fn (string $c): Page => $this->deliveries($id, $limit, $c),
        );
    }
}
