<p align="center">
  <a href="https://lunixi.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-laravel/main/.github/assets/lunixi-logo-dark.svg">
      <img src="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-laravel/main/.github/assets/lunixi-logo.svg" alt="Lunixi" height="64">
    </picture>
  </a>
</p>

# Lunixi for Laravel

[![CI](https://github.com/Lunixi-Payment/lunixi-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/Lunixi-Payment/lunixi-laravel/actions/workflows/ci.yml) [![Packagist](https://img.shields.io/packagist/v/lunixi/laravel?label=packagist)](https://packagist.org/packages/lunixi/laravel) [![Downloads](https://img.shields.io/packagist/dt/lunixi/laravel)](https://packagist.org/packages/lunixi/laravel/stats) [![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](https://laravel.com) [![PHP](https://img.shields.io/packagist/dependency-v/lunixi/laravel/php)](https://packagist.org/packages/lunixi/laravel) [![License](https://img.shields.io/packagist/l/lunixi/laravel)](LICENSE) [![Docs](https://img.shields.io/badge/docs-docs.lunixi.com-FF6B35)](https://docs.lunixi.com/sdks/laravel)

**[Documentation](https://docs.lunixi.com/sdks/laravel) · [Changelog](https://github.com/Lunixi-Payment/lunixi-laravel/blob/main/CHANGELOG.md) · [Issues](https://github.com/Lunixi-Payment/lunixi-laravel/issues) · [Security](https://github.com/Lunixi-Payment/lunixi-laravel/blob/main/SECURITY.md)**

Laravel integration for the [Lunixi PHP SDK](https://github.com/Lunixi-Payment/lunixi-php) (`lunixi/php-sdk`).
The SDK does the work — request signing, tokens, retries, idempotency, every
Lunixi API. This package connects it to Laravel:

- configuration from `.env`, one or more connections (API keys)
- `Lunixi` facade and `LunixiClient` injection
- requests through the Laravel HTTP client, so `Http::fake()` covers them
- bearer token in your cache, encrypted
- webhook route with signature verification, redelivery handling and a
  Laravel event, handled in the request or on your queue
- `<x-lunixi::checkout-form>` Blade component for the checkout form
- `lunixi:reconcile` to read pending payments when a webhook is lost
- `lunixi:install`, `lunixi:check`, `lunixi:generate-key`
- a test helper that posts signed webhooks

Requirements: PHP 8.2+, Laravel 12 or 13, `ext-sodium`, `ext-curl`, `ext-json`.

This source targets Laravel adapter **0.1.1** with PHP SDK **^0.29.5**. The earlier
adapter 0.1.0 requires ^0.28, which does not include the SDK's new hosted-URL
accessor. Check the published tag and Packagist version before deploying this
update; a source change alone is not a registry release.

## Installation

```bash
composer require lunixi/laravel
php artisan lunixi:install
php artisan lunixi:generate-key
```

`lunixi:generate-key` writes the private key to `storage/lunixi/private.pem`
(owner read/write only) and prints the public key. Register the public key in
the Lunixi dashboard, then set:

```dotenv
LUNIXI_ENVIRONMENT=TEST
LUNIXI_KEY_ID=mk_test_sk_...
LUNIXI_PRIVATE_KEY_PATH=/var/www/app/storage/lunixi/private.pem
LUNIXI_WEBHOOK_SECRET=whsec_...
```

```bash
php artisan lunixi:check
```

`lunixi:check` fetches a token with your key and prints the environment and
key id. It never prints the key or the token.

Keep the private key file out of version control and out of the public web
root. `LUNIXI_PRIVATE_KEY` (the key inline, `\n` for newlines) is for local use
only.

## Calling Lunixi

Every SDK client is available through the facade or by injecting
`Lunixi\Sdk\LunixiClient`:

```php
use Lunixi\Laravel\Facades\Lunixi;

$payment = Lunixi::payments()->get($paymentId);
```

```php
use Lunixi\Sdk\LunixiClient;

final class RefundOrder
{
    public function __construct(private readonly LunixiClient $lunixi) {}

    public function __invoke(Order $order): void
    {
        $this->lunixi->payments()->refund(
            $order->lunixi_payment_id,
            $order->refund_amount_minor,
            'Customer request',
            "refund-{$order->id}-{$order->refund_attempt}"
        );
    }
}
```

The method reference is in the [SDK README](https://github.com/Lunixi-Payment/lunixi-php#readme). SDK exceptions
(`Lunixi\Sdk\Exception\ApiException`, `AuthenticationException`,
`ConfigurationException` …) reach your code unchanged.

### More than one key

Add a connection in `config/lunixi.php` and select it by name. Wallet bulk
payouts, for example, are approved by a second key:

```php
'connections' => [
    'default' => [/* ... */],
    'approver' => [
        'base_url' => env('LUNIXI_BASE_URL', 'https://api-gateway.lunixi.com'),
        'environment' => env('LUNIXI_ENVIRONMENT', 'LIVE'),
        'key_id' => env('LUNIXI_APPROVER_KEY_ID'),
        'private_key_path' => env('LUNIXI_APPROVER_PRIVATE_KEY_PATH'),
    ],
],
```

```php
$created = Lunixi::wallet()->bulkPayouts->create($payoutRequest, "payroll-{$period}");
$batchId = (string) $created['batch']['id'];
Lunixi::wallet()->bulkPayouts->submit($batchId);

$checker = Lunixi::connection('approver')->wallet()->bulkPayouts;
$checker->approve($batchId);
$checker->process($batchId);
```

## Taking a payment

The existing Blade component renders the Lunixi form on your own website and
is the default integration path. Enabled methods, installments and multi-card
eligibility remain controlled by the Lunixi form. There is no package admin UI
for selecting embedded or hosted mode.

1. **Create the payment intent** when the buyer submits the order. Amounts are
   integers in the currency's minor unit (kuruş for TRY). Authorize the customer
   against the order and protect the checkout POST with Laravel's native CSRF
   middleware. Reserve the attempt and its original request/key durably before
   calling Lunixi. Reuse that key after a timeout; changing the card, refreshing
   the page or switching presentation does not establish a safe new attempt.
   Resolve uncertain or failed outcomes before permitting another attempt.

   ```php
   use Lunixi\Laravel\Facades\Lunixi;
   use Lunixi\Sdk\Payment\CreateIntentRequest;

   $attempt = $order->attempts()->create();
   $request = (new CreateIntentRequest($order->total_minor, 'TRY', $order->number))
       ->withCallbackUrl(route('checkout.return', $order));

   $intent = Lunixi::payments()->createIntent($request, "order-{$order->id}-attempt-{$attempt->id}");

   $order->update([
       'lunixi_payment_id' => $intent->paymentId(),
       'lunixi_checkout_script' => $intent->checkoutFormContent(),
       'status' => 'pending',
   ]);
   ```

2. **Show the form** on your payment page:

   ```blade
   <x-lunixi::checkout-form :script="$order->lunixi_checkout_script" />
   ```

   or straight from the intent: `<x-lunixi::checkout-form :intent="$intent" />`.
   The gateway's script is rendered as is; it loads the Lunixi checkout and
   mounts the form. If the script is empty the component shows a "payment could
   not be started" message (translatable: `lunixi::checkout.unavailable`).

   With a Content Security Policy, pass your nonce:
   `<x-lunixi::checkout-form :script="..." :nonce="Vite::cspNonce()" />`.
   The script loads the checkout runtime from the Lunixi CDN; with
   `'strict-dynamic'` in `script-src` that load is trusted through the nonce,
   otherwise allow the Lunixi CDN host in `script-src`.

   Render only the gateway script received by your authenticated server-side
   SDK call; never accept markup from a browser parameter. Enforce ownership
   before reading the saved script, encrypt its token-bearing model attribute
   at rest, and send `Cache-Control: private, no-store` and
   `Referrer-Policy: no-referrer`. The component renders HTML; it does not add
   authorization, CSRF or durable payment orchestration to your application.

3. **The buyer returns** to your `callbackUrl` after 3D Secure. The return
   itself does not prove the payment: show a "payment is being confirmed" page,
   and if you want to show the state, read it with
   `Lunixi::payments()->get($order->lunixi_payment_id)`. Do not mark the order
   paid here.

4. **The webhook decides** the order state (next section).

5. **Reconciliation** covers a webhook that never arrives (next sections).

Capture, void and refund are SDK calls (`payments()->capture()`, `->void()`,
`->refund()`), each with its own idempotency key.

### Optional server-side redirect

With PHP SDK ^0.29.5 and a backend that returns `paymentPageUrl`, an application
can explicitly offer hosted checkout instead of rendering the component. Use
the same saved intent and original attempt; the backend URL may carry a token
different from the embedded token. The SDK validates its exact HTTPS payment
origin and path. Do not construct a URL from `token()`.

```php
use Illuminate\Support\Facades\Gate;

// In your authenticated, CSRF-protected checkout POST; define the order policy.
Gate::authorize('pay', $order);
$url = $intent->paymentPageUrl(); // same authenticated create-intent response
abort_if($url === '', 409, 'Payment session requires reconciliation.');

return redirect()->away($url, 303, [
    'Cache-Control' => 'private, no-store',
    'Referrer-Policy' => 'no-referrer',
]);
```

If the URL is absent, preserve the attempt and reconcile; do not create another
intent or key just to obtain a redirect. The embedded form can still be used
when its original session is valid. Encrypt a saved URL and never log it. A
browser return remains informational in either mode. Payment links and
subscription portal redirects are separate products.

## Webhooks

The package registers `POST /lunixi/webhook` (route name `lunixi.webhook`).
Register its full URL in the Lunixi dashboard and put the endpoint's secret in
`LUNIXI_WEBHOOK_SECRET`. The route has no session or CSRF middleware; the
signature is the authentication.

Each verified webhook fires `Lunixi\Laravel\Events\WebhookReceived`:

```php
use Lunixi\Laravel\Events\WebhookReceived;

final class UpdateOrderFromLunixi
{
    public function handle(WebhookReceived $event): void
    {
        $webhook = $event->webhook;
        if (!$webhook->matches('payment.*')) {
            return;
        }

        $order = Order::where('lunixi_payment_id', $webhook->data()['intentId'] ?? null)->first();
        if ($order === null) {
            return;
        }

        match ($webhook->type()) {
            'payment.captured', 'payment.authorized' => $order->markPaid(),
            'payment.failed', 'payment.expired', 'payment.voided' => $order->markFailed(),
            'payment.refunded', 'payment.partially_refunded' => $order->syncRefunds(),
            default => null,
        };
    }
}
```

Payment events carry the payment id as `intentId` and amounts as minor-unit
strings under `data()`. Other products' event names are constants in the SDK
(`InsuranceEvents`, `SubscriptionEvents`, `KycEvents` …).

Lunixi delivers each event at least once, so a listener must give the same
result when it receives the same event again. The package also remembers
processed event ids (for `dedupe_ttl`, default 7 days) and answers a repeat
with `duplicate` without calling your listeners.

| Answer | When | What Lunixi does |
|---|---|---|
| 200 `ok` | Listeners ran | Done |
| 200 `duplicate` | Event id already processed | Done |
| 200 `queued` | Queue mode: event put on your queue | Done |
| 409 `in_progress` | Same event is being handled by another request | Delivers again later |
| 400 `invalid_signature` | Signature, timestamp or headers wrong | Delivers again later |
| 500 `processing_failed` | A listener threw | Delivers again later |
| 503 `not_configured` | `LUNIXI_WEBHOOK_SECRET` (or `mode`) not set | Delivers again later |

A listener that throws leaves the event unprocessed, so the next delivery runs
your listeners again.

### Handling webhooks on the queue

```dotenv
LUNIXI_WEBHOOK_MODE=queue
LUNIXI_WEBHOOK_QUEUE=lunixi
```

The signature is verified in the request, the answer is `200 queued`, and
`ProcessWebhookJob` runs your listeners on the queue. A failing listener fails
the job; retries follow `webhooks.queue.tries` and `webhooks.queue.backoff`,
then the job goes to `failed_jobs` (retry it with `php artisan queue:retry`).

### Own route

Set `LUNIXI_WEBHOOKS_ENABLED=false` and register the route yourself, outside the
`web` middleware group:

```php
Route::lunixiWebhooks('payments/lunixi/hook');
```

### Cache

Processed ids and the lock that stops two parallel deliveries of one event
use the cache store in `webhooks.cache_store` (default store when null). Use a
store shared by all your servers (Redis, database, Memcached) in production.

## Reconciliation

When a webhook is lost, `lunixi:reconcile` reads the payment from Lunixi.
Implement `PendingPaymentRepository` for your orders and bind it:

```php
use Lunixi\Laravel\Payments\PendingPaymentRepository;

final class PendingOrders implements PendingPaymentRepository
{
    public function pendingPaymentIds(\DateTimeInterface $createdBefore, int $limit): iterable
    {
        return Order::where('status', 'pending')
            ->whereNotNull('lunixi_payment_id')
            ->where('created_at', '<', $createdBefore)
            ->oldest()
            ->limit($limit)
            ->pluck('lunixi_payment_id');
    }
}

// AppServiceProvider::register()
$this->app->bind(PendingPaymentRepository::class, PendingOrders::class);
```

```php
// routes/console.php
Schedule::command('lunixi:reconcile')->everyFiveMinutes()->withoutOverlapping();
```

Each payment read fires `Lunixi\Laravel\Events\PaymentReconciled` with the
payment (`$event->payment->status()`). A payment can still be in progress or
need reconciliation (`PaymentStatus::NEEDS_RECONCILIATION`): mark the order
paid only for a captured or authorized status, and leave it pending otherwise.

## Testing your integration

Requests go through the Laravel HTTP client, so `Http::fake()` works:

```php
Http::fake([
    '*/api/v1/auth/token' => Http::response(['data' => ['access_token' => 'tok', 'expires_in' => 900]]),
    '*/api/v1/payments/*' => Http::response(['data' => ['id' => 'pay_1', 'status' => 'CAPTURED']]),
]);
```

Post a correctly signed webhook to your listeners:

```php
use Lunixi\Laravel\Testing\SignsLunixiWebhooks;

final class PaymentWebhookTest extends TestCase
{
    use SignsLunixiWebhooks;

    public function test_captured_payment_marks_order_paid(): void
    {
        config(['lunixi.webhooks.secret' => 'whsec_test']);
        $order = Order::factory()->create(['lunixi_payment_id' => 'pay_1']);

        $this->postLunixiWebhook('payment.captured', ['intentId' => 'pay_1', 'amount' => '10000', 'currency' => 'TRY'])
            ->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
    }
}
```

## Configuration

`php artisan vendor:publish --tag=lunixi-config` publishes `config/lunixi.php`.
Every option is described there. Other tags: `lunixi-views`, `lunixi-lang`.

| Variable | Default | |
|---|---|---|
| `LUNIXI_BASE_URL` | `https://api-gateway.lunixi.com` | |
| `LUNIXI_ENVIRONMENT` | `LIVE` | `LIVE` or `TEST` |
| `LUNIXI_KEY_ID` | — | Required |
| `LUNIXI_PRIVATE_KEY_PATH` | — | Required (or `LUNIXI_PRIVATE_KEY`) |
| `LUNIXI_WEBHOOK_SECRET` | — | Required for webhooks |
| `LUNIXI_WEBHOOK_MODE` | `sync` | `sync` or `queue` |
| `LUNIXI_WEBHOOK_PATH` | `lunixi/webhook` | |
| `LUNIXI_TOKEN_CACHE_STORE` | default store | |
| `LUNIXI_HTTP_TRANSPORT` | `laravel` | `laravel` or `curl` |
| `LUNIXI_LOG_CHANNEL` | default channel | |

Logs never contain request bodies, secrets, private keys or tokens.

### Octane

Clients are built once per connection and hold no request state; the token is
in the cache. They are safe to keep between Octane requests.

## License

MIT
