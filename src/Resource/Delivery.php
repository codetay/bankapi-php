<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** GET /webhooks/{id}/deliveries item (schema DeliveryItem). */
final class Delivery
{
    public function __construct(
        public readonly string $id,
        public readonly string $eventType,
        public readonly int $attempt,
        public readonly ?int $statusCode,
        public readonly string $error,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            eventType: (string) ($data['event_type'] ?? ''),
            attempt: (int) ($data['attempt'] ?? 0),
            statusCode: isset($data['status_code']) ? (int) $data['status_code'] : null,
            error: (string) ($data['error'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
        );
    }
}
