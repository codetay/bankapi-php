<?php

declare(strict_types=1);

namespace BankApi\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Drift guard: every path/method the SDK calls must exist in the pinned
 * openapi.json, and every JSON key a DTO reads must exist in its schema.
 * Refresh the fixture with `composer sync-spec` when GO-KIT changes its API.
 */
final class OpenApiContractTest extends TestCase
{
    /** @var list<array{string, string}> */
    private const CALLS = [
        ['get', '/banking/summary'],
        ['get', '/banking/connections'],
        ['get', '/banking/connections/summary'],
        ['get', '/banking/connections/{connId}'],
        ['get', '/banking/transactions'],
        ['get', '/banking/transactions/{txId}'],
        ['post', '/banking/transactions/{txId}/match'],
        ['get', '/banking/payment-intents'],
        ['get', '/webhooks'],
        ['post', '/webhooks'],
        ['get', '/webhooks/{endpointId}'],
        ['delete', '/webhooks/{endpointId}'],
        ['post', '/webhooks/{endpointId}/enable'],
        ['get', '/webhooks/{endpointId}/deliveries'],
    ];

    /** @var array<string, list<string>> schema name => JSON keys the DTOs read */
    private const SCHEMA_FIELDS = [
        'BankTransactionItem' => ['id', 'amount', 'direction', 'bank_ref', 'connection_id', 'description', 'match_status', 'matched_intent_id', 'transaction_date', 'created_at'],
        'TransactionDetail' => ['id', 'amount', 'direction', 'bank_ref', 'connection_id', 'description', 'match_status', 'matched_intent_id', 'match_review_reason', 'account_number_masked', 'transaction_date', 'created_at', 'last_seen_at', 'redelivery_count'],
        'BankConnectionItem' => ['id', 'bank_code', 'label', 'status', 'account_type', 'account_number', 'account_number_masked', 'balance', 'capabilities', 'created_at', 'verified_at'],
        'IntentResponse' => ['id', 'code', 'expected_amount', 'status', 'matched_transaction_id', 'expires_at'],
        'EndpointBody' => ['id', 'url', 'event_types', 'active', 'description', 'failure_count'],
        'CreateEndpointOutputBody' => ['id', 'url', 'secret'],
        'DeliveryItem' => ['id', 'event_type', 'attempt', 'status_code', 'error', 'created_at'],
        'TxMatchInputBody' => ['intent_id'],
    ];

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEverySdkCallExistsInSpec(): void
    {
        $paths = self::spec()['paths'];
        foreach (self::CALLS as [$method, $path]) {
            self::assertArrayHasKey($path, $paths, "spec lost path {$path}");
            self::assertArrayHasKey($method, $paths[$path], "spec lost {$method} {$path}");
        }
    }

    public function testEveryDtoFieldExistsInSchema(): void
    {
        $schemas = self::spec()['components']['schemas'];
        foreach (self::SCHEMA_FIELDS as $schema => $fields) {
            self::assertArrayHasKey($schema, $schemas, "spec lost schema {$schema}");
            $props = $schemas[$schema]['properties'] ?? [];
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $props, "schema {$schema} lost field {$field}");
            }
        }
    }
}
