<?php

declare(strict_types=1);

namespace BankApi\Webhook;

/** A verified webhook: the v1 envelope plus its delivery headers. webhookId is the dedupe key. */
final class Event
{
    /**
     * @param array<string, mixed>                              $data
     * @param array{id: string, type: string, flow_id: string}|null $trigger set on flow.output only
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $apiVersion,
        public readonly string $createdAt,
        public readonly string $orgId,
        public readonly array $data,
        public readonly ?array $trigger,
        public readonly string $webhookId,
        public readonly int $timestamp,
    ) {
    }

    /** False for a type newer than this SDK: acknowledge it, do not fail. */
    public function isKnown(): bool
    {
        return in_array($this->type, EventType::ALL, true);
    }
}
