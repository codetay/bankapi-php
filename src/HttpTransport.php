<?php
declare(strict_types=1);

namespace BankApi;

use BankApi\Exception\ApiException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 wrapper owning auth header, JSON codec, problem+json mapping and
 * bounded retry (GET only, 429/5xx/network, max N retries, backoff + jitter).
 */
final class HttpTransport
{
    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly int $maxRetries = 2,
        ?\Closure $sleep = null,
    ) {
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param array<string, int|string> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->sendRequest($this->buildRequest($method, $path, $query, $body));
            } catch (ClientExceptionInterface $e) {
                if ($this->shouldRetry($method, $attempt)) {
                    $this->backoff(++$attempt);
                    continue;
                }
                throw $e;
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $this->decode($response);
            }
            if (($status === 429 || $status >= 500) && $this->shouldRetry($method, $attempt)) {
                $this->backoff(++$attempt);
                continue;
            }

            throw ApiException::fromResponse($status, $this->decode($response), $response->getHeaders());
        }
    }

    /** @param array<string, int|string> $query @param array<string, mixed>|null $body */
    private function buildRequest(string $method, string $path, array $query, ?array $body): \Psr\Http\Message\RequestInterface
    {
        $uri = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('X-API-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'bankapi-php/0.1.0');

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function shouldRetry(string $method, int $attempt): bool
    {
        return strtoupper($method) === 'GET' && $attempt < $this->maxRetries;
    }

    private function backoff(int $attempt): void
    {
        ($this->sleep)((2 ** $attempt) * 100 + random_int(0, 100));
    }
}
