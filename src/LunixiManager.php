<?php

declare(strict_types=1);

namespace Lunixi\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Lunixi\Laravel\Auth\CacheTokenStore;
use Lunixi\Laravel\Http\LaravelHttpClient;
use Lunixi\Laravel\Support\ConfigurationFactory;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\CurlHttpClient;
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\LunixiClient;

/**
 * Builds one LunixiClient per configured connection, lazily, and keeps it for
 * the life of the process. A client holds no per-request state (the bearer
 * token lives in the cache), so this is safe under Octane.
 *
 * Calls the manager does not define go to the default connection:
 * Lunixi::payments() is Lunixi::connection()->payments().
 *
 * @mixin LunixiClient
 */
final class LunixiManager
{
    public const TRANSPORT_LARAVEL = 'laravel';
    public const TRANSPORT_CURL = 'curl';

    /** @var array<string,LunixiClient> */
    private array $clients = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConfigurationFactory $configurations,
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        private readonly StringEncrypter $encrypter
    ) {
    }

    /**
     * @throws ConfigurationException when the connection is missing or incomplete.
     */
    public function connection(?string $name = null): LunixiClient
    {
        $name ??= $this->defaultConnection();

        return $this->clients[$name] ??= $this->make($name);
    }

    public function defaultConnection(): string
    {
        return (string) $this->config->get('lunixi.default', 'default');
    }

    /**
     * @param array<int,mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }

    private function make(string $name): LunixiClient
    {
        $settings = $this->config->get("lunixi.connections.{$name}");
        if (!is_array($settings)) {
            throw new ConfigurationException("Lunixi connection [{$name}] is not defined in config/lunixi.php.");
        }

        return new LunixiClient(
            $this->configurations->make($name, $settings),
            $this->transport(),
            $this->tokenStore()
        );
    }

    private function transport(): HttpClientInterface
    {
        $transport = (string) $this->config->get('lunixi.http.transport', self::TRANSPORT_LARAVEL);

        return match ($transport) {
            self::TRANSPORT_LARAVEL => new LaravelHttpClient($this->http),
            self::TRANSPORT_CURL => new CurlHttpClient(),
            default => throw new ConfigurationException(
                "Lunixi http.transport must be 'laravel' or 'curl', [{$transport}] given."
            ),
        };
    }

    private function tokenStore(): CacheTokenStore
    {
        $store = $this->config->get('lunixi.token_cache.store');
        $encrypt = (bool) $this->config->get('lunixi.token_cache.encrypt', true);

        return new CacheTokenStore(
            $this->cache->store(is_string($store) && $store !== '' ? $store : null),
            $encrypt ? $this->encrypter : null
        );
    }
}
