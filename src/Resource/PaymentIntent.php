<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** GET /banking/payment-intents item (schema IntentResponse). */
final class PaymentIntent
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly int $expectedAmount,
        public readonly string $status,
        public readonly string $matchedTransactionId,
        public readonly string $expiresAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            code: (string) ($data['code'] ?? ''),
            expectedAmount: (int) ($data['expected_amount'] ?? 0),
            status: (string) ($data['status'] ?? ''),
            matchedTransactionId: (string) ($data['matched_transaction_id'] ?? ''),
            expiresAt: (string) ($data['expires_at'] ?? ''),
        );
    }
}
