<?php
declare(strict_types=1);

namespace BankApi\Resource;

/** POST /webhooks result. secret (whsec_) is shown ONLY here — store it now. */
final class CreatedEndpoint
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $secret,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            secret: (string) ($data['secret'] ?? ''),
        );
    }
}
