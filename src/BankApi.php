<?php

declare(strict_types=1);

namespace BankApi;

use BankApi\Service\BankingService;
use BankApi\Service\WebhookEndpointService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Entry point. Omit $httpClient to auto-discover any installed PSR-18 client. */
final class BankApi
{
    public const VERSION = '1.0.0';

    private readonly HttpTransport $transport;
    private ?BankingService $banking = null;
    private ?WebhookEndpointService $webhookEndpoints = null;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl = 'https://api.bankapi.vn',
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->transport = new HttpTransport(
            $apiKey,
            self::requireSecureBaseUrl($baseUrl),
            $httpClient,
            $requestFactory,
            $streamFactory,
        );
    }

    /**
     * The API key rides on every request, so the base URL has to be https —
     * a misconfigured "http://" would put it on the wire in clear text. Plain
     * http is allowed only against a loopback host, for local development.
     */
    private static function requireSecureBaseUrl(string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw new \InvalidArgumentException('BankAPI base URL must be an absolute URL, e.g. https://api.bankapi.vn');
        }
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new \InvalidArgumentException(sprintf(
                'BankAPI base URL must use https (got "%s"); plain http is only allowed for loopback hosts.',
                $scheme !== '' ? $scheme : '(none)'
            ));
        }

        $trimmed = rtrim(trim($baseUrl), '/');
        if (str_ends_with(strtolower($trimmed), HttpTransport::API_VERSION_PATH)) {
            throw new \InvalidArgumentException(
                'baseUrl must be the API origin (e.g. https://acme.bankapi.vn); the SDK appends /v1'
            );
        }

        return $trimmed;
    }

    public function banking(): BankingService
    {
        return $this->banking ??= new BankingService($this->transport);
    }

    public function webhookEndpoints(): WebhookEndpointService
    {
        return $this->webhookEndpoints ??= new WebhookEndpointService($this->transport);
    }
}
