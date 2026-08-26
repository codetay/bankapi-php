<?php

declare(strict_types=1);

namespace BankApi\Resource;

/** GET /banking/connections/summary (schema ConnSummaryOutputBody). */
final class ConnectionsSummary
{
    /** @param list<array<string, mixed>> $connections */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $connections,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            from: (string) ($data['from'] ?? ''),
            to: (string) ($data['to'] ?? ''),
            connections: is_array($data['connections'] ?? null) ? array_values($data['connections']) : [],
        );
    }
}
