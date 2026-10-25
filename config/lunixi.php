<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The connection used by the Lunixi facade and by LunixiClient injection.
    | Use Lunixi::connection('name') for any other connection.
    |
    */

    'default' => env('LUNIXI_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One entry per API key. The key id and the Ed25519 private key come from
    | the Lunixi dashboard. Prefer `private_key_path` (a file outside the web
    | root); `private_key` is for local use only and accepts "\n" for newlines.
    |
    | A second connection is needed where a second key must approve, for
    | example wallet bulk payouts (`wallet:bulk:approve`).
    |
    */

    'connections' => [

        'default' => [
            'base_url' => env('LUNIXI_BASE_URL', 'https://api-gateway.lunixi.com'),
            'environment' => env('LUNIXI_ENVIRONMENT', 'LIVE'),
            'key_id' => env('LUNIXI_KEY_ID'),
            'private_key_path' => env('LUNIXI_PRIVATE_KEY_PATH'),
            'private_key' => env('LUNIXI_PRIVATE_KEY'),
            'timeout' => 30,
            'max_retries' => 2,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Bearer token cache
    |--------------------------------------------------------------------------
    |
    | The short-lived bearer token is kept in the cache so every request does
    | not fetch a new one. `store` null uses the default cache store. With
    | `encrypt` the token is stored encrypted with the application key.
    |
    */

    'token_cache' => [
        'store' => env('LUNIXI_TOKEN_CACHE_STORE'),
        'encrypt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    |
    | `laravel` sends requests through the Laravel HTTP client, so Http::fake()
    | works in your tests. `curl` uses the SDK's own curl client.
    |
    */

    'http' => [
        'transport' => env('LUNIXI_HTTP_TRANSPORT', 'laravel'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The route is registered without the `web` middleware group (no session,
    | no CSRF): the signature is the authentication. Set `enabled` to false to
    | register it yourself with Route::lunixiWebhooks('your/path').
    |
    | `mode` sync handles the event inside the request; a failing listener
    | returns 500 and Lunixi delivers the event again. `mode` queue verifies
    | the signature, answers 200 and handles the event on your queue.
    |
    */

    'webhooks' => [
        'enabled' => env('LUNIXI_WEBHOOKS_ENABLED', true),
        'path' => env('LUNIXI_WEBHOOK_PATH', 'lunixi/webhook'),
        'secret' => env('LUNIXI_WEBHOOK_SECRET'),
        'tolerance' => 300,
        'middleware' => [],
        'mode' => env('LUNIXI_WEBHOOK_MODE', 'sync'),
        'queue' => [
            'connection' => env('LUNIXI_WEBHOOK_QUEUE_CONNECTION'),
            'name' => env('LUNIXI_WEBHOOK_QUEUE'),
            'tries' => 10,
            'backoff' => [10, 60, 300, 900, 3600],
        ],
        'cache_store' => env('LUNIXI_WEBHOOK_CACHE_STORE'),
        'dedupe_ttl' => 60 * 60 * 24 * 7,
        'lock_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Defaults of the lunixi:reconcile command. It asks your
    | PendingPaymentRepository for payments still pending after
    | `older_than_minutes` and reads their status from Lunixi.
    |
    */

    'reconcile' => [
        'older_than_minutes' => 15,
        'limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    |
    | Null uses the default channel. Request bodies, secrets, private keys and
    | tokens are never logged.
    |
    */

    'log_channel' => env('LUNIXI_LOG_CHANNEL'),

];
