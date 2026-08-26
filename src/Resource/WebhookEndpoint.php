<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** GET /webhooks item (schema EndpointBody). Never carries the secret. */
final class WebhookEndpoint
{
    /** @param list<string> $eventTypes */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly array $eventTypes,
        public readonly bool $active,
        public readonly string $description,
        public readonly int $failureCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            eventTypes: is_array($data['event_types'] ?? null) ? array_values($data['event_types']) : [],
            active: (bool) ($data['active'] ?? false),
            description: (string) ($data['description'] ?? ''),
            failureCount: (int) ($data['failure_count'] ?? 0),
        );
    }
}
