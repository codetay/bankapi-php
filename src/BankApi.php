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
    public const VERSION = '0.1.0';

    private readonly HttpTransport $transport;
    private ?BankingService $banking = null;
    private ?WebhookEndpointService $webhookEndpoints = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.bankapi.vn',
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->transport = new HttpTransport($apiKey, $baseUrl, $httpClient, $requestFactory, $streamFactory);
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
