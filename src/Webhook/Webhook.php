<?php

declare(strict_types=1);

namespace BankApi\Webhook;

use BankApi\Exception\SignatureVerificationException;

/**
 * Verifies BankAPI webhook signatures: HMAC-SHA256 hex over
 * "<delivery_id>.<timestamp>.<raw body>", carried as "sha256=<hex>" in
 * X-Webhook-Signature. Signature is checked before the timestamp tolerance.
 */
final class Webhook
{
    private const SIGNATURE_PREFIX = 'sha256=';

    /**
     * @param array<string, string|list<string>> $headers case-insensitive lookup
     * @param int                                $tolerance max |now - timestamp| in seconds (replay guard)
     * @param int|null                           $now overrides time() for tests
     */
    public static function constructEvent(string $payload, array $headers, string $secret, int $tolerance = 300, ?int $now = null): Event
    {
        if ($secret === '') {
            throw new SignatureVerificationException('webhook secret must not be empty');
        }

        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        $deliveryId = self::required($h, 'x-webhook-delivery-id');
        $timestampRaw = self::required($h, 'x-webhook-timestamp');
        $signatureHeader = self::required($h, 'x-webhook-signature');

        if (!str_starts_with($signatureHeader, self::SIGNATURE_PREFIX)) {
            throw new SignatureVerificationException('X-Webhook-Signature is not in "sha256=<hex>" form');
        }

        $expected = hash_hmac('sha256', $deliveryId . '.' . $timestampRaw . '.' . $payload, $secret);
        if (!hash_equals($expected, substr($signatureHeader, strlen(self::SIGNATURE_PREFIX)))) {
            throw new SignatureVerificationException('webhook signature mismatch');
        }

        $timestamp = (int) $timestampRaw;
        $now ??= time();
        if (abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('webhook timestamp outside tolerance');
        }

        $decoded = json_decode($payload, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $type = is_string($decoded['event'] ?? null) && $decoded['event'] !== '' ? $decoded['event'] : ($h['x-webhook-event'] ?? '');

        return new Event($type, $deliveryId, $timestamp, $data);
    }

    /** @param array<string, string> $h */
    private static function required(array $h, string $name): string
    {
        $value = $h[$name] ?? '';
        if ($value === '') {
            throw new SignatureVerificationException(sprintf('missing %s header', $name));
        }

        return $value;
    }
}
