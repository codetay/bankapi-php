<?php
declare(strict_types=1);

namespace BankApi\Tests;

use BankApi\Exception\SignatureVerificationException;
use BankApi\Webhook\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    /** @return list<array{secret: string, delivery_id: string, timestamp: string, body: string, signature_header: string}> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/webhook_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<string>> */
    private static function headersFor(array $v): array
    {
        $decoded = json_decode($v['body'], true);

        return [
            'X-Webhook-Event' => [$decoded['event']],
            'X-Webhook-Delivery-Id' => [$v['delivery_id']],
            'X-Webhook-Timestamp' => [$v['timestamp']],
            'X-Webhook-Signature' => [$v['signature_header']],
        ];
    }

    public function testGoldenVectorsAllVerify(): void
    {
        foreach (self::vectors() as $v) {
            $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
            $decoded = json_decode($v['body'], true);
            self::assertSame($decoded['event'], $event->type);
            self::assertSame($v['delivery_id'], $event->deliveryId);
            self::assertSame((int) $v['timestamp'], $event->timestamp);
            self::assertSame($decoded['data'], $event->data);
        }
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $v = self::vectors()[0];
        $headers = array_change_key_case(self::headersFor($v), CASE_UPPER);
        $event = Webhook::constructEvent($v['body'], $headers, $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame($v['delivery_id'], $event->deliveryId);
    }

    public function testScalarHeaderValuesAccepted(): void
    {
        $v = self::vectors()[0];
        $headers = array_map(static fn (array $vals): string => $vals[0], self::headersFor($v));
        $event = Webhook::constructEvent($v['body'], $headers, $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame($v['delivery_id'], $event->deliveryId);
    }

    public function testEventTypeFallsBackToBodyWhenHeaderMissing(): void
    {
        $v = self::vectors()[0];
        $headers = self::headersFor($v);
        unset($headers['X-Webhook-Event']);
        $event = Webhook::constructEvent($v['body'], $headers, $v['secret'], 300, (int) $v['timestamp']);
        self::assertSame('bank.credit', $event->type);
    }

    public function testTimestampExactlyAtToleranceAccepted(): void
    {
        $v = self::vectors()[0];
        $event = Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp'] + 300);
        self::assertSame($v['delivery_id'], $event->deliveryId);
    }

    public function testTamperedBodyRejected(): void
    {
        $v = self::vectors()[0];
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'] . ' ', self::headersFor($v), $v['secret'], 300, (int) $v['timestamp']);
    }

    public function testWrongSecretRejected(): void
    {
        $v = self::vectors()[0];
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], self::headersFor($v), 'whsec_wrong', 300, (int) $v['timestamp']);
    }

    public function testStaleTimestampRejected(): void
    {
        $v = self::vectors()[0];
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], self::headersFor($v), $v['secret'], 300, (int) $v['timestamp'] + 301);
    }

    public function testMissingSignatureHeaderRejected(): void
    {
        $v = self::vectors()[0];
        $headers = self::headersFor($v);
        unset($headers['X-Webhook-Signature']);
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], $headers, $v['secret'], 300, (int) $v['timestamp']);
    }

    public function testMalformedSignaturePrefixRejected(): void
    {
        $v = self::vectors()[0];
        $headers = self::headersFor($v);
        $headers['X-Webhook-Signature'] = [substr($v['signature_header'], 7)]; // strip "sha256="
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent($v['body'], $headers, $v['secret'], 300, (int) $v['timestamp']);
    }
}
