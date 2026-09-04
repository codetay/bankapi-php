<?php

declare(strict_types=1);

namespace BankApi;

use BankApi\Exception\ApiException;
use BankApi\Exception\ConnectionException;
use BankApi\Exception\MalformedResponseException;
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
    /** Every path this transport builds is joined under this API version prefix. */
    public const API_VERSION_PATH = '/v1';

    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
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
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            $this->validateIdempotencyKey($idempotencyKey);
        }

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->sendRequest($this->buildRequest($method, $path, $query, $body, $idempotencyKey));
            } catch (ClientExceptionInterface $e) {
                if ($this->shouldRetry($method, $attempt)) {
                    $this->backoff(++$attempt);
                    continue;
                }
                throw new ConnectionException($e->getMessage(), $e);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return $this->decode($response, strict: true);
            }
            if (($status === 429 || $status >= 500) && $this->shouldRetry($method, $attempt)) {
                $this->backoff(++$attempt);
                continue;
            }

            throw ApiException::fromResponse($status, $this->decode($response), $response->getHeaders());
        }
    }

    /**
     * The API would reject a malformed key with a 422, but checking the
     * pattern client-side fails fast without a round trip.
     */
    private function validateIdempotencyKey(string $idempotencyKey): void
    {
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Idempotency-Key must be 1-64 characters of letters, digits, "_" or "-" (got %s).',
                var_export($idempotencyKey, true)
            ));
        }
    }

    /**
     * @param array<string, int|string> $query
     * @param array<string, mixed>|null $body
     */
    private function buildRequest(string $method, string $path, array $query, ?array $body, ?string $idempotencyKey = null): \Psr\Http\Message\RequestInterface
    {
        $uri = rtrim($this->baseUrl, '/') . self::API_VERSION_PATH . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('X-API-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'bankapi-php/' . BankApi::VERSION);

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        }

        return $request;
    }

    /**
     * Strict mode (2xx path) refuses a non-JSON or non-array body instead of
     * silently returning empty data; the lenient mode (error path) keeps
     * mapping by status even when the error body is not valid problem+json.
     *
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, bool $strict = false): array
    {
        $raw = (string) $response->getBody();
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if ($strict) {
            throw new MalformedResponseException($response->getStatusCode(), 'response body is not a JSON object');
        }

        return [];
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
