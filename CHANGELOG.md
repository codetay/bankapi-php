# Changelog

All notable changes to `codetay/bankapi-php` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-09-28

BankAPI now signs webhooks on [Standard Webhooks](https://www.standardwebhooks.com/)
instead of the previous ad hoc `X-Webhook-*` scheme. **Migrating from 1.0:**
read the webhook secret from `webhook-id`/`webhook-timestamp`/`webhook-signature`
headers (not `X-Webhook-Delivery-Id`/`X-Webhook-Timestamp`/`X-Webhook-Signature`);
`Event::$deliveryId` is now `Event::$webhookId`, and `Event::$type`/`$data`
now come from a parsed `{id, type, api_version, created_at, org_id, data}`
envelope instead of `{event, data}`. If you dispatched on `$event->type` with
a bare string, switch to the new `EventType::*` constants.

### Breaking

- `Webhook::constructEvent()` now verifies the Standard Webhooks scheme: any
  `v1,<base64>` entry of the `webhook-signature` header must equal
  `base64(HMAC-SHA256(key, "<webhook-id>.<webhook-timestamp>.<raw body>"))`,
  read from the `webhook-id`/`webhook-timestamp`/`webhook-signature` headers.
  The previous `X-Webhook-Signature: sha256=<hex>` / `X-Webhook-Delivery-Id` /
  `X-Webhook-Timestamp` scheme is no longer accepted.
- `Event` is rewritten: `$deliveryId` is renamed to `$webhookId`; `$type` and
  `$data` now come from parsing the signed body as a v1 envelope
  (`{id, type, api_version, created_at, org_id, data}`, `flow.output` also
  carrying `trigger`) instead of the previous `{event, data}` shape. `Event`
  gained `$id`, `$apiVersion`, `$createdAt`, `$orgId`, `$trigger`, and
  `isKnown()`.
- Added `Webhook::verify()` (returns `[webhookId, timestamp]` without parsing
  the body) and `Webhook::decodeSecret()` (the raw HMAC key of a
  `whsec_<base64>` secret, accepting both the standard and URL-safe base64
  alphabets, padded or not, so a secret minted before this release keeps
  verifying unchanged).
- Added the generated `EventType` class (`EventType::BANK_CREDIT`, …,
  `EventType::ALL`) listing every event type of the pinned GO-KIT contract.
  A type this SDK version does not know yet still parses; `$event->isKnown()`
  is `false` for it instead of the call throwing.

### Added

- `EventType::WEBHOOK_TEST` (`webhook.test`), sent by the dashboard's "send
  test" action. Acknowledge it like any other event.
- `ErrorCode` now covers every coded 4xx the API returns (182 codes).

## [1.0.0] - 2026-09-04

The SDK now targets the frozen GO-KIT API contract (pinned by
`packages/core/tests/fixtures/openapi.lock.json`), which moved every
endpoint under `/v1`. **Migrating from 0.1:** if you were passing a
`baseUrl` that already ended in `/v1`, drop the suffix — the SDK appends it
now and rejects a `baseUrl` that already has it. If you constructed
`ApiException` (or a subclass) directly with positional constructor
arguments including `$previous`, pass it as a named `previous:` argument
instead — the constructor gained `$errorCode` and `$replayed` parameters
before it. If you inspected exception messages to branch on the failure
kind, read the new `errorCode()` accessor instead.

### Breaking

- Every request is now sent under `/v1` (`HttpTransport` appends the
  exported `API_VERSION_PATH` constant after `baseUrl`). `baseUrl` must be
  the API origin only (e.g. `https://acme.bankapi.vn`) — a `baseUrl` that
  already ends in `/v1` now throws `InvalidArgumentException` instead of
  doubling the path.
- `ApiException::__construct()` gained two parameters, `?string $errorCode`
  and `bool $replayed`, inserted before the existing `?\Throwable $previous`.
  Code that constructed `ApiException` (or a subclass) directly with a
  positional `$previous` argument must switch to a named `previous:`
  argument.

### Added

- `BankingService::createPaymentIntent()` and `BankingService::paymentIntent()`.
- Optional `idempotencyKey` argument on `createPaymentIntent` and
  `WebhookEndpointService::create()`: validated against
  `^[A-Za-z0-9_-]{1,64}$` (rejected client-side with
  `InvalidArgumentException` before any request is sent) and sent as the
  `Idempotency-Key` header; both methods generate one with
  `bin2hex(random_bytes(16))` when the caller omits it.
- `ApiException::errorCode()` — the registry error code from `problem.type`
  with the `urn:bankapi:error:` prefix stripped (`null` for any other
  shape) — and a `$replayed` property, read from the `Idempotent-Replayed`
  response header. A dedicated accessor keeps this from being confused with
  the inherited int-typed `Exception::getCode()`, which `ApiException` does
  not override.
- Generated `BankApi\ErrorCode` class (`ErrorCode::ALL`, `ErrorCode::isKnown()`),
  pinned to the GO-KIT error-code registry via `composer gen:error-codes`.
- `composer sync-spec` / `composer verify-spec` pin and check the OpenAPI
  fixture against a GO-KIT ref by lock
  (`packages/core/tests/fixtures/openapi.lock.json`); CI now checks out that
  ref and runs `verify-spec` on every matrix leg.

### Changed

- The OpenAPI fixture and contract tests now track the frozen GO-KIT
  `4ef6a7c` contract instead of the pre-freeze spec.

### Known limitations

- `ApiException::$replayed` is true only when the replayed response was
  itself an error; a successful replay returns the same intent (same `id`),
  which is all a caller needs — a replayed 2xx cannot be told apart from a
  fresh create by any response field, and the 409
  `idempotency.in_progress` / 422 `idempotency.key_reused` errors do not
  carry `Idempotent-Replayed` either.

### Security

- The base URL must now be `https` (plain `http` is accepted only for loopback
  hosts). The API key is sent on every request, so a mistyped `http://` no
  longer puts it on the wire in clear text.
- The webhook event type is read from the signed payload only. A delivery
  whose signed body carries no `event` is rejected instead of falling back to
  the unsigned `X-Webhook-Event` header, which a replayed delivery could
  relabel.
- `#[\SensitiveParameter]` on the API key and webhook secret keeps them out of
  stack traces, and `CreatedEndpoint` redacts its signing secret when dumped.

## [0.1.0] - 2026-08-27

### Added

- `BankApi` client with `X-API-Key` authentication and PSR-18 auto-discovery.
- Banking API: `summary()`, `connections()`, `connectionsSummary()`,
  `connection()`, `transactions()` (cursor-paginated with `autoPaging()`),
  `transaction()`, `matchTransaction()`, `paymentIntents()`.
- Webhook endpoint management: `create()` (signing secret shown once),
  `all()`, `get()`, `delete()`, `enable()`, `deliveries()`.
- Webhook signature verification (`Webhook::constructEvent()`): HMAC-SHA256
  over `<delivery_id>.<timestamp>.<body>`, timing-safe comparison, replay
  tolerance window, signed-body event type. Verified against golden vectors
  generated by the BankAPI server's own signing implementation.
- Exception hierarchy on RFC 7807 problem responses: `ValidationException`,
  `AuthenticationException`, `PermissionException`, `NotFoundException`,
  `RateLimitException` (with `retryAfter`), `ConnectionException` (transport
  failures), `MalformedResponseException` (non-JSON 2xx bodies) — all under
  `ApiException`.
- Bounded retry: GET requests only, on 429/5xx/network errors, max 2 retries
  with exponential backoff and jitter.
