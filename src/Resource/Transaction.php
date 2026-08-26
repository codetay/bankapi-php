<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** One row of GET /banking/transactions (schema BankTransactionItem). */
final class Transaction
{
    public function __construct(
        public readonly string $id,
        public readonly int $amount,
        public readonly string $direction,
        public readonly string $bankRef,
        public readonly string $connectionId,
        public readonly string $description,
        public readonly string $matchStatus,
        public readonly string $matchedIntentId,
        public readonly string $transactionDate,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data unknown keys are ignored (forward compatible) */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            amount: (int) ($data['amount'] ?? 0),
            direction: (string) ($data['direction'] ?? ''),
            bankRef: (string) ($data['bank_ref'] ?? ''),
            connectionId: (string) ($data['connection_id'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            matchStatus: (string) ($data['match_status'] ?? ''),
            matchedIntentId: (string) ($data['matched_intent_id'] ?? ''),
            transactionDate: (string) ($data['transaction_date'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
        );
    }
}
