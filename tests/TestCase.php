<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Illuminate\Foundation\Application;
use Lunixi\Laravel\LunixiServiceProvider;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const BASE_URL = 'https://api-gateway.test.lunixi.com';
    protected const KEY_ID = 'mk_test_sk_laravel';
    protected const WEBHOOK_SECRET = 'whsec_test_laravel';

    /** @var array{privateKey:string, publicKey:string}|null */
    private static ?array $keyPair = null;

    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        return [LunixiServiceProvider::class];
    }

    /** @return array<string,class-string> */
    protected function getPackageAliases($app): array
    {
        return ['Lunixi' => \Lunixi\Laravel\Facades\Lunixi::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('lunixi.connections.default', [
            'base_url' => self::BASE_URL,
            'environment' => 'TEST',
            'key_id' => self::KEY_ID,
            'private_key' => self::privateKey(),
            'timeout' => 5,
            'max_retries' => 0,
        ]);
        $app['config']->set('lunixi.webhooks.secret', self::WEBHOOK_SECRET);
    }

    protected static function privateKey(): string
    {
        self::$keyPair ??= Ed25519Signer::generateKeyPair();

        return self::$keyPair['privateKey'];
    }

    /** Fake token response in the gateway's success envelope. */
    protected static function tokenResponse(string $token = 'tok_test', int $expiresIn = 900): array
    {
        return ['status' => 'success', 'code' => 'OK', 'data' => ['access_token' => $token, 'expires_in' => $expiresIn]];
    }
}
