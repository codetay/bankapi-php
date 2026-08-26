<?php

declare(strict_types=1);

namespace BankApi\Webhook;

/** A verified webhook event. deliveryId is the receiver-side idempotency key. */
final class Event
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly string $deliveryId,
        public readonly int $timestamp,
        public readonly array $data,
    ) {
    }
}
