# codetay/bankapi-php

Official PHP SDK for [BankAPI.VN](https://api.bankapi.vn) — bank account
connections, transaction listing/matching, payment intents, and webhook
signature verification.

Framework-agnostic. If you're on Laravel, use `codetay/bankapi-laravel`
instead (service provider, facade, webhook middleware) — see its README.

## Install

```bash
composer require codetay/bankapi-php:^1.0
```

The SDK talks HTTP through [PSR-18](https://www.php-fig.org/psr/psr-18/) and
auto-discovers an installed client via `php-http/discovery`. If your project
doesn't already depend on a PSR-18 client, add one:

```bash
composer require guzzlehttp/guzzle
```

You can also pass a specific PSR-18 client, request factory, and stream
factory explicitly to `BankApi::__construct()` instead of relying on
discovery.

**Configure the client you pass in.** A discovered client runs with its own
defaults, and Guzzle's default is no timeout at all — one hung API call then
occupies a PHP-FPM worker until the socket closes. Two settings are worth
making explicit in any client you hand to the SDK:

```php
$client = new \GuzzleHttp\Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    // The API key travels in the X-API-Key header, which Guzzle does NOT
    // strip when a redirect changes host — don't follow redirects.
    'allow_redirects' => false,
]);

$bankapi = new \BankApi\BankApi('bk_live_...', 'https://api.bankapi.vn', $client);
```

The Laravel package ships a client configured this way out of the box.

The base URL must be `https` (plain `http` is accepted only for loopback
hosts during local development); anything else is refused with an
`InvalidArgumentException` rather than sending your API key in clear text.
Pass your API origin only (e.g. `https://acme.bankapi.vn`) — the SDK appends
`/v1` itself (the public `HttpTransport::API_VERSION_PATH` constant), so a
`baseUrl` that already ends in `/v1` is rejected instead of doubling the
path.

## Quickstart

```php
use BankApi\BankApi;

$bankapi = new BankApi('bk_live_...');

// List transactions, newest first, and page through everything lazily.
$page = $bankapi->banking()->transactions(limit: 50);
foreach ($page->autoPaging() as $transaction) {
    echo "{$transaction->id}: {$transaction->amount} ({$transaction->direction})\n";
}

// Reconcile an inbound transaction against a payment intent.
$detail = $bankapi->banking()->matchTransaction(
    txId: 'tx_01H...',
    intentId: 'pi_01H...',
);
```

`BankApi::__construct(string $apiKey, string $baseUrl = 'https://api.bankapi.vn', ...)`
authenticates every request with an `X-API-Key: <apiKey>` header.

> **Base URL is your organization host.** Org-scoped operations (banking,
> webhook endpoints) must be called on your organization's own host —
> `https://<your-org-slug>.bankapi.vn` — not the account-level API host.
> Calling them on a non-organization host fails with
> `403 "this operation must be accessed on an organization host"`. Pass your
> org host as `$baseUrl`:
>
> ```php
> $bankapi = new BankApi('bk_live_...', baseUrl: 'https://acme.bankapi.vn');
> ```

### Pagination

List methods (`banking()->transactions()`, `banking()->paymentIntents()`,
`webhookEndpoints()->all()`, `webhookEndpoints()->deliveries()`) return a
`BankApi\Page`, a cursor-paginated single page:

```php
$page = $bankapi->banking()->transactions(limit: 20);

foreach ($page as $transaction) {   // items on this page only
    // ...
}

$page->nextCursor;                  // '' when this was the last page

foreach ($page->autoPaging() as $transaction) {
    // fetches subsequent pages lazily as you iterate
}
```

## Payment intents

`createPaymentIntent` and `webhookEndpoints()->create()` are idempotent: pass
your own `idempotencyKey` to control retries explicitly, or omit it and the
SDK generates one with `bin2hex(random_bytes(16))`. A retry with the same key
and the same request body replays the first response instead of creating a
second intent. `ApiException::$replayed` is true only when the replayed
response was itself an error; a successful replay returns the same intent
(same `id`), which is all a caller needs.

```php
$intent = $bankapi->banking()->createPaymentIntent(
    code: 'PN-1042',
    expectedAmount: 150_000,
    expiresInSecs: 900, // optional; server default when omitted
);
echo "{$intent->id}: {$intent->status}\n";

// Pass your own key so a retried request is guaranteed to replay, not double-create
$retried = $bankapi->banking()->createPaymentIntent(
    code: 'PN-1042',
    expectedAmount: 150_000,
    idempotencyKey: 'order-1042-attempt-1',
);

$current = $bankapi->banking()->paymentIntent($intent->id);
```

`ApiException::errorCode()` is the registry error code (`problem.type` with
the `urn:bankapi:error:` prefix stripped), typed `?string` since a server can
roll out a new code before this SDK is regenerated. Compare it against
`BankApi\ErrorCode` constants, or use `ErrorCode::isKnown()` to check whether
it's one this SDK knows about:

```php
use BankApi\ErrorCode;
use BankApi\Exception\ApiException;

try {
    $bankapi->banking()->createPaymentIntent(code: 'PN-1042', expectedAmount: 150_000, idempotencyKey: $key);
} catch (ApiException $e) {
    echo $e->errorCode() . "\n";
    if ($e->errorCode() === ErrorCode::IDEMPOTENCY_IN_PROGRESS) {
        // the first request with this key is still executing — back off and retry
    }
    // $e->replayed would be true here only if this error was itself a
    // replayed response — the idempotency.* errors above never carry
    // Idempotent-Replayed, so it stays false for both of them.
}
```

## Webhook verification

Verify the signature on every incoming webhook request before trusting its
payload, then respond `2xx` quickly (BankAPI retries on `408`/`429`/`5xx`):

```php
use BankApi\Exception\SignatureVerificationException;
use BankApi\Webhook\Webhook;

$payload = file_get_contents('php://input');

try {
    $event = Webhook::constructEvent(
        payload: $payload,
        headers: getallheaders(),
        secret: $_ENV['BANKAPI_WEBHOOK_SECRET'],
    );
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// Dedupe retried deliveries using $event->deliveryId (e.g. a unique
// constraint or a seen-ids cache) before acting on the event.
if (! already_processed($event->deliveryId)) {
    handle($event->type, $event->data);
    mark_processed($event->deliveryId);
}

http_response_code(200);
```

`Webhook::constructEvent()` verifies an HMAC-SHA256 hex signature computed
over `"<delivery_id>.<timestamp>.<raw body>"`, carried as
`X-Webhook-Signature: sha256=<hex>` alongside `X-Webhook-Event`,
`X-Webhook-Delivery-Id`, and `X-Webhook-Timestamp` (Unix seconds). It also
rejects deliveries whose timestamp is older than `$tolerance` seconds
(default 300) to guard against replay. Always pass the **raw, unparsed**
request body — re-encoding JSON before verifying will break the signature
check.

The webhook secret (`whsec_...`) is shown **once**, in the response of
`webhookEndpoints()->create(...)` — store it immediately, it cannot be
retrieved again later.

## Errors

Every failure raises a subclass of `BankApi\Exception\ApiException` — HTTP
errors are built from the API's `problem+json` body, transport failures are
wrapped too:

| Condition   | Exception                                    | Notes                          |
|-------------|-----------------------------------------------|---------------------------------|
| 400, 422    | `BankApi\Exception\ValidationException`       | invalid request/parameters      |
| 401         | `BankApi\Exception\AuthenticationException`   | missing/invalid API key         |
| 403         | `BankApi\Exception\PermissionException`       | key lacks permission            |
| 404         | `BankApi\Exception\NotFoundException`         | resource does not exist         |
| 429         | `BankApi\Exception\RateLimitException`        | has `->retryAfter` (seconds, nullable) |
| other non-2xx | `BankApi\Exception\ApiException`            | base class, catches everything in this table |
| no response (DNS/connect/TLS/timeout) | `BankApi\Exception\ConnectionException` | `->status` is 0; original PSR-18 error via `->getPrevious()` |
| 2xx with a non-JSON body | `BankApi\Exception\MalformedResponseException` | e.g. a proxy or captive portal answered instead of the API |

All of them expose `->status`, `->title`, `->detail`, and `->body` (the
decoded problem response). Catching `ApiException` catches every failure the
SDK can raise during a request.

```php
use BankApi\Exception\RateLimitException;
use BankApi\Exception\ApiException;

try {
    $bankapi->banking()->transaction('tx_does_not_exist');
} catch (RateLimitException $e) {
    sleep($e->retryAfter ?? 1);
} catch (ApiException $e) {
    log_error("bankapi error {$e->status}: {$e->detail}");
}
```

`GET` requests are retried automatically (up to 2 extra attempts) on
network errors, `429`, and `5xx` responses, with backoff and jitter.
Non-`GET` requests are never retried automatically, since they may not be
idempotent.
