<?php
declare(strict_types=1);

namespace BankApi\Resource;

/**
 * GET /banking/summary (schema TxSummaryOutputBody). Nested aggregates
 * (match_counts, prev, days, connections) are kept as raw arrays.
 */
// ponytail: raw arrays for nested aggregates; add typed DTOs if devs ask
final class BankingSummary
{
    /**
     * @param array<string, int>        $matchCounts
     * @param array<string, mixed>      $prev
     * @param list<array<string, mixed>> $days
     * @param list<array<string, mixed>> $connections
     */
    public function __construct(
        public readonly int $count,
        public readonly int $creditTotal,
        public readonly int $creditMatchedTotal,
        public readonly int $debitTotal,
        public readonly string $from,
        public readonly string $to,
        public readonly array $matchCounts,
        public readonly array $prev,
        public readonly array $days,
        public readonly array $connections,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            count: (int) ($data['count'] ?? 0),
            creditTotal: (int) ($data['credit_total'] ?? 0),
            creditMatchedTotal: (int) ($data['credit_matched_total'] ?? 0),
            debitTotal: (int) ($data['debit_total'] ?? 0),
            from: (string) ($data['from'] ?? ''),
            to: (string) ($data['to'] ?? ''),
            matchCounts: is_array($data['match_counts'] ?? null) ? $data['match_counts'] : [],
            prev: is_array($data['prev'] ?? null) ? $data['prev'] : [],
            days: is_array($data['days'] ?? null) ? array_values($data['days']) : [],
            connections: is_array($data['connections'] ?? null) ? array_values($data['connections']) : [],
        );
    }
}
