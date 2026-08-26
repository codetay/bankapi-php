# bankapi/bankapi-php

Official PHP SDK for [BankAPI.VN](https://api.bankapi.vn) — bank account
connections, transaction listing/matching, payment intents, and webhook
signature verification.

Framework-agnostic. If you're on Laravel, use `bankapi/bankapi-laravel`
instead (service provider, facade, webhook middleware) — see its README.

## Install

```bash
composer require bankapi/bankapi-php
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

Every non-2xx response raises a subclass of `BankApi\Exception\ApiException`,
built from the API's `problem+json` body:

| HTTP status | Exception                                    | Notes                          |
|-------------|-----------------------------------------------|---------------------------------|
| 400, 422    | `BankApi\Exception\ValidationException`       | invalid request/parameters      |
| 401         | `BankApi\Exception\AuthenticationException`   | missing/invalid API key         |
| 403         | `BankApi\Exception\PermissionException`       | key lacks permission            |
| 404         | `BankApi\Exception\NotFoundException`         | resource does not exist         |
| 429         | `BankApi\Exception\RateLimitException`        | has `->retryAfter` (seconds, nullable) |
| other       | `BankApi\Exception\ApiException`              | base class, catches everything above too |

All of them expose `->status`, `->title`, `->detail`, and `->body` (the
decoded problem response).

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
