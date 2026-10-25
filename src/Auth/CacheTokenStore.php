<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Auth;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Lunixi\Sdk\Auth\TokenStoreInterface;

/**
 * Keeps the SDK's bearer token in a Laravel cache store, so PHP-FPM workers
 * share one token instead of fetching a new one per request.
 *
 * With an encrypter the token is stored encrypted: a shared Redis or file
 * cache never holds it in clear text. A value that cannot be decrypted (for
 * example after APP_KEY rotation) reads as absent, and the SDK fetches a new
 * token.
 */
final class CacheTokenStore implements TokenStoreInterface
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ?StringEncrypter $encrypter = null
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        if ($this->encrypter === null) {
            return $value;
        }

        try {
            $token = $this->encrypter->decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        return $token !== '' ? $token : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            $this->cache->forget($key);

            return;
        }

        $stored = $this->encrypter !== null ? $this->encrypter->encryptString($value) : $value;
        $this->cache->put($key, $stored, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->cache->forget($key);
    }
}
