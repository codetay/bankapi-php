<?php

declare(strict_types=1);

namespace BankApi\Resource;

/** GET /banking/connections item (schema BankConnectionItem). */
final class Connection
{
    /** @param array{supports_balance?: bool, supports_debit?: bool} $capabilities */
    public function __construct(
        public readonly string $id,
        public readonly string $bankCode,
        public readonly string $label,
        public readonly string $status,
        public readonly string $accountType,
        public readonly ?string $accountNumber,
        public readonly ?string $accountNumberMasked,
        public readonly ?int $balance,
        public readonly array $capabilities,
        public readonly string $createdAt,
        public readonly string $verifiedAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            bankCode: (string) ($data['bank_code'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            accountType: (string) ($data['account_type'] ?? ''),
            accountNumber: isset($data['account_number']) ? (string) $data['account_number'] : null,
            accountNumberMasked: isset($data['account_number_masked']) ? (string) $data['account_number_masked'] : null,
            balance: isset($data['balance']) ? (int) $data['balance'] : null,
            capabilities: is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
            createdAt: (string) ($data['created_at'] ?? ''),
            verifiedAt: (string) ($data['verified_at'] ?? ''),
        );
    }
}
