<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware((array) config('lunixi.webhooks.middleware', []))
    ->group(static function (): void {
        Route::lunixiWebhooks((string) config('lunixi.webhooks.path', 'lunixi/webhook'));
    });
