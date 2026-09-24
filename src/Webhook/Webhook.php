<?php

declare(strict_types=1);

namespace BankApi\Webhook;

use BankApi\Exception\SignatureVerificationException;

/**
 * Verifies BankAPI webhooks per Standard Webhooks: any v1 entry of
 * webhook-signature must equal base64(HMAC-SHA256(key,
 * "{webhook-id}.{webhook-timestamp}.{raw body}")), key being the decoded
 * base64 after whsec_. The signature is checked before the timestamp.
 */
final class Webhook
{
    private const SECRET_PREFIX = 'whsec_';

    /** @param array<string, string|list<string>> $headers case-insensitive */
    public static function constructEvent(string $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = 300, ?int $now = null): Event
    {
        [$webhookId, $timestamp] = self::verify($payload, $headers, $secret, $tolerance, $now);

        // Integers stay ints: json_decode keeps 64-bit values exact on 64-bit PHP.
        $e = json_decode($payload, true);
        if (!is_array($e)
            || !is_string($e['id'] ?? null) || !is_string($e['type'] ?? null) || $e['type'] === ''
            || !is_string($e['api_version'] ?? null) || !is_string($e['created_at'] ?? null)
            || !is_string($e['org_id'] ?? null) || !is_array($e['data'] ?? null)) {
            throw new SignatureVerificationException('signed payload is not a webhook envelope');
        }

        /** @var array{id: string, type: string, flow_id: string}|null $trigger set on flow.output only */
        $trigger = is_array($e['trigger'] ?? null) ? $e['trigger'] : null;

        return new Event(
            $e['id'],
            $e['type'],
            $e['api_version'],
            $e['created_at'],
            $e['org_id'],
            $e['data'],
            $trigger,
            $webhookId,
            $timestamp,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array{0: string, 1: int} webhook-id and webhook-timestamp
     */
    public static function verify(string $payload, array $headers, #[\SensitiveParameter] string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $key = self::decodeSecret($secret);

        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $webhookId = self::required($h, 'webhook-id');
        $timestampRaw = self::required($h, 'webhook-timestamp');
        $signatureHeader = self::required($h, 'webhook-signature');

        $expected = base64_encode(hash_hmac('sha256', $webhookId . '.' . $timestampRaw . '.' . $payload, $key, true));
        $matched = false;
        foreach (explode(' ', $signatureHeader) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException('webhook signature mismatch');
        }

        if (preg_match('/^\d+$/', $timestampRaw) !== 1) {
            throw new SignatureVerificationException('webhook-timestamp is not Unix seconds');
        }
        $timestamp = (int) $timestampRaw;
        $now ??= time();
        if (abs($now - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('webhook timestamp outside tolerance');
        }

        return [$webhookId, $timestamp];
    }

    /**
     * The HMAC key of a whsec_ secret. Both base64 alphabets are accepted,
     * padded or not: secrets minted before envelope v1 are base64url.
     */
    public static function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, strlen(self::SECRET_PREFIX)) : $secret;
        $standard = strtr($encoded, '-_', '+/');
        $standard = str_pad($standard, (int) (ceil(strlen($standard) / 4) * 4), '=');
        $key = base64_decode($standard, true);
        if ($encoded === '' || $key === false || $key === '') {
            throw new SignatureVerificationException('webhook secret must be whsec_<base64>');
        }

        return $key;
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
