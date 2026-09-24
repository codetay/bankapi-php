<?php

declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\Exception\SignatureVerificationException;
use BankApi\Webhook\EventType;
use BankApi\Webhook\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** @return array{tolerance_seconds: int, standard_webhooks_reference: array<string, string>, vectors: list<array<string, string>>} */
    private static function file(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private static function vector(string $name): array
    {
        foreach (self::file()['vectors'] as $v) {
            if ($v['name'] === $name) {
                return $v;
            }
        }
        self::fail("vector {$name} missing from the GO-KIT fixture");
    }

    /** @return array<string, list<string>> */
    private static function headersFor(array $v): array
    {
        return [
            'webhook-id' => [$v['msg_id']],
            'webhook-timestamp' => [$v['timestamp']],
            'webhook-signature' => [$v['signature_header']],
        ];
    }

    public function testEveryGoldenVectorVerifiesWithEitherSecret(): void
    {
        $vectors = self::file()['vectors'];
        self::assertGreaterThanOrEqual(7, count($vectors));
        foreach ($vectors as $v) {
            $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
            self::assertSame($v['msg_id'], $event->webhookId);
            self::assertSame('v1', $event->apiVersion);
            if (isset($v['previous_secret'])) {
                self::assertSame($event->id, Webhook::constructEvent($v['body'], self::headersFor($v), $v['previous_secret'], 300, (int) $v['timestamp'])->id);
            }
        }
    }

    public function testStandardWebhooksReferenceSignature(): void
    {
        $ref = self::file()['standard_webhooks_reference'];
        [$id] = Webhook::verify($ref['body'], self::headersFor($ref), $ref['secret'], 300, (int) $ref['timestamp']);
        self::assertSame($ref['msg_id'], $id);
    }

    public function testLegacyUrlSafeSecretVerifies(): void
    {
        $v = self::vector('bank.credit.legacy_urlsafe_secret');
        self::assertMatchesRegularExpression('/[-_]/', $v['secret']);
        self::assertSame(32, strlen(Webhook::decodeSecret($v['secret'])));
        self::assertSame(EventType::BANK_CREDIT, Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp'])->type);
    }

    public function testAmountAboveTwoPow53StaysAnExactInt(): void
    {
        $v = self::vector('bank.credit.amount_above_2_pow_53');
        $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame(9007199254740993, $event->data['amount']);
    }

    public function testFlowOutputCarriesItsTrigger(): void
    {
        $v = self::vector('flow.output');
        $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame(EventType::FLOW_OUTPUT, $event->type);
        self::assertSame(EventType::BANK_CREDIT, $event->trigger['type'] ?? null);
        self::assertSame(['order' => 'DH1245', 'paid' => true], $event->data);
    }

    public function testUnknownTypeIsNotAnError(): void
    {
        $v = self::vector('unknown.future_event');
        $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame('bank.future_event', $event->type);
        self::assertFalse($event->isKnown());
    }

    public function testToleranceIsSymmetric(): void
    {
        $v = self::vector('bank.credit');
        $ts = (int) $v['timestamp'];
        foreach ([$ts - 300, $ts + 300] as $now) {
            self::assertSame($v['msg_id'], Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, $now)->webhookId);
        }
        foreach ([$ts - 301, $ts + 301] as $now) {
            try {
                Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, $now);
                self::fail("now={$now} must be outside the tolerance");
            } catch (SignatureVerificationException $e) {
                self::assertStringContainsString('tolerance', $e->getMessage());
            }
        }
    }

    public function testTamperingAndNonV1EntriesFail(): void
    {
        $v = self::vector('bank.credit');
        $now = (int) $v['timestamp'];
        $cases = [
            'body' => [$v['body'] . ' ', self::headersFor($v)],
            'id' => [$v['body'], ['webhook-id' => ['other']] + self::headersFor($v)],
            'v2' => [$v['body'], ['webhook-signature' => [preg_replace('/^v1,/', 'v2,', $v['signature_header'])]] + self::headersFor($v)],
        ];
        foreach ($cases as $name => [$body, $headers]) {
            try {
                Webhook::constructEvent($body, $headers, $v['secret'], 300, $now);
                self::fail("{$name}: must not verify");
            } catch (SignatureVerificationException $e) {
                self::assertSame('webhook signature mismatch', $e->getMessage());
            }
        }
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $v = self::vector('bank.credit');
        $event = Webhook::constructEvent($v['body'], array_change_key_case(self::headersFor($v), CASE_UPPER), $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame($v['msg_id'], $event->webhookId);
    }

    public function testEmptySecretAndNonEnvelopeBodyFail(): void
    {
        $v = self::vector('bank.credit');
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], self::headersFor($v), '', 300, (int) $v['timestamp']);
    }

    public function testReferenceBodyIsNotAnEnvelope(): void
    {
        $ref = self::file()['standard_webhooks_reference'];
        $this->expectExceptionMessage('signed payload is not a webhook envelope');
        Webhook::constructEvent($ref['body'], self::headersFor($ref), $ref['secret'], 300, (int) $ref['timestamp']);
    }
}
