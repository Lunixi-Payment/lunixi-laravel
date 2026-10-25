<?php

declare(strict_types=1);

namespace Lunixi\Laravel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Lunixi\Laravel\Console\CheckCommand;
use Lunixi\Laravel\Console\GenerateKeyCommand;
use Lunixi\Laravel\Console\InstallCommand;
use Lunixi\Laravel\Console\ReconcileCommand;
use Lunixi\Laravel\Support\ConfigurationFactory;
use Lunixi\Laravel\Support\PrivateKeyResolver;
use Lunixi\Laravel\Webhooks\QueueSettings;
use Lunixi\Laravel\Webhooks\WebhookController;
use Lunixi\Laravel\Webhooks\WebhookProcessor;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use Psr\Log\LoggerInterface;

/**
 * Registers the Lunixi SDK in the container. Nothing here reads credentials:
 * a connection is built on first use, so `config:cache`, `route:list` and
 * other commands work on a machine without Lunixi keys.
 */
final class LunixiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/lunixi.php', 'lunixi');

        $this->app->singleton(ConfigurationFactory::class, fn (Application $app): ConfigurationFactory => new ConfigurationFactory(
            new PrivateKeyResolver(fn (string $path): string => $app->basePath($path))
        ));

        $this->app->singleton(LunixiManager::class, fn (Application $app): LunixiManager => new LunixiManager(
            $app->make('config'),
            $app->make(ConfigurationFactory::class),
            $app->make(\Illuminate\Http\Client\Factory::class),
            $app->make('cache'),
            $app->make('encrypter')
        ));

        $this->app->bind(LunixiClient::class, fn (Application $app): LunixiClient => $app->make(LunixiManager::class)->connection());

        $this->app->singleton(WebhookVerifier::class);

        $this->app->singleton(WebhookProcessor::class, function (Application $app): WebhookProcessor {
            $config = $app->make('config');
            $storeName = $config->get('lunixi.webhooks.cache_store');
            $cache = $app->make('cache')->store(is_string($storeName) && $storeName !== '' ? $storeName : null);
            $store = $cache->getStore();

            return new WebhookProcessor(
                $cache,
                $store instanceof LockProvider ? $store : null,
                $app->make('events'),
                $app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                (int) $config->get('lunixi.webhooks.dedupe_ttl', 604800),
                (int) $config->get('lunixi.webhooks.lock_seconds', 120),
                QueueSettings::fromArray((array) $config->get('lunixi.webhooks.queue', []))
            );
        });

        $this->app->when(WebhookController::class)
            ->needs(LoggerInterface::class)
            ->give(fn (Application $app): LoggerInterface => $app->make('log')->channel($app->make('config')->get('lunixi.log_channel')));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'lunixi');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'lunixi');
        Blade::componentNamespace('Lunixi\\Laravel\\View\\Components', 'lunixi');

        Router::macro('lunixiWebhooks', function (string $path = 'lunixi/webhook'): RoutingRoute {
            /** @var Router $this */
            return $this->post($path, WebhookController::class)->name('lunixi.webhook');
        });

        if ((bool) $this->app->make('config')->get('lunixi.webhooks.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/lunixi.php' => $this->app->configPath('lunixi.php'),
            ], 'lunixi-config');
            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/lunixi'),
            ], 'lunixi-views');
            $this->publishes([
                __DIR__ . '/../lang' => $this->app->langPath('vendor/lunixi'),
            ], 'lunixi-lang');

            $this->commands([
                InstallCommand::class,
                CheckCommand::class,
                GenerateKeyCommand::class,
                ReconcileCommand::class,
            ]);
        }
    }
}
