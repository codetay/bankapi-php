<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** GET /banking/transactions/{txId} (schema TransactionDetail). */
final class TransactionDetail
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
        public readonly string $matchReviewReason,
        public readonly string $accountNumberMasked,
        public readonly string $transactionDate,
        public readonly string $createdAt,
        public readonly string $lastSeenAt,
        public readonly int $redeliveryCount,
    ) {
    }

    /** @param array<string, mixed> $data */
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
            matchReviewReason: (string) ($data['match_review_reason'] ?? ''),
            accountNumberMasked: (string) ($data['account_number_masked'] ?? ''),
            transactionDate: (string) ($data['transaction_date'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
            lastSeenAt: (string) ($data['last_seen_at'] ?? ''),
            redeliveryCount: (int) ($data['redelivery_count'] ?? 0),
        );
    }
}
