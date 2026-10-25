# Changelog

All notable changes to `lunixi/laravel`.

## 0.1.0

First release. Requires `lunixi/php-sdk` ^0.28, PHP 8.2+, Laravel 12 or 13.

### Added

- `LunixiServiceProvider` (package auto-discovery) and `config/lunixi.php`:
  named connections, private key from a file or inline, token cache, HTTP
  transport, webhooks, reconciliation, log channel.
- `Lunixi` facade and `LunixiClient` container binding (default connection);
  `Lunixi::connection('name')` for other keys.
- `LaravelHttpClient`: SDK transport over the Laravel HTTP client, so
  `Http::fake()` covers Lunixi calls.
- `CacheTokenStore`: the bearer token in a Laravel cache store, encrypted with
  the application key by default.
- Webhook route `POST /lunixi/webhook` (`lunixi.webhook`) and
  `Route::lunixiWebhooks()`: signature verification by the SDK, processed-id
  memory, a lock against parallel deliveries of one event, the
  `WebhookReceived` event, `sync` and `queue` modes.
- `<x-lunixi::checkout-form>`: renders the gateway's checkout form script, with
  an optional CSP nonce; English and Turkish message when the script is empty.
- `PendingPaymentRepository`, `lunixi:reconcile` and `PaymentReconciled`.
- `lunixi:install`, `lunixi:check`, `lunixi:generate-key`.
- `SignsLunixiWebhooks` test helper.
