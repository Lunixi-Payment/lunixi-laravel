<?php

declare(strict_types=1);

namespace Lunixi\Laravel\Tests;

use Lunixi\Laravel\Facades\Lunixi;
use Lunixi\Laravel\LunixiManager;
use Lunixi\Laravel\Support\ConfigurationFactory;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\PaymentClient;

final class ConnectionTest extends TestCase
{
    public function test_config_maps_to_sdk_configuration(): void
    {
        $config = Lunixi::connection()->config();

        self::assertSame(self::BASE_URL, $config->baseUrl());
        self::assertSame(self::KEY_ID, $config->keyId());
        self::assertSame('TEST', $config->environment());
        self::assertSame(5.0, $config->timeout());
        self::assertSame(0, $config->maxRetries());
        self::assertSame(ConfigurationFactory::USER_AGENT, $config->userAgent());
    }

    public function test_injected_client_is_the_default_connection(): void
    {
        self::assertSame(Lunixi::connection(), $this->app->make(LunixiClient::class));
        self::assertSame(Lunixi::connection(), Lunixi::connection('default'));
    }

    public function test_facade_forwards_to_default_connection(): void
    {
        self::assertInstanceOf(PaymentClient::class, Lunixi::payments());
        self::assertSame(Lunixi::connection()->payments(), Lunixi::payments());
    }

    public function test_named_connection_is_separate(): void
    {
        config()->set('lunixi.connections.approver', [
            'base_url' => self::BASE_URL,
            'environment' => 'TEST',
            'key_id' => 'mk_test_sk_approver',
            'private_key' => self::privateKey(),
        ]);

        $approver = Lunixi::connection('approver');

        self::assertNotSame(Lunixi::connection(), $approver);
        self::assertSame('mk_test_sk_approver', $approver->config()->keyId());
    }

    public function test_unknown_connection_throws(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('[missing] is not defined');

        Lunixi::connection('missing');
    }

    public function test_application_boots_without_credentials(): void
    {
        config()->set('lunixi.connections.default.key_id', null);
        config()->set('lunixi.connections.default.private_key', null);
        $this->app->forgetInstance(LunixiManager::class);

        $this->artisan('route:list')->assertSuccessful();

        $this->expectException(ConfigurationException::class);
        Lunixi::connection();
    }

    public function test_private_key_path_wins_and_is_read_from_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lunixi-key');
        file_put_contents($path, self::privateKey());
        config()->set('lunixi.connections.default.private_key', 'not-a-key');
        config()->set('lunixi.connections.default.private_key_path', $path);
        $this->app->forgetInstance(LunixiManager::class);

        try {
            self::assertSame(self::privateKey(), Lunixi::connection()->config()->privateKey());
        } finally {
            unlink($path);
        }
    }

    public function test_relative_private_key_path_resolves_against_base_path(): void
    {
        $relative = 'storage/lunixi-test-key.pem';
        $absolute = $this->app->basePath($relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, self::privateKey());
        config()->set('lunixi.connections.default.private_key_path', $relative);
        $this->app->forgetInstance(LunixiManager::class);

        try {
            self::assertSame(self::privateKey(), Lunixi::connection()->config()->privateKey());
        } finally {
            unlink($absolute);
        }
    }

    public function test_unreadable_private_key_path_throws_without_key_material(): void
    {
        config()->set('lunixi.connections.default.private_key_path', '/nonexistent/lunixi.pem');
        $this->app->forgetInstance(LunixiManager::class);

        try {
            Lunixi::connection();
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('/nonexistent/lunixi.pem', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public function test_inline_private_key_accepts_escaped_newlines(): void
    {
        config()->set('lunixi.connections.default.private_key', str_replace("\n", '\n', self::privateKey()));
        $this->app->forgetInstance(LunixiManager::class);

        self::assertSame(trim(self::privateKey()), Lunixi::connection()->config()->privateKey());
    }

    public function test_unknown_transport_throws(): void
    {
        config()->set('lunixi.http.transport', 'guzzle');
        $this->app->forgetInstance(LunixiManager::class);

        $this->expectException(ConfigurationException::class);
        Lunixi::connection();
    }
}
